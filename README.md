# fastmon.eu Real User Monitoring for Shopware 6

Collects Core Web Vitals, JavaScript errors and per-layer backend timings from real
visitors and reports them to [fastmon.eu](https://fastmon.eu). EU-hosted, cookieless.

| | |
|---|---|
| Shopware | 6.6.x or 6.7.x |
| PHP | 8.2 or newer |
| Licence | MIT |
| Support | [fastmon.eu/en/contact](https://fastmon.eu/en/contact/) for the service, this repository's issues for the plugin |
| Download | [releases](https://github.com/fastmon-dev/shopware6/releases) for the zip, [packagist](https://packagist.org/packages/fastmon/shopware-collector) for Composer |
| Multi-node shops | a shared lock store in `LOCK_DSN`, see *Connecting* |
| Per-layer backend timings (optional) | Tideways PHP extension 5.23 or newer; nothing else here needs an extension |

## What it does

**Injects the fastmon snippets** into the storefront: the tracker bundle, an early-error
bootstrap that catches JavaScript errors thrown before the tracker loads, and a no-JS
pixel for clients that never run scripts at all.

**Publishes a `Server-Timing` header** so fastmon can correlate backend phases with the
Web Vitals of the very same pageview, including the full-page-cache verdict, which needs
no extension and is the single most useful thing a shop can put in that header.

**Connects itself to fastmon** and creates the application. One application covers the
whole shop: every sales-channel domain registers itself in fastmon on its first pageview,
so there is nothing to add by hand and nothing to revisit when a domain is added.

## Installation

With Composer:

```bash
composer require fastmon/shopware-collector
bin/console plugin:refresh
bin/console plugin:install --activate FastmonCollector
bin/console cache:clear
```

The Composer package carries no built administration assets, because they are generated
and not committed. Run the project's own JS build after installing
(`bin/build-administration.sh`, or `composer run build:js` on 6.7).

Or as a zip, for a shop that is not deployed with Composer: download
`FastmonCollector-X.Y.Z.zip` from the
[releases](https://github.com/fastmon-dev/shopware6/releases), upload it under *Extensions
-> My extensions -> Upload extension*, and run the same three console lines. The zip
carries the built administration, so there is no build step.

Then open *Extensions -> My extensions -> fastmon.eu -> Configure* and press **Connect to
fastmon**.

## Connecting

Pressing **Connect to fastmon** sends you to fastmon's own consent screen, where you
approve the connection for one organisation and choose what this shop may do. You land
back in the administration and the shop has its credentials. Nothing is typed in and
nothing secret ships inside the plugin.

Underneath it is the OAuth 2.0 Authorization Code grant with PKCE, as a public client:
each installation registers itself and gets its own `client_id` with exactly one redirect
URI, this shop's own administration, which is the domain the approver sees and judges on
the consent screen. The contract with the backend, the error handling and the token
mechanics are in
[`docs/fastmon-oauth-connection.md`](docs/fastmon-oauth-connection.md).

Two things a shop has to get right.

**A worker or a cron has to run.** The weekly scheduled task
`fastmon_collector.renew_connection` spends the refresh token to buy the next one. A
refresh token expires sixty days after it was issued and every use resets that, so a shop
whose Messenger worker and scheduled-task cron are both idle loses the connection after
two months of nobody looking.

**On more than one node, set `LOCK_DSN`.** Refreshes are serialised through Shopware's
`lock.factory`, and Shopware's default is `flock`, a lock on the local filesystem: enough
for one machine and nothing for two. Point `LOCK_DSN` at a store every node reaches (Redis,
or the database). Otherwise two nodes refresh in the same moment, each presenting the same
refresh token, and fastmon ends the connection, because that is what a stolen token looks
like.

The connection belongs to the organisation, not to the person who approved it, so it keeps
working after they leave.

If a shop cannot run that grant at all, an administration served over plain http for
instance, the panel says so and offers a field for an API key from the fastmon dashboard
instead. A key does not expire and cannot renew itself, which is why it is second;
everything after a credential exists is identical either way.

### What a merchant needs on the fastmon side

| | |
|---|---|
| An account and an organisation | registration and org creation are open to everyone |
| **An approved organisation** | fastmon reviews new accounts before they may collect data |
| Role **owner** or **member** | `app:write` is not in the viewer bundle |

The approval gate is the one that surprises people. Connecting works immediately; creating
an application answers `403 organization_not_approved` until the organisation passes
review. Approval comes from an admin decision, a partner provisioning the client, or the
first successful payment. The plugin renders this as a waiting state and keeps the
credential, because there is nothing for the merchant to fix or retry.

The connection asks for **`org:read`**, **`app:read`**, **`app:write`** and
**`site:read`**: what the panel needs to name the organisation, list and configure an
application, and show the domains fastmon has actually seen. The shop produces
measurements and never queries them, so there is no `analytics:read`. The approver may
narrow that list, and the panel reads back what was actually granted rather than assuming,
so a connection without `app:write` is flagged before the first write fails on it.

### Choosing an application

Once connected, the panel shows the organisation the connection is bound to and offers to
link an existing application or create one. Creating one sets:

| | |
|---|---|
| `site_policy` | `auto`: every new domain becomes a Site on its first beacon |
| `pagetype_ruleset` | `shopware6`: page types classified from the storefront's body classes |
| `environment` | your choice; `dev` applications are not billed |
| `preset` | data scope: `minimal`, `standard` (default) or `full` |

`standard` is the PII-free posture: pseudonymous stitch, fetch/XHR aggregates, query key
names and error stack frames, but no message text, no query values and no session write.
There is no plan limit on applications.

The panel verifies the credential **and** the linked application when it opens. An
application deleted in the dashboard, or one whose hashes were rotated, leaves the
storefront serving a snippet that collects nothing, so that state is reported and another
application offered, rather than looking healthy.

**Disconnecting hands the connection back to fastmon.** The refresh token is revoked, so
the shop cannot rotate its way back in, and the entry disappears from *Organisation
settings -> Access*. A pasted API key is only forgotten here: it lives in the merchant's
dashboard, and this shop has no authority to revoke it. Uninstalling reaches fastmon not at
all, because an uninstall has to finish on a shop with no internet; it clears the stored
rows.

## Server-Timing

Independent of everything above: it needs no fastmon account, sends nothing anywhere, and
only annotates a response that was going out regardless. A shop that never connects still
gets a useful header in devtools.

```http
Server-Timing: fm-origin-cache;desc=hit, fm-origin-age;desc=312, fm-backend;dur=3.1
Server-Timing: fm-origin-cache;desc=miss, fm-backend;dur=128.4, rdbms;dur=42.5, redis;dur=6.0
```

Seven entries need no profiler at all, which is what a shop without Tideways gets instead
of nothing:

| Entry | What it is |
|---|---|
| `fm-origin-cache` | the full-page-cache verdict: `hit`, `miss`, or `bypass` |
| `fm-origin-age` | how old the served copy is, in seconds, on a hit |
| `fm-backend` | total PHP wall time |
| `fm-render` | template render time, measured by the plugin because no profiler extension reports it |
| `fm-pagetype` | the page type, derived from the dispatched route |
| `fm-host` | which machine answered |
| `fm-loggedin` | whether a customer was authenticated |

`bypass` is a response the cache was never going to serve (a logged-in customer, a filled
cart, a POST). Keeping those out of `miss` is what stops the cache rate from looking
terrible on a shop whose cache is working perfectly. Both verdicts come from Shopware's own
`HttpCacheHitEvent` and `HttpCacheStoreEvent` rather than from response headers, and the
header is written in `BeforeSendResponseEvent` rather than in `kernel.response`, because
Shopware's HTTP cache sits outside the kernel and a `kernel.response` listener never fires
on a hit. [`docs/server-timing-header.md`](docs/server-timing-header.md) has the rest,
including why the layer names are mostly left alone.

The per-layer numbers (database, cache, search, outbound HTTP) come from the Tideways
extension's `getLayerMetrics()`, which is why that extension is the one optional
requirement. The configuration page reports which state the source is in: measuring,
installed but too old (pre-5.23), or not installed.

Settings, all per sales channel:

| Setting | Default | What it does |
|---|---|---|
| `serverTiming` | on | the header itself |
| `serverTimingHost` | off | adds `fm-host`: the first label of `gethostname()`, or `FASTMON_SERVER_NAME` |
| `serverTimingLoggedIn` | off | adds `fm-loggedin` |

The two opt-ins say something about the merchant's infrastructure or about the visitor
rather than about the request, which makes sending them a decision rather than a
measurement. `fm-loggedin` in particular: this header is classified as server
self-measurement carrying no visitor entropy, and on that basis it is collected in every
privacy mode, including the cookieless one, without consent.

## Where the tracker and beacon come from

Ad blockers drop roughly a third of third-party analytics, so the tracker and the beacon
can be served from the shop's own domain instead. One card in the plugin configuration
picks between three modes, mirroring fastmon's own:

| Mode | Where `/s/` and `/c/` come from | Needs |
|---|---|---|
| **fastmon's collector** | `fastmon.site` | nothing, works everywhere |
| **Your own domain** | one host you forward, e.g. `metrics.example.com` | that host proxying to fastmon |
| **Same origin as the page** | each storefront's own domain | every storefront domain proxying to fastmon |

The third is the one for a shop with several sales-channel domains: fastmon's `relative`
mode emits a host-less `/c/<hash>` that resolves against whatever origin the page is on, so
one embed is first-party on all of them.

**The plugin does not forward those paths.** A beacon per pageview through PHP-FPM would
put fastmon's latency in front of the shop's worker pool, and on a shop with Varnish or a
CDN in front, where cached pages never reach PHP at all, it would turn "PHP on cache
misses" into "PHP on every pageview". The forwarding belongs in the web server; the
[fastmon docs](https://docs.fastmon.eu/en/guides/first-party-proxy) cover the
configuration.

What the plugin does is refuse a mode it cannot prove. **Check setup** probes the origins
the chosen mode would use, and the apply button stays out of reach until every one of them
answers. The order matters: the collector endpoint is baked into the bundle fastmon serves,
so applying a mode before the proxy exists makes every tracker in every browser post into a
404, with no error and nobody noticing for days. Going back to fastmon's collector needs no
proof and always works.
[`docs/collection-endpoint-probe.md`](docs/collection-endpoint-probe.md) explains what the
two probes prove and what they deliberately do not.

**The proxy secret.** The panel generates the `pk_` secret the proxy must send in
`FM-Proxy-Key`. Without it fastmon's edge will not trust the `X-Forwarded-For` the proxy
forwards, and every visitor ends up carrying the shop server's address: one country, one
pseudonymous identity, collected and plausible and wrong. The plugin cannot verify the
secret, because a wrong one degrades silently rather than failing, so check the geo
breakdown in the dashboard after switching over. The proxy must also forward the visitor's
`User-Agent`, which is what fastmon derives browser, OS and device from. Rotating is
generating again; the previous secret stays valid, so a proxy config can be updated without
a gap.

## Storefront settings

| Setting | Default | Scope |
|---|---|---|
| `active` | on | per sales channel, leave one storefront untracked |
| `enableErrorBootstrap` | on | catches errors thrown before the tracker loads |
| `enablePixel` | on | no-JS pixel for crawlers and link previews |
| `sourceHash` / `collectorHash` | not set | written when you link an application |

Both snippets are gated on `sourceHash`, which is only ever written once an application has
actually been linked, so an unconfigured shop renders nothing rather than a script tag
pointing at an empty hash.

## Where the credentials are stored

In a table of the plugin's own, `fastmon_collector_connection`: one row, created by one
migration and dropped again when the plugin is uninstalled without *keep user data*. Not in
`system_config`, which is memoised once per request, tagged into the page cache, and
readable through Shopware's generic system-config endpoint by anyone holding
`system_config:read`. Each of those is right for a setting and wrong for a token.

The entity admits the system scope only, so `GET /api/fastmon-collector-connection` answers
`403 Forbidden` whatever privileges the user holds, and the plugin's own routes report
whether a credential exists and what it may do, never what it is.
[`docs/credential-storage.md`](docs/credential-storage.md) has the reasoning, including why
the tokens are not encrypted.

`sourceHash` and `collectorHash` do stay in `system_config`: they are configuration in the
full sense, they are read on every page, and Shopware dropping the cached pages that carry
the old hash when they change is the point rather than a side effect to avoid.

## Environment

| Variable | What it is for |
|---|---|
| `LOCK_DSN` | Shopware's own: a lock store every node reaches. Required on more than one node |
| `FASTMON_SERVER_NAME` | the name `fm-host` reports, for orchestrated setups where the hostname changes on every deploy. Deliberately not a plugin setting: `system_config` is shared by every node, which is the opposite of what the dimension is for |
| `FASTMON_OAUTH_REDIRECT_URI` | where fastmon returns to, when the administration is not served at `APP_URL` plus its usual path |
| `FASTMON_API_BASE_URL` | `https://api.fastmon.eu` by default; point it at a local backend or a stub to develop |
| `FASTMON_APP_BASE_URL` | `https://app.fastmon.eu` by default, the dashboard the panel links to |

Every resource call goes to `https://api.fastmon.eu/v1/...`. The OAuth endpoints are not
reached that way at all: they are read from the discovery document at
`/.well-known/oauth-authorization-server` ([RFC 8414][rfc8414]), so nothing about where
they live is compiled into a shop that cannot be updated. The base URL is not a setting,
because there is one production fastmon and a wrong value is a connection that fails in a
way no merchant can diagnose.

## Development

[`AGENTS.md`](AGENTS.md) has the engineering rules and the quality gates.

```bash
composer install
composer ci                  # php-cs-fixer, phpstan (level max), phpmd, unit suite
shopware-cli extension validate --full --check-against highest .
```

The unit suite needs nothing but the plugin's own Composer dependencies: no shop, no
database. The integration suite boots a real Shopware kernel and runs from inside a
Shopware project with the plugin under `custom/plugins/FastmonCollector`:

```bash
vendor/bin/phpunit -c custom/plugins/FastmonCollector/phpunit.xml.dist --testsuite integration
```

`tests/TestBootstrap.php` uses Shopware's `TestBootstrapper`, which installs a separate
`<database>_test` on the first run (a few minutes) and keeps the plugin installed and
active in it. The same bootstrap serves CI. Inside a project, `FASTMON_TEST_MODE=unit` runs
the unit suite without booting the kernel. The dev container ships a full Shopware 6.7 to
test against, see [`.devcontainer/README.md`](.devcontainer/README.md).

A release is a tag. [`.github/workflows/release.yml`](.github/workflows/release.yml)
refuses one that disagrees with `composer.json`, runs the same gates a pull request runs,
then builds the zip and attaches it to the GitHub release.

[rfc8414]: https://datatracker.ietf.org/doc/html/rfc8414
