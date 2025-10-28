# GLS API Authentication Issue - Diagnosis & Solution

## Issue Summary

The GetParcelList API endpoint is returning "Unauthorized" errors, and the test account has been locked due to too many failed login attempts.

```
Error: Unauthorized. (Code: -1)
Error: Too many failed login attempts. Your account has been locked until 15:38.
```

## Root Cause Analysis

### ✓ What's Working (Code is Correct)

1. **Password Hashing**: Our implementation correctly hashes the password using SHA-512 and converts it to a byte array
   - Reference (php_rest_client.php:27): `$password = "[".implode(',',unpack('C*', hash('sha512', $pwd, true)))."]";`
   - Our implementation (Client.php:42-52): Returns `array_values(unpack('C*', hash('sha512', $password, true)))`
   - Both produce the same JSON output: `"Password":[57,115,234,...]`

2. **Request Format**: The JSON request structure matches the GLS API requirements
   - Username, Password, and date fields are correctly formatted
   - Date format `/Date(timestamp)/` is correct

3. **API Endpoint**: Using correct test API URL
   - `https://api.test.mygls.hu/ParcelService.svc/json/GetParcelList`

### ✗ What's Wrong (Authentication Failure)

The "Unauthorized" error indicates that the GLS API is rejecting the credentials. This can happen due to:

1. **Incorrect Password or Username**
   - The credentials stored in WordPress settings may not match the actual MyGLS account
   - Extra whitespace or special characters may have been accidentally added

2. **Test Account Expired or Changed**
   - The public test account credentials (`myglsapitest@test.mygls.hu` / `1pImY_gls.hu`) may have:
     - Been changed by GLS
     - Expired or been deactivated
     - Reached usage limits

3. **Account Locked** (Current State)
   - Too many failed authentication attempts
   - Locked until 15:38 (5-10 minute lockout period)

## Evidence from Logs

```
Request body: {
  "Username":"myglsapitest@test.mygls.hu",
  "Password":[57,115,234,22,47,82,252,77,...],  ← Format is CORRECT
  "PickupDateFrom":"/Date(1761523200000)/",
  "PickupDateTo":"/Date(1761609600000)/",
  "PrintDateFrom":null,
  "PrintDateTo":null
}

Response: {
  "GetParcelListErrors":[{
    "ErrorCode":-1,
    "ErrorDescription":"Unauthorized."  ← Authentication rejected
  }]
}
```

## Solutions

### Immediate Actions

1. **Wait for Account Unlock**
   - The account is locked until 15:38
   - Wait at least 5-10 minutes before trying again
   - Do NOT attempt to test again immediately (will extend the lockout)

2. **Verify Credentials**
   ```bash
   # Run the diagnostic script
   php diagnose-auth.php
   ```

   This will:
   - Test the exact credentials being used
   - Show detailed error messages
   - Provide specific guidance based on the error

3. **Test Credentials in MyGLS Web Portal**
   - Try logging in to https://www.test.mygls.hu/
   - Use the same username and password from your settings
   - If login fails in the web portal, the credentials are definitely wrong

### Fixing the Issue

#### Option 1: Use Your Own Production Credentials

The public test credentials may no longer be valid. Use your actual GLS account:

1. Log in to MyGLS portal: https://www.mygls.hu/ (or your country's portal)
2. Get your API credentials from account settings
3. Update WordPress MyGLS settings:
   - Username: Your actual MyGLS email
   - Password: Your actual MyGLS password
   - Client Number: Your actual client number
   - Test Mode: OFF (uncheck for production) or ON (check for test environment)

#### Option 2: Contact GLS Support

If you need test credentials:

1. Contact GLS technical support
2. Request API test account credentials
3. Verify the credentials work before configuring WordPress

#### Option 3: Verify Test Account Status

The public test credentials in the reference implementation may be outdated:
- Reference implementation shows: `myglsapitest@test.mygls.hu` / `1pImY_gls.hu`
- These credentials may have been changed or deactivated by GLS
- Check GLS API documentation for current test credentials

### How to Update Credentials in WordPress

1. Go to: **WordPress Admin → MyGLS → Settings**
2. Update the following fields:
   - **Username**: Your MyGLS email
   - **Password**: Your MyGLS password (plain text, it will be hashed automatically)
   - **Client Number**: Your GLS client number
   - **Test Mode**: Enable if using test environment
3. Click **"Test Connection"** button
4. Save settings if test succeeds

## Diagnostic Tools Created

### 1. diagnose-auth.php
Comprehensive authentication tester that:
- Validates password hashing
- Builds and sends actual API request
- Provides detailed error analysis
- Shows exact request/response for debugging

**Usage:**
```bash
# Edit the credentials in the file first:
nano diagnose-auth.php

# Then run:
php diagnose-auth.php
```

### 2. test-password-hash.php (Already exists)
Tests password hashing algorithm:
```bash
# Edit password in file, then run:
php test-password-hash.php
```

## Prevention

To avoid account lockouts in the future:

1. **Verify credentials before deployment**
   - Use diagnostic tools to test credentials
   - Don't deploy with untested credentials

2. **Use Test Connection button**
   - Always test in WordPress before saving
   - The Settings page has a "Test Connection" button (Settings.php:168-174)

3. **Proper error handling**
   - The plugin already has good error handling (Client.php:158-166)
   - Errors are logged and displayed to users

## Technical Details

### Password Hash Implementation (Verified Correct)

```php
// Client.php:42-52
private function hashPassword($password) {
    $password = trim($password);
    $hash = hash('sha512', $password, true);  // Binary SHA-512
    return array_values(unpack('C*', $hash)); // Convert to byte array
}
```

This matches the GLS reference implementation and produces correct output.

### Request Format (Verified Correct)

```php
// Client.php:73-79
$request_data = [
    'Username' => $this->username,
    'Password' => $this->password_hash,  // Byte array
    'PickupDateFrom' => $pickup_from ? $this->formatDate($pickup_from) : null,
    'PickupDateTo' => $pickup_to ? $this->formatDate($pickup_to) : null,
    'PrintDateFrom' => $print_from ? $this->formatDate($print_from) : null,
    'PrintDateTo' => $print_to ? $this->formatDate($print_to) : null
];
```

This matches the GLS API requirements.

## Conclusion

**The code implementation is correct.** The "Unauthorized" error is caused by invalid credentials, not a code bug.

**Action Required:**
1. Wait for account unlock (after 15:38)
2. Verify the actual credentials are correct
3. Use the diagnostic script to test
4. Update WordPress settings with correct credentials

## References

- GLS API Documentation: https://api.mygls.hu/
- Reference Implementation: PHPminta/php_rest_client.php:27
- Our Implementation: includes/API/Client.php:42-52
- Settings Page: includes/Admin/Settings.php:429-566
