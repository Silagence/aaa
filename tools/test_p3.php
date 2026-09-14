<?php
/**
 * P3 端到端测试：发布 / 短链 / 详情 / 点赞 / 播放计数 / 广场筛选 / 嵌入
 *
 * 用法：php tools/test_p3.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

$BASE = 'http://127.0.0.1:8000';
$JAR  = sys_get_temp_dir() . '/dramatool_p3_cookie.txt';
@unlink($JAR);

$pass = 0;
$fail = 0;

function req(string $method, string $url, array $data = [], bool $json = false): array
{
    global $JAR;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $JAR,
        CURLOPT_COOKIEFILE     => $JAR,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body, 'json' => $json ? json_decode((string) $body, true) : null];
}

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($extra !== '' ? " -> $extra" : '') . "\n"; }
}

function csrfFrom(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/"csrfToken"\s*:\s*"([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

echo "=== P3 端到端测试 ===\n\n";

// ---------- 1. 注册 / 登录 ----------
echo "[1] 注册并登录\n";
$email = 'p3_' . time() . '@test.local';
$reg = req('GET', "$BASE/register");
$token = csrfFrom($reg['body']);
check('注册页可访问', $reg['code'] === 200 && $token !== '');

$r = req('POST', "$BASE/register", [
    'nickname' => 'P3测试员', 'email' => $email,
    'password' => 'Test123456', 'password_confirm' => 'Test123456', '_token' => $token,
]);
check('注册成功', $r['code'] === 302 || $r['code'] === 200, "HTTP {$r['code']}");

$home = req('GET', "$BASE/editor.php");
$token = csrfFrom($home['body']);
check('编辑器页注入 CSRF', $token !== '');
check('编辑器页含发布按钮', str_contains($home['body'], 'id="btnPublish"'));
check('编辑器页含分享弹窗', str_contains($home['body'], 'id="shareModal"'));
check('编辑器页含简介/标签输入', str_contains($home['body'], 'id="workDesc"') && str_contains($home['body'], 'id="workTags"'));

// ---------- 2. 保存作品 ----------
echo "\n[2] 保存作品（含简介与标签）\n";
$workData = json_encode([
    'manifest' => ['name' => 'P3测试作品', 'author' => 'P3测试员', 'startScene' => 'scene_001',
                   'canvas' => ['width' => 1280, 'height' => 720]],
    'scenes' => [['id' => 'scene_001', 'name' => '开场', 'nodes' => [
        ['type' => 'dialog', 'speaker' => '旁白', 'text' => 'P3 测试对话'],
    ]]],
], JSON_UNESCAPED_UNICODE);

$r = req('POST', "$BASE/api/works/save", [
    'id' => 0, 'title' => 'P3测试作品', 'description' => '这是 P3 测试简介',
    'tags' => '悬疑,校园,短篇', 'data' => $workData, '_token' => $token,
], true);
check('保存返回 ok', !empty($r['json']['ok']), $r['body']);
$workId = (int) ($r['json']['id'] ?? 0);
check('返回作品 id', $workId > 0);

// ---------- 3. 发布并生成短链 ----------
echo "\n[3] 发布作品并生成短链\n";
$r = req('POST', "$BASE/api/works/$workId/publish", ['public' => 1, '_token' => $token], true);
check('发布返回 ok', !empty($r['json']['ok']), $r['body']);
$code = (string) ($r['json']['short_code'] ?? '');
$shareUrl = (string) ($r['json']['share_url'] ?? '');
check('短链码为 8 位', strlen($code) === 8, "code=$code");
check('短链码字符集合法', (bool) preg_match('/^[23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ]{8}$/', $code), "code=$code");
check('share_url 指向 /w/{code}', str_contains($shareUrl, '/w/' . $code), $shareUrl);

// 重复发布应复用同一短链
$r2 = req('POST', "$BASE/api/works/$workId/publish", ['public' => 1, '_token' => $token], true);
check('重复发布复用同一短链', ($r2['json']['short_code'] ?? '') === $code);

// ---------- 4. 详情页 ----------
echo "\n[4] 短链详情页\n";
$d = req('GET', "$BASE/w/$code");
check('详情页 200', $d['code'] === 200, "HTTP {$d['code']}");
check('详情页含作品标题', str_contains($d['body'], 'P3测试作品'));
check('详情页含简介', str_contains($d['body'], '这是 P3 测试简介'));
check('详情页含标签', str_contains($d['body'], '悬疑'));
check('详情页含 OG 标签', str_contains($d['body'], 'og:title'));
check('详情页含点赞按钮', str_contains($d['body'], 'id="btnLike"'));
check('详情页含嵌入代码框', str_contains($d['body'], 'id="embedCode"'));
check('详情页含播放入口', str_contains($d['body'], 'id="btnPlay"'));

$d404 = req('GET', "$BASE/w/zzzzzzzz");
check('不存在的短链返回 404', $d404['code'] === 404, "HTTP {$d404['code']}");

// ---------- 5. 点赞 ----------
echo "\n[5] 点赞 / 取消点赞\n";
$r = req('POST', "$BASE/api/works/$workId/like", ['action' => 'like', '_token' => $token], true);
check('点赞成功', !empty($r['json']['ok']), $r['body']);
check('点赞后 liked=true', ($r['json']['liked'] ?? false) === true);
check('点赞数=1', (int) ($r['json']['like_count'] ?? -1) === 1, 'count=' . ($r['json']['like_count'] ?? 'null'));

$r = req('POST', "$BASE/api/works/$workId/like", ['action' => 'like', '_token' => $token], true);
check('重复点赞幂等（仍为 1）', (int) ($r['json']['like_count'] ?? -1) === 1, 'count=' . ($r['json']['like_count'] ?? 'null'));

$r = req('POST', "$BASE/api/works/$workId/like", ['action' => 'unlike', '_token' => $token], true);
check('取消点赞成功', !empty($r['json']['ok']));
check('取消后点赞数=0', (int) ($r['json']['like_count'] ?? -1) === 0, 'count=' . ($r['json']['like_count'] ?? 'null'));

$r = req('POST', "$BASE/api/works/$workId/like", ['action' => 'like', '_token' => $token], true);
check('再次点赞恢复为 1', (int) ($r['json']['like_count'] ?? -1) === 1);

// 未登录点赞应被拒绝（无 CSRF 令牌 → 419；有令牌但未登录 → 401）
$noAuth = req('POST', "$BASE/api/works/$workId/like", ['action' => 'like']);
check('未登录点赞被拒绝', in_array($noAuth['code'], [401, 403, 419], true), "HTTP {$noAuth['code']}");

// ---------- 6. 播放计数 ----------
echo "\n[6] 播放计数（会话去重）\n";
$r = req('POST', "$BASE/api/works/$workId/play", [], true);
check('播放上报成功', !empty($r['json']['ok']), $r['body']);
$r = req('POST', "$BASE/api/works/$workId/play", [], true);
check('同会话重复播放仍返回 ok', !empty($r['json']['ok']));

$row = req('GET', "$BASE/api/works/$workId/public", [], true);
check('公开接口可读取作品', !empty($row['json']['ok']), $row['body']);

// ---------- 7. 广场筛选 ----------
echo "\n[7] 广场搜索 / 标签 / 排序\n";
$s = req('GET', "$BASE/square");
check('广场页 200', $s['code'] === 200);
check('广场页含搜索框', str_contains($s['body'], 'name="q"'));
check('广场页含排序链接', str_contains($s['body'], 'sort=hot'));
check('广场页含标签云', str_contains($s['body'], 'tag-chip'));
check('广场页展示测试作品', str_contains($s['body'], 'P3测试作品'));

$s = req('GET', "$BASE/square?q=" . urlencode('P3测试作品'));
check('关键词搜索命中', str_contains($s['body'], 'P3测试作品'));

$s = req('GET', "$BASE/square?q=" . urlencode('不存在的关键词xyz'));
check('无关关键词不命中', !str_contains($s['body'], 'P3测试作品'));

$s = req('GET', "$BASE/square?tag=" . urlencode('悬疑'));
check('标签筛选命中', str_contains($s['body'], 'P3测试作品'));

$s = req('GET', "$BASE/square?tag=" . urlencode('不存在的标签'));
check('无关标签不命中', !str_contains($s['body'], 'P3测试作品'));

$s = req('GET', "$BASE/square?sort=hot");
check('最热排序页 200', $s['code'] === 200);

$s = req('GET', "$BASE/square?sort=badvalue");
check('非法排序值被兜底', $s['code'] === 200 && str_contains($s['body'], 'sort-link is-active'), "HTTP {$s['code']} body=" . substr(strip_tags($s['body']), 0, 300));

// ---------- 8. 嵌入页 ----------
echo "\n[8] iframe 嵌入页\n";
$e = req('GET', "$BASE/embed/$code");
check('嵌入页 200', $e['code'] === 200, "HTTP {$e['code']}");
check('嵌入页注入 embed 标记', str_contains($e['body'], '"embed":true') || str_contains($e['body'], '"embed": true'));
check('嵌入页注入 workId', str_contains($e['body'], '"workId"'));
check('嵌入页无存档按钮', !str_contains($e['body'], 'id="btnSave"'));
check('嵌入页含舞台', str_contains($e['body'], 'id="stage"'));

$e404 = req('GET', "$BASE/embed/zzzzzzzz");
check('不存在短链的嵌入页 404', $e404['code'] === 404, "HTTP {$e404['code']}");

// ---------- 9. 取消发布后不可访问 ----------
echo "\n[9] 取消发布后短链失效\n";
$r = req('POST', "$BASE/api/works/$workId/publish", ['public' => 0, '_token' => $token], true);
check('取消发布成功', !empty($r['json']['ok']), $r['body']);
$d = req('GET', "$BASE/w/$code");
check('取消发布后详情页 404', $d['code'] === 404, "HTTP {$d['code']}");

// ---------- 10. 主题 ----------
echo "\n[10] 主题切换\n";
$h = req('GET', "$BASE/");
check('首页引入 theme.css', str_contains($h['body'], 'theme.css'));
check('首页引入 theme.js', str_contains($h['body'], 'theme.js'));
check('首页含 FOUC 防护脚本', str_contains($h['body'], 'data-theme'));
check('首页含主题切换按钮', str_contains($h['body'], 'id="btnTheme"'));

$p = req('GET', "$BASE/player.php");
check('播放器引入 theme.css', str_contains($p['body'], 'theme.css'));
check('播放器设置面板含主题项', str_contains($p['body'], 'themeSelect') || str_contains($p['body'], '主题'));

$sq = req('GET', "$BASE/square");
check('广场页引入 theme.js', str_contains($sq['body'], 'theme.js'));
check('广场页含主题切换按钮', str_contains($sq['body'], 'id="themeToggle"'));

$ed = req('GET', "$BASE/editor.php");
check('编辑器引入 theme.js', str_contains($ed['body'], 'theme.js'));
check('编辑器含主题切换按钮', str_contains($ed['body'], 'id="btnTheme"'));

echo "\n=== 结果：$pass 通过 / $fail 失败 ===\n";
exit($fail > 0 ? 1 : 0);
