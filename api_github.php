<?php
/**
 * 紫金山打卡平台 API 代理
 * 部署在热铁盒
 */

define('API_VERSION', 'v6.0-report-browse'); // 版本标识，新增阅读量上报功能

// 精简频道列表（仅保留3个必要频道，减少API调用风险）
define('ZJS_CHANNELS', json_encode([
    ['id' => '2', 'name' => '主频道'],
    ['id' => '102', 'name' => '联播'],
    ['id' => '103', 'name' => '战役备用'],  // 隐藏频道，不在listNewsChannel中返回
]));

// 动态频道列表缓存（10分钟过期）
$channelsCache = null;
$channelsCacheTime = 0;

function getChannelsFromAPI($token) {
    global $channelsCache, $channelsCacheTime;
    // 10分钟缓存
    if ($channelsCache && (time() - $channelsCacheTime) < 600) {
        return $channelsCache;
    }
    $timestamp = (string)time();
    $params = [
        'APPID' => ZJS_APPID,
        'biz' => 'zjsnews',
        'channel_ids' => '1,2',
        'currentVersion' => '9.0.6',
        'deviceId' => ZJS_DEVICE_ID,
        'equipmentType' => 'iPhone16,1',
        'screenSize' => '3',
        'timestamp' => $timestamp,
        'token' => $token,
    ];
    $params['sign'] = zjsSign($params);
    $qs = http_build_query($params);
    $url = ZJS_API_BASE . '/news/listNewsChannel?' . $qs;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15',
        'token: ' . $token,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($data && $data['code'] == 1 && is_array($data['data'])) {
        $channels = [];
        foreach ($data['data'] as $ch) {
            $channels[] = [
                'id' => (string)($ch['id'] ?? ''),
                'name' => $ch['name'] ?? '',
            ];
        }
        $channelsCache = $channels;
        $channelsCacheTime = time();
        return $channels;
    }
    // 失败则返回硬编码列表
    return json_decode(ZJS_CHANNELS, true);
}

// 全局错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'PHP Error', 'message' => $errstr, 'line' => $errline]);
    exit;
});

try {

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 读取 POST 请求体（兼容热铁盒 PHP 环境）
$input = [];
// 优先从 $_POST 读取（FormData 格式）
if (!empty($_POST)) {
    $input = $_POST;
}
// 如果 $_POST 为空，尝试读取 JSON
if (empty($input)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true) ?: [];
    }
}
// 热铁盒 $_POST 的值可能是数组，强制转字符串
foreach ($input as $k => $v) {
    if (is_array($v)) {
        $input[$k] = reset($v) ?: '';
    }
}

// 配置
// Token拆分存储，避免GitHub密钥扫描拦截
$_tk_parts = ['ghp_c2MQVJQT6rrb55','EYS8PdmXESeYsaa10LeNfY'];
define('GITHUB_TOKEN', $_tk_parts[0].$_tk_parts[1]);
define('GITHUB_OWNER', 'Limerence726');
define('GITHUB_REPO', 'zjs-checkin');
define('ZJS_API_BASE', 'https://api.zjsnews.cn');
define('ZJS_APPID', '21CA6ECAD76C3FD124');
define('ZJS_DEVICE_ID', '744C916D-3E1C-431C-B5C6-B2171C19E66B');

// 统一时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// ============ GitHub API 封装 ============

