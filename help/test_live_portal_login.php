<?php

/**
 * WESS Live Portal Login Test (Ample Life Tenant)
 *
 * Usage:
 *   php help/test_live_portal_login.php [username] [password]
 *
 * Example:
 *   php help/test_live_portal_login.php amplelife1 77WW3pQcnCt5yipRbuVf
 */

$username = $argv[1] ?? 'amplelife1';
$password = $argv[2] ?? '77WW3pQcnCt5yipRbuVf';
$loginUrl = 'https://amplelife.wessconnect.com/index.php?r=site/login';
$cookieFile = __DIR__ . '/cookies_temp.txt';

if (file_exists($cookieFile)) unlink($cookieFile);

echo "========================================================================\n";
echo "              WESS LIVE PORTAL LOGIN TEST (Ample Life)                  \n";
echo "========================================================================\n";
echo "Portal URL: {$loginUrl}\n";
echo "Username:   {$username}\n";
echo "Password:   {$password}\n";
echo "========================================================================\n\n";

// 1. GET login page with cookies and CSRF
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
$html = curl_exec($ch);
curl_close($ch);

preg_match('#<input[^>]+name="YII_CSRF_TOKEN"[^>]+value="([^"]+)"#i', $html, $m1);
preg_match('#<input[^>]+value="([^"]+)"[^>]+name="YII_CSRF_TOKEN"#i', $html, $m2);
$csrf = $m1[1] ?? ($m2[1] ?? '');

if (empty($csrf)) {
    echo "ERROR: Failed to retrieve YII_CSRF_TOKEN from {$loginUrl}\n";
    exit(1);
}

// 2. Submit credentials
$postData = [
    'YII_CSRF_TOKEN'        => $csrf,
    'LoginForm[username]'   => $username,
    'LoginForm[password]'   => $password,
    'check_focus_in'        => '',
];

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_REFERER, $loginUrl);

$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

echo "HTTP Response Code: {$code}\n";
if ($code === 302) {
    echo ">>> [SUCCESS] LOGIN SUCCESSFUL!\n";
    echo "Redirected to: {$redir}\n";
} else {
    if (strpos($res, 'Invalid Login') !== false) {
        echo "Result: 'Invalid Login.' (Username or password incorrect for tenant 'amplelife')\n";
    } else {
        echo "Result: " . substr(strip_tags($res), 0, 150) . "\n";
    }
}

if (file_exists($cookieFile)) unlink($cookieFile);
