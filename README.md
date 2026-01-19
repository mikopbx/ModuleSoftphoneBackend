# ModuleSoftphoneBackend

REST API backend for mobile SIP telephony applications with proper JWT authentication.

## Overview

ModuleSoftphoneBackend provides a secure REST API for softphone applications with:
- **JWT Bearer Token Authentication** (RFC 6750 compliant)
- **Separate Access and Refresh Tokens**
- **HMAC-SHA256 Token Signing**
- **Security Logging**
- **Proper HTTP Status Codes**

## Security Notes

- **Bearer token**: send token via `Authorization: Bearer <token>` header.
- **Token types**: access token for API calls, refresh token to renew access token.
- **Status codes**: `200`, `400`, `401`, `403`, `500`.

## API Endpoints

### Authentication

#### Login
```
POST /pbxcore/api/module-softphone-backend/v1/auth/login

Content-Type: application/json

Request:
{
  "username": "user@example.com",
  "password": "secure_password"
}

Response (200):
{
  "success": true,
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### Refresh Token
```
POST /pbxcore/api/module-softphone-backend/v1/auth/refresh

Authorization: Bearer {refresh_token}

Response (200):
{
  "success": true,
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### Logout
```
POST /pbxcore/api/module-softphone-backend/v1/auth/logout

Authorization: Bearer {access_token}

Response (200):
{
  "success": true,
  "message": "Logged out successfully"
}
```

### Protected Resources

#### Get User Profile
```
GET /pbxcore/api/module-softphone-backend/v1/profile

Authorization: Bearer {access_token}

Response (200):
{
  "success": true,
  "user": {
    "id": 1,
    "username": "user@example.com",
    "role": "user"
  }
}
```

### Public Endpoints

#### Health Check
```
GET /pbxcore/api/module-softphone-backend/v1/health

Response (200):
{
  "success": true,
  "status": "ok",
  "timestamp": 1234567890
}
```

## Configuration

### Token Expiry Times

Edit in `Lib/SoftphoneBackendConf.php`:
```php
public const ACCESS_TOKEN_EXPIRY = 3600;      // 1 hour
public const REFRESH_TOKEN_EXPIRY = 2592000;  // 30 days
```

### Secret Key Management

The module generates and stores the signing secret key in the module data directory:

- `modules/ModuleSoftphoneBackend/db/secret.key`

This path is persistent across reboots (unlike `/var/etc`).

## CLI Examples (Linux, curl)

```bash
# Base URL
BASE_URL="http://127.0.0.1/pbxcore/api/module-softphone-backend/v1"

# 1) Login and get tokens (save full response)
LOGIN_RES="$(curl -sS -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"201","password":"b749601f329a635a19c4a87bc359be3d"}')"
echo "$LOGIN_RES"

# 2) Extract tokens (no jq required)
# Option A (sed -E):
ACCESS_TOKEN="$(printf '%s\n' "$LOGIN_RES" | sed -nE 's/.*"access_token":"([^"]+)".*/\1/p')"
REFRESH_TOKEN="$(printf '%s\n' "$LOGIN_RES" | sed -nE 's/.*"refresh_token":"([^"]+)".*/\1/p')"
# Option B (php):
# ACCESS_TOKEN="$(printf '%s' "$LOGIN_RES" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["access_token"] ?? "";')"
# REFRESH_TOKEN="$(printf '%s' "$LOGIN_RES" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["refresh_token"] ?? "";')"

# 3) Call protected endpoint
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# 4) Refresh access token
curl -sS -X POST "$BASE_URL/auth/refresh" \
  -H "Authorization: Bearer $REFRESH_TOKEN"

# 5) Logout
curl -sS -X POST "$BASE_URL/auth/logout" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# 6) Health check (public)
curl -sS "$BASE_URL/health"

# 7) Internal JWT verification endpoint used by nginx (should return 200/401)
curl -sS "$BASE_URL/check-media-access" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# Error examples:
# 401 Unauthorized
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer invalid_token"

# 403 Forbidden (refresh token used as access token)
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer $REFRESH_TOKEN"

# 400 Bad Request (empty credentials)
curl -sS -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"","password":""}'

# Nchan endpoints (users-state)
# Publisher (no auth, localhost only)
curl -sS -X POST "$BASE_URL/pub/users-state" \
  -H "Content-Type: application/json" \
  -d '{"event":"users-state-update"}'

# Subscriber (auth required)
# Longpoll/EventSource compatible:
curl -sS "$BASE_URL/sub/users-state?authorization=$ACCESS_TOKEN"
```

## Installation

1. Place module in `/Volumes/projects/pbx_miko/modules/ModuleSoftphoneBackend`
2. Run module setup
3. Module will be available at Web UI

## Logging

Security events are logged to:
```
/storage/usbdisk1/mikopbx/log/ModuleSoftphoneBackend/security.log
```

Events logged:
- `AUTH_SUCCESS` - Successful login
- `AUTH_FAILED` - Failed login attempt
- `LOGOUT` - User logout

## License

Proprietary and confidential. All rights reserved.

## Support

Email: help@miko.ru

