# Admin session API

All endpoints use the admin origin (locally http://localhost:8085). Send `Accept: application/json`, retain the administrator session cookie, and include `X-CSRF-TOKEN` on every POST. There are no bearer tokens or shared Basic-auth secrets. Responses carrying session/account data use `Cache-Control: no-store, private`.

## Bootstrap

`GET /api/session` returns `{"user":null,"csrf_token":"..."}` for a guest, or the current safe administrator summary. The Inertia pages receive the token as a shared prop. Obtain a fresh token after the session changes.

## Request OTP

`POST /api/login/otp`:

```json
{ "mobile_number": "09171234567" }
```

A successful request returns:

```json
{
    "challenge_id": "opaque-uuid",
    "message": "If this number belongs to an active administrator, a sign-in code has been sent.",
    "delivery": "sms",
    "expires_in": 300,
    "resend_after": 60
}
```

Unknown and inactive numbers receive the same response shape without an SMS or usable challenge. Local/testing capture mode returns `delivery: captured` and writes privately to disk. OTPs are never returned by the API.

Limits: one request per phone every 60 seconds, three per phone per 30 minutes, and ten per IP per 30 minutes. Failed deliveries consume the request limit but leave no usable code.

## Sign in

`POST /api/login`:

```json
{ "challenge_id": "opaque-uuid", "otp": "123456" }
```

The same requesting browser/session must submit a six-digit code. A code expires after five minutes, allows five attempts, and can be consumed once. Verification is limited to 30 requests per IP per five minutes.

Success returns `{"user":{...},"redirect":"/dashboard"}`, rotates the session ID, and ends previous sessions for that admin. The summary contains only id, name, mobile_number, email, status, and assigned permission summaries. It contains no password, OTP hash, session version, or legacy archive.

## Authenticated requests

- `GET /api/user`: returns `{"user":{...}}`; requires a current active admin session.
- `POST /api/logout`: records logout, invalidates the session, regenerates the CSRF token, and returns `{"message":"You have been signed out.","redirect":"/login"}`.
- `GET /dashboard`: protected Inertia account overview.

Player records and player sessions cannot authenticate to these routes. The `gamexsports_admin_session` cookie and `admin_sessions` table are independent from the player portal. The frontend uses the explicit CSRF header to avoid relying on a shared localhost XSRF cookie.

## Errors

| Status | Meaning                                                                       |
| ------ | ----------------------------------------------------------------------------- |
| 401    | Missing, inactive, or replaced admin session                                  |
| 419    | Missing or stale CSRF token                                                   |
| 422    | Validation error or invalid/expired/exhausted OTP; field errors are in errors |
| 429    | Request/verification limit or send lock                                       |
| 503    | SMS is unavailable or the provider rejected the request                       |

Public registration, password login, and password reset are not exposed. Ending game routes and their permissions are documented in [ending-games.md](ending-games.md).
