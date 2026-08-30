# Device Authorization Grant for the fastmon backend

What the fastmon backend needs so the Shopware **plugin** can connect the way the Shopware
**app** already does. Until it exists the plugin falls back to a pasted API token; the flow
below is the one it prefers and probes for on every connect.

## Why the existing grant cannot be reused

`app/api/v1/auth_app.py` implements the Authorization Code grant for **confidential**
clients only:

- `POST /auth/app/token` authenticates the client with `client_secret` against an Argon2
  hash from `settings.OAUTH_APP_CLIENTS` (`auth_app.py`, the `token` endpoint).
- `redirect_uris` is an exact-match allowlist per client, with a glob branch that
  `_redirect_uri_allowed()` itself marks as temporary and as an RFC 9700 account-takeover
  risk.

Both assumptions hold for the app, because the OAuth client is the *app server* at
`shopware-app-backend.fastmon.eu` — one deployment, one secret, one fixed redirect URI.
Neither holds for a plugin: it runs on the merchant's own server under a domain nobody can
register in advance, and its code ships through the Shopware Store, so an embedded secret
is public.

RFC 8628 is the grant for exactly that client. It needs no client secret and no redirect
URI, and it reuses most of what is already there.

## Endpoints

### `POST /v1/auth/app/device/code`

Public. `application/x-www-form-urlencoded`, mirroring the existing token endpoint.

```
client_id=shopware-plugin
```

Validate `client_id` against a **public** client entry (one with no `secret_hash`, or a
`public: true` flag on `OAuthAppClient`). Then mint and store, keyed by `device_code`:

```json
{
  "device_code": "<opaque, >=32 bytes of entropy>",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://fastmon.eu/device",
  "verification_uri_complete": "https://fastmon.eu/device?user_code=WDJB-MJHT",
  "expires_in": 900,
  "interval": 5
}
```

`user_code` is typed by a human, so use a confusable-free alphabet (RFC 8628 §6.1
suggests `BCDFGHJKLMNPQRSTVWXZ`) and keep it to 8 characters in two groups. Storage is the
same Valkey shape `store_app_auth_code()` already uses, with two records: `device_code →
{client_id, user_code, status, user_id?}` and `user_code → device_code` for the lookup
from the SPA.

Rate-limit per client and per IP: `user_code` is short enough to be worth guessing, which
is why §5.2 of the RFC calls it out.

### `POST /v1/auth/app/token` — one more `grant_type`

```
grant_type=urn:ietf:params:oauth:grant-type:device_code
device_code=<opaque>
client_id=shopware-plugin
```

No `client_secret`. Responses, per RFC 8628 §3.5:

| State | HTTP | Body |
|---|---|---|
| Not approved yet | 400 | `{"error": "authorization_pending"}` |
| Polling too fast | 400 | `{"error": "slow_down"}` |
| Declined | 400 | `{"error": "access_denied"}` |
| Expired | 400 | `{"error": "expired_token"}` |
| Approved | 200 | `{"access_token": "fm_…", "token_type": "bearer", "account": {...}, "organization": {...}}` |

The plugin reads both the flat `{"error": "..."}` shape and fastmon's nested
`{"error": {"code": "..."}}` envelope, so either is fine — flat is the RFC's.

On approval, issue the key through the existing `_issue_app_key()` with
`issued_via=client.client_id`, unchanged. `device_code` is single-use: consume it the way
`consume_app_auth_code()` already does.

`organization` is `{id, name}` of the organization the merchant approved for. It matters
more than it looks: with it, the shop stores what was consented to and never renders an
organization picker, so there is no way to approve for one organization on the consent
screen and then report to another from the shop. The plugin reads it if present and falls
back to asking if not, so shipping it needs no plugin release.

Enforce `interval`: a poll arriving sooner than `interval` seconds after the previous one
answers `slow_down` rather than `authorization_pending`.

### SPA: `/device`

A page where a logged-in user enters the `user_code` (pre-filled from `?user_code=` when
they arrived through `verification_uri_complete`), sees who is asking, and approves or
declines.

Approval is a consent decision that mints a full-catalog key, so it needs the **same
guards the existing consent endpoint has**: `require_session_credential` (session only,
never an API key) and `require_step_up()`. Nothing about the device grant weakens that —
it removes the redirect, not the consent.

Backing it: `POST /v1/auth/app/device/approve` with
`{user_code, approve, organization_id, …step-up fields}`, resolving `user_code →
device_code` and writing the decision, `user_id` and `organization_id` onto the device
record. `organization_id` is **mandatory on approval and never guessed** - the same rule
the Authorization Code consent follows - and must be one the approving user is a member
of, or the approval is rejected.

## What the plugin already does

Implemented in `src/Api/FastmonClient.php` and covered by `tests/Unit/FastmonClientTest.php`:

- `client_id` is **`shopware-plugin`** (`FastmonClient::CLIENT_ID`). Public by design.
- Calls everything under **`/v1`**. Production answers 404 on the unprefixed form
  (`GET /account` → 404, `GET /v1/account` → 401), so the note in `CLAUDE.md` about the
  API being served unprefixed does not describe what api.fastmon.eu serves today.
- Treats **404 or 405** on `/v1/auth/app/device/code` as "this instance has no device grant"
  and shows the token field instead — so shipping the endpoints is what turns the flow on,
  with no plugin release needed.
- Honours `interval` and adds five seconds on `slow_down`.
- Stops on `access_denied` and `expired_token` rather than polling a dead authorization.
- Keeps `device_code` server-side and hands the browser an opaque handle
  (`DeviceAuthorizationSession`), so an XSS in the Shopware admin cannot walk off with a
  redeemable credential.
- Reads `organization` from the token response and stores it; the organization picker
  only appears when the response carries none.
- Handles the documented error contract: `organization_not_approved` (a waiting state,
  the token is **kept**), `permission_denied` (names `details.permission`),
  `organization_not_found` and `credential_expired` (reconnect).

## Checklist

- [ ] `OAuthAppClient` can express a public client (no `secret_hash`)
- [ ] `shopware-plugin` registered as one
- [ ] `POST /v1/auth/app/device/code`, rate-limited per client and per IP
- [ ] `device_code` and `user_code` records in Valkey with a TTL matching `expires_in`
- [ ] `grant_type=…:device_code` on `POST /v1/auth/app/token`, with `interval` enforcement
- [ ] `POST /v1/auth/app/device/approve`, session-only plus step-up
- [ ] `organization_id` mandatory on approval, membership-checked, never guessed
- [ ] `organization` returned in the token response
- [ ] SPA `/device` page, `?user_code=` pre-fill and an organization selector
- [ ] Tokens still carry `issued_via`, so they stay revocable per integration
