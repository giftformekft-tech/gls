#!/bin/bash

# Test GLS API Authentication
# Usage: ./test-api-auth.sh <username> <password>

USERNAME="${1:-myglsapitest@test.mygls.hu}"
PASSWORD="${2:-1pImY_gls.hu}"

echo "Testing GLS API Authentication"
echo "==============================="
echo "Username: $USERNAME"
echo "Password: [hidden]"
echo ""

# Generate SHA512 hash and convert to byte array
# Note: This requires openssl or similar tool
PASSWORD_HASH=$(echo -n "$PASSWORD" | openssl dgst -sha512 -binary | od -An -td1 | tr -d '\n' | sed 's/^ /[/;s/ *$/]/;s/  */,/g')

echo "Password Hash (first 50 chars): ${PASSWORD_HASH:0:50}..."
echo ""

# Calculate timestamps (yesterday to today)
DATE_FROM=$(($(date -d "yesterday" +%s) * 1000))
DATE_TO=$(($(date +%s) * 1000))

# Build JSON request
REQUEST=$(cat <<EOF
{
  "Username": "$USERNAME",
  "Password": $PASSWORD_HASH,
  "PickupDateFrom": "/Date($DATE_FROM)/",
  "PickupDateTo": "/Date($DATE_TO)/",
  "PrintDateFrom": null,
  "PrintDateTo": null
}
EOF
)

echo "Request JSON:"
echo "$REQUEST" | head -3
echo "..."
echo ""

# Make API call
echo "Calling API..."
echo "=============="
RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" \
  -X POST \
  -H "Content-Type: application/json" \
  -d "$REQUEST" \
  "https://api.test.mygls.hu/ParcelService.svc/json/GetParcelList")

# Extract HTTP code
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE:/d')

echo "HTTP Status: $HTTP_CODE"
echo ""

# Check response
if [ "$HTTP_CODE" == "200" ]; then
    echo "✓ SUCCESS: Authentication successful!"
    echo ""
    echo "Response:"
    echo "$BODY" | head -20

    # Check for errors in response
    if echo "$BODY" | grep -q '"ErrorCode":-1'; then
        echo ""
        echo "✗ API returned error despite HTTP 200"
        echo "$BODY" | grep -o '"ErrorDescription":"[^"]*"'
    fi
elif [ "$HTTP_CODE" == "401" ]; then
    echo "✗ AUTHENTICATION FAILED"
    echo "The username/password combination is incorrect"
    echo ""
    echo "Actions:"
    echo "1. Verify your MyGLS credentials"
    echo "2. Check if you have API access enabled"
    echo "3. Confirm you're using the TEST environment credentials"
    echo "4. Contact GLS support to verify your API password"
else
    echo "✗ HTTP ERROR: $HTTP_CODE"
    echo ""
    echo "Response:"
    echo "$BODY"
fi

echo ""
echo "==============================="
echo "Test completed"
