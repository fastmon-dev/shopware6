# Upstream Node 22: copied in below so we don't pull Microsoft images. Needed
# by shopware-cli for `extension build` (admin / storefront JS) and for Claude
# Code. Digest-pinned to defend against tag hijacking; bump deliberately via
# `docker pull node:22-bookworm-slim` + inspect.
FROM node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5 AS node

# Composer 2 binary from the official image (no installer script).
FROM composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

# mlocati's install-php-extensions: the standard way to add PHP extensions to
# the official php image without hand-rolling apt + docker-php-ext-install.
FROM mlocati/php-extension-installer:latest@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 AS phpext

# Upstream PHP 8.3 CLI on bookworm: same Debian base as the node stage so the
# binaries we copy in are ABI-compatible. 8.3 sits inside Shopware 6.6/6.7's
# supported PHP range (>= 8.2) and matches what the shop container runs.
FROM php:8.3-cli-bookworm@sha256:177529735599a8244b2c903522f029839dce1c2ac4be122fdc00ada4b45a20e4

# PHP extensions shopware/core's composer.json requires (so `composer install`
# in the plugin resolves without --ignore-platform-reqs) + pcov for coverage.
COPY --from=phpext /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl zip gd bcmath pdo_mysql sodium opcache pcov \
 && echo 'memory_limit=-1' > /usr/local/etc/php/conf.d/zz-dev.ini

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Node 22: binaries + bundled npm from the official node image.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# git + ca-certificates for tooling; sudo so the dev user can chown mounted
# volumes during postCreateCommand; sshpass + openssh-client because the dev
# container drives the shop container's bin/console over dockware's built-in
# SSH (password auth, see sw.sh): the alternative would be a docker socket
# mount, which this stack deliberately avoids. unzip for composer dist installs.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      fish \
      git \
      gnupg \
      jq \
      make \
      openssh-client \
      sshpass \
      sudo \
      unzip \
      vim \
 && rm -rf /var/lib/apt/lists/*

# shopware-cli (Go binary) from the GitHub release, version-pinned and verified
# against the release's checksums.txt. TARGETARCH is set by BuildKit
# (amd64 / arm64); the release names the amd64 tarball "x86_64". Bump the
# version deliberately; check https://github.com/shopware/shopware-cli/releases.
ARG SHOPWARE_CLI_VERSION=0.18.3
ARG TARGETARCH
RUN set -eu; \
    case "$TARGETARCH" in \
      amd64) arch=x86_64 ;; \
      arm64) arch=arm64 ;; \
      *) echo "unsupported TARGETARCH: $TARGETARCH" >&2; exit 1 ;; \
    esac; \
    base="https://github.com/shopware/shopware-cli/releases/download/${SHOPWARE_CLI_VERSION}"; \
    tmp="$(mktemp -d)"; cd "$tmp"; \
    curl -fsSLO "${base}/shopware-cli_Linux_${arch}.tar.gz"; \
    curl -fsSLO "${base}/checksums.txt"; \
    sha256sum --check --ignore-missing checksums.txt; \
    tar -xzf "shopware-cli_Linux_${arch}.tar.gz" shopware-cli; \
    install -m 0755 shopware-cli /usr/local/bin/shopware-cli; \
    cd /; rm -rf "$tmp"; \
    shopware-cli --version

# Non-root dev user. Sudo is scoped to the single chown invocation needed by
# the postCreateCommand to take ownership of named-volume mount points, because Docker
# creates these as root, so the dev user can't write to them without help. Any
# other root operation requires rebuilding the image.
RUN useradd -m -u 1000 -s /usr/bin/fish dev \
 && echo 'dev ALL=(root) NOPASSWD: /usr/bin/chown dev\:dev /workspaces/shopware_plugin /workspaces/shopware_plugin/vendor /home/dev/.cache/composer /home/dev/.claude' > /etc/sudoers.d/dev \
 && chmod 0440 /etc/sudoers.d/dev \
 && visudo -c -f /etc/sudoers.d/dev

# Claude Code. Auth survives rebuilds via two bind mounts (see
# docker-compose.yml): /home/dev/.claude for the credentials directory and
# /home/dev/.claude.json for the account / onboarding state sibling file.
RUN npm install -g @anthropic-ai/claude-code

# SSH config: container-local (not mounted from host) so we explicitly limit
# which identities ssh offers. `IdentitiesOnly yes` + only the deploy key
# means git push/pull can't accidentally pick up another mounted key: the
# signing key is invisible to the ssh auth flow.
#
# `StrictHostKeyChecking accept-new` silently trusts the github.com host key on
# first contact, then rejects any subsequent change. known_hosts lives on the
# image's writable layer (not bind-mounted) so atomic rewrites don't trip on
# cross-device link() errors.
RUN install -d -m 0700 -o dev -g dev /home/dev/.ssh /home/dev/.cache/composer \
 && chown dev:dev /home/dev/.cache /home/dev/.cache/composer \
 && printf 'Host github.com\n    HostName github.com\n    User git\n    IdentityFile /home/dev/.ssh/id_fastmon_shopware_plugin\n    IdentitiesOnly yes\n    StrictHostKeyChecking accept-new\n' > /home/dev/.ssh/config \
 && chmod 0600 /home/dev/.ssh/config \
 && chown dev:dev /home/dev/.ssh/config

# Bootstrap + shop helper baked into the image so they work even though the
# workspace volume starts empty, and can be re-run if the create hook never
# fired. See devcontainer.json postCreateCommand.
#   fastmon-shopware-plugin-bootstrap  chown + git init/fetch + composer install
#   fastmon-sw <args>                  bin/console <args> inside the shop container
COPY bootstrap.sh /usr/local/bin/fastmon-shopware-plugin-bootstrap
COPY sw.sh /usr/local/bin/fastmon-sw
RUN chmod 0755 /usr/local/bin/fastmon-shopware-plugin-bootstrap /usr/local/bin/fastmon-sw

# A fresh named volume inherits ownership from the image directory it mounts
# over, so create the workspace dev-owned here; otherwise the empty volume
# mounts as root and the postCreate bootstrap (run as dev) can't write to it.
RUN mkdir -p /workspaces/shopware_plugin && chown dev:dev /workspaces/shopware_plugin

# Git change detection: compare only mtime+size (core.checkStat=minimal). The
# default also compares ctime/ino/dev/uid/gid, which are unstable across the
# OrbStack/virtiofs mount (MOUNT mode): git then reports the whole tree as
# modified on every stat. System scope so it applies to every checkout.
RUN git config --system core.checkStat minimal

USER dev
