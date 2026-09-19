<?php
/**
 * 数据库迁移脚本
 *
 * 流程：
 *   1. 读取 sql/schema.sql 并执行建表语句（CREATE TABLE IF NOT EXISTS，幂等）
 *   2. 多租户增量迁移：检测隔离表是否缺 `site` 列，缺失则补加列、回填 site 值、
 *      重建带 site 前缀的索引与唯一键（仅在列不存在时执行，幂等）
 *
 * 用法：
 *   php tools/migrate.php            # 执行建表 + 站点迁移
 *   php tools/migrate.php --dry-run  # 只打印将要执行的语句
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config/config.php';

$dryRun = in_array('--dry-run', $argv, true);
$schemaFile = $root . '/sql/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "找不到建表脚本：{$schemaFile}\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "读取建表脚本失败\n");
    exit(1);
}

// 去掉注释行后按分号切分语句
// 注意：
//   1. 只把「行首为 --」的整行视为注释，避免误伤字符串或行内内容
//   2. 必须按字面 "\n" 切分，不能用 preg_split('/\R/')：
//      \R 会匹配 UTF-8 多字节字符中的 0x85 / 0x0B / 0x0C 等字节，
//      导致中文注释被从字符中间截断，残留内容被误当作 SQL 执行
$lines = explode("\n", str_replace("\r\n", "\n", $sql));
$clean = [];
foreach ($lines as $line) {
    if (preg_match('/^\s*--/', $line)) {
        continue;
    }
    $clean[] = $line;
}
$statements = array_filter(
    array_map('trim', explode(';', implode("\n", $clean))),
    static fn(string $s): bool => $s !== ''
);

$db = $config['db'];
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']);

echo "目标数据库：{$db['username']}@{$db['host']}:{$db['port']}/{$db['database']}\n";
echo '待执行语句：' . count($statements) . " 条\n\n";

if ($dryRun) {
    foreach ($statements as $i => $stmt) {
        echo '--- [' . ($i + 1) . "] ---\n" . $stmt . ";\n\n";
    }
    echo "dry-run 模式，未实际执行建表语句。\n\n";
    // 站点迁移也以 dry-run 形式预览
    $site = (string) ($config['app']['site'] ?? 'default');
    echo "=== 多租户迁移预览（dry-run）===\n";
    echo "当前 app.site = {$site}\n";
    foreach (site_migration_plan() as $table => $plan) {
        echo "表 {$table}：若缺 site 列，将 ADD COLUMN site AFTER `{$plan['after']}`、回填 site='{$site}'、\n";
        echo "  DROP 旧索引 [" . implode(', ', $plan['drop_indexes']) . "]、\n";
        echo "  ADD 新索引 [" . implode(', ', array_keys($plan['add_indexes'])) . "]\n";
    }
    exit(0);
}

try {
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, '数据库连接失败：' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 阶段 1：执行建表语句
// ---------------------------------------------------------------------------
$ok = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $ok++;
        // 提取表名用于输出
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $stmt, $m)) {
            echo "  [建表] {$m[1]}\n";
        } else {
            echo "  [执行] " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . "…\n";
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "  [失败] " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\n阶段 1 完成，成功执行 {$ok} 条建表语句。\n";

// ---------------------------------------------------------------------------
// 阶段 2：多租户增量迁移
// ---------------------------------------------------------------------------
echo "\n=== 阶段 2：多租户 site 列迁移 ===\n";

$site = (string) ($config['app']['site'] ?? 'default');
if ($site === '') {
    $site = 'default';
}
echo "当前 app.site = {$site}\n";

$plan = site_migration_plan();
$migrated = 0;
$skipped = 0;

foreach ($plan as $table => $tablePlan) {
    if (!column_exists($pdo, $table, 'site')) {
        // 步骤 1：添加 site 列
        $after = $tablePlan['after'];
        $alterSql = "ALTER TABLE `{$table}` ADD COLUMN `site` VARCHAR(32) NOT NULL DEFAULT '' AFTER `{$after}`";
        try {
            $pdo->exec($alterSql);
            echo "  [{$table}] 已添加 site 列（AFTER `{$after}`）\n";
            $migrated++;
        } catch (PDOException $e) {
            fwrite(STDERR, "  [{$table}] 添加 site 列失败：" . $e->getMessage() . "\n");
            exit(1);
        }

        // 步骤 2：回填历史数据 site 值（用当前 app.site 标记既有数据归属）
        $backfillSql = "UPDATE `{$table}` SET `site` = ? WHERE `site` = ''";
        try {
            $stmt = $pdo->prepare($backfillSql);
            $stmt->execute([$site]);
            $affected = $stmt->rowCount();
            echo "  [{$table}] 回填 site='{$site}'，影响 {$affected} 行\n";
        } catch (PDOException $e) {
            fwrite(STDERR, "  [{$table}] 回填失败：" . $e->getMessage() . "\n");
            exit(1);
        }
    } else {
        echo "  [{$table}] site 列已存在，跳过\n";
        $skipped++;
    }

    // 步骤 3：删除旧索引（按名称存在性检测，避免报错）
    foreach ($tablePlan['drop_indexes'] as $oldIndex) {
        if (index_exists($pdo, $table, $oldIndex)) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$oldIndex}`");
                echo "  [{$table}] 删除旧索引 {$oldIndex}\n";
            } catch (PDOException $e) {
                fwrite(STDERR, "  [{$table}] 删除旧索引 {$oldIndex} 失败：" . $e->getMessage() . "\n");
            }
        }
    }

    // 步骤 4：添加新索引（按名称存在性检测，避免重复添加）
    foreach ($tablePlan['add_indexes'] as $newIndex => $indexDef) {
        if (!index_exists($pdo, $table, $newIndex)) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD {$indexDef}");
                echo "  [{$table}] 添加新索引 {$newIndex}\n";
            } catch (PDOException $e) {
                fwrite(STDERR, "  [{$table}] 添加新索引 {$newIndex} 失败：" . $e->getMessage() . "\n");
            }
        }
    }
}

echo "\n阶段 2 完成：迁移 {$migrated} 张表，跳过 {$skipped} 张已具备 site 列的表。\n";

// 输出结果表清单
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "\n当前库中共 " . count($tables) . " 张表：\n";
foreach ($tables as $t) {
    echo "  - {$t}\n";
}

// ---------------------------------------------------------------------------
// 辅助函数
// ---------------------------------------------------------------------------

/**
 * 检查某表是否已存在指定列
 */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() !== false;
}

