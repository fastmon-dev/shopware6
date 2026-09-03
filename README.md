# FastmonCollector: fastmon.eu real user monitoring for Shopware 6

Collects Core Web Vitals, JavaScript errors and per-layer backend timings from real
visitors and reports them to [fastmon.eu](https://fastmon.eu). EU-hosted, cookieless.

| | |
|---|---|
| Shopware | 6.6.x or 6.7.x |
| PHP | 8.2+ |
| Multi-node shops | a shared lock store in `LOCK_DSN` (see *Connecting*) |
| Server-Timing layer breakdown (optional) | Tideways PHP extension **5.23+**, everything else needs none |

## What it does

**Injects the fastmon snippets** into the storefront: the tracker bundle, an early-error
bootstrap that catches JavaScript errors thrown before the tracker loads, and a no-JS
pixel for clients that never run scripts at all.

**Publishes a `Server-Timing` header** so fastmon can correlate backend phases with the
Web Vitals of the very same pageview, including the full-page-cache verdict, which needs
no extension and is the single most useful thing a shop can put in that header.

**Connects itself to fastmon**, creates the application, and stores the ids. One
application covers the whole shop: every sales-channel domain registers itself in fastmon
on its first pageview, so there is nothing to add by hand and nothing to revisit when a
domain is added.

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate FastmonCollector
bin/console cache:clear
```

Then open **Settings → Extensions → My extensions → fastmon.eu → Configure** and press
**Connect to fastmon**.

## Connecting

Pressing **Connect to fastmon** sends you to fastmon's own consent screen, where you
approve the connection for one organisation and choose what this shop may do. You land
back in the administration and the shop has its credentials. Nothing is typed in and
nothing secret ships inside the plugin.

Underneath it is the OAuth 2.0 **Authorization Code grant with PKCE**, as a *public*
client. A plugin runs on your server, under a domain fastmon cannot know in advance, and
it ships as readable code through the Store, so it can hold no `client_secret`, and it
has no redirect URI anyone could register centrally. Both follow from that:

- **Each installation registers itself** ([RFC 7591][rfc7591]) and gets its own
  `client_id`, with one exact redirect URI: this shop's own administration. That domain is
  what the approver sees and judges on the consent screen.
- **PKCE ([RFC 7636][rfc7636]) authenticates the exchange**, and nothing else does. The
  verifier is generated on your server, never reaches the browser, and never leaves the
  shop. An intercepted authorization code is worthless without it.

A weekly scheduled task (`fastmon_collector.renew_connection`) spends the refresh token to
buy the next one, which is all it takes to keep a connection alive on a shop nobody
administers: a refresh token expires sixty days after it was issued, and every use resets
that. It needs a running Messenger worker or the scheduled-task cron, like every other
Shopware task.

What the shop ends up holding is a pair: an **access token** valid for fifteen minutes and
a **refresh token that rotates on every use**. The plugin refreshes a minute before expiry
and retries a rejected call once, so neither is visible to anyone. A refresh token works
exactly once: presenting a spent one is how fastmon detects a stolen credential, and it
ends the connection, so the successor is stored before the new access token is used, and
concurrent admin requests are serialised through a lock rather than racing each other into
a false theft signal.

**The lock is Shopware's `lock.factory`, and it is only as shared as `LOCK_DSN` makes
it.** Shopware's default is `flock`, a lock on the local filesystem, which is enough for
one machine and nothing for two. On a multi-node shop set `LOCK_DSN` to a store every node
reaches (Redis, or the database); otherwise two nodes can refresh at the same moment, each
presenting the same refresh token, and fastmon ends the connection because that is what a
stolen token looks like.

The connection belongs to the **organisation**, not to the person who approved it: it
keeps working after they leave, which is what a shop needs. fastmon asks the approver for
`org_key:manage` in return, so an owner or a member can approve it and a viewer cannot.

**Where the redirect lands.** fastmon returns to `APP_URL` + the administration's path, an
absolute `https` URI without wildcards or fragments, which is what registration allows
(plain `http` only on loopback, so local development works). If your administration is
served somewhere else, set `FASTMON_OAUTH_REDIRECT_URI` in the environment.

**If a shop cannot run that**, whether an administration served over plain http or a
fastmon instance without app connections, the panel says so and offers a field for an API key
from the fastmon dashboard instead. A key does not expire and cannot renew itself, which is
why it is second; everything after a credential exists is identical either way. See
[`docs/fastmon-oauth-connection.md`](docs/fastmon-oauth-connection.md) for the contract
between the plugin and the backend.

### What a merchant needs on the fastmon side

| | |
|---|---|
| An account and an organisation | registration and org creation are open to everyone |
| **An approved organisation** | fastmon reviews new accounts before they may collect data |
| Role **owner** or **member** | `app:write` is not in the viewer bundle |

The approval gate is the one that surprises people. Connecting works immediately; creating
an application answers `403 organization_not_approved` until the organisation passes
review. Approval comes from an admin decision, a partner provisioning the client, or the
first successful payment, and the plugin renders this as a waiting state rather than an
error, because there is nothing for the merchant to fix or retry.

There is no plan limit on applications. `environment=prod` is billed, `dev` is not.

The connection asks for **`org:read`**, **`app:read`**, **`app:write`** and
**`site:read`**: what the panel needs to name the organisation, list and configure an
application, and show the domains fastmon has actually seen. Nothing more; the shop
produces measurements and never queries them, so there is no `analytics:read`. The
approver may narrow the list, and the panel reads back what was actually granted rather
than assuming: a connection without `app:write` is flagged before the first write fails on
it. A pasted API key needs the same four, bound to the organisation the shop reports to.

### How the plugin reacts to fastmon's errors

| Response | Meaning | What the plugin does |
|---|---|---|
| `403 organization_not_approved` | the organisation is still under review | renders a waiting state, **keeps the credential** |
| `403 permission_denied` | the connection lacks a permission | names it from `details.permission` so it can be approved |
| `404 organization_not_found` | the account is no longer a member | asks the merchant to connect again |
| `401 credential_expired` | the credential reached its expiry | says so, asks to connect again |
| `401` otherwise | the access token expired, or the connection was ended | refreshes and retries once, then asks to connect again |
| `400 invalid_grant` at the token endpoint | the refresh token is spent, expired or revoked | drops the credential and asks to connect again |

Two rows matter more than the rest. `organization_not_approved` is the only failure where
the credential is still good: discarding it there would turn a wait into a reconnect the
merchant cannot complete either, and a freshly registered account runs into it every time.
`invalid_grant` is the opposite: it cannot be retried under any of its four meanings, and
retrying is exactly what looks like theft from fastmon's side.

### Choosing an application

Once connected, the panel shows the organisation the connection is bound to (the one the
merchant approved on fastmon's own consent screen, so there is no way to approve for one
and report to another) and offers to link an existing application or create one. Creating
one sets:

| | |
|---|---|
| `site_policy` | `auto`: every new domain becomes a Site on its first beacon |
| `pagetype_ruleset` | `shopware6`: page types classified from the storefront's body classes |
| `environment` | your choice; `dev` applications are not billed |
| `preset` | data scope: `minimal`, `standard` (default) or `full` |

`standard` is the PII-free posture: pseudonymous stitch, fetch/XHR aggregates, query key
names and error stack frames, but no message text, no query values and no session write.

The panel verifies both the credential **and** the linked application when it opens. An
application deleted in the dashboard, or one whose hashes were rotated, leaves the
storefront serving a snippet that collects nothing, so that state is reported and offers
to link another application, rather than looking healthy or forcing a disconnect that
would throw away a working credential.

**Disconnecting hands the connection back to fastmon.** The refresh token is revoked, so
the shop cannot rotate its way back in, and the entry disappears from *Organisation
settings → Access* without the merchant having to remove it there. A pasted API key is only
forgotten here: it lives in their dashboard, and this shop has no authority to revoke it.

Uninstalling does not reach fastmon: an uninstall has to finish on a shop with no internet,
so no HTTP call belongs in it. It clears the stored rows; the grant, if one was left
standing, is dropped under *Organisation settings → Access*.

## Server-Timing

Independent of everything above: it needs no fastmon account, sends nothing anywhere, and
only annotates a response that was going out regardless. A shop that never connects still
gets a useful header in devtools.

```http
Server-Timing: fm-fpc;desc=hit, fm-backend;dur=3.1
Server-Timing: fm-fpc;desc=miss, fm-backend;dur=128.4, rdbms;dur=42.5, redis;dur=6.0
```

### Why the layer names are mostly left alone

fastmon's collector already recognises the Tideways vocabulary and promotes it into the
dashboard columns itself (`rdbms` → `db_dur`, `redis` → `kv_dur`, `elasticsearch` →
`search_dur`, `http` → `http_dur`). Renaming everything to the first-party `fm-*` aliases
would gain nothing and would *lose* the per-layer drill-down, because several layers map
into one summed column: two `fm-kv` entries are one number, while `redis` plus `memcache`
is that same number **and** the split that explains it.

So `fm-*` is used only where there is no native equivalent: `fm-backend` for total PHP
wall time, `fm-fpc` for the cache verdict, and `fm-host` for the machine that answered.

The plugin also does not arbitrate the collector's entry caps. It sorts slowest first and
sends; the collector fills its caps in that same order, and every layer Tideways reports
is one it already knows, so there is nothing to protect.

### The cache verdict, and why this is not a `kernel.response` listener

Shopware's HTTP cache sits *outside* the kernel. On a full-page-cache hit the inner kernel
never runs, so a `kernel.response` listener never fires, and the response that goes out is
the stored one, still carrying the header from whichever request populated the cache. The
browser would receive a complete, plausible and entirely wrong set of backend timings, on
every hit, for as long as the entry lives. On a warm shop that is the overwhelming
majority of pageviews.

This plugin writes in `BeforeSendResponseEvent` instead, which Shopware dispatches on
every main request, hit and miss alike, *after* the response has been written to the
cache. So the header always describes the request actually being answered, and our entries
never end up inside the cached copy. For caches Shopware does not control (Varnish, nginx,
a CDN), stale `fm-*` entries are stripped on the way out; the `fm-` prefix is fastmon's
namespace, so nothing else is ever touched.

`fm-fpc` reports `hit`, `miss`, or `bypass` for a response the cache was never going to
serve (a logged-in customer, a filled cart, a POST). Keeping those out of `miss` is what
stops the cache rate from looking terrible on a shop whose cache is working perfectly.

Both verdicts come from Shopware's own events, `HttpCacheHitEvent` and
`HttpCacheStoreEvent`, rather than from response headers. `Cache-Control` says the
opposite of what it looks like here: Shopware serves a perfectly cacheable storefront
page with `no-cache, private`, because that header governs the *browser* while its own
reverse-proxy cache stores the page regardless. Reading intent out of it reports `bypass`
for every page the cache is actually working on, and `miss` never appears at all.

### What the plugin measures itself

Seven entries need no profiler at all, which is what a shop without Tideways gets instead
of nothing:

| Entry | What it is |
|---|---|
| `fm-fpc` | the full-page-cache verdict: `hit`, `miss`, or `bypass` |
| `fm-cacheage` | how old the served copy is, in seconds, on a hit |
| `fm-backend` | total PHP wall time |
| `fm-render` | template render time: **no profiler extension reports this**, so `render_dur` is empty on every Shopware shop without it |
| `fm-pagetype` | the page type, derived from the dispatched route |
| `fm-host` | which machine answered |
| `fm-loggedin` | whether a customer was authenticated |

**`fm-cacheage`** is gated on the hit, not on the header being present: Symfony sets an
`Age` on a miss too, derived from the `Date` header, and that zero would look like a
measurement.

**`fm-pagetype`** produces exactly the values fastmon's `shopware6` body-class ruleset
produces, so the two cannot disagree, which matters because only the ruleset works on a
cache hit, where no controller ran. The route is the more reliable half of that pair: a
theme can change body classes, and search plugins routinely take over the search page.

**`fm-host`** is off by default (`serverTimingHost`, per sales channel): which machine
answered is a fact about the merchant's infrastructure, and only a cluster has a use for
it. Switched on, it carries only the first label of `gethostname()`: an FQDN would disclose
domain structure and internal naming, while `web-01` discloses that the servers are called
`web-01`. `FASTMON_SERVER_NAME` overrides it. The *name* is deliberately **not** a plugin
setting: settings live in `system_config`, the database every node of the cluster shares,
so a configured name would be identical on all of them, the exact opposite of what the
dimension is for. On orchestrated setups set
the environment variable to something short and stable (`web-01`): a pod name changes on
every deploy and is long and random enough that fastmon discards it as an identifier.

**`fm-loggedin`** is off by default and reports `yes`/`no`. The bit itself is harmless,
but the header it rides in is classified as server self-measurement carrying no visitor
entropy, and on that basis it is collected in **every** privacy mode, including the
cookieless one, without consent. Switching it on (`serverTimingLoggedIn`, per sales
channel) is a decision about that classification, which is why it is the one entry that
kept its own switch.

### Configuration

One switch: **`serverTiming`**, on by default, per sales channel. And two opt-ins, both off
by default and per sales channel: **`serverTimingHost`** for `fm-host` and
**`serverTimingLoggedIn`** for `fm-loggedin`. Those two are the entries that say something
about the merchant's infrastructure or the visitor rather than about the request, which
makes sending them a decision rather than a measurement.

There used to be a switch per entry (total, cache verdict, page type, render) and a layer
blocklist. They are gone on purpose: every one of those entries is either free (cache
verdict and age, total) or measured anyway (render time, page type), so a knob offered a
choice nobody has a reason to make, and each one was another way for a shop to report less
than it thinks.

The configuration page reports which sources exist on this host and what each is doing:
measuring, installed but too old, or not installed.

### Entry budget

The collector accepts at most 32 entries per pageview and at most 8 whose names it does
not recognise; the rest are dropped silently. Tideways easily reports a dozen layers
nobody has a column for (`compiling`, `autoloading`, `gc`, `shell`, `sleep`, …), so left
alone the interesting ones would compete with the noise for those 8 slots and lose at
random. The plugin sorts slowest-first and spends that budget deliberately. Layers under
1 ms are dropped, which is the same floor the collector applies.

### The layer breakdown needs Tideways

The per-layer numbers (database, cache, search, outbound HTTP) come from the Tideways
extension's `getLayerMetrics()`, which hands back the wall time it already aggregated for
this request. **That API is the whole reason Tideways is the requirement**, and it is the
only one of its kind:

- **OpenTelemetry cannot supply them.** Spans go to whatever processors existed when the
  TracerProvider was built, and Shopware's integration (`shopware/opentelemetry`) relies
  on auto-instrumentation through the `opentelemetry` extension, which builds that
  provider during composer autoloading, before any plugin exists. No documented way to
  add a processor afterwards, no way to read spans back out. Its instrumentation does
  collect controller, HTTP-client and MySQL timings; they go to the OTLP exporter, not
  anywhere a plugin can reach.
- **Measuring the database ourselves is closed too.** Shopware builds its connection in
  `Kernel::boot()` via `MySQLFactory::create()` with no middlewares, long before the
  container exists, so a plugin cannot register a DBAL middleware.

Without Tideways the plugin still reports the entries above: they need no extension.
The configuration page says which state the source is in: measuring, installed but too old
(pre-5.23), or not installed. A boolean cannot tell the last two apart, and they are
different afternoons.

`LayerMetricsProviderInterface` is the seam if a readable source ever appears. One at a
time, deliberately: two profilers measure overlapping things with different boundaries,
and summing two views of the same database time would report more `db` than the request
it sits in.

## Data collection: where the tracker and beacon come from

Ad blockers drop roughly a third of third-party analytics. Serving the tracker and the
beacon from the shop's own domain avoids that. One card in the plugin configuration picks
between three modes, mirroring fastmon's own:

| Mode | Where `/s/` and `/c/` come from | Needs |
|---|---|---|
| **fastmon's collector** | `fastmon.site` | nothing, works everywhere |
| **Your own domain** | one host you forward, e.g. `metrics.example.com` | that host proxying to fastmon |
| **Same origin as the page** | each storefront's own domain | every storefront domain proxying to fastmon |

The third is the one for a shop with several sales-channel domains: fastmon's `relative`
mode emits a host-less `/c/<hash>` that resolves against whatever origin the page is on,
so one embed is first-party on all of them without per-domain configuration.

**The plugin does not forward those paths.** A beacon per pageview through PHP-FPM would
put fastmon's latency in front of the shop's worker pool, and on a shop with Varnish or a
CDN in front, where cached pages never reach PHP at all, it would turn "PHP on cache
misses" into "PHP on every pageview". The forwarding belongs in the web server; the
[fastmon docs](https://docs.fastmon.eu/en/guides/first-party-proxy) cover the configuration.

What the plugin does is refuse a mode it cannot prove. **Check setup** probes the origins
the chosen mode would use, and the apply button stays out of reach until every one of them
answers. For `relative` that means *every* storefront domain: a relative endpoint resolves
per origin, so one unconfigured storefront would collect nothing while looking exactly
like the ones that work.

The order matters. The collector endpoint is baked into the bundle fastmon serves, so
applying a mode before the proxy exists makes every tracker in every browser post into a
404: no error, no data, and nobody notices for days. Going back to fastmon's collector
needs no proof and always works.

### What the probes actually prove

The script probe is the strong one and costs nothing: a plain GET that fastmon renders
from cache. The body has to contain `/c/<collectorHash>`, because that is the endpoint
fastmon bakes into the bundle, so a catch-all route or a soft 404 answering `200 OK` with
HTML cannot pass.

The collector probe uses a hash that belongs to nobody. `/c/<hash>` always answers with a
1×1 GIF whatever happens behind it, so the GIF proves the path reached fastmon, and an
unknown hash means the beacon is dropped before anything is recorded. Probing with the
real hash would work too, and would write a synthetic pageview into the customer's data
every time someone pressed the button.

### The proxy secret

The panel generates the `pk_` secret the proxy must send in `FM-Proxy-Key`. Without it
fastmon's edge will not trust the `X-Forwarded-For` the proxy forwards, and every visitor
ends up carrying the shop server's address: one country, one pseudonymous identity,
collected and plausible and wrong. **The plugin cannot verify the secret**: a wrong one
degrades silently rather than failing, so check the geo breakdown in the dashboard after
switching over. The proxy must also forward the visitor's `User-Agent`, which is what
fastmon derives browser, OS and device from.

Rotating is generating again; the previous secret stays valid so a proxy config can be
updated without a gap.

## Storefront settings

| Setting | Default | Scope |
|---|---|---|
| `active` | on | per sales channel, leave one storefront untracked |
| `enableErrorBootstrap` | on | catches errors thrown before the tracker loads |
| `enablePixel` | on | no-JS pixel for crawlers and link previews |
| `sourceHash` / `collectorHash` | not set | written when you link an application |

Both snippets are gated on `sourceHash`, which only ever gets written once an application
has actually been linked, so an unconfigured shop renders nothing rather than a script tag
pointing at an empty hash.

## Where the credentials are stored

In a table of the plugin's own, `fastmon_collector_connection`: one row, one column per
field, created by one migration and dropped again when the plugin is uninstalled without
*keep user data*. It holds the registration, the token pair with its expiry, the pasted
key, who approved the connection, what it points at, and the authorization in flight while
the merchant is away at the consent screen.

**Not in `system_config`, and that is the whole point.** That store is built for
configuration: it is memoised once per request, it is tagged into the page cache, it keeps
every value as a JSON-wrapped string, and it is readable through Shopware's generic
system-config endpoint by anyone holding `system_config:read`. Each of those is right for
a setting and wrong for a token, and together they were the reason this plugin needed a
raw SQL read to get an uncached refresh token, a flag to keep writes out of the page
cache, and a JSON blob for the authorization. A row has none of them:

- **Nothing memoises it.** The request that waited for the refresh lock reads what the
  winner stored, which is the difference between a rotated token and a connection fastmon
  ends for reuse.
- **No cached page is tagged with it.** A rotation invalidates nothing, on every supported
  Shopware release, with no flag and no version floor.
- **It is not reachable through the API.** The entity admits the system scope only, so
  `GET /api/fastmon-collector-connection` answers `403 Forbidden` whatever privileges the
  user holds. The plugin's own routes report whether a credential exists and what it may
  do, never what it is.

Two values stay in `system_config` on purpose: `sourceHash` and `collectorHash`, the pair the
storefront templates render. Those are configuration in the full sense, they are read on
every page, and Shopware dropping the cached pages that carry the old hash when they change
is the point rather than a side effect to avoid.

The tokens are in plain text, like every other Shopware plugin's API credentials.
Encrypting them would mean keeping a key in `.env`, next to the database credentials that
already grant access to the same table: it would change who can read them from "anyone
with the database" to "anyone with the database and the application directory", which is
the same person on every shop this will run on.

What did change with app connections is how much a stolen row is worth. The access token
expires in minutes and the refresh token rotates on every use, so a copy taken from a
backup stops working the moment the shop refreshes, and using it announces the theft,
because fastmon ends a connection whose refresh token is presented twice.

None of it is a form field. The fallback key is typed once, behind the *Connect with an
API key instead* link on the connect panel, and is not shown again.

## API paths

Every resource call goes to `https://api.fastmon.eu/v1/…`. fastmon serves the API
unprefixed as well and describes `/v1` as a legacy alias, but a plugin in the wild cannot
be redeployed in step with the backend, and a prefix that works before and after is worth
more than one that is merely newer.

The OAuth endpoints are not reached that way at all. They are read from the discovery
document at `/.well-known/oauth-authorization-server` ([RFC 8414][rfc8414]), which is the
contract fastmon publishes for them, so nothing about where they live is compiled into a
shop that cannot be updated.

The base URL is not a setting: one production fastmon, every shop talks to it, and a
wrong value is a connection that fails in a way no merchant can diagnose. Set
`FASTMON_API_BASE_URL` in the environment to develop against a local backend or a stub.
`FASTMON_APP_BASE_URL` does the same for the dashboard links the panel renders
(`https://app.fastmon.eu` by default), which is a different host and cannot be derived
from the API's. The discovery document has to name the API base URL as its `issuer`, and
every endpoint in it has to live under it; a document that says otherwise is refused.

## Development

```bash
composer install
composer ci                  # php-cs-fixer, phpstan (level max), phpmd, unit suite
composer test-integration    # from inside a Shopware project - see Tests below
shopware-cli extension validate --full --check-against highest .
shopware-cli extension zip . --release
```

The individual steps are `composer cs-check` / `cs-fix`, `phpstan`, `phpmd` and `test`.
`phpmd.xml` says which stock rules are off and why; a method that legitimately exceeds
a threshold carries the reason in its own docblock rather than the threshold being
raised for everyone.

### Tests

Two suites, and they need different things.

The **unit** suite needs nothing but the plugin's own Composer dependencies: no shop, no
database. It covers the header building, the connection and provisioning logic, the
collection-mode guarantee and the structural check that nothing on the storefront path can
reach fastmon.

The **integration** suite boots a real Shopware kernel and covers the places where the
plugin touches Shopware itself: the raw origin query against the actual schema, the ACL
on the admin routes, what the storefront templates render per collection mode, and the
`Server-Timing` header on a real page. It runs from inside a Shopware project with the
plugin under `custom/plugins/`, using the project's PHPUnit:

```bash
vendor/bin/phpunit -c custom/plugins/fastmon-collector/phpunit.xml.dist --testsuite integration
```

`tests/TestBootstrap.php` uses Shopware's `TestBootstrapper`, which installs a separate
`<database>_test` on the first run (a few minutes) and keeps the plugin installed and
active in it. The same bootstrap serves `shopware/github-actions` in CI. Inside a project,
`FASTMON_TEST_MODE=unit` runs the unit suite without booting the kernel.

`shopware-cli extension validate --full` reports one warning on the no-JS pixel's empty
`alt`. That is correct markup for a 1×1 beacon carrying no content: the rule cannot tell
a decorative image from an undescribed one, and giving it a description would be wrong.

### PHP version

`composer.json` requires PHP `>=8.2`, lower than the PHP 8.5 baseline the maintainers
use for new code. That is deliberate: a Store plugin cannot raise the floor above what
the Shopware releases it supports run on, and Shopware 6.6 supports PHP 8.2 to 8.4, 6.7
supports 8.2 to 8.5. The code is written to stay forward-compatible up to PHP 8.5. Nothing
here relies on a construct that only works on the older end of that range, and CI proves
it: `composer ci` runs on PHP 8.2 to 8.4 against both Shopware branches and on 8.5 against
6.7, and the integration suite runs against the latest release of each branch.

The dev container ships a full Shopware 6.7 to test against; see
[`.devcontainer/README.md`](.devcontainer/README.md).

```bash
fastmon-sw --wait
fastmon-sw plugin:refresh
fastmon-sw plugin:install --activate FastmonCollector
fastmon-sw cache:clear
```

[rfc7591]: https://datatracker.ietf.org/doc/html/rfc7591
[rfc8414]: https://datatracker.ietf.org/doc/html/rfc8414
[rfc7636]: https://datatracker.ietf.org/doc/html/rfc7636
