# Proving the collection endpoints

Why `Fastmon\Collector\Collection\EndpointChecker` probes what it probes, and why it
accepts what it accepts. The class itself is small; the decisions behind it are not, and
they are the kind that get quietly reverted by someone who assumes an oversight.

The merchant-facing half is in [`README.md`](../README.md), *Collection*. This file is for
whoever changes the probe, the modes, or the forwarding rules the probe checks.

## Why the plugin does not forward the paths itself

`/s/<hash>.js` and `/c/<hash>` are served by the merchant's web server, forwarded to
fastmon. The plugin only checks whether that forwarding exists.

A beacon per pageview through PHP-FPM would put fastmon's latency in front of the shop's
worker pool, and on a shop with Varnish or a CDN in front, where cached pages never reach
PHP at all, it would turn "PHP on cache misses" into "PHP on every pageview". The
forwarding belongs in the web server. This is the same rule
`StorefrontPathIsIsolatedTest` enforces structurally for the rest of the plugin, and
[`AGENTS.md`](../AGENTS.md) states as rule 2.

## Which origins get probed

`RELATIVE` resolves against whatever origin the page is on, so every storefront domain
has to serve the paths. A shop with three domains where only one is configured would
collect nothing from the other two, silently, because the snippet is present and the
tracker simply posts into a 404. `CUSTOM` pins one host, so there is exactly one origin to
prove. `DEFAULT` points at fastmon and has nothing to prove.

Which domains count as a storefront origin, and why three filters are applied to the list,
is documented at `EndpointChecker::storefrontOrigins()`.

## Why these two probes and no others

The script probe is the strong one and it costs nothing: `/s/<hash>.js` is a plain GET
that fastmon renders from cache, and the body it returns has to contain
`/c/<collector hash>`, because that is the endpoint fastmon bakes into the bundle. A
response carrying that string cannot be a shop's 404 page, a catch-all route answering
`200 OK` with HTML, or another application's bundle.

The collector probe deliberately uses a hash that belongs to nobody
(`EndpointChecker::UNROUTABLE_HASH`). `/c/<hash>` always answers with a 1x1 GIF, whatever
happens behind it, so the GIF proves the path reached fastmon, and an unknown hash means
the beacon is dropped before anything is recorded. Probing with the real hash would work
too, and would write a synthetic pageview into the customer's data every time someone
pressed the button.

## Why private origins are not rejected

The custom domain is typed by an administrator and then fetched from the shop server,
which is the shape of a request-forgery surface: `http://169.254.169.254` or an internal
host would be probed, and the transport error comes back as `detail`. It is left as it is,
deliberately.

The caller holds `system_config:update`, and that right already lets them point a
sales-channel domain anywhere, which is the same set of origins this class probes in
`RELATIVE` mode. Blocking private ranges here would take nothing away from such an account
and would break every shop that legitimately lives on one: a staging system, an intranet
shop, the dev container, all of which need the probe most.

And `detail` is the diagnosis. "Could not resolve host" versus "HTTP 502" is the
difference between a DNS entry and a proxy rule, which is what the merchant is here to
find out.
