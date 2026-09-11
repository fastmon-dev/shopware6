# The Server-Timing header, plugin side

Which names the plugin puts into `Server-Timing` and why, and which of the collector's
limits it does not try to arbitrate. Implemented by
`Fastmon\Collector\ServerTiming\ServerTimingHeaderBuilder`.

The full contract lives with the collector, `docs/server-timing-setup.md` in the fastmon
backend. This file records the decisions on the plugin's side of it.

## Format

Comma separated `name;dur=<ms>` entries per the W3C Server Timing specification,
optionally `;desc=<text>`. Browsers show them in the network panel, and fastmon reads them
from `performance.getEntriesByType("navigation")[0].serverTiming`.

## Why the layer names are mostly left alone

fastmon's collector already recognises the Tideways vocabulary and promotes it into the
dashboard columns itself (`rdbms` to `db_dur`, `redis` to `kv_dur`, `elasticsearch` to
`search_dur`, `http` to `http_dur`). Renaming everything to the first-party `fm-*` aliases
would gain nothing and would *lose* the per-layer drill-down, because several layers map
into the same summed column: two `fm-kv` entries are one number, while `redis` plus
`memcache` is that same number and the split that explains it.

So `fm-*` is used only where there is no native equivalent to lean on:

- `fm-backend` for total PHP wall time. Our own measurement, and the one entry the layers
  below are a share of.
- `fm-origin-cache` for the full-page-cache verdict, which no profiler reports, and
  `fm-origin-age` for how old the served copy was. Sent one after the other, see below.
- `fm-host` for the machine that answered. A name, never a duration.

## Why the pair says `origin`, and why the age is a `desc`

`origin` names the tier, the way `origin_cache_status`, `origin_dur` and `origin_host` do
on the collector's side. It is not cosmetic: a shop behind a CDN has two caches and two
ages, the edge's and its own, and an unqualified name does not say which one arrived.

The age travels as a `desc` because it is in seconds and a `dur` is in milliseconds. As a
`dur` it would read as a layer that took 312ms rather than a page that was five minutes
old, and sending the milliseconds instead is no way out either: the collector drops any
`dur` above ten million as a mistaken timestamp, which is under three hours and well
inside what a full page cache serves. As a `desc` it is a number the collector reads as a
number and a browser's network panel shows as a label, which is what it is.

## Why nothing here arbitrates the collector's caps

The collector keeps at most 32 entries per pageview and, of those, at most 8 whose names
are outside its catalog. Neither limit needs a policy on this side. Every layer Tideways
reports is either promoted into a column (`rdbms`, `redis`, `http`, and so on) or listed in
that catalog (`autoloading`, `compiling`, `gc`, `disk`, and so on), so the layers reach the
second limit not at all.

The `fm-origin-*` pair is the exception, and it is a named one: until the collector
promotes those two names they are outside its catalog, so on a cache hit they take two of
those eight slots. That is affordable because a hit is the response with the fewest entries
to begin with, no layer timings exist for a page nobody rendered. And where a third-party
provider does emit a foreign vocabulary, the collector fills the rest of that budget in the
order the header arrives, which is the order the builder writes: slowest first.

## Where the header is written

Not in `kernel.response`, because Shopware's HTTP cache sits outside the kernel. The
reasoning belongs with the listener that makes the choice and stays there, at
`Fastmon\Collector\Subscriber\ServerTimingSubscriber`.

## Why the layer breakdown needs Tideways

The per-layer numbers come from `\Tideways\Profiler::getLayerMetrics()`, which hands back
the wall time the extension already aggregated for this request. That API is the whole
reason Tideways is the requirement, and it is the only one of its kind:

- **OpenTelemetry cannot supply them.** Spans go to whatever processors existed when the
  TracerProvider was built, and Shopware's integration (`shopware/opentelemetry`) relies on
  auto-instrumentation through the `opentelemetry` extension, which builds that provider
  during composer autoloading, before any plugin exists. There is no documented way to add
  a processor afterwards and no way to read spans back out. Its instrumentation does
  collect controller, HTTP-client and MySQL timings; they go to the OTLP exporter, not
  anywhere a plugin can reach. The same reasoning sits on
  `Fastmon\Collector\ServerTiming\LayerMetricsProviderInterface`, which is where a second
  source would be added.
- **Measuring the database ourselves is closed too.** Shopware builds its connection in
  `Kernel::boot()` via `MySQLFactory::create()` with no middlewares, long before the
  container exists, so a plugin cannot register a DBAL middleware.

Without Tideways the plugin still reports the seven entries that need no profiler. The
configuration page distinguishes measuring, installed but too old (pre-5.23), and not
installed, because a boolean cannot tell the last two apart and they are different
afternoons.

One source at a time, deliberately: two profilers measure overlapping things with
different boundaries, and summing two views of the same database time would report more
`db` than the request it sits in.
