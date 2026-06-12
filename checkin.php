<?php
/**
 * 紫金山自动打卡 - GitHub Actions 版本 v2
 * 对齐 Python v9 脚本的所有 API 和签名算法
 *
 * 域名：api.zjsnews.cn + total.zjsnews.cn
 * 登录：GET /user/loginByPwd（MD5(password) + MD5签名）
 * 签名：sign = MD5(按key排序的所有参数值直接拼接)
 * 成功码：code == 1
 *
 * 日期：2026-06-12
 */

// ==================== 常量 ====================
const APPID      = '21CA6ECAD76C3FD124';
const DEVICE_ID  = '744C916D-3E1C-431C-B5C6-B2171C19E66B';
const API_BASE   = 'https://api.zjsnews.cn';
const TOTAL_BASE = 'https://total.zjsnews.cn';
const ARTICLE_COUNT = 5;

const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) '
    . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ZjsNews/9.0.6';
const UA_OKHTTP = 'okhttp/4.9.3';

// ==================== 签名算法 ====================

/**
 * sign = MD5(按key字典序排序后，所有参数值直接拼接)
 * 与 Python v9 的 make_sign() 完全一致
 */
function makeSign(array $params): string {
    ksort($params);
    $valuesStr = implode('', array_map('strval', array_values($params)));
    return md5($valuesStr);
}

/**
 * 给参数追加 sign，构建带签名的 GET URL
 */
function buildSignedUrl(string $baseUrl, array $params): string {
    $params['sign'] = makeSign($params);
    $qs = http_build_query($params);
    return $baseUrl . '?' . $qs;
}

/**
 * 给 POST body 追加 sign
 */
function buildSignedPostData(array $data): array {
    $data['sign'] = makeSign($data);
    return $data;
}

// ==================== HTTP 工具 ====================

function curlGet(string $url, array $headers = [], int $timeout = 15): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        echo "    ❌ GET 请求失败: {$err}\n";
        return null;
    }
    return $resp;
}

function curlPostJson(string $url, array $body, array $headers = [], int $timeout = 15): ?string {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $defaultHeaders = ['Content-Type: application/json'];
    $headers = array_merge($defaultHeaders, $headers);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        echo "    ❌ POST 请求失败: {$err}\n";
        return null;
    }
    return $resp;
}

// ==================== API 封装 ====================

/**
 * 通用 GET 请求（带签名 + token header）
 */
function apiGet(string $path, array $params, string $token, string $desc = ''): ?array {
    $url = buildSignedUrl(API_BASE . $path, $params);
    $headers = ["token: {$token}", "User-Agent: " . UA_OKHTTP];
    $resp = curlGet($url, $headers);
    if ($resp === null) return null;

    $data = json_decode($resp, true);
    if ($data === null) {
        echo "    ❌ {$desc}: JSON 解析失败\n";
        return null;
    }
    $code = $data['code'] ?? -1;
    $msg  = $data['msg'] ?? '';
    if ($code == 1) {
        echo "    ✅ {$desc}: {$msg}\n";
    } elseif ($code == 505) {
        echo "    🔒 {$desc}: {$msg}（token 过期）\n";
    } else {
        echo "    ⚠️  {$desc}: code={$code} msg={$msg}\n";
    }
    return $data;
}

/**
 * 通用 POST 请求（JSON body + 签名 + token header）
 */
function apiPost(string $path, array $body, string $token, string $desc = ''): ?array {
    $signedBody = buildSignedPostData($body);
    $headers = ["token: {$token}", "User-Agent: " . UA_OKHTTP];
    $resp = curlPostJson(API_BASE . $path, $signedBody, $headers);
    if ($resp === null) return null;

    $data = json_decode($resp, true);
    if ($data === null) {
        echo "    ❌ {$desc}: JSON 解析失败\n";
        return null;
    }
    $code = $data['code'] ?? -1;
    $msg  = $data['msg'] ?? '';
    if ($code == 1) {
        echo "    ✅ {$desc}: {$msg}\n";
    } elseif ($code == 505) {
        echo "    🔒 {$desc}: {$msg}（token 过期）\n";
    } else {
        echo "    ⚠️  {$desc}: code={$code} msg={$msg}\n";
    }
    return $data;
}

/**
 * POST 到 total.zjsnews.cn（埋点，无签名，JSON body）
 */
