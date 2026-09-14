<?php
/**
 * 数据库迁移脚本
 *
 * 读取 sql/schema.sql 并在目标库中执行建表语句。
 *
 * 用法：
 *   php tools/migrate.php            # 执行建表
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
// 注意：只把「行首为 --」的整行视为注释，避免误伤字符串或行内内容
$lines = preg_split('/\R/', $sql) ?: [];
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
    echo "dry-run 模式，未实际执行。\n";
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

echo "\n完成，成功执行 {$ok} 条语句。\n";

// 输出结果表清单
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo '当前库中共 ' . count($tables) . " 张表：\n";
foreach ($tables as $t) {
    echo "  - {$t}\n";
}