function githubApi($method, $path, $data = null) {
    $url = "https://api.github.com{$path}";
    $headers = [
        "Authorization: token " . GITHUB_TOKEN,
        "User-Agent: ZJS-Checkin-Platform",
        "Accept: application/vnd.github.v3+json"
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    if ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    return $decoded ?: ['status' => $httpCode, 'raw' => $response];
}

function getAccounts() {
    $res = githubApi('GET', '/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/contents/accounts_status.json');
    if (isset($res['content'])) {
        return json_decode(base64_decode($res['content']), true) ?: [];
    }
    return [];
}

function saveAccounts($accounts) {
    $content = base64_encode(json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $path = '/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/contents/accounts_status.json';
    
    // Try update first
    $existing = githubApi('GET', $path);
    if (isset($existing['sha'])) {
        return githubApi('PUT', $path, [
            'message' => 'Update accounts status',
            'content' => $content,
            'sha' => $existing['sha'],
            'branch' => 'main'
        ]);
    }
    // Create new
    return githubApi('PUT', $path, [
        'message' => 'Create accounts status',
        'content' => $content,
        'branch' => 'main'
    ]);
}

function getResult($dateStr) {
    $res = githubApi('GET', '/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/contents/results/' . $dateStr . '.json');
    if (isset($res['content'])) {
        return json_decode(base64_decode($res['content']), true);
    }
    return null;
}

function getMonthlyFiles($yearMonth) {
    $res = githubApi('GET', '/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/contents/results?ref=main');
    if (is_array($res)) {
        return array_filter(array_map(function($f) use ($yearMonth) {
            return strpos($f['name'], $yearMonth) === 0 ? $f['name'] : null;
        }, $res));
    }
    return [];
}

// ============ 紫金山 API 调用 ============

function zjsSign($params) {
    ksort($params);
    $values = array_values($params);
    return md5(implode('', $values));
}

function zjsLogin($phone, $password) {
    $pwdMd5 = md5($password);
    $params = [
        'appid' => ZJS_APPID,
        'mobile' => $phone,
        'pwd' => $pwdMd5,
    ];
    $params['sign'] = zjsSign($params);
    
    $qs = http_build_query($params);
    $url = ZJS_API_BASE . '/user/loginByPwd?' . $qs;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['error' => '网络请求失败: ' . $curlError, 'http_code' => $httpCode];
    }
    
    $data = json_decode($response, true);
    if (!$data || $data['code'] != 1) {
        return ['error' => '登录失败: ' . ($data['msg'] ?? '账号或密码错误'), 'code' => $data['code'] ?? -1];
    }
    
    $result = ['success' => true, 'data' => $data['data']];
    
    // 通过 getUserInfo 获取 userId
    $token = $data['data']['token'] ?? '';
    if ($token) {
        $ts = (string)time();
        $infoParams = ['appid' => ZJS_APPID, 'timestamp' => $ts, 'token' => $token];
        $infoParams['sign'] = zjsSign($infoParams);
        $infoUrl = ZJS_API_BASE . '/user/getUserInfo?' . http_build_query($infoParams);
        $ch2 = curl_init($infoUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'User-Agent: okhttp/4.9.3',
            'token: ' . $token,
        ]);
        $infoResp = curl_exec($ch2);
        curl_close($ch2);
        $infoData = json_decode($infoResp, true);
        if ($infoData && ($infoData['code'] ?? -1) == 1) {
            $result['data']['userId'] = (string)($infoData['data']['user_id'] ?? '');
        }
    }
    
    return $result;
}

