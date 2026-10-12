<?php

require __DIR__ . '/../vendor/autoload.php';

$token = '5|blMkzRnxDNQBGv8rs4kp6I1XwCT3dNUwXGsAhGbC67191d0e';
$baseUrl = 'https://api.prelive.wessconnect.net/api/v1/online';

function req($url) {
    global $token;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => $res, 'json' => json_decode($res, true)];
}

echo "========================================================\n";
echo "           WESS API APPOINTMENT DIAGNOSTIC TEST         \n";
echo "========================================================\n\n";

// 1. Check Branches
$branchesRes = req("$baseUrl/branches");
echo "1. Branches List (HTTP {$branchesRes['code']}):\n";
$branches = $branchesRes['json']['data'] ?? [];
foreach ($branches as $b) {
    echo "   - Branch ID: {$b['id']}, Code: {$b['code']}, Name: {$b['name']}\n";
}
echo "\n";

// 2. Test Appointments for each branch
foreach ($branches as $b) {
    $bId = $b['id'];
    echo "--------------------------------------------------------\n";
    echo "TESTING BRANCH #{$bId} ({$b['name']})\n";
    echo "--------------------------------------------------------\n";

    // Test A: Absolutely no parameters
    $urlA = "$baseUrl/branches/{$bId}/appointments";
    $resA = req($urlA);
    $metaA = $resA['json']['meta'] ?? [];
    $totalA = $metaA['total'] ?? (is_array($resA['json']['data'] ?? null) ? count($resA['json']['data']) : 'N/A');
    echo "[Test A] No parameters: $urlA\n";
    echo "  HTTP: {$resA['code']} | Total: {$totalA}\n";
    if (!empty($resA['json']['data'])) {
        echo "  Found " . count($resA['json']['data']) . " records! First ID: " . ($resA['json']['data'][0]['id'] ?? '') . "\n";
    }

    // Test B: High per_page
    $urlB = "$baseUrl/branches/{$bId}/appointments?per_page=100";
    $resB = req($urlB);
    $totalB = $resB['json']['meta']['total'] ?? 0;
    echo "[Test B] Per page 100: $urlB\n";
    echo "  HTTP: {$resB['code']} | Total: {$totalB}\n";

    // Test C: Wide date range (Past 5 years to future 5 years)
    $queryC = http_build_query([
        'filter' => [
            'date_from' => '2020-01-01 00:00:00',
            'date_to'   => '2030-12-31 23:59:59',
        ],
        'per_page' => 100,
    ]);
    $urlC = "$baseUrl/branches/{$bId}/appointments?$queryC";
    $resC = req($urlC);
    $totalC = $resC['json']['meta']['total'] ?? 0;
    echo "[Test C] Wide Date Range (2020 to 2030): $urlC\n";
    echo "  HTTP: {$resC['code']} | Total: {$totalC}\n";

    // Test D: Today only date filter
    $today = date('Y-m-d');
    $queryD = http_build_query([
        'filter' => [
            'date_from' => "$today 00:00:00",
            'date_to'   => "$today 23:59:59",
        ],
    ]);
    $urlD = "$baseUrl/branches/{$bId}/appointments?$queryD";
    $resD = req($urlD);
    $totalD = $resD['json']['meta']['total'] ?? 0;
    echo "[Test D] Today only ($today): $urlD\n";
    echo "  HTTP: {$resD['code']} | Total: {$totalD}\n";

    // Test E: Filter by all status codes
    $queryE = http_build_query([
        'filter' => [
            'status' => 'OP,RQ,CF,BC',
        ],
    ]);
    $urlE = "$baseUrl/branches/{$bId}/appointments?$queryE";
    $resE = req($urlE);
    $totalE = $resE['json']['meta']['total'] ?? 0;
    echo "[Test E] All statuses (OP,RQ,CF,BC): $urlE\n";
    echo "  HTTP: {$resE['code']} | Total: {$totalE}\n";

    // Test F: Check bookable services & employees to see if branch is configured
    $servRes = req("$baseUrl/branches/{$bId}/appointments/services");
    $servCount = count($servRes['json']['data'] ?? []);
    $empRes = req("$baseUrl/branches/{$bId}/appointments/employees");
    $empCount = count($empRes['json']['data'] ?? []);
    echo "[Test F] Branch Configuration Check:\n";
    echo "  Services: {$servCount} | Employees: {$empCount}\n\n";
}

// 3. Customer Upcoming Appointments Check
echo "========================================================\n";
echo "3. Testing /customers/{id}/appointments/upcoming-appointments\n";
echo "========================================================\n";
// Let's test customer 105 (Jane Doe from sample response)
$cust105 = req("$baseUrl/branches/1/customers/105/appointments/upcoming-appointments?from=" . urlencode(date('Y-m-d 00:00:00')) . "&to=" . urlencode(date('Y-m-d 23:59:59', strtotime('+60 days'))));
echo "Customer #105 upcoming: HTTP {$cust105['code']}\n";
echo "Response: " . substr($cust105['raw'], 0, 500) . "\n\n";

echo "Done.\n";