function apiPostTotal(string $path, array $body, string $token, string $desc = ''): ?array {
    $headers = ["token: {$token}", "User-Agent: " . UA_OKHTTP];
    $resp = curlPostJson(TOTAL_BASE . $path, $body, $headers);
    if ($resp === null) return null;

    $data = json_decode($resp, true);
    if ($data === null) return null;
    echo "    ✅ {$desc}\n";
    return $data;
}

// ==================== 业务逻辑 ====================

/**
 * 密码登录：GET /user/loginByPwd（pwd_hash 版本，跳过内部 md5）
 * pwd_hash 已经是 md5(password)，直接传给紫金山接口
 */
function zjsLoginWithHash(string $mobile, string $pwdHash): array {
    $params = [
        'appid'  => APPID,
        'mobile' => $mobile,
        'pwd'    => $pwdHash,  // 已经是 MD5，不再二次 md5
    ];
    $url = buildSignedUrl(API_BASE . '/user/loginByPwd', $params);
    $headers = ['User-Agent: ' . UA_OKHTTP];
    $resp = curlGet($url, $headers);

    if ($resp === null) {
        return ['success' => false, 'msg' => '请求失败'];
    }
    $json = json_decode($resp, true);
    if ($json === null) {
        return ['success' => false, 'msg' => 'JSON解析失败'];
    }
    if (($json['code'] ?? 0) != 1 || empty($json['data']['token'])) {
        return ['success' => false, 'msg' => $json['msg'] ?? '登录失败'];
    }

    $token = $json['data']['token'];
    $userId = '';

    // 获取 user_id
    $userInfo = zjsGetUserId($token);
    if ($userInfo) {
        $userId = $userInfo;
    }

    return [
        'success' => true,
        'token'   => $token,
        'user_id' => $userId,
    ];
}

/**
 * 密码登录：GET /user/loginByPwd
 */
function zjsLogin(string $mobile, string $password): array {
    $pwdMd5 = md5($password);
    $params = [
        'appid'  => APPID,
        'mobile' => $mobile,
        'pwd'    => $pwdMd5,
    ];
    $url = buildSignedUrl(API_BASE . '/user/loginByPwd', $params);
    $headers = ['User-Agent: ' . UA_OKHTTP];
    $resp = curlGet($url, $headers);

    if ($resp === null) {
        return ['success' => false, 'msg' => '请求失败'];
    }
    $json = json_decode($resp, true);
    if ($json === null) {
        return ['success' => false, 'msg' => 'JSON解析失败'];
    }
    if (($json['code'] ?? 0) != 1 || empty($json['data']['token'])) {
        return ['success' => false, 'msg' => $json['msg'] ?? '登录失败'];
    }

    $token = $json['data']['token'];
    $userId = '';

    // 获取 user_id
    $userInfo = zjsGetUserId($token);
    if ($userInfo) {
        $userId = $userInfo;
    }

    return [
        'success' => true,
        'token'   => $token,
        'user_id' => $userId,
    ];
}

/**
 * 获取用户信息（user_id）
 */
function zjsGetUserId(string $token): ?string {
    $params = [
        'appid'     => APPID,
        'timestamp' => strval(time()),
        'token'     => $token,
    ];
    $url = buildSignedUrl(API_BASE . '/user/getUserInfo', $params);
    $headers = ["token: {$token}", "User-Agent: " . UA_OKHTTP];
    $resp = curlGet($url, $headers);

    if ($resp === null) return null;
    $json = json_decode($resp, true);
    if (($json['code'] ?? 0) == 1 && !empty($json['data']['user_id'])) {
        return strval($json['data']['user_id']);
    }
    return null;
}

/**
 * 获取文章列表
 */
