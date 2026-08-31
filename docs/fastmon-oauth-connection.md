# The app connection, plugin side

What the plugin does with fastmon's OAuth surface, and the assumptions it makes. Written
after the endpoints shipped, and checked against them (`api.fastmon.eu`, verified
2026-08-31) rather than against the documentation alone.

The merchant-facing half is in [`README.md`](../README.md), *Connecting*. This file is for
whoever changes either side.

## Why the plugin runs this grant and not the app's

The fastmon Shopware **app** is a confidential client: its app server holds a
`client_secret` and has one fixed, pre-registered `redirect_uri`. A **plugin** has neither.
It runs on the merchant's own server, under a domain nobody can register in advance, and
its code ships through the Shopware Store, so an embedded secret is public in every shop
that ever downloaded it.

fastmon's answer to that is what this implements: **self-registration** (RFC 7591) gives
each installation its own `client_id` with its own exact redirect URI, and **PKCE**
(RFC 7636, S256 only) authenticates the exchange in place of a secret.

An earlier draft of this file asked the backend for the Device Authorization Grant
(RFC 8628) instead. That is not what shipped, and it is not needed: the objection the
device grant answers - a client that can hold no secret and own no redirect - is answered
here by registering a redirect that belongs to the merchant's own shop, which the approver
can see and judge on the consent screen.

## The flow, as the plugin runs it

| Step | Where | What |
|---|---|---|
| Discovery | `FastmonOAuthClient::metadata()` | `GET /.well-known/oauth-authorization-server`, cached per request, never persisted |
| Register | `ConnectionService::client()` | once per shop, again only when the redirect URI changes |
| Authorize | administration | full-page redirect; the verifier stays in `system_config` |
| Callback | `util/oauth-callback.js` | takes `?code&state` off whatever admin page loaded, reloads back to the panel |
| Exchange | `ConnectionService::completeAuthorization()` | server to server, with the stored verifier |
| Refresh | `AccessTokenProvider` | a minute before expiry, under a lock, successor stored first |
| Revoke | `ConnectionService::disconnect()` | hands the refresh token back; ends the grant |

`credential_owner` is **`organization`**: a shop has to keep reporting after the employee
who approved it leaves. fastmon asks the approver for `org_key:manage` in return, which is
the same authority as issuing an organization key - correct, because that is what this
produces.

Scopes asked for: `org:read app:read app:write site:read`. The granted set is read back
from the token response and stored, because the approver may tick fewer and their role cuts
the list again.

## Three properties of the backend the plugin depends on

These are not documentation; they were read out of `app/api/v1/auth_app.py` and
`app/services/oauth_token.py`, and a change to any of them is a change to this plugin.

1. **`GET /account` refuses an app connection**, whoever owns it (`AuthContext.require_user`).
   So the plugin never calls it. `GET /organizations` is the check instead: it is the one
   call every credential kind can make, and for an org-owned connection it returns exactly
   the organization the connection is bound to - which verifies the credential and resolves
   the name in one round trip.
2. **Refresh rotation has no grace window.** `rotate_refresh_token()` spends the token with
   a conditional `UPDATE`, and the loser of a race is reported as reuse, which revokes the
   grant. Two concurrent refreshes from one shop would therefore disconnect it. Hence the
   lock in `AccessTokenProvider`, and hence the write order in `ConnectionStore::saveTokens()`.
3. **A refresh token lives 60 days** from issue, and every rotation resets that. Until
   `RenewConnectionTask` there was nothing to reset it except somebody opening the plugin's
   configuration page, so a shop that ran quietly for two months lost its connection to a
   calendar rather than to anything that happened. The task runs weekly, eight times more
   often than the window it protects, so a worker that was down for a month costs nothing.
   It does not replace the refresh in the admin: an access token lives fifteen minutes, and
   every panel call needs a live one.

## Redirect URI

`APP_URL` plus the administration's own path, as the browser reports it, accepted only when
its host and port match `APP_URL`. Without that check, anyone who can already write the
plugin configuration could register a client whose authorization codes go to their own
domain.

fastmon requires an absolute `https` URI with no wildcards and no fragment; plain `http` is
allowed on `localhost`, `127.0.0.1` and `::1`, which is what makes local development work.
A shop behind a proxy that rewrites the address the browser sees sets
`FASTMON_OAUTH_REDIRECT_URI`.

## What is not covered here

Consent itself, and everything on fastmon's side of it. The plugin cannot influence it and
does not model it: it sends the merchant to `authorization_endpoint` and reads whatever
comes back.
