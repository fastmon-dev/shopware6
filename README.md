# FastmonCollector — fastmon.eu real user monitoring for Shopware 6

Collects Core Web Vitals, JavaScript errors and per-layer backend timings from real
visitors and reports them to [fastmon.eu](https://fastmon.eu). EU-hosted, cookieless.

| | |
|---|---|
| Shopware | 6.6.x or 6.7.x |
| PHP | 8.2+ |
| Server-Timing layer breakdown (optional) | Tideways PHP extension **5.23+** — everything else needs none |

## What it does

**Injects the fastmon snippets** into the storefront: the tracker bundle, an early-error
bootstrap that catches JavaScript errors thrown before the tracker loads, and a no-JS
pixel for clients that never run scripts at all.

**Publishes a `Server-Timing` header** so fastmon can correlate backend phases with the
Web Vitals of the very same pageview — including the full-page-cache verdict, which needs
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

The plugin uses the OAuth 2.0 **Device Authorization Grant** ([RFC 8628][rfc8628]): it
shows a short code, you approve it in the fastmon dashboard in your own browser, and the
shop picks up the token by itself.

This is not the grant the fastmon Shopware *app* uses, and the difference is not a
preference. The app runs the Authorization Code grant as a *confidential* client: its app
server holds a `client_secret` and has one fixed, pre-registered `redirect_uri`. A plugin
has neither — it runs on your server, under a domain fastmon cannot register in advance,
and a secret shipped inside a Store zip is a secret in every shop that downloaded it. The
device grant is the member of the same OAuth family designed for exactly that client.

**If your fastmon instance does not serve that grant yet**, the panel says so and offers a
token field instead: create an API token in the fastmon dashboard and paste it in.
Everything after a token exists is identical either way. See
[`docs/fastmon-backend-device-flow.md`](docs/fastmon-backend-device-flow.md) for what the
backend needs.

### What a merchant needs on the fastmon side

| | |
|---|---|
| An account and an organisation | registration and org creation are open to everyone |
| **An approved organisation** | fastmon reviews new accounts before they may collect data |
| Role **owner** or **member** | `app:write` is not in the viewer bundle |

The approval gate is the one that surprises people. Connecting works immediately; creating
an application answers `403 organization_not_approved` until the organisation passes
review. Approval comes from an admin decision, a partner provisioning the client, or the
first successful payment — the plugin renders this as a waiting state rather than an
error, because there is nothing for the merchant to fix or retry.

There is no plan limit on applications. `environment=prod` is billed, `dev` is not.

When pasting a token instead of using the guided flow, it needs the scopes **`app:read`**
and **`app:write`**, bound to the organisation the shop should report to.

### How the plugin reacts to fastmon's errors

| Response | Meaning | What the plugin does |
|---|---|---|
| `403 organization_not_approved` | the organisation is still under review | renders a waiting state, **keeps the token** |
| `403 permission_denied` | the token lacks a permission | names it from `details.permission` so it can be added |
| `404 organization_not_found` | the account is no longer a member | asks the merchant to connect again |
| `401 credential_expired` | the token reached its expiry | says so, asks to connect again |
| `401` otherwise | revoked, or the app was disconnected | asks the merchant to connect again |

The distinction that matters is the first row: it is the only failure where the token is
still good. Discarding it there would turn a wait into a reconnect the merchant cannot
complete either, and a freshly registered account runs into it every time.

### Choosing an application

Once connected, the panel shows the organisation fastmon carried through the grant - the
one the merchant approved on fastmon's own consent screen - and offers to link an existing
application or create one. Against a fastmon instance that does not carry the organisation
yet, the panel asks for it instead. Creating one sets:

| | |
|---|---|
| `site_policy` | `auto` — every new domain becomes a Site on its first beacon |
| `pagetype_ruleset` | `shopware6` — page types classified from the storefront's body classes |
| `environment` | your choice; `dev` applications are not billed |
| `preset` | data scope: `minimal`, `standard` (default) or `full` |

`standard` is the PII-free posture: pseudonymous stitch, fetch/XHR aggregates, query key
names and error stack frames, but no message text, no query values and no session write.

The panel verifies both the token **and** the linked application when it opens. An
application deleted in the dashboard, or one whose hashes were rotated, leaves the
storefront serving a snippet that collects nothing — so that state is reported and offers
to link another application, rather than looking healthy or forcing a disconnect that
would throw away a working token.

**Disconnecting removes the token from this shop only.** To revoke it on fastmon's side,
remove the integration under *Connected apps* in the fastmon dashboard.

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
wall time, and `fm-fpc` for the cache verdict.

### The cache verdict, and why this is not a `kernel.response` listener

Shopware's HTTP cache sits *outside* the kernel. On a full-page-cache hit the inner kernel
never runs, so a `kernel.response` listener never fires — and the response that goes out is
the stored one, still carrying the header from whichever request populated the cache. The
browser would receive a complete, plausible and entirely wrong set of backend timings, on
every hit, for as long as the entry lives. On a warm shop that is the overwhelming
majority of pageviews.

This plugin writes in `BeforeSendResponseEvent` instead, which Shopware dispatches on
every main request, hit and miss alike, *after* the response has been written to the
cache. So the header always describes the request actually being answered, and our entries
never end up inside the cached copy. For caches Shopware does not control — Varnish, nginx,
a CDN — stale `fm-*` entries are stripped on the way out; the `fm-` prefix is fastmon's
namespace, so nothing else is ever touched.

`fm-fpc` reports `hit`, `miss`, or `bypass` for a response the cache was never going to
serve (a logged-in customer, a filled cart, a POST). Keeping those out of `miss` is what
stops the cache rate from looking terrible on a shop whose cache is working perfectly.

Both verdicts come from Shopware's own events — `HttpCacheHitEvent` and
`HttpCacheStoreEvent` — rather than from response headers. `Cache-Control` says the
opposite of what it looks like here: Shopware serves a perfectly cacheable storefront
page with `no-cache, private`, because that header governs the *browser* while its own
reverse-proxy cache stores the page regardless. Reading intent out of it reports `bypass`
for every page the cache is actually working on, and `miss` never appears at all.

### What the plugin measures itself

Six entries need no profiler at all, which is what a shop without Tideways gets instead of
nothing:

| Entry | What it is |
|---|---|
| `fm-fpc` | the full-page-cache verdict: `hit`, `miss`, or `bypass` |
| `fm-cacheage` | how old the served copy is, in seconds, on a hit |
| `fm-backend` | total PHP wall time |
| `fm-render` | template render time — **no profiler extension reports this**, so `render_dur` is empty on every Shopware shop without it |
| `fm-pagetype` | the page type, derived from the dispatched route |
| `fm-node` | which machine answered |
| `fm-loggedin` | whether a customer was authenticated |

**`fm-cacheage`** is gated on the hit, not on the header being present: Symfony sets an
`Age` on a miss too, derived from the `Date` header, and that zero would look like a
measurement.

**`fm-pagetype`** produces exactly the values fastmon's `shopware6` body-class ruleset
produces, so the two cannot disagree — which matters because only the ruleset works on a
cache hit, where no controller ran. The route is the more reliable half of that pair: a
theme can change body classes, and search plugins routinely take over the search page.

**`fm-node`** carries only the first label of `gethostname()` — an FQDN would disclose
domain structure and internal naming, `web-01` discloses that the servers are called
`web-01`. `FASTMON_SERVER_NAME` overrides it. It is deliberately **not** a plugin setting: settings live in `system_config`,
the database every node of the cluster shares, so a configured name would be identical on
all of them — the exact opposite of what the dimension is for. On orchestrated setups set
the environment variable to something short and stable (`web-01`): a pod name changes on
every deploy and is long and random enough that fastmon discards it as an identifier.

**`fm-loggedin`** is off by default and reports `yes`/`no`. The bit itself is harmless,
but the header it rides in is classified as server self-measurement carrying no visitor
entropy, and on that basis it is collected in **every** privacy mode — including the
cookieless one, without consent. Switching it on is a decision about that classification.

### Configuration

One switch: **`serverTiming`**, on by default, per sales channel.

There used to be one per entry — total, cache verdict, page type, render, node, login —
and a layer blocklist. They are gone on purpose: every entry is either free (cache verdict
and age, total, node) or measured anyway (render time, page type), so a knob offered a
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

The per-layer numbers — database, cache, search, outbound HTTP — come from the Tideways
extension's `getLayerMetrics()`, which hands back the wall time it already aggregated for
this request. **That API is the whole reason Tideways is the requirement**, and it is the
only one of its kind:

- **OpenTelemetry cannot supply them.** Spans go to whatever processors existed when the
  TracerProvider was built, and Shopware's integration (`shopware/opentelemetry`) relies
  on auto-instrumentation through the `opentelemetry` extension, which builds that
  provider during composer autoloading — before any plugin exists. No documented way to
  add a processor afterwards, no way to read spans back out. Its instrumentation does
  collect controller, HTTP-client and MySQL timings; they go to the OTLP exporter, not
  anywhere a plugin can reach.
- **Measuring the database ourselves is closed too.** Shopware builds its connection in
  `Kernel::boot()` via `MySQLFactory::create()` with no middlewares, long before the
  container exists, so a plugin cannot register a DBAL middleware.

Without Tideways the plugin still reports the five entries above — they need no extension.
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
| **fastmon's collector** | `fastmon.site` | nothing — works everywhere |
| **Your own domain** | one host you forward, e.g. `metrics.example.com` | that host proxying to fastmon |
| **Same origin as the page** | each storefront's own domain | every storefront domain proxying to fastmon |

The third is the one for a shop with several sales-channel domains: fastmon's `relative`
mode emits a host-less `/c/<hash>` that resolves against whatever origin the page is on,
so one embed is first-party on all of them without per-domain configuration.

**The plugin does not forward those paths.** A beacon per pageview through PHP-FPM would
put fastmon's latency in front of the shop's worker pool — and on a shop with Varnish or a
CDN in front, where cached pages never reach PHP at all, it would turn "PHP on cache
misses" into "PHP on every pageview". The forwarding belongs in the web server; the
[fastmon docs](https://docs.fastmon.eu/first-party) cover the configuration.

What the plugin does is refuse a mode it cannot prove. **Check setup** probes the origins
the chosen mode would use, and the apply button stays out of reach until every one of them
answers. For `relative` that means *every* storefront domain: a relative endpoint resolves
per origin, so one unconfigured storefront would collect nothing while looking exactly
like the ones that work.

The order matters. The collector endpoint is baked into the bundle fastmon serves, so
applying a mode before the proxy exists makes every tracker in every browser post into a
404 — no error, no data, and nobody notices for days. Going back to fastmon's collector
needs no proof and always works.

### What the probes actually prove

The script probe is the strong one and costs nothing — a plain GET that fastmon renders
from cache. The body has to contain `/c/<collectorHash>`, because that is the endpoint
fastmon bakes into the bundle, so a catch-all route or a soft 404 answering `200 OK` with
HTML cannot pass.

The collector probe uses a hash that belongs to nobody. `/c/<hash>` always answers with a
1×1 GIF whatever happens behind it, so the GIF proves the path reached fastmon — and an
unknown hash means the beacon is dropped before anything is recorded. Probing with the
real hash would work too, and would write a synthetic pageview into the customer's data
every time someone pressed the button.

### The proxy secret

The panel generates the `pk_` secret the proxy must send in `FM-Proxy-Key`. Without it
fastmon's edge will not trust the `X-Forwarded-For` the proxy forwards, and every visitor
ends up carrying the shop server's address — one country, one pseudonymous identity,
collected and plausible and wrong. **The plugin cannot verify the secret**: a wrong one
degrades silently rather than failing, so check the geo breakdown in the dashboard after
switching over. The proxy must also forward the visitor's `User-Agent`, which is what
fastmon derives browser, OS and device from.

Rotating is generating again; the previous secret stays valid so a proxy config can be
updated without a gap.

## Storefront settings

| Setting | Default | Scope |
|---|---|---|
| `active` | on | per sales channel — leave one storefront untracked |
| `enableErrorBootstrap` | on | catches errors thrown before the tracker loads |
| `enablePixel` | on | no-JS pixel for crawlers and link previews |
| `trackerId` / `pixelId` | — | written when you link an application |

Both snippets are gated on `trackerId`, which only ever gets written once an application
has actually been linked, so an unconfigured shop renders nothing rather than a script tag
pointing at an empty id.

## Where the token is stored

In `system_config`, in plain text, like every other Shopware plugin's API credentials.
Encrypting it would mean keeping a key in `.env`, next to the database credentials that
already grant access to the same table — it would change who can read the token from
"anyone with the database" to "anyone with the database and the application directory",
which is the same person on every shop this will run on.

It is declared as a `password` field so the administration masks it, and the admin API
reports only *whether* one exists, never its value.

## API paths

Every call goes to `https://api.fastmon.eu/v1/…`. fastmon's own notes describe the API as
served unprefixed with `/v1` as a legacy alias, and that is where it is going rather than
where production is: `GET /account` answers 404 today while `GET /v1/account` answers 401.
The prefix stays valid after the unprefixed routes ship, and a plugin in the wild cannot
be redeployed in step with the backend, so `/v1` is the permanent choice rather than a
stopgap.

The base URL is not a setting — one production fastmon, every shop talks to it, and a
wrong value is a connection that fails in a way no merchant can diagnose. Set
`FASTMON_API_BASE_URL` in the environment to develop against a local backend or a stub.

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
`alt`. That is correct markup for a 1×1 beacon carrying no content — the rule cannot tell
a decorative image from an undescribed one, and giving it a description would be wrong.

### PHP version

`composer.json` requires PHP `>=8.2`, lower than the PHP 8.5 baseline the maintainers
use for new code. That is deliberate: a Store plugin cannot raise the floor above what
the Shopware releases it supports run on, and Shopware 6.6 supports PHP 8.2–8.4, 6.7
supports 8.2–8.5. The code is written to stay forward-compatible up to PHP 8.5 — nothing
here relies on a construct that only works on the older end of that range — and CI proves
it: `composer ci` runs on PHP 8.2–8.4 against both Shopware branches and on 8.5 against
6.7, and the integration suite runs against the latest release of each branch.

The dev container ships a full Shopware 6.7 to test against; see
[`.devcontainer/README.md`](.devcontainer/README.md).

```bash
fastmon-sw --wait
fastmon-sw plugin:refresh
fastmon-sw plugin:install --activate FastmonCollector
fastmon-sw cache:clear
```

[rfc8628]: https://datatracker.ietf.org/doc/html/rfc8628