function zjsFetchArticles(string $token, string $userId, int $count = 5): array {
    $params = [
        'appid'          => APPID,
        'channel_id'     => '2',
        'currentVersion' => '9.0.6',
        'deviceId'       => DEVICE_ID,
        'equipmentType'  => 'iPhone16,1',
        'pageNumber'     => '1',
        'pageSize'       => strval($count + 10),
        'screenSize'     => '1125*2436',
        'timestamp'      => strval(time()),
        'token'          => $token,
        'user_id'        => $userId,
    ];
    $url = buildSignedUrl(API_BASE . '/news/listHomeNewsAndLayouts', $params);
    $headers = ["token: {$token}", "User-Agent: " . UA_OKHTTP];
    $resp = curlGet($url, $headers, 20);

    if ($resp === null) return [];
    $json = json_decode($resp, true);
    if (($json['code'] ?? 0) != 1) return [];

    $pageData = $json['data']['pageData'] ?? [];
    $ids = [];
    foreach ($pageData as $page) {
        if (!is_array($page)) continue;
        if (isset($page['news_id'])) {
            $ids[] = strval($page['news_id']);
        } elseif (isset($page['news_list']) && is_array($page['news_list'])) {
            foreach ($page['news_list'] as $item) {
                if (is_array($item) && isset($item['news_id'])) {
                    $ids[] = strval($item['news_id']);
                }
            }
        }
        if (count($ids) >= $count) break;
    }
    return array_slice($ids, 0, $count);
}

/**
 * 每日签到
 */
function zjsSign(string $token, string $userId, string $mobile): array {
    $timestamp = strval(time());
    $result = ['sign' => false, 'task' => false, 'sign_msg' => ''];

    // 签到
    $params = [
        'appid'     => APPID,
        'timestamp' => $timestamp,
        'token'     => $token,
        'user_id'   => $userId,
    ];
    $resp = apiGet('/integral/sign', $params, $token, '签到');
    if ($resp && ($resp['code'] ?? 0) == 1) {
        $result['sign'] = true;
        $signData = $resp['data'] ?? [];
        $result['sign_msg'] = sprintf(
            'signStatus=%s, integral=%s',
            $signData['signStatus'] ?? '?',
            $signData['integral'] ?? '?'
        );
    }

    // task/record type=4（签到任务记录）
    $taskBody = [
        'mobile'    => $mobile,
        'appid'     => APPID,
        'user_id'   => $userId,
        'type'      => 4,
        'timestamp' => $timestamp,
        'token'     => $token,
    ];
    $taskResp = apiPost('/task/record', $taskBody, $token, '签到任务记录');
    $result['task'] = ($taskResp && ($taskResp['code'] ?? 0) == 1);

    return $result;
}

/**
 * 阅读一篇文章的全套操作
 */
