<?php
/**
 * 素材清单同步脚本
 *
 * 扫描 public/assets 下的 bg / bgm / sfx / sprites 目录，把实际存在的文件
 * 同步进 public/assets/manifest.json：
 *   - 新增文件 → 追加条目
 *   - 已删除文件 → 移除条目
 *   - 已存在的条目 → 保留其 id / name / category / character 等人工维护字段
 *
 * 用法：
 *   php tools/sync_manifest.php            # 同步（写入 manifest.json）
 *   php tools/sync_manifest.php --dry-run  # 只打印差异，不写文件
 *   php tools/sync_manifest.php --prune    # 同时删除 manifest 中已不存在的文件条目
 *
 * 说明：
 *   - 默认不删除条目（避免误删人工配置），只做新增；加 --prune 才清理失效条目。
 *   - 立绘的 category / character 由目录结构推断：
 *       sprites/<分类>/<角色>/xxx.png  → category=<分类>, character=<角色>
 *       sprites/xxx.png                → category=其他, character=未设置
 *     已存在条目的 category / character 不会被覆盖，可手工调整。
 */

$root = dirname(__DIR__);
$assetsDir = $root . '/public/assets';
$manifestPath = $assetsDir . '/manifest.json';

$dryRun = in_array('--dry-run', $argv, true);
$prune  = in_array('--prune', $argv, true);

// 分类目录 → manifest 字段名
$sections = [
    'bg'      => 'backgrounds',
    'sprites' => 'sprites',
    'bgm'     => 'bgm',
    'sfx'     => 'sfx',
];

// 各分类允许的扩展名
$extensions = [
    'bg'      => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'],
    'sprites' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'],
    'bgm'     => ['mp3', 'ogg', 'wav', 'm4a', 'aac', 'flac'],
    'sfx'     => ['mp3', 'ogg', 'wav', 'm4a', 'aac', 'flac'],
];

if (!is_file($manifestPath)) {
    fwrite(STDERR, "找不到 manifest.json：{$manifestPath}\n");
    exit(1);
}

$manifest = json_decode(file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "manifest.json 解析失败，请检查 JSON 格式。\n");
    exit(1);
}

/**
 * 递归收集目录下的素材文件，返回相对 assets 目录的路径（统一用 / 分隔）。
 */
function scanFiles($dir, array $allowedExt, $assetsDir)
{
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $allowedExt, true)) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($assetsDir) + 1));
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

/**
 * 由文件路径生成默认 id：去掉扩展名，非字母数字字符转下划线。
 */
function defaultId($rel)
{
    $noExt = preg_replace('/\.[^.]+$/', '', $rel);
    $id = preg_replace('/[^0-9A-Za-z_\x{4e00}-\x{9fa5}]+/u', '_', $noExt);
    return trim($id, '_');
}

/**
 * 由文件路径生成默认显示名：取文件名（不含扩展名）。
 */
function defaultName($rel)
{
    $base = basename($rel);
    return preg_replace('/\.[^.]+$/', '', $base);
}

/**
 * 立绘：从 sprites/<分类>/<角色>/file.png 推断 category / character。
 */
function spriteMeta($rel)
{
    $parts = explode('/', $rel);   // sprites / 分类 / 角色 / 文件
    if (count($parts) >= 4) {
        return ['category' => $parts[1], 'character' => $parts[2]];
    }
    return ['category' => '其他', 'character' => ''];
}

$added = [];
$removed = [];
$kept = 0;

foreach ($sections as $dir => $section) {
    $existing = isset($manifest[$section]) && is_array($manifest[$section])
        ? $manifest[$section] : [];

    // 以 src 为键建立索引，src 是文件与条目之间的唯一纽带
    $bySrc = [];
    foreach ($existing as $item) {
        if (!empty($item['src'])) $bySrc[$item['src']] = $item;
    }

    $files = scanFiles($assetsDir . '/' . $dir, $extensions[$dir], $assetsDir);
    $usedIds = [];
    foreach ($existing as $item) {
        if (!empty($item['id'])) $usedIds[$item['id']] = true;
    }

    $result = [];
    foreach ($files as $rel) {
        if (isset($bySrc[$rel])) {
            $result[] = $bySrc[$rel];   // 保留人工维护的字段
            $kept++;
            continue;
        }
        // 新文件 → 生成条目
        $id = defaultId($rel);
        $base = $id;
        $n = 2;
        while (isset($usedIds[$id])) { $id = $base . '_' . $n; $n++; }
        $usedIds[$id] = true;

        $item = ['id' => $id, 'name' => defaultName($rel), 'src' => $rel];
        if ($dir === 'sprites') {
            $meta = spriteMeta($rel);
            $item['thumb'] = $rel;
            $item['category'] = $meta['category'];
            if ($meta['character'] !== '') $item['character'] = $meta['character'];
        } elseif ($dir === 'bg') {
            $item['thumb'] = $rel;
        }
        $result[] = $item;
        $added[] = "[{$section}] {$rel}  (id={$id})";
    }

    // 失效条目（文件已不存在）
    foreach ($existing as $item) {
        if (empty($item['src'])) continue;
        if (!in_array($item['src'], $files, true)) {
            $removed[] = "[{$section}] {$item['src']}  (id=" . ($item['id'] ?? '?') . ")";
        }
    }

    $manifest[$section] = $result;
}

// 输出差异
echo "素材同步结果：\n";
echo "  保留条目：" . $kept . "\n";
echo "  新增条目：" . count($added) . "\n";
foreach ($added as $line) echo "    + {$line}\n";
echo "  失效条目：" . count($removed) . "\n";
foreach ($removed as $line) echo "    - {$line}\n";

if ($dryRun) {
    echo "\n[dry-run] 未写入 manifest.json。\n";
    exit(0);
}

if ($removed && !$prune) {
    echo "\n注意：失效条目已保留（未加 --prune）。如需清理请执行：php tools/sync_manifest.php --prune\n";
}

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON 编码失败：" . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($manifestPath, $json . "\n");
echo "\n已写入 {$manifestPath}\n";