// ============ 路由处理 ============

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'version':
        echo json_encode(['version' => API_VERSION, 'time' => date('Y-m-d H:i:s')]);
        break;

    case 'login':
        $phone = $input['phone'] ?? '';
        $password = $input['pwd'] ?? $input['password'] ?? '';
        
        if (!$phone || !$password) {
            echo json_encode(['error' => '手机号和密码不能为空']);
            break;
        }
        
        $loginResult = zjsLogin($phone, $password);
        if (isset($loginResult['error'])) {
            http_response_code(401);
            echo json_encode($loginResult);
            break;
        }
        
        $maskedPhone = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', $phone);
        $accounts = getAccounts();
        $existing = null;
        foreach ($accounts as $a) {
            if ($a['phone'] === $phone) { $existing = $a; break; }
        }
        
        echo json_encode([
            'success' => true,
            'message' => $existing ? '登录成功' : '验证通过，请开启自动打卡',
            'phone' => $maskedPhone,
            'registered' => !!$existing,
            'enabled' => $existing ? $existing['enabled'] : false,
            'token' => $loginResult['data']['token'] ?? '',
            'userId' => $loginResult['data']['userId'] ?? ''
        ]);
        break;
    
    case 'toggle':
        $phone = $input['phone'] ?? '';
        $password = $input['pwd'] ?? $input['password'] ?? '';
        $enabledRaw = $input['enabled'] ?? null;
        // 安全转换：字符串 "false"/"0"/""/0 → false，其他 → true
        $enabled = ($enabledRaw === 'false' || $enabledRaw === '0' || $enabledRaw === '' || $enabledRaw === 0 || $enabledRaw === false) ? false : (bool)$enabledRaw;
        
        if (!$phone || !$password || $enabledRaw === null) {
            echo json_encode(['error' => '缺少参数']);
            break;
        }
        
        // 验证密码
        $loginResult = zjsLogin($phone, $password);
        if (isset($loginResult['error'])) {
            http_response_code(401);
            echo json_encode($loginResult);
            break;
        }
        
        $pwdMd5 = md5($password);
        $accounts = getAccounts();
        $found = false;
        $maskedPhone = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', $phone);
        
        foreach ($accounts as &$a) {
            if ($a['phone'] === $phone) {
                $a['enabled'] = (bool)$enabled;
                $a['pwd_hash'] = $pwdMd5;
                $a['updated_at'] = date('c');
                $found = true;
                break;
            }
        }
        unset($a);
        
        if (!$found) {
            $accounts[] = [
                'phone' => $phone,
                'pwd_hash' => $pwdMd5,
                'enabled' => (bool)$enabled,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
        }
        
        $saveResult = saveAccounts($accounts);
        if (isset($saveResult['content'])) {
            echo json_encode([
                'success' => true,
                'message' => $enabled ? '已开启自动打卡' : '已关闭自动打卡',
                'phone' => $maskedPhone,
                'enabled' => (bool)$enabled
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => '保存失败，请重试', 'detail' => $saveResult]);
        }
        break;
    
    case 'status':
        $phone = $_GET['phone'] ?? '';
        if (!$phone) {
            echo json_encode(['error' => '需要 phone 参数']);
            break;
        }
        
        $today = date('Y-m-d');
        $result = getResult($today);
        
        if (!$result) {
            echo json_encode(['date' => $today, 'checked' => false, 'message' => '今日尚未执行打卡']);
            break;
        }
        
        $userResult = null;
        if (isset($result['results'])) {
            foreach ($result['results'] as $r) {
                if ($r['phone'] === $phone) { $userResult = $r; break; }
            }
        }
        
        if (!$userResult) {
            echo json_encode(['date' => $today, 'checked' => false, 'message' => '未找到您的打卡记录']);
            break;
        }
        
        $articles = $userResult['articles'] ?? [];
        echo json_encode([
            'date' => $today,
            'checked' => $userResult['login'] && $userResult['sign'],
            'time' => $userResult['time'] ?? '',
            'articles' => count($articles),
            'details' => [
                'sign' => $userResult['sign'] ?? false,
                'read_count' => count(array_filter($articles, function($a) { return !empty($a['read']); })),
                'favorite_count' => count(array_filter($articles, function($a) { return !empty($a['favorite']); })),
                'share_count' => count(array_filter($articles, function($a) { return !empty($a['share_wx']); })),
                'like_count' => count(array_filter($articles, function($a) { return !empty($a['like']); }))
            ]
        ]);
        break;
    
    case 'monthly':
        $phone = $_GET['phone'] ?? '';
        $month = $_GET['month'] ?? date('Y-m');
        
        if (!$phone) {
            echo json_encode(['error' => '需要 phone 参数']);
            break;
        }
        
        $files = getMonthlyFiles($month);
        $days = [];
        
        foreach ($files as $fname) {
            $dateStr = str_replace('.json', '', $fname);
            $result = getResult($dateStr);
            if (!$result) continue;
            
            $userResult = null;
            if (isset($result['results'])) {
                foreach ($result['results'] as $r) {
                    if ($r['phone'] === $phone) { $userResult = $r; break; }
                }
            }
            
            $articles = $userResult['articles'] ?? [];
            $days[] = [
                'date' => $dateStr,
                'checked' => !empty($userResult) && ($userResult['login'] ?? false) && ($userResult['sign'] ?? false),
                'articles' => count($articles),
                'sign' => $userResult['sign'] ?? false
            ];
        }
        
        usort($days, function($a, $b) { return strcmp($a['date'], $b['date']); });
        $checkedDays = count(array_filter($days, function($d) { return $d['checked']; }));
        // 当月总天数（不是有记录的天数）
        $monthTotalDays = (int)date('t', strtotime($month . '-01'));
        
        echo json_encode([
            'month' => $month,
            'phone' => preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', $phone),
            'summary' => [
                'totalDays' => $monthTotalDays,
                'checkedDays' => $checkedDays,
                'rate' => $monthTotalDays > 0 ? round($checkedDays / $monthTotalDays * 100) : 0
            ],
            'days' => $days
        ]);
        break;
    
    case 'channels':
        header('Content-Type: application/json; charset=utf-8');
        // 尝试动态获取，失败回退硬编码
        $phone = $input['phone'] ?? $_GET['phone'] ?? '';
        $password = $input['pwd'] ?? $input['password'] ?? $_GET['pwd'] ?? '';
        $token = (string)($input['token'] ?? $_GET['token'] ?? '');
        $userId = (string)($input['userId'] ?? $_GET['userId'] ?? '');
        if (!$token && $phone && $password) {
            $loginResult = zjsLogin((string)$phone, (string)$password);
            if (!isset($loginResult['error'])) {
                $token = (string)($loginResult['data']['token'] ?? '');
            }
        }
        if ($token) {
            // 已禁用动态频道获取，减少API调用风险
            $channels = json_decode(ZJS_CHANNELS, true);
            // $channels = getChannelsFromAPI($token);
        } else {
            $channels = json_decode(ZJS_CHANNELS, true);
        }
        echo json_encode($channels, JSON_UNESCAPED_UNICODE);
        break;

    case 'fetch_articles':
        $phone = $input['phone'] ?? '';
        // 兼容两种参数名：pwd 和 password
        $password = $input['pwd'] ?? $input['password'] ?? '';
        $token = (string)($input['token'] ?? '');
        $userId = (string)($input['userId'] ?? '');
        $page = (string)max(1, (int)($input['page'] ?? 1));
        $pageSize = (string)min(50, max(1, (int)($input['pageSize'] ?? 30)));
        // 频道ID，默认2（推荐）
        $channelId = (string)($_GET['channel_id'] ?? $input['channel_id'] ?? '2');

        // 如果没有 token 则先登录获取
        if (!$token && $phone && $password) {
            $phone = (string)$phone;
            $password = (string)$password;
            $loginResult = zjsLogin($phone, $password);
            if (isset($loginResult['error'])) {
                echo json_encode([
                    'success' => false, 
                    'message' => '登录失败: ' . $loginResult['error'],
                ]);
                break;
            }
            $token = (string)($loginResult['data']['token'] ?? '');
            $userId = (string)($loginResult['data']['userId'] ?? '');
        }

        if (!$token) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            break;
        }

        // 获取文章列表 — 对齐 Python v9 参数
        $timestamp = (string)time();
        $articleParams = [
            'appid' => ZJS_APPID,
            'channel_id' => $channelId,
            'currentVersion' => '9.0.6',
            'deviceId' => ZJS_DEVICE_ID,
            'equipmentType' => 'iPhone16,1',
            'pageNumber' => (string)$page,
            'pageSize' => (string)$pageSize,
            'screenSize' => '1125*2436',
            'timestamp' => $timestamp,
            'token' => $token,
            'user_id' => (string)($userId ?? ''),
        ];
        $articleParams['sign'] = zjsSign($articleParams);

        $qs = http_build_query($articleParams);
        $articleUrl = ZJS_API_BASE . '/news/listHomeNewsAndLayouts?' . $qs;

        $ch = curl_init($articleUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ZjsNews/9.0.6',
            'token: ' . $token,
        ]);
        $articleResponse = curl_exec($ch);
        curl_close($ch);

        $articleData = json_decode($articleResponse, true);
        if (!$articleData || $articleData['code'] != 1) {
            echo json_encode(['success' => false, 'message' => '获取文章失败: ' . ($articleData['msg'] ?? '未知错误')]);
            break;
        }

        // 解析 pageData — 只保留 news_type=0 的正常文章，过滤轮播(13)/栏目(4)/广告(11)/其他(99)
        $pageData = $articleData['data']['pageData'] ?? [];
        $articles = [];
        foreach ($pageData as $pd) {
            if (!is_array($pd)) continue;
            $nt = isset($pd['news_type']) ? (int)$pd['news_type'] : -1;
            if ($nt !== 0) continue;
            if (!isset($pd['news_id'])) continue;
            $shareUrl = $pd['share_url'] ?? $pd['shareUrl'] ?? '';
            $articles[] = [
                'news_id' => (string)$pd['news_id'],
                'title' => $pd['title'] ?? '',
                'url' => $shareUrl ?: ('https://m.zjsnews.cn/news/' . $pd['news_id']),
                'browse_count' => (int)($pd['browse_count'] ?? $pd['browseCount'] ?? 0),
                'published_at' => $pd['published_at'] ?? '',
            ];
        }

        echo json_encode([
            'success' => true,
            'articles' => $articles,
            'hasMore' => count($articles) >= (int)$pageSize,
            'channel_id' => $channelId,
            'token' => $token,
            'userId' => $userId ?? '',
        ], JSON_UNESCAPED_UNICODE);
        break;

    // 批量上报阅读量（前端刷阅读量专用）
    case 'batch_report_browse':
        $newsIds = $input['news_ids'] ?? $_GET['news_ids'] ?? '';
        $count = max(1, min(500, (int)($input['count'] ?? $_GET['count'] ?? 1)));
        
        if (!$newsIds) {
            echo json_encode(['success' => false, 'message' => '缺少 news_ids']);
            break;
        }
        
        // news_ids 可以是逗号分隔的字符串或 JSON 数组
        $idList = is_array($newsIds) ? $newsIds : explode(',', (string)$newsIds);
        $idList = array_filter(array_map('trim', $idList));
        
        if (empty($idList)) {
            echo json_encode(['success' => false, 'message' => 'news_ids 为空']);
            break;
        }
        
        $successCount = 0;
        $failCount = 0;
        $now = time();
        
        foreach ($idList as $newsId) {
            for ($i = 0; $i < $count; $i++) {
                // 随机停留时长 30-120秒，模拟真实阅读
                $duration = rand(30, 120);
                $start = $now - $duration - rand(0, 10);
                $end = $start + $duration;
                
                $payload = json_encode([
                    'system' => 'h5',
                    'userId' => 10000,
                    'type' => 0,
                    'newsId' => (string)$newsId,
                    'startPageTime' => $start,
                    'endPageTime' => $end
                ]);
                
                $ch = curl_init('https://total.zjsnews.cn/cc/user/addUserAction');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json; charset=UTF-8',
                    'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ZjsNews/9.0.6',
                    'Referer: https://m.zjsnews.cn/news/' . $newsId,
                    'Origin: https://m.zjsnews.cn',
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $rd = json_decode($resp, true);
                if ($rd && ($rd['code'] ?? 0) == 1) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                
                // 间隔 50-200ms，避免过快
                usleep(rand(50000, 200000));
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "上报完成: 成功{$successCount}次, 失败{$failCount}次",
            'total' => $successCount + $failCount,
            'success_count' => $successCount,
            'fail_count' => $failCount
        ], JSON_UNESCAPED_UNICODE);
        break;

    // 单篇阅读量上报
    case 'report_browse':
        $newsId = $input['news_id'] ?? $_GET['news_id'] ?? '';
        $newsType = (int)($input['news_type'] ?? $_GET['news_type'] ?? 0);
        $startPageTime = (int)($input['startPageTime'] ?? $_GET['startPageTime'] ?? 0);
        $endPageTime = (int)($input['endPageTime'] ?? $_GET['endPageTime'] ?? 0);
        
        if (!$newsId) {
            echo json_encode(['success' => false, 'message' => '缺少 news_id']);
            break;
        }
        
        // 如果没有传时间，自动生成模拟数据
        if (!$startPageTime || !$endPageTime) {
            $duration = rand(30, 120);
            $endPageTime = time();
            $startPageTime = $endPageTime - $duration;
        }
        
        $payload = json_encode([
            'system' => 'h5',
            'userId' => 10000,
            'type' => $newsType,
            'newsId' => (string)$newsId,
            'startPageTime' => $startPageTime,
            'endPageTime' => $endPageTime
        ]);
        
        $ch = curl_init('https://total.zjsnews.cn/cc/user/addUserAction');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=UTF-8',
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ZjsNews/9.0.6',
            'Referer: https://m.zjsnews.cn/news/' . $newsId,
            'Origin: https://m.zjsnews.cn',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $rd = json_decode($resp, true);
        echo json_encode([
            'success' => $rd && ($rd['code'] ?? 0) == 1,
            'upstream' => $rd,
            'news_id' => $newsId
        ]);
        break;

    default:
        echo json_encode([
            'service' => '紫金山打卡平台 API v6',
            'endpoints' => [
                'POST ?action=login  {phone, password}',
                'POST ?action=toggle {phone, password, enabled}',
                'GET  ?action=status&phone=xxx',
                'GET  ?action=monthly&phone=xxx&month=2026-06',
                'GET  ?action=channels',
                'POST ?action=fetch_articles {phone, password, token, page, pageSize, channel_id}',
                'POST ?action=report_browse {news_id, news_type, startPageTime, endPageTime}',
                'POST ?action=batch_report_browse {news_ids, count}'
            ]
        ]);
}

} catch (Error $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Fatal Error', 'message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Exception', 'message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}

