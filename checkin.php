<?php
/**
 * 紫金山自动打卡 - GitHub Actions 版本
 * 读取 accounts.json，执行打卡，结果写入 checkin_result.json
 */

// 读取账号配置
$accountsFile = __DIR__ . '/accounts.json';
if (!file_exists($accountsFile)) {
    die("错误：accounts.json 不存在\n");
}

$accounts = json_decode(file_get_contents($accountsFile), true);
if (empty($accounts) || !is_array($accounts)) {
    die("错误：账号列表为空或格式错误\n");
}

echo "开始打卡，共 " . count($accounts) . " 个账号\n\n";

$results = [];
$currentDate = date('Y-m-d');
$currentTime = date('Y-m-d H:i:s');

foreach ($accounts as $index => $acc) {
    $phone = $acc['phone'] ?? '';
    $password = $acc['password'] ?? '';
    
    if (empty($phone) || empty($password)) {
        echo "账号 #{$index} 信息不完整，跳过\n";
        continue;
    }
    
    echo "处理账号: " . substr($phone, 0, 3) . '****' . substr($phone, -4) . "\n";
    
    $result = [
        'phone'    => $phone,
        'time'     => $currentTime,
        'date'     => $currentDate,
        'login'    => false,
        'checkin'  => false,
        'read'     => false,
        'favorite' => false,
        'share'    => false,
        'like'     => false,
        'error'    => null
    ];
    
    // 1. 登录
    $loginResult = zjsLogin($phone, $password);
    if (!$loginResult['success']) {
        $result['error'] = '登录失败: ' . ($loginResult['msg'] ?? '未知错误');
        echo "  ✗ 登录失败: " . $result['error'] . "\n";
        $results[] = $result;
        continue;
    }
    
    $result['login'] = true;
    $token = $loginResult['token'];
    $userId = $loginResult['user_id'];
    echo "  ✓ 登录成功\n";
    
    // 2. 签到
    $checkinResult = zjsCheckin($token, $userId);
    $result['checkin'] = $checkinResult['success'];
    $result['checkin_msg'] = $checkinResult['msg'] ?? '';
    echo "  " . ($checkinResult['success'] ? '✓' : '✗') . " 签到: " . ($checkinResult['msg'] ?? '') . "\n";
    
    // 3. 阅读埋点
    $readResult = zjsRead($token, $userId, '123456');
    $result['read'] = $readResult['success'];
    echo "  " . ($readResult['success'] ? '✓' : '✗') . " 阅读埋点\n";
    
    // 4. 收藏
    $favResult = zjsFavorite($token, $userId, '123456');
    $result['favorite'] = $favResult['success'];
    echo "  " . ($favResult['success'] ? '✓' : '✗') . " 收藏\n";
    
    // 5. 分享
    $shareResult = zjsShare($token, $userId, '123456');
    $result['share'] = $shareResult['success'];
    echo "  " . ($shareResult['success'] ? '✓' : '✗') . " 分享\n";
    
    // 6. 点赞
    $likeResult = zjsLike($token, $userId, '123456');
    $result['like'] = $likeResult['success'];
    echo "  " . ($likeResult['success'] ? '✓' : '✗') . " 点赞\n";
    
    $results[] = $result;
    echo "\n";
}

// 保存结果
$outputFile = __DIR__ . '/checkin_result.json';
$outputData = [
    'date'    => $currentDate,
    'time'    => $currentTime,
    'success' => true,
    'count'   => count($results),
    'results' => $results
];

file_put_contents($outputFile, json_encode($outputData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "打卡完成，结果已保存到: checkin_result.json\n";

/**
 * 登录
 */
function zjsLogin($mobile, $password) {
    $url = 'https://zjsnews.zjsnews.cn/api/user/login';
    $data = [
        'mobile'   => $mobile,
        'password' => md5($password),
        'type'     => '1'
    ];
    
    $response = curlPost($url, $data);
    if ($response === false) {
        return ['success' => false, 'msg' => '请求失败'];
    }
    
    $json = json_decode($response, true);
    if (($json['code'] ?? 1) !== 0 || empty($json['data']['token'])) {
        return ['success' => false, 'msg' => $json['msg'] ?? '登录失败'];
    }
    
    return [
        'success' => true,
        'token'   => $json['data']['token'],
        'user_id' => strval($json['data']['user_id'])
    ];
}

/**
 * 签到
 */
function zjsCheckin($token, $userId) {
    $url = 'https://zjsnews.zjsnews.cn/api/signin/add';
    $sign = md5('token=' . $token . '&type=1&userid=' . $userId);
    $postData = 'token=' . urlencode($token) . '&type=1&userid=' . $userId . '&sign=' . $sign;
    
    $response = curlPostRaw($url, $postData, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response === false) {
        return ['success' => false, 'msg' => '请求失败'];
    }
    
    $json = json_decode($response, true);
    return [
        'success' => ($json['code'] ?? 1) === 0,
        'msg'      => $json['msg'] ?? ''
    ];
}

/**
 * 阅读埋点
 */
function zjsRead($token, $userId, $articleId) {
    $url = 'https://zjsnews.zjsnews.cn/api/article/read';
    $sign = md5('articleid=' . $articleId . '&channelid=1&token=' . $token . '&userid=' . $userId);
    $postData = 'articleid=' . $articleId . '&channelid=1&token=' . urlencode($token) . '&userid=' . $userId . '&sign=' . $sign;
    
    $response = curlPostRaw($url, $postData, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response === false) {
        return ['success' => false];
    }
    
    $json = json_decode($response, true);
    return ['success' => ($json['code'] ?? 1) === 0];
}

/**
 * 收藏
 */
function zjsFavorite($token, $userId, $articleId) {
    $url = 'https://zjsnews.zjsnews.cn/api/article/favorite';
    $sign = md5('articleid=' . $articleId . '&token=' . $token . '&userid=' . $userId);
    $postData = 'articleid=' . $articleId . '&token=' . urlencode($token) . '&userid=' . $userId . '&sign=' . $sign;
    
    $response = curlPostRaw($url, $postData, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response === false) {
        return ['success' => false];
    }
    
    $json = json_decode($response, true);
    return ['success' => ($json['code'] ?? 1) === 0];
}

/**
 * 分享
 */
function zjsShare($token, $userId, $articleId) {
    $url = 'https://zjsnews.zjsnews.cn/api/article/share';
    $sign = md5('articleid=' . $articleId . '&token=' . $token . '&userid=' . $userId);
    $postData = 'articleid=' . $articleId . '&token=' . urlencode($token) . '&userid=' . $userId . '&sign=' . $sign;
    
    $response = curlPostRaw($url, $postData, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response === false) {
        return ['success' => false];
    }
    
    $json = json_decode($response, true);
    return ['success' => ($json['code'] ?? 1) === 0];
}

/**
 * 点赞
 */
function zjsLike($token, $userId, $articleId) {
    $url = 'https://zjsnews.zjsnews.cn/api/article/like';
    $sign = md5('articleid=' . $articleId . '&token=' . $token . '&type=1&userid=' . $userId);
    $postData = 'articleid=' . $articleId . '&token=' . urlencode($token) . '&type=1&userid=' . $userId . '&sign=' . $sign;
    
    $response = curlPostRaw($url, $postData, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response === false) {
        return ['success' => false];
    }
    
    $json = json_decode($response, true);
    return ['success' => ($json['code'] ?? 1) === 0];
}

/**
 * CURL POST (数组格式)
 */
function curlPost($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * CURL POST (原始字符串)
 */
function curlPostRaw($url, $rawData, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}
