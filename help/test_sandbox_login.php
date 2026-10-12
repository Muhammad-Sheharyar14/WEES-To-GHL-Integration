<?php

$loginUrl = 'https://wess.prelive.wessconnect.net/index.php?r=site/login';
$cookieFile = __DIR__ . '/cookies_sandbox.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
$html = curl_exec($ch);
curl_close($ch);

preg_match('#<input[^>]+value="([^"]+)"[^>]+name="YII_CSRF_TOKEN"#i', $html, $m);
$csrf = $m[1] ?? '';

$postData = [
    'YII_CSRF_TOKEN'        => $csrf,
    'LoginForm[username]'   => 'amplelife1',
    'LoginForm[password]'   => 'ZdgSlcLXWpOyl59CzONW',
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

echo "Sandbox portal test with amplelife1 / ZdgSlcLXWpOyl59CzONW:\n";
echo "HTTP: $code\n";
echo "Redirect: $redir\n";

if (file_exists($cookieFile)) unlink($cookieFile);
