<?php
/**
 * P2 端到端验证脚本（HTTP 层）
 *
 * 覆盖：注册/登录 → 保存作品 → 发布 → 广场列表 → 公开接口 → 版本快照 → 恢复
 *      → 取消发布 → 公开接口 404 → 注销账号
 */

declare(strict_types=1);

const BASE = 'http://127.0.0.1:8000';
const JAR  = __DIR__ . '/p2_cookie.txt';

$pass = 0;
$fail = 0;

function req(string $method, string $path, array $data = [], bool $json = false, bool $follow = true): array
{
    $ch = curl_init(BASE . $path);
    $headers = ['Accept: application/json'];
    if ($json) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR      => JAR,
        CURLOPT_COOKIEFILE     => JAR,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($data) : http_build_query($data));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    return ['code' => $code, 'head' => $head, 'body' => $body];
}

function csrfFrom(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/"csrfToken"\s*:\s*"([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($extra !== '' ? " -> $extra" : '') . "\n";
    }
}

function jbody(array $r): array
{
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : [];
}

@unlink(JAR);

$email = 'p2_' . time() . '@example.com';
$passwd = 'Passw0rd!234';

echo "== 1. 注册 ==\n";
$r = req('GET', '/register');
$token = csrfFrom($r['body']);
check('注册页可访问', $r['code'] === 200 && $token !== '');
$r = req('POST', '/register', ['_token' => $token, 'email' => $email, 'password' => $passwd, 'password_confirm' => $passwd, 'nickname' => 'P2测试']);
check('注册成功（跳转）', $r['code'] === 200, 'code=' . $r['code']);

echo "== 2. 登录 ==\n";
// 注册后已自动登录，先登出再验证登录流程
$r = req('GET', '/works');
$token = csrfFrom($r['body']);
$r = req('POST', '/logout', ['_token' => $token]);
check('登出成功', $r['code'] === 200, 'code=' . $r['code']);

$r = req('GET', '/login');
$token = csrfFrom($r['body']);
check('登录页可访问且拿到 CSRF', $r['code'] === 200 && $token !== '');
$r = req('POST', '/login', ['_token' => $token, 'email' => $email, 'password' => $passwd]);
if ($r['code'] !== 200) {
    echo "  [DEBUG] login code={$r['code']} token=" . substr($token, 0, 12) . "...\n";
    echo "  [DEBUG] body=" . substr(strip_tags($r['body']), 0, 300) . "\n";
}
check('登录成功', $r['code'] === 200, 'code=' . $r['code']);

// 登录会轮换会话 ID 与 CSRF 令牌，后续请求需重新取令牌（与浏览器刷新页面一致）
$r = req('GET', '/works');
$token = csrfFrom($r['body']);
check('登录后拿到新 CSRF 令牌', $token !== '');

echo "== 3. 保存作品 ==\n";
$r = req('GET', '/editor');
$token = csrfFrom($r['body']);
check('编辑器可访问且拿到 CSRF', $token !== '');
$workData = json_encode([
    'manifest' => ['title' => 'P2 测试作品', 'start' => 'scene_001'],
    'scenes'   => [[
        'id' => 'scene_001', 'name' => '开场',
        'nodes' => [
            ['id' => 'n1', 'type' => 'say', 'speaker' => '旁白', 'text' => '这是一个测试故事。'],
            ['id' => 'n2', 'type' => 'choose', 'options' => [['text' => '继续', 'target' => 'scene_001']]],
        ],
    ]],
], JSON_UNESCAPED_UNICODE);
$r = req('POST', '/api/works/save', ['_token' => $token, 'title' => 'P2 测试作品', 'description' => 'P2 端到端测试', 'data' => $workData]);
$j = jbody($r);
check('保存作品成功', ($j['ok'] ?? false) === true, $r['body']);
$workId = (int) ($j['id'] ?? 0);
check('返回作品 ID', $workId > 0);

