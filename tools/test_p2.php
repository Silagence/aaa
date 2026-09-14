<?php
/**
 * P2 端到端接口测试
 *
 * 覆盖：注册/登录 → 保存作品 → 发布/取消发布 → 版本快照列表 → 恢复历史版本
 *      → 播放器读取云端作品 → 注销账号
 *
 * 用法：php tools/test_p2.php
 */

declare(strict_types=1);

$base = 'http://127.0.0.1:8000';
$jar = sys_get_temp_dir() . '/dramatool_p2_cookie.txt';
@unlink($jar);

$pass = 0;
$fail = 0;

/**
 * 发起请求，返回 [httpCode, body, headers]
 */
function req(string $method, string $url, array $data = [], bool $json = false): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        return [0, 'CURL ERROR: ' . $err, []];
    }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headers = substr($raw, 0, $size);
    $body = substr($raw, $size);
    return [$code, $body, $headers];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($detail !== '' ? " -> $detail" : '') . "\n";
    }
}

/**
 * 从 HTML 中提取 CSRF token
 */
function csrfFrom(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/"csrfToken"\s*:\s*"([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

echo "=== P2 端到端测试 ===\n\n";

// ---------- 1. 注册 ----------
echo "[1] 注册新用户\n";
$email = 'p2test_' . time() . '@example.com';
$password = 'Passw0rd!2026';

[$code, $html] = req('GET', "$base/register");
$token = csrfFrom($html);
check('注册页可访问', $code === 200, "HTTP $code");
check('注册页含 CSRF token', $token !== '');

[$code, $body] = req('POST', "$base/register", [
    'email'    => $email,
    'password' => $password,
    'nickname' => 'P2测试员',
    '_token'   => $token,
]);
check('注册成功（302 跳转）', $code === 302, "HTTP $code");

// ---------- 2. 登录 ----------
echo "\n[2] 登录\n";
[$code, $html] = req('GET', "$base/login");
$token = csrfFrom($html);
check('登录页可访问', $code === 200, "HTTP $code");

[$code, $body] = req('POST', "$base/login", [
    'email'    => $email,
    'password' => $password,
    '_token'   => $token,
]);
check('登录成功（302 跳转）', $code === 302, "HTTP $code");

// ---------- 3. 作品列表页 ----------
echo "\n[3] 作品列表页\n";
[$code, $html] = req('GET', "$base/works");
check('作品列表可访问', $code === 200, "HTTP $code");
check('列表页含发布按钮', strpos($html, 'js-publish') !== false);
check('列表页含播放入口', strpos($html, 'player?work=') !== false);
$token = csrfFrom($html);
check('列表页含 CSRF token', $token !== '');

// ---------- 4. 保存作品（新建） ----------
echo "\n[4] 保存作品到云端\n";
$workData = json_encode([
    'manifest' => ['name' => 'P2测试作品', 'startScene' => 'scene_001', 'canvas' => ['width' => 1280, 'height' => 720]],
    'assets'   => [],
    'scenes'   => [
        ['id' => 'scene_001', 'nodes' => [
            ['type' => 'say', 'speaker' => '旁白', 'text' => '第一版内容'],
        ]],
    ],
], JSON_UNESCAPED_UNICODE);

[$code, $body] = req('POST', "$base/api/works/save", [
    'id'    => 0,
    'title' => 'P2测试作品',
    'data'  => $workData,
    '_token' => $token,
]);
$res = json_decode($body, true);
check('保存返回 200', $code === 200, "HTTP $code / $body");
check('保存成功', !empty($res['ok']), $body);
$workId = (int) ($res['id'] ?? 0);
check('返回作品 ID', $workId > 0, "id=$workId");

// ---------- 5. 发布 / 取消发布 ----------
echo "\n[5] 发布 / 取消发布\n";
[$code, $body] = req('POST', "$base/api/works/$workId/publish", [
    'public' => 1,
    '_token' => $token,
]);
$res = json_decode($body, true);
check('发布成功', $code === 200 && !empty($res['ok']), $body);
check('发布状态为 1', (int) ($res['is_public'] ?? -1) === 1, $body);

[$code, $body] = req('POST', "$base/api/works/$workId/publish", [
    'public' => 0,
    '_token' => $token,
]);
$res = json_decode($body, true);
check('取消发布成功', $code === 200 && !empty($res['ok']), $body);
check('发布状态为 0', (int) ($res['is_public'] ?? -1) === 0, $body);

// ---------- 6. 二次保存生成快照 ----------
echo "\n[6] 二次保存生成版本快照\n";
$workData2 = json_encode([
    'manifest' => ['name' => 'P2测试作品', 'startScene' => 'scene_001', 'canvas' => ['width' => 1280, 'height' => 720]],
    'assets'   => [],
    'scenes'   => [
        ['id' => 'scene_001', 'nodes' => [
            ['type' => 'say', 'speaker' => '旁白', 'text' => '第二版内容'],
        ]],
    ],
], JSON_UNESCAPED_UNICODE);

[$code, $body] = req('POST', "$base/api/works/save", [
    'id'    => $workId,
    'title' => 'P2测试作品',
    'data'  => $workData2,
    '_token' => $token,
]);
$res = json_decode($body, true);
check('二次保存成功', $code === 200 && !empty($res['ok']), $body);

// ---------- 7. 历史版本列表 ----------
echo "\n[7] 历史版本列表\n";
[$code, $body] = req('GET', "$base/api/works/$workId/revisions");
$res = json_decode($body, true);
check('列表接口返回 200', $code === 200, "HTTP $code / $body");
check('列表返回 ok', !empty($res['ok']), $body);
$revisions = $res['revisions'] ?? [];
check('至少 1 条快照', count($revisions) >= 1, 'count=' . count($revisions));
$revId = (int) ($revisions[0]['id'] ?? 0);
check('快照含 id 与时间', $revId > 0 && !empty($revisions[0]['created_at']), $body);

// ---------- 8. 恢复历史版本 ----------
echo "\n[8] 恢复历史版本\n";
[$code, $body] = req('POST', "$base/api/works/$workId/revisions/$revId/restore", [
    '_token' => $token,
]);
$res = json_decode($body, true);
check('恢复接口返回 200', $code === 200, "HTTP $code / $body");
check('恢复成功', !empty($res['ok']), $body);
$restoredText = $res['data']['scenes'][0]['nodes'][0]['text'] ?? '';
check('恢复内容为第一版', $restoredText === '第一版内容', "text=$restoredText");

// ---------- 9. 播放器读取云端作品 ----------
echo "\n[9] 播放器读取云端作品\n";
[$code, $body] = req('GET', "$base/api/works/$workId");
$res = json_decode($body, true);
check('作品详情接口正常', $code === 200 && !empty($res['ok']), $body);
check('详情含 data', !empty($res['work']['data']), $body);

[$code, $html] = req('GET', "$base/player?work=$workId");
check('播放器页可访问', $code === 200, "HTTP $code");
check('播放器注入登录态', strpos($html, '"user"') !== false);

// ---------- 10. 越权防护 ----------
echo "\n[10] 越权防护\n";
[$code, $body] = req('GET', "$base/api/works/999999");
$res = json_decode($body, true);
check('访问他人/不存在作品返回 404', $code === 404, "HTTP $code / $body");

// ---------- 11. 个人中心与注销入口 ----------
echo "\n[11] 个人中心\n";
[$code, $html] = req('GET', "$base/profile");
check('个人中心可访问', $code === 200, "HTTP $code");
check('含注销账号区块', strpos($html, 'profile/delete') !== false);
$token = csrfFrom($html);

// ---------- 12. 注销账号 ----------
echo "\n[12] 注销账号\n";
[$code, $body] = req('POST', "$base/profile/delete", [
    'password' => 'wrong-password',
    '_token'   => $token,
]);
check('密码错误时拒绝注销', $code === 302, "HTTP $code");

[$code, $body] = req('POST', "$base/profile/delete", [
    'password' => $password,
    '_token'   => $token,
]);
check('密码正确时注销成功', $code === 302, "HTTP $code");

[$code, $html] = req('GET', "$base/works");
check('注销后无法访问作品页', $code === 302, "HTTP $code");

// ---------- 13. 注销后无法登录 ----------
echo "\n[13] 注销后登录校验\n";
[$code, $html] = req('GET', "$base/login");
$token = csrfFrom($html);
[$code, $body] = req('POST', "$base/login", [
    'email'    => $email,
    'password' => $password,
    '_token'   => $token,
]);
check('注销账号无法登录（302 回登录页）', $code === 302, "HTTP $code");

echo "\n=== 结果：$pass 通过 / $fail 失败 ===\n";
@unlink($jar);
exit($fail > 0 ? 1 : 0);
