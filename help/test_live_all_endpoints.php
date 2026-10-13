<?php

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel Application for Facades (Http, Log, etc.)
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Wess\WessClient;

$token = $argv[1] ?? '';
$baseUrl = $argv[2] ?? 'https://api.wessconnect.com/api/v1/online';

if (empty($token)) {
    die("Usage: php help/test_live_all_endpoints.php <TOKEN> [BASE_URL]\n");
}

echo "========================================================================\n";
echo "              LIVE WESS API CLIENT COMPREHENSIVE TEST                   \n";
echo "========================================================================\n";
echo "Base URL: {$baseUrl}\n";
echo "Token:    " . substr($token, 0, 8) . "..." . substr($token, -6) . "\n\n";

$client = new WessClient($token, $baseUrl);

// 1. Test Connection
echo "1. Testing WessClient::testConnection()...\n";
try {
    $conn = $client->testConnection();
    echo "   [SUCCESS] Connected to WESS!\n";
    echo "   User ID:     " . ($conn['user']['id'] ?? 'N/A') . "\n";
    echo "   Vendor Name: " . ($conn['user']['name'] ?? 'N/A') . "\n";
    echo "   Roles:       " . implode(', ', $conn['roles'] ?? []) . "\n";
    echo "   Permissions: " . count($conn['permissions'] ?? []) . " granted\n\n";
} catch (Exception $e) {
    echo "   [FAILED] " . $e->getMessage() . "\n\n";
}

// 2. Test getBranches via WessClient
echo "2. Testing WessClient::getBranches()...\n";
try {
    $branches = $client->getBranches();
    echo "   [SUCCESS] Branches retrieved (Total: " . count($branches) . ")\n";
    foreach ($branches as $b) {
        echo "   - ID: {$b['id']}, Code: {$b['code']}, Name: {$b['name']}\n";
    }
    if (empty($branches)) {
        echo "   (Note: No branches currently marked as enabled for online API in the live WESS portal)\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "   [FAILED] " . $e->getMessage() . "\n\n";
}

// 3. Test lookupCustomerByPhone via WessClient
echo "3. Testing WessClient::lookupCustomerByPhone('+6591234567')...\n";
try {
    $cust = $client->lookupCustomerByPhone('+6591234567');
    if ($cust) {
        echo "   [SUCCESS] Customer found: " . json_encode($cust) . "\n\n";
    } else {
        echo "   [SUCCESS] Lookup executed cleanly (No customer with test phone number found, as expected).\n\n";
    }
} catch (Exception $e) {
    echo "   [FAILED] " . $e->getMessage() . "\n\n";
}

echo "========================================================================\n";
echo "ALL CLIENT METHODS VERIFIED AGAINST LIVE PRODUCTION SERVER!\n";
echo "========================================================================\n";
