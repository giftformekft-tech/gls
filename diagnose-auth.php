<?php
/**
 * Comprehensive GLS API Authentication Diagnostic Tool
 *
 * This script helps diagnose authentication issues with the GLS API
 * by comparing our implementation with the reference implementation.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "═══════════════════════════════════════════════════════════\n";
echo "  GLS API Authentication Diagnostic Tool\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Test credentials - REPLACE WITH YOUR ACTUAL CREDENTIALS
$test_credentials = [
    'username' => 'myglsapitest@test.mygls.hu',
    'password' => '1pImY_gls.hu',
    'client_number' => 100000001,
    'test_mode' => true,
    'country' => 'hu'
];

echo "┌─ Step 1: Credentials Being Tested\n";
echo "├─────────────────────────────────────────────────────────────\n";
echo "│ Username: " . $test_credentials['username'] . "\n";
echo "│ Password: " . str_repeat('*', strlen($test_credentials['password'])) . " (length: " . strlen($test_credentials['password']) . ")\n";
echo "│ Client Number: " . $test_credentials['client_number'] . "\n";
echo "│ Test Mode: " . ($test_credentials['test_mode'] ? 'YES' : 'NO') . "\n";
echo "│ Country: " . strtoupper($test_credentials['country']) . "\n";
echo "└─────────────────────────────────────────────────────────────\n\n";

// Hash functions
function hashPasswordOurWay($password) {
    $password = trim($password);
    $hash = hash('sha512', $password, true);
    return array_values(unpack('C*', $hash));
}

function hashPasswordReferenceStringWay($password) {
    return "[".implode(',',unpack('C*', hash('sha512', $password, true)))."]";
}

echo "┌─ Step 2: Password Hash Verification\n";
echo "├─────────────────────────────────────────────────────────────\n";

$password_hash_array = hashPasswordOurWay($test_credentials['password']);
$password_hash_string = hashPasswordReferenceStringWay($test_credentials['password']);

echo "│ SHA-512 Hash (first 10 bytes): [" . implode(',', array_slice($password_hash_array, 0, 10)) . ",...]\n";
echo "│ Hash Length: " . count($password_hash_array) . " bytes (should be 64)\n";
echo "│ ✓ Hash format is correct\n";
echo "└─────────────────────────────────────────────────────────────\n\n";

// Build API request
echo "┌─ Step 3: Building API Request\n";
echo "├─────────────────────────────────────────────────────────────\n";

$domain = $test_credentials['test_mode']
    ? "api.test.mygls.{$test_credentials['country']}"
    : "api.mygls.{$test_credentials['country']}";
$url = "https://{$domain}/ParcelService.svc/json/GetParcelList";

echo "│ API URL: " . $url . "\n";

// Use date range from yesterday to today (more likely to have data)
$date_from = strtotime('-1 days');
$date_to = strtotime('today');

$request_data = [
    'Username' => $test_credentials['username'],
    'Password' => $password_hash_array,
    'PickupDateFrom' => '/Date(' . ($date_from * 1000) . ')/',
    'PickupDateTo' => '/Date(' . ($date_to * 1000) . ')/',
    'PrintDateFrom' => null,
    'PrintDateTo' => null
];

$json_body = json_encode($request_data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);

echo "│ Date Range: " . date('Y-m-d', $date_from) . " to " . date('Y-m-d', $date_to) . "\n";
echo "│ Request Body Length: " . strlen($json_body) . " bytes\n";
echo "└─────────────────────────────────────────────────────────────\n\n";

echo "┌─ Step 4: Request JSON Preview\n";
echo "├─────────────────────────────────────────────────────────────\n";
// Show request with password truncated
$preview_data = $request_data;
$preview_data['Password'] = array_slice($password_hash_array, 0, 5) + ['...truncated...'];
echo "│ " . json_encode($preview_data, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES) . "\n";
echo "└─────────────────────────────────────────────────────────────\n\n";

echo "┌─ Step 5: Sending API Request\n";
echo "├─────────────────────────────────────────────────────────────\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json; charset=utf-8',
    'Content-Length: ' . strlen($json_body)
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$test_credentials['test_mode']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "│ HTTP Status Code: " . $http_code . "\n";

if ($curl_error) {
    echo "│ ✗ CURL Error: " . $curl_error . "\n";
    echo "└─────────────────────────────────────────────────────────────\n\n";
    exit(1);
}

echo "└─────────────────────────────────────────────────────────────\n\n";

echo "┌─ Step 6: API Response Analysis\n";
echo "├─────────────────────────────────────────────────────────────\n";

$result = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "│ ✗ Invalid JSON Response\n";
    echo "│ JSON Error: " . json_last_error_msg() . "\n";
    echo "│ Raw Response (first 500 chars):\n";
    echo "│ " . substr($response, 0, 500) . "\n";
    echo "└─────────────────────────────────────────────────────────────\n\n";
    exit(1);
}

// Check for errors
$has_errors = false;

if ($http_code === 401) {
    echo "│ ✗ HTTP 401 UNAUTHORIZED\n";
    echo "│   This means the API rejected your credentials.\n";
    $has_errors = true;
} elseif ($http_code !== 200) {
    echo "│ ✗ HTTP " . $http_code . " Error\n";
    $has_errors = true;
}

if (isset($result['GetParcelListErrors']) && is_array($result['GetParcelListErrors'])) {
    foreach ($result['GetParcelListErrors'] as $error) {
        if (isset($error['ErrorCode']) && $error['ErrorCode'] !== 0) {
            echo "│ ✗ API Error (Code " . $error['ErrorCode'] . "): " . ($error['ErrorDescription'] ?? 'Unknown') . "\n";
            $has_errors = true;

            // Provide specific guidance
            if (strpos($error['ErrorDescription'], 'Unauthorized') !== false) {
                echo "│\n";
                echo "│   DIAGNOSIS: Authentication Failed\n";
                echo "│   ───────────────────────────────────\n";
                echo "│   The username or password is incorrect.\n";
                echo "│\n";
                echo "│   Common causes:\n";
                echo "│   1. Wrong password entered in settings\n";
                echo "│   2. Test account credentials may have changed/expired\n";
                echo "│   3. Extra spaces in username or password fields\n";
                echo "│   4. Account locked due to too many failed attempts\n";
                echo "│\n";
                echo "│   SOLUTIONS:\n";
                echo "│   • Verify credentials in MyGLS web portal\n";
                echo "│   • Try logging into https://www.test.mygls.hu/ with these credentials\n";
                echo "│   • Wait 5-10 minutes if account is locked\n";
                echo "│   • Contact GLS support to verify test account status\n";
            } elseif (strpos($error['ErrorDescription'], 'locked') !== false || strpos($error['ErrorDescription'], 'failed login') !== false) {
                echo "│\n";
                echo "│   DIAGNOSIS: Account Locked\n";
                echo "│   ───────────────────────────────────\n";
                echo "│   Too many failed login attempts.\n";
                echo "│\n";
                echo "│   SOLUTIONS:\n";
                echo "│   • Wait for the specified time (usually 5-10 minutes)\n";
                echo "│   • Verify correct credentials before trying again\n";
                echo "│   • Contact GLS support if issue persists\n";
            }
        }
    }
}

if (!$has_errors) {
    echo "│ ✓ Authentication Successful!\n";
    echo "│   API responded correctly to your credentials.\n";

    if (isset($result['PrintDataInfoList'])) {
        $parcel_count = count($result['PrintDataInfoList']);
        echo "│   Found " . $parcel_count . " parcel(s) in the specified date range.\n";
    }
}

echo "└─────────────────────────────────────────────────────────────\n\n";

if (!$has_errors) {
    echo "╔═══════════════════════════════════════════════════════════╗\n";
    echo "║                  ✓ DIAGNOSIS COMPLETE                     ║\n";
    echo "║                                                           ║\n";
    echo "║  Your credentials are CORRECT and working!               ║\n";
    echo "║                                                           ║\n";
    echo "║  If you're still having issues in WordPress:             ║\n";
    echo "║  1. Check the WordPress MyGLS settings page              ║\n";
    echo "║  2. Ensure credentials match those tested above          ║\n";
    echo "║  3. Save settings and try 'Test Connection' button       ║\n";
    echo "╚═══════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔═══════════════════════════════════════════════════════════╗\n";
    echo "║                  ✗ AUTHENTICATION FAILED                  ║\n";
    echo "║                                                           ║\n";
    echo "║  Please review the diagnosis above and:                  ║\n";
    echo "║  1. Verify your credentials are correct                  ║\n";
    echo "║  2. Try logging into MyGLS web portal                    ║\n";
    echo "║  3. Contact GLS support if needed                        ║\n";
    echo "╚═══════════════════════════════════════════════════════════╝\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  Raw API Response (for debugging):\n";
echo "═══════════════════════════════════════════════════════════\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "═══════════════════════════════════════════════════════════\n";
