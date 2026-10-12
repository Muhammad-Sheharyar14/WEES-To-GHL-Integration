<?php

/**
 * WESS Login & Token Retrieval Script
 *
 * Usage:
 *   php help/test_wess_login.php [username] [password] [host]
 *
 * Example:
 *   php help/test_wess_login.php amplelife1 77WW3pQcnCt5yipRbuVf https://api.wessconnect.com
 */

$username = $argv[1] ?? 'amplelife1';
$password = $argv[2] ?? '77WW3pQcnCt5yipRbuVf';
$host     = rtrim($argv[3] ?? 'https://api.wessconnect.com', '/');

echo "========================================================================\n";
echo "                   WESS LOGIN & TOKEN GENERATION TEST                   \n";
echo "========================================================================\n";
echo "Host:     {$host}\n";
echo "Username: {$username}\n";
echo "Password: {$password}\n";
echo "========================================================================\n\n";

$loginUrl = "{$host}/api/v1/auth/login";

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'username' => $username,
    'password' => $password,
]));

$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: {$code}\n";
if ($err) {
    echo "cURL Error: {$err}\n";
} else {
    $json = json_decode($res, true);
    if ($code === 200 && !empty($json['data']['token'])) {
        $token = $json['data']['token'];
        echo ">>> LOGIN SUCCESSFUL!\n";
        echo "Generated Bearer Token: {$token}\n";
        echo "Expires At:             " . ($json['data']['expires_at'] ?? 'N/A') . "\n";
        echo "User ID:                " . ($json['data']['user']['id'] ?? 'N/A') . "\n";
        echo "\nNow testing token against /branches...\n";

        $branchUrl = "{$host}/api/v1/online/branches";
        $ch2 = curl_init($branchUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        $bRes = curl_exec($ch2);
        $bCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        echo "Branches HTTP Code: {$bCode}\n";
        echo "Branches Response: " . substr(trim($bRes), 0, 250) . "...\n";
    } else {
        echo "Login Failed (HTTP {$code}):\n";
        echo $res . "\n";
    }
}
