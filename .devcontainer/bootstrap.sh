#!/usr/bin/env bash
# Idempotent devcontainer bootstrap for the fastmon Shopware plugin workspace.
#
# Baked into the image as /usr/local/bin/fastmon-shopware-plugin-bootstrap (see
# Dockerfile) and wired as postCreateCommand. If your editor doesn't run
# lifecycle hooks (or the container was reused so the create hook never fired),
# run it manually once after attaching:
#
#     fastmon-shopware-plugin-bootstrap
#
# Safe to re-run: the clone is skipped once .git exists, so it never clobbers an
# existing checkout or uncommitted work. This sets up the *plugin toolchain*;
# installing the plugin into the shop container is a separate, explicit step
# (via fastmon-sw, see the summary printed at the end) because the shop takes a
# while to boot on first start.
set -euo pipefail

WS=/workspaces/shopware_plugin

# 1. Take ownership of the named-volume mountpoints. Docker creates them owned by
#    the image build UID (1000), but on macOS the dev user is remapped to the
#    host UID, so without this it can't write to them. The argument list must
#    match the NOPASSWD sudoers entry in the Dockerfile exactly.
#    `|| true`: in MOUNT mode $WS is a virtiofs bind (not a named volume) where
#    chown is a no-op / may fail; tolerate that. vendor, the composer cache and
#    .claude are always named volumes, so their chown still applies.
sudo chown dev:dev "$WS" "$WS/vendor" /home/dev/.cache/composer /home/dev/.claude || true

# 2. Bootstrap the empty workspace volume (git init + fetch, NOT clone, because the
#    vendor sub-volume is already mounted here so the dir is never empty and
#    clone would refuse). Skipped once .git exists, so rebuilds keep the
#    checkout incl. uncommitted work. Auth is the repo-scoped deploy key
#    (id_fastmon_shopware_plugin via ~/.ssh/config baked into the image).
if [ ! -d "$WS/.git" ]; then
  cd "$WS"
  git init -q -b main
  git remote add origin git@github.com:fastmon-dev/shopware-plugin.git
  if git fetch --tags origin 2>/dev/null; then
    git checkout -f -B main origin/main
  else
    echo "!! Could not fetch origin, remote not reachable or repo not created yet."
    echo "   Workspace left as an empty git repo; add your files and push when ready."
  fi
fi

# 3. Plugin toolchain: composer install pulls shopware/core + storefront into the
#    vendor volume so phpunit/phpstan/cs-fixer run against the real classes
#    without a Shopware kernel. Skipped if there is no composer.json yet (fresh
#    clone-mode workspace whose remote doesn't exist).
cd "$WS"
if [ -f composer.json ]; then
  composer install --no-interaction --prefer-dist
fi

# 4. Container-only Claude Code permissions. The container is an isolated,
#    throwaway sandbox, so run without prompts here. This file is gitignored,
#    never touches host settings or the committed repo, and survives rebuilds.
#    Written only if absent, so edits you make inside the container are kept.
CLAUDE_SETTINGS="$WS/.claude/settings.local.json"
if [ ! -f "$CLAUDE_SETTINGS" ]; then
  mkdir -p "$WS/.claude"
  cat > "$CLAUDE_SETTINGS" <<'JSON'
{
  "permissions": {
    "defaultMode": "bypassPermissions",
    "additionalDirectories": [
      "/workspaces/backend",
      "/workspaces/platform-fastmon-collector-app",
      "/workspaces/shopware-sctracking",
      "/workspaces/docs"
    ]
  }
}
JSON
fi

PLUGIN="${FASTMON_PLUGIN_NAME:-FastmonCollector}"
echo "fastmon-shopware-plugin devcontainer bootstrap complete."
echo "  Toolchain here:  php, composer, node/npm, shopware-cli (+ vendor/bin/* after composer install)"
echo "  Shop container:  fastmon-sw --wait                       # block until Shopware answers"
echo "                   fastmon-sw plugin:refresh"
echo "                   fastmon-sw plugin:install --activate $PLUGIN"
echo "                   fastmon-sw cache:clear"
echo "  Browser:         http://localhost   admin: http://localhost/admin  (admin / shopware)"
