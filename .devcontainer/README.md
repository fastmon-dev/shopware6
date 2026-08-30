# Shopware Plugin Dev Container

A self-contained development environment for the fastmon Shopware 6 plugin,
**plus a real shop to test it in**. Two containers on one Compose network:

| Service    | Container                 | What it is                                                                                                                                                          |
|------------|---------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `plugin`   | `fastmon-shopware-plugin` | The dev container you attach to. PHP 8.3 CLI (with Shopware's required extensions), Composer 2, Node 22, `shopware-cli` 0.18.3, Claude Code. Runs the plugin's own toolchain. |
| `shopware` | `fastmon-shopware`        | `dockware/shopware:6.7.13.1` — complete Shopware 6.7.13.1 (Apache, MySQL, Mailcatcher, SSH) with this repo mounted as `custom/plugins/<PluginName>`. Reachable at http://localhost. |

The dev container has **no docker socket**; it talks to the shop over dockware's
built-in SSH via the `fastmon-sw` helper (`fastmon-sw cache:clear` ≙
`bin/console cache:clear` inside the shop).

## Architecture

- **Compose-based** (`docker-compose.yml` + `devcontainer.json`) — the monorepo
  standard, matching `backend/` and `frontend/`.
- **Workspace mode — one knob** in `devcontainer.json`'s `dockerComposeFile`:
  - **Mount mode (default)** — add `docker-compose.mount.yml` to bind your host
    checkout into **both** containers: as the workspace here and as the plugin
    directory in the shop. One edit, live in both, no sync step. The OrbStack
    virtiofs rename race is defused by `core.checkStat=minimal` (system-wide in
    the `Dockerfile`).

    ```jsonc
    "dockerComposeFile": ["docker-compose.yml", "docker-compose.mount.yml"]
    ```
  - **Clone mode (fallback)** — drop the overlay; workspace lives on a named
    volume (`fastmon-shopware-plugin-src`) that is likewise mounted into the
    shop, bootstrapped via `git init`+`fetch`.

    ```jsonc
    "dockerComposeFile": ["docker-compose.yml"]
    ```
- **`vendor/`** lives on its own named volume in the dev container (a
  `composer install` here pulls `shopware/core` + `storefront` as dev deps so
  phpunit/phpstan see the real classes, like `shopware-sctracking` does). Inside
  the **shop** the same path is **shadowed by an empty volume** — the shop must
  never see a second copy of Shopware's classes. `.git` is hidden from the shop
  too (dockware's recommendation).
- **Plugin directory name** = the plugin's technical name (class name from
  `composer.json` → `extra.shopware-plugin-class`). Until the plugin is named it
  defaults to the placeholder `FastmonCollector`; override in your host shell:

  ```sh
  export FASTMON_PLUGIN_NAME=MyPluginName
  ```
- **Siblings mounted read-only** for grep/reference without leaving the
  container: `backend/` (tracker source, `/c/` collector + `/s/` source routes,
  beacon schema), `platform-fastmon-collector-app/` (the Shopware *app*
  variant: manifest, Twig snippets, app server) and `shopware-sctracking/` (a
  finished Shopware *plugin*: plugin class, subscribers, phpunit/phpstan setup).
  `docs/` is mounted read-write.
- **Git auth** uses the per-repo deploy key `~/.ssh/id_fastmon_shopware_plugin`;
  commit **signing** uses the shared `~/.ssh/id_fastmon_signing`.

## One-time host setup

Run the init script **on your Mac** (not in the container). Idempotent:

```sh
./.devcontainer/init.sh
```

It checks / sets up:

1. **Dedicated Claude store** — `~/.devcontainer/fastmon-shopware-plugin/.claude{,.json}`,
   separate from the global `~/.claude`. The script creates it.
2. **Deploy key** — `~/.ssh/id_fastmon_shopware_plugin`, a deploy key on
   `fastmon-dev/shopware-plugin` (assumed repo name — adjust `bootstrap.sh` if
   the repo ends up elsewhere).
3. **Signing key** — `~/.ssh/id_fastmon_signing` (+ `.pub`).
4. **Git identity** — host `~/.gitconfig` `user.name`, plus `FASTMON_GIT_EMAIL`
   exported in your host shell.
5. **Sibling checkouts** next to `shopware_plugin/`.
6. **Host port 80** free for the shop (see *Ports* below if it isn't).

## Opening

Open the folder in your dev-container-capable editor and reopen in the
container. On first create the bootstrap runs automatically via
`postCreateCommand`; if your editor doesn't run it, do it once by hand:

```sh
fastmon-shopware-plugin-bootstrap
```

It `chown`s the volume mountpoints, does the `git init`+`fetch` in clone mode,
runs `composer install` if a `composer.json` exists, and writes the
container-only Claude permissions (`.claude/settings.local.json`, gitignore it).

**First start is slow**: Compose pulls `dockware/shopware` (~1.8 GB) and the
image initialises MySQL + Shopware on boot. `fastmon-sw --wait` blocks until
the storefront answers.

## Testing the plugin in the shop

```sh
fastmon-sw --wait                                   # shop booted?
fastmon-sw plugin:refresh                           # Shopware scans custom/plugins
fastmon-sw plugin:install --activate <PluginName>
fastmon-sw cache:clear
```

Then in your browser:

- Storefront: http://localhost
- Admin: http://localhost/admin — `admin` / `shopware`
  (plugin config under *Extensions → My extensions → … → Configure*, per sales channel)
- Mailcatcher (every mail the shop sends): http://localhost:1080

After Twig/PHP changes: `fastmon-sw cache:clear` (dockware runs with template
caching; PHP changes are picked up on the next request). `fastmon-sw --shell`
drops you into `/var/www/html` inside the shop for anything else
(`bin/console`, `tail -f var/log/*.log`, `bin/build-storefront.sh`, …).

### Wiring the storefront to a local fastmon backend

The snippets the plugin injects load the tracker/pixel from a **script base
URL** that the *browser* must reach — so for a local backend it's the URL the
backend is published on for your Mac (e.g. `http://localhost:8000`), not a
Compose service name. Set that in the plugin config; the backend's `/c/` and
`/s/` routes allow any origin, so no CORS work is needed.

### Plugin toolchain (dev container)

```sh
composer install                              # once; shopware/core lands on the vendor volume
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
shopware-cli extension validate --full --check-against highest .
shopware-cli extension zip . --release        # store-ready zip
```

## Ports

Published by Compose (no editor forwarding needed):

| Host                     | Container        | Override                                   |
|--------------------------|------------------|--------------------------------------------|
| `80` → http://localhost  | shopware `:80`   | `FASTMON_SHOP_PORT=8090`                   |
| `1080`                   | mailcatcher      | `FASTMON_SHOP_MAIL_PORT`                   |

The image ships with the sales-channel domain `http://localhost`. If you move
the shop off port 80, tell Shopware once after the first boot:

```sh
fastmon-sw sales-channel:update:domain http://localhost:8090
```

## Testing against another Shopware version

`dockware/shopware` is tagged by Shopware version (`6.7.13.1`, `6.6.10.23`,
`6.7-latest`, …). The default is digest-pinned; to try another release:

```sh
export FASTMON_SHOPWARE_IMAGE=dockware/shopware:6.6.10.23
docker compose -f .devcontainer/docker-compose.yml up -d shopware
```

Shop data lives in the `fastmon-shopware-mysql` volume — switching major
versions on the same volume is not supported by the image; remove the volume
first (see below). For Shopware < 6.7 dockware only publishes the legacy
`dockware/dev` image.

## Troubleshooting

- **`address already in use` on `docker compose up`** — port 80 (or 1080) is
  taken on the host. Set `FASTMON_SHOP_PORT` / `FASTMON_SHOP_MAIL_PORT` and
  update the sales-channel domain (see *Ports*).
- **`fastmon-sw` hangs / "Connection refused"** — the shop hasn't finished
  booting; `fastmon-sw --wait`. Check `docker logs -f fastmon-shopware` on the
  host.
- **Plugin not listed after `plugin:refresh`** — the directory name under
  `custom/plugins/` must equal the plugin class name; check
  `FASTMON_PLUGIN_NAME` and that `composer.json` has
  `"type": "shopware-platform-plugin"` + `extra.shopware-plugin-class`.
- **Class redeclared / autoload errors in the shop** — the vendor shadow volume
  is missing; make sure the `…/vendor` line is present for the `shopware`
  service in *both* compose files.
- **Workspace empty except `vendor`** — the bootstrap didn't run; run
  `fastmon-shopware-plugin-bootstrap`.
- **`git@github.com: Permission denied (publickey)`** — the deploy key isn't
  mounted or isn't on the repo. Verify with
  `ssh -i ~/.ssh/id_fastmon_shopware_plugin -o IdentitiesOnly=yes -T git@github.com`.
- **Config change not picked up (Zed)** — Zed doesn't rebuild automatically.
  `docker compose -f .devcontainer/docker-compose.yml down`, then reopen.
- **Clean rebuild** — a plain rebuild keeps the volumes. Factory-fresh shop
  (drops the shop DB) and/or fresh plugin deps:

  ```sh
  docker compose -f .devcontainer/docker-compose.yml down
  docker volume rm fastmon-shopware-mysql                 # fresh shop
  docker volume rm fastmon-shopware-plugin-vendor \
                   fastmon-shopware-plugin-composer-cache # fresh composer deps
  docker volume rm fastmon-shopware-plugin-src            # clone-mode workspace — discards uncommitted work!
  ```
