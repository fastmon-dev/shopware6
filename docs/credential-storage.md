# Where the credentials live, and why not in `system_config`

The merchant-facing half is in [`README.md`](../README.md), *Where the credentials are
stored*. This file is the reasoning behind the table.

## One table of the plugin's own

`fastmon_collector_connection`: one row, one column per field, created by one migration
and dropped again when the plugin is uninstalled without *keep user data*. It holds the
registration, the token pair with its expiry, the pasted API key, who approved the
connection, what it points at, and the authorization in flight while the merchant is away
at the consent screen.

`system_config` is built for configuration, and each of its properties is right for a
setting and wrong for a token:

- **It is memoised once per request.** The request that waited for the refresh lock has to
  read what the winner stored, not what it read before waiting. That is the difference
  between a rotated token and a connection fastmon ends for reuse.
- **It is tagged into the page cache.** A rotation would invalidate cached pages several
  times a day for a value no page renders.
- **It keeps every value as a JSON-wrapped string**, which is how the authorization in
  flight ended up as a blob rather than as columns.
- **It is readable through Shopware's generic system-config endpoint** by anyone holding
  `system_config:read`.

Between them they were the reason this plugin once needed a raw SQL read to get an
uncached refresh token, a flag to keep writes out of the page cache, and a JSON blob for
the authorization. A row of its own needs none of the three.

The entity admits the system scope only, so `GET /api/fastmon-collector-connection`
answers `403 Forbidden` whatever privileges the user holds. The plugin's own routes report
whether a credential exists and what it may do, never what it is, and the fallback API key
is typed once behind the *Connect with an API key instead* link and never shown again.

## Why the tokens are not encrypted

They are in plain text, like every other Shopware plugin's API credentials. Encrypting
them would mean keeping a key in `.env`, next to the database credentials that already
grant access to the same table: it would change who can read them from "anyone with the
database" to "anyone with the database and the application directory", which is the same
person on every shop this will run on.

What did change with app connections is how much a stolen row is worth. The access token
expires in fifteen minutes and the refresh token rotates on every use, so a copy taken
from a backup stops working the moment the shop refreshes, and using it announces the
theft, because fastmon ends a connection whose refresh token is presented twice.

## The two values that stay in `system_config`

`sourceHash` and `collectorHash`, the pair the storefront templates render. Those are
configuration in the full sense: they are read on every page, and Shopware dropping the
cached pages that carry the old hash when they change is the point rather than a side
effect to avoid.