function zjsReadArticle(string $newsId, int $index, int $total, string $token, string $userId, string $mobile): array {
    echo "\n  [{$index}/{$total}] 📄 文章 {$newsId}\n";

    $result = [
        'news_id'  => $newsId,
        'read'     => false,
        'integral' => false,
        'favorite' => false,
        'share_wx' => false,
        'share_pyq' => false,
        'like'     => false,
        'task_read' => false,
        'task_like' => false,
    ];

    $startTs = time();
    // GitHub Actions 中不实际等待，直接用时间戳
    $readDuration = rand(15, 35);
    $endTs = $startTs + $readDuration;
    echo "    ⏱️  模拟阅读 {$readDuration}s\n";

    $timestamp = strval(time());

    // 1. 阅读埋点 → total.zjsnews.cn
    $trackBody = [
        'system'         => 'iOS18.5',
        'userId'         => $userId,
        'type'           => '0',
        'newsId'         => $newsId,
        'startPageTime'  => strval($startTs),
        'endPageTime'    => strval($endTs),
        'startPlaytime'  => '0',
        'endPlaytime'    => '0',
    ];
    $trackResp = apiPostTotal('/cc/user/addUserAction', $trackBody, $token, '阅读埋点');
    $result['read'] = ($trackResp !== null);

    // 2. 阅读积分 (type=3)
    $params = [
        'news_id'   => $newsId,
        'type'      => '3',
        'user_id'   => $userId,
        'timestamp' => $timestamp,
        'token'     => $token,
        'appid'     => APPID,
    ];
    $intResp = apiGet('/news/saveUserIntegral', $params, $token, '阅读积分(type=3)');
    $result['integral'] = ($intResp && ($intResp['code'] ?? 0) == 1);

    // 3. 收藏
    $params = [
        'news_id'   => $newsId,
        'timestamp' => $timestamp,
        'token'     => $token,
        'user_id'   => $userId,
        'appid'     => APPID,
    ];
    $favResp = apiGet('/user/saveUserLike', $params, $token, '收藏文章');
    $result['favorite'] = ($favResp && ($favResp['code'] ?? 0) == 1);

    // 4. 分享 x2 (type=0 微信, type=1 朋友圈)
    foreach (['0', '1'] as $shareType) {
        $params = [
            'news_id'   => $newsId,
            'device_id' => DEVICE_ID,
            'type'      => $shareType,
            'timestamp' => $timestamp,
            'token'     => $token,
            'user_id'   => $userId,
            'appid'     => APPID,
        ];
        $shareResp = apiGet('/news/saveShareNews', $params, $token, "分享(type={$shareType})");
        $ok = ($shareResp && ($shareResp['code'] ?? 0) == 1);
        if ($shareType == '0') $result['share_wx'] = $ok;
        else $result['share_pyq'] = $ok;

        // task/record type=3（分享任务记录）
        $taskBody = [
            'mobile'    => $mobile,
            'newsId'    => $newsId,
            'user_id'   => $userId,
            'type'      => 3,
            'timestamp' => $timestamp,
            'appid'     => APPID,
            'token'     => $token,
        ];
        apiPost('/task/record', $taskBody, $token, '分享任务记录');
    }

    // 5. 点赞
    $params = [
        'news_id'         => $newsId,
        'user_id'         => $userId,
        'type'            => '1',
        'integralVersion' => '3.0',
        'timestamp'       => $timestamp,
        'token'           => $token,
        'appid'           => APPID,
    ];
    $likeResp = apiGet('/news/updateNewsOpLike', $params, $token, '文章点赞');
    $result['like'] = ($likeResp && ($likeResp['code'] ?? 0) == 1);

    // task/record type=2（点赞任务记录）
    $taskBody = [
        'mobile'    => $mobile,
        'newsId'    => $newsId,
        'user_id'   => $userId,
        'type'      => 2,
        'timestamp' => $timestamp,
        'appid'     => APPID,
        'token'     => $token,
    ];
    $taskResp = apiPost('/task/record', $taskBody, $token, '点赞任务记录');
    $result['task_like'] = ($taskResp && ($taskResp['code'] ?? 0) == 1);

    // 6. task/record type=1（阅读任务记录）
    $taskBody = [
        'mobile'    => $mobile,
        'newsId'    => $newsId,
        'user_id'   => $userId,
        'type'      => 1,
        'timestamp' => $timestamp,
        'appid'     => APPID,
        'token'     => $token,
    ];
    $taskResp = apiPost('/task/record', $taskBody, $token, '阅读任务记录');
    $result['task_read'] = ($taskResp && ($taskResp['code'] ?? 0) == 1);

    return $result;
}

// ==================== 主流程 ====================

// 从 GitHub API 读取 accounts_status.json（前端管理的数据源）
$githubToken = getenv('PAT_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '';
$githubOwner = 'Limerence726';
$githubRepo  = 'zjs-checkin';

$accounts = [];
$source = 'unknown';

if (!empty($githubToken)) {
    $apiUrl = "https://api.github.com/repos/{$githubOwner}/{$githubRepo}/contents/accounts_status.json";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "Authorization: token {$githubToken}",
            "User-Agent: ZJS-Checkin-Actions",
            "Accept: application/vnd.github.v3+json",
        ],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $httpCode === 200) {
        $json = json_decode($resp, true);
        if (isset($json['content'])) {
            $accounts = json_decode(base64_decode($json['content']), true) ?: [];
            $source = 'accounts_status.json (GitHub API)';
        }
    }
}

// Fallback: 从环境变量 ACCOUNTS_JSON 读取（兼容旧配置）
if (empty($accounts)) {
    $accountsJson = getenv('ACCOUNTS_JSON');
    if (!empty($accountsJson)) {
        $accounts = json_decode($accountsJson, true);
        $source = 'ACCOUNTS_JSON secret';
    } else {
        $accountsFile = __DIR__ . '/accounts.json';
        if (file_exists($accountsFile)) {
            $accounts = json_decode(file_get_contents($accountsFile), true);
            $source = 'accounts.json file';
        }
    }
}

if (empty($accounts) || !is_array($accounts)) {
    die("错误：账号列表为空。请在前端添加账号并开启自动打卡。\n");
}

// 只执行 enabled 的账号
$enabledAccounts = array_filter($accounts, function($a) {
    return !empty($a['enabled']);
});

if (empty($enabledAccounts)) {
    die("提示：没有已开启自动打卡的账号。请在前端开启。\n");
}