/**
 * 检查某表是否已存在指定索引
 */
function index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table, $indexName]);
    return $stmt->fetchColumn() !== false;
}

/**
 * 多租户迁移计划：表 => [after 列, 待删旧索引, 待加新索引]
 *
 * 旧索引名与 schema.sql 改造前的命名一致；新索引名与当前 schema.sql 一致。
 * 旧索引即使不存在也不报错（跳过），便于在多次迁移或不同起点上幂等执行。
 */
function site_migration_plan(): array
{
    return [
        'works' => [
            'after' => 'id',
            'drop_indexes' => [
                'uk_works_short_code',
                'idx_works_user_status',
                'idx_works_updated',
                'idx_works_public_updated',
                'idx_works_public_play',
            ],
            'add_indexes' => [
                'uk_works_site_short_code'        => 'UNIQUE KEY `uk_works_site_short_code` (`site`, `short_code`)',
                'idx_works_site_user_status'     => 'KEY `idx_works_site_user_status` (`site`, `user_id`, `status`)',
                'idx_works_site_updated'         => 'KEY `idx_works_site_updated` (`site`, `updated_at`)',
                'idx_works_site_public_updated'  => 'KEY `idx_works_site_public_updated` (`site`, `is_public`, `status`, `updated_at`)',
                'idx_works_site_public_play'      => 'KEY `idx_works_site_public_play` (`site`, `is_public`, `status`, `play_count`)',
            ],
        ],
        'work_revisions' => [
            'after' => 'id',
            'drop_indexes' => ['idx_revisions_work'],
            'add_indexes' => [
                'idx_revisions_site_work' => 'KEY `idx_revisions_site_work` (`site`, `work_id`, `created_at`)',
            ],
        ],
        'work_assets' => [
            'after' => 'id',
            'drop_indexes' => [
                'uk_assets_user_hash',
                'idx_assets_user_type',
                'idx_assets_public',
            ],
            'add_indexes' => [
                'uk_assets_site_user_hash'  => 'UNIQUE KEY `uk_assets_site_user_hash` (`site`, `user_id`, `hash`)',
                'idx_assets_site_user_type' => 'KEY `idx_assets_site_user_type` (`site`, `user_id`, `type`, `status`)',
                'idx_assets_site_public'   => 'KEY `idx_assets_site_public` (`site`, `visibility`, `status`, `type`)',
            ],
        ],
        'likes' => [
            'after' => 'id',
            'drop_indexes' => [
                'uk_likes_work_user',
                'idx_likes_user',
            ],
            'add_indexes' => [
                'uk_likes_site_work_user' => 'UNIQUE KEY `uk_likes_site_work_user` (`site`, `work_id`, `user_id`)',
                'idx_likes_site_user'    => 'KEY `idx_likes_site_user` (`site`, `user_id`)',
            ],
        ],
        'comments' => [
            'after' => 'id',
            'drop_indexes' => [
                'idx_comments_work',
                'idx_comments_parent',
            ],
            'add_indexes' => [
                'idx_comments_site_work'   => 'KEY `idx_comments_site_work` (`site`, `work_id`, `status`, `created_at`)',
                'idx_comments_site_parent' => 'KEY `idx_comments_site_parent` (`site`, `parent_id`, `status`, `created_at`)',
            ],
        ],
        'favorites' => [
            'after' => 'id',
            'drop_indexes' => [
                'uk_favorites_work_user',
                'idx_favorites_user',
            ],
            'add_indexes' => [
                'uk_favorites_site_work_user' => 'UNIQUE KEY `uk_favorites_site_work_user` (`site`, `work_id`, `user_id`)',
                'idx_favorites_site_user'     => 'KEY `idx_favorites_site_user` (`site`, `user_id`, `created_at`)',
            ],
        ],
        'reports' => [
            'after' => 'id',
            'drop_indexes' => [
                'uk_reports_target_user',
                'idx_reports_status',
                'idx_reports_target',
            ],
            'add_indexes' => [
                'uk_reports_site_target_user' => 'UNIQUE KEY `uk_reports_site_target_user` (`site`, `target_type`, `target_id`, `user_id`)',
                'idx_reports_site_status'     => 'KEY `idx_reports_site_status` (`site`, `status`, `created_at`)',
                'idx_reports_site_target'     => 'KEY `idx_reports_site_target` (`site`, `target_type`, `target_id`)',
            ],
        ],
        'announcements' => [
            'after' => 'id',
            'drop_indexes' => ['idx_announcements_status'],
            'add_indexes' => [
                'idx_announcements_site_status' => 'KEY `idx_announcements_site_status` (`site`, `status`, `pinned`, `created_at`)',
            ],
        ],
    ];
}
