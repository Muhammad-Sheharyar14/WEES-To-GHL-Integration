<?php

require __DIR__ . '/../vendor/autoload.php';

/**
 * WESS Live Key Diagnostic Test
 *
 * Tests the key provided by the client (77WW3pQcnCt5yipRbuVf) across:
 * 1. Live API server: https://api.wessconnect.com
 * 2. Prelive API server: https://api.prelive.wessconnect.net
 * 3. Multiple authentication methods (Bearer token, custom headers, query params)
 */

$key = $argv[1] ?? '77WW3pQcnCt5yipRbuVf';
$baseUrl = $argv[2] ?? 'https://api.wessconnect.com/api/v1/online';

echo "========================================================================\n";
echo "                   WESS API KEY DIAGNOSTIC TEST                         \n";
echo "========================================================================\n";
echo "Testing Key:      {$key}\n";
echo "Key Length:       " . strlen($key) . " characters\n";
echo "Target Base URL:  {$baseUrl}\n";
echo "========================================================================\n\n";

function callWess(string $url, array $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $fullHeaders = array_merge([
        'Accept: application/json',
        'User-Agent: WESS-GHL-Integration-Test/1.0',
    ], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $fullHeaders);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'error' => $err,
        'body' => $res,
        'json' => json_decode($res, true)
    ];
}

// 1. Direct Bearer Token Test on /user
$rootUrl = preg_replace('#/online$#', '', rtrim($baseUrl, '/'));
echo "1. Testing /user endpoint with Bearer Token:\n";
$userRes = callWess("{$rootUrl}/user", ['Authorization: Bearer ' . $key]);
echo "   URL:  {$rootUrl}/user\n";
echo "   HTTP: {$userRes['code']}\n";
echo "   Body: " . trim($userRes['body']) . "\n\n";

// 2. Direct Bearer Token Test on /branches
echo "2. Testing /branches endpoint with Bearer Token:\n";
$branchRes = callWess("{$baseUrl}/branches", ['Authorization: Bearer ' . $key]);
echo "   URL:  {$baseUrl}/branches\n";
echo "   HTTP: {$branchRes['code']}\n";
echo "   Body: " . trim($branchRes['body']) . "\n\n";

// 3. Testing Alternative Auth Headers
echo "3. Testing Alternative Auth Headers on /user:\n";
$altHeaders = [
    'X-Api-Key'  => 'X-Api-Key: ' . $key,
    'x-token'    => 'x-token: ' . $key,
    'api-key'    => 'api-key: ' . $key,
    'wess-token' => 'wess-token: ' . $key,
    'Raw-Auth'   => 'Authorization: ' . $key,
];

foreach ($altHeaders as $name => $hdr) {
    $res = callWess("{$rootUrl}/user", [$hdr]);
    echo "   [$name] HTTP {$res['code']}: " . substr(trim($res['body']), 0, 70) . "\n";
}
echo "\n";

// 4. Analysis & Recommendations
echo "========================================================================\n";
echo "DIAGNOSTIC CONCLUSION & NEXT STEPS:\n";
echo "========================================================================\n";
if ($userRes['code'] === 200 || $branchRes['code'] === 200) {
    echo "[SUCCESS] Key is valid and active!\n";
} else {
    echo "[INFO] WESS uses Laravel Sanctum API tokens.\n";
    echo "  - Sandbox Token: '5|blMkzRnxDNQBGv8rs4kp6I1XwCT3dNUwXGsAhGbC67191d0e' (format: ID|Hash, 52 chars)\n";
    echo "  - Provided Key:  '{$key}' (length: " . strlen($key) . " chars, no ID prefix)\n";
    echo "\nPossible Reasons:\n";
    echo "  1. The key might be the account password (like sandbox password 'ZdgSlcLXWpOyl59CzONW' which is also 20 chars).\n";
    echo "     -> If it's a password, run: php help/test_wess_login.php <live_username> {$key}\n";
    echo "  2. The key might be an API token copied without its prefix ID (e.g., 1|{$key}).\n";
    echo "  3. The API token needs to be generated from the WESS live portal / settings.\n";
}
echo "========================================================================\n";