echo "═══════════════════════════════════════════════════\n";
echo "  紫金山新闻自动打卡 v3（api.zjsnews.cn）\n";
echo "  数据源: {$source}\n";
echo "  总账号: " . count($accounts) . " | 已启用: " . count($enabledAccounts) . "\n";
echo "  时间: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

$allResults = [];
$currentDate = date('Y-m-d');
$currentTime = date('Y-m-d H:i:s');

foreach ($enabledAccounts as $index => $acc) {
    $phone    = $acc['phone'] ?? '';
    $pwdHash  = $acc['pwd_hash'] ?? '';  // MD5(password)，由前端 toggle 时存入
    $password = $acc['password'] ?? '';  // 兼容旧格式 ACCOUNTS_JSON

    if (empty($phone) || (empty($pwdHash) && empty($password))) {
        echo "账号 #{$index} 信息不完整，跳过\n";
        continue;
    }

    $phoneMasked = substr($phone, 0, 3) . '****' . substr($phone, -4);
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📱 账号: {$phoneMasked}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $accountResult = [
        'phone'       => $phone,
        'time'        => $currentTime,
        'login'       => false,
        'sign'        => false,
        'articles'    => [],
        'error'       => null,
    ];

    // 1. 登录
    // pwd_hash 是 MD5(password)，zjsLogin 内部会再做 md5($password)
    // 所以如果有 pwd_hash，需要用它作为 password 参数（zjsLogin 内部 md5(pwd_hash) = md5(md5(password))）
    // 但实际上紫金山登录接口的 pwd 参数就是 md5(password)，zjsLogin 内部不应该再 md5
    // 所以直接把 pwd_hash 传给 zjsLogin，但需要跳过 zjsLogin 内部的 md5
    echo "\n🔑 登录中...\n";
    if (!empty($pwdHash)) {
        // pwd_hash = md5(password)，直接作为 pwd 参数，跳过 zjsLogin 的内部 md5
        $loginResult = zjsLoginWithHash($phone, $pwdHash);
    } else {
        $loginResult = zjsLogin($phone, $password);
    }
    if (!$loginResult['success']) {
        $accountResult['error'] = '登录失败: ' . ($loginResult['msg'] ?? '未知错误');
        echo "  ❌ " . $accountResult['error'] . "\n";
        $allResults[] = $accountResult;
        continue;
    }
    $accountResult['login'] = true;
    $token  = $loginResult['token'];
    $userId = $loginResult['user_id'];
    echo "  ✅ 登录成功 userId=" . substr($userId, 0, 4) . "****\n";

    // 2. 签到
    echo "\n📋 每日签到\n";
    $signResult = zjsSign($token, $userId, $phone);
    $accountResult['sign'] = $signResult['sign'];
    $accountResult['sign_msg'] = $signResult['sign_msg'] ?? '';

    // 3. 获取文章列表
    echo "\n📰 获取文章列表...\n";
    $articleIds = zjsFetchArticles($token, $userId, ARTICLE_COUNT);
    if (empty($articleIds)) {
        echo "  ⚠️  无法获取文章列表，跳过阅读环节\n";
    } else {
        echo "  📋 " . count($articleIds) . " 篇文章\n";

        // 4. 阅读全套
        echo "\n📌 阅读 + 积分 + 收藏 + 分享 + 点赞 + 任务记录\n";
        foreach ($articleIds as $i => $newsId) {
            $readResult = zjsReadArticle($newsId, $i + 1, count($articleIds), $token, $userId, $phone);
            $accountResult['articles'][] = $readResult;
            // 简单间隔避免过快
            if ($i < count($articleIds) - 1) {
                usleep(rand(500000, 1500000)); // 0.5-1.5s
            }
        }
    }

    $allResults[] = $accountResult;
    echo "\n";
}

// ==================== 保存结果 ====================

$outputData = [
    'date'    => $currentDate,
    'time'    => $currentTime,
    'version' => 'v2',
    'api_base' => API_BASE,
    'success' => true,
    'count'   => count($allResults),
    'results' => $allResults,
];

$outputFile = __DIR__ . '/checkin_result.json';
file_put_contents($outputFile, json_encode($outputData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "═══════════════════════════════════════════════════\n";
echo "📊 打卡完成\n";
echo "  账号数: " . count($allResults) . "\n";
echo "  结果文件: checkin_result.json\n";
echo "═══════════════════════════════════════════════════\n";

