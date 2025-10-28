<?php
/**
 * Test script to verify password hashing for GLS API
 */

// Test password (replace with your actual password for testing)
$test_password = '1pImY_gls.hu'; // Use test password first

// Method 1: Our implementation (from Client.php)
function hashPasswordOurWay($password) {
    $password = trim($password);
    $hash = hash('sha512', $password, true);
    return array_values(unpack('C*', $hash));
}

// Method 2: PHP Reference implementation
function hashPasswordReferenceWay($password) {
    return "[".implode(',',unpack('C*', hash('sha512', $password, true)))."]";
}

echo "Testing Password Hashing\n";
echo "========================\n\n";

echo "Password: " . $test_password . "\n\n";

// Our way
$our_hash = hashPasswordOurWay($test_password);
echo "Our Implementation (PHP array):\n";
print_r($our_hash);
echo "\n";

echo "Our Implementation (JSON encoded):\n";
echo json_encode($our_hash, JSON_NUMERIC_CHECK) . "\n\n";

// Reference way
$ref_hash = hashPasswordReferenceWay($test_password);
echo "Reference Implementation (string):\n";
echo $ref_hash . "\n\n";

// Compare
$our_json = json_encode($our_hash, JSON_NUMERIC_CHECK);
if ($our_json === $ref_hash) {
    echo "✓ MATCH: Both methods produce identical output\n";
} else {
    echo "✗ MISMATCH: Methods produce different output\n";
    echo "Our output:       " . $our_json . "\n";
    echo "Reference output: " . $ref_hash . "\n";
}

echo "\n\nTest GetParcelList Request Format:\n";
echo "===================================\n";

$username = "myglsapitest@test.mygls.hu";
$password_array = hashPasswordOurWay($test_password);

$request_data = [
    'Username' => $username,
    'Password' => $password_array,
    'PickupDateFrom' => '/Date(' . (strtotime('2020-04-16 23:59:59') * 1000) . ')/',
    'PickupDateTo' => '/Date(' . (strtotime('2020-04-16 23:59:59') * 1000) . ')/',
    'PrintDateFrom' => null,
    'PrintDateTo' => null
];

$json_request = json_encode($request_data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);
echo "JSON Request:\n";
echo $json_request . "\n\n";

// Verify format matches reference
$expected_password_start = '"Password":[';
if (strpos($json_request, $expected_password_start) !== false) {
    echo "✓ Password format is correct (JSON array, not string)\n";
} else {
    echo "✗ Password format is incorrect\n";
}

echo "\n\nNow test with your actual credentials:\n";
echo "======================================\n";
echo "1. Replace \$test_password with your actual MyGLS password\n";
echo "2. Run: php test-password-hash.php\n";
echo "3. Copy the JSON request and test it manually with curl\n";