echo "== 4. 发布作品 ==\n";
$r = req('POST', "/api/works/$workId/publish", ['_token' => $token, 'public' => 1]);
$j = jbody($r);
check('发布成功', ($j['ok'] ?? false) === true && ($j['is_public'] ?? 0) === 1, $r['body']);

echo "== 5. 作品广场 ==\n";
$r = req('GET', '/square');
check('广场页可访问', $r['code'] === 200);
check('广场页含作品标题', str_contains($r['body'], 'P2 测试作品'));
check('广场页含作者昵称', str_contains($r['body'], 'P2测试'));

echo "== 6. 公开接口（未登录） ==\n";
$anonJar = __DIR__ . '/p2_anon.txt';
@unlink($anonJar);
$ch = curl_init(BASE . "/api/works/$workId/public");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = json_decode($body, true) ?: [];
check('匿名可读公开作品', $code === 200 && ($j['ok'] ?? false) === true, "code=$code body=$body");
check('公开作品含 data', isset($j['work']['data']['scenes']));

echo "== 7. 版本快照 ==\n";
$workData2 = json_encode([
    'manifest' => ['title' => 'P2 测试作品', 'start' => 'scene_001'],
    'scenes'   => [[
        'id' => 'scene_001', 'name' => '开场',
        'nodes' => [['id' => 'n1', 'type' => 'say', 'speaker' => '旁白', 'text' => '修改后的文本。']],
    ]],
], JSON_UNESCAPED_UNICODE);
$r = req('POST', '/api/works/save', ['_token' => $token, 'id' => $workId, 'title' => 'P2 测试作品', 'data' => $workData2]);
check('二次保存成功', (jbody($r)['ok'] ?? false) === true, $r['body']);

$r = req('GET', "/api/works/$workId/revisions");
$j = jbody($r);
$revs = $j['revisions'] ?? [];
check('存在历史快照', count($revs) >= 1, $r['body']);
$revId = (int) ($revs[0]['id'] ?? 0);

echo "== 8. 恢复历史版本 ==\n";
$r = req('POST', "/api/works/$workId/revisions/$revId/restore", ['_token' => $token]);
$j = jbody($r);
check('恢复成功', ($j['ok'] ?? false) === true, $r['body']);
check('恢复后为旧文本', str_contains(json_encode($j['data'] ?? [], JSON_UNESCAPED_UNICODE), '这是一个测试故事'));

echo "== 9. 取消发布 ==\n";
$r = req('POST', "/api/works/$workId/publish", ['_token' => $token, 'public' => 0]);
$j = jbody($r);
check('取消发布成功', ($j['ok'] ?? false) === true && ($j['is_public'] ?? 1) === 0, $r['body']);

$ch = curl_init(BASE . "/api/works/$workId/public");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('取消发布后公开接口 404', $code === 404, "code=$code");

echo "== 10. 越权防护 ==\n";
$r = req('GET', '/api/works/999999');
check('他人/不存在作品返回 404', $r['code'] === 404, 'code=' . $r['code']);

echo "== 11. 注销账号 ==\n";
$r = req('GET', '/profile');
$token = csrfFrom($r['body']);
check('个人中心可访问', $r['code'] === 200);
$r = req('POST', '/profile/delete', ['_token' => $token, 'password' => 'wrong-password']);
check('错误密码无法注销', $r['code'] === 200 && str_contains($r['body'], '密码不正确'), 'code=' . $r['code']);

$r = req('GET', '/profile');
$token = csrfFrom($r['body']);
$r = req('POST', '/profile/delete', ['_token' => $token, 'password' => $passwd]);
check('正确密码注销成功', $r['code'] === 200, 'code=' . $r['code']);
$r = req('GET', '/works');
check('注销后访问作品页被重定向到登录', str_contains($r['body'], '登录') || str_contains($r['head'], 'Location:'), 'code=' . $r['code']);

echo "\n===== 结果：$pass 通过 / $fail 失败 =====\n";
@unlink(JAR);
exit($fail === 0 ? 0 : 1);
