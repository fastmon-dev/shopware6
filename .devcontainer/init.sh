#!/usr/bin/env bash
# One-time HOST setup for the fastmon Shopware plugin dev container — run on
# your Mac, NOT inside the container. Idempotent: safe to re-run any time.
#
#     ./.devcontainer/init.sh
#
# It prepares everything the container binds from the host and can't create
# itself:
#   1. The DEDICATED per-project Claude store (~/.devcontainer/fastmon-shopware-plugin/)
#      so auth survives rebuilds without the global ~/.claude refresh-token churn.
#   2. Verifies the GitHub deploy key (auth) and the SSH signing key.
#   3. Verifies git identity, FASTMON_GIT_EMAIL, and the sibling checkouts.
#   4. Warns if host port 80 (the shop) is already taken.
# Only (1) is created; the rest are checks with actionable hints.
set -euo pipefail

PROJECT=fastmon-shopware-plugin
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_PARENT="$(cd "$SCRIPT_DIR/../.." && pwd)"

AUTH_KEY="$HOME/.ssh/id_fastmon_shopware_plugin"
SIGN_KEY="$HOME/.ssh/id_fastmon_signing"
STORE="$HOME/.devcontainer/$PROJECT"
SHOP_PORT="${FASTMON_SHOP_PORT:-80}"

if [ -t 1 ]; then
  G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; B=$'\033[1m'; N=$'\033[0m'
else
  G=""; Y=""; R=""; B=""; N=""
fi
ok()   { printf '%s✓%s %s\n' "$G" "$N" "$1"; }
warn() { printf '%s!%s %s\n' "$Y" "$N" "$1"; }
err()  { printf '%s✗%s %s\n' "$R" "$N" "$1"; }

problems=0

printf '%sHost setup for the %s dev container%s\n\n' "$B" "$PROJECT" "$N"

# 1. Dedicated Claude store — the only thing we create.
mkdir -p "$STORE/.claude"
# Self-heal: if the container was ever opened before this script ran, Docker's
# single-file bind mount created .claude.json as an EMPTY DIRECTORY (its default
# for a missing source path). Claude Code stores the logged-in account there, so
# a directory silently breaks auth persistence. Replace it with a real file.
if [ -d "$STORE/.claude.json" ]; then
  if rmdir "$STORE/.claude.json" 2>/dev/null; then
    warn "Removed a stray .claude.json directory (Docker auto-created it before setup ran)"
  else
    err "$STORE/.claude.json is a non-empty directory — remove it by hand, then re-run"
    problems=$((problems + 1))
  fi
fi
if [ ! -f "$STORE/.claude.json" ]; then
  echo '{}' > "$STORE/.claude.json"
  ok "Created Claude store $STORE/{.claude,.claude.json}"
else
  ok "Claude store present ($STORE)"
fi

# 2a. GitHub deploy key (auth).
if [ -f "$AUTH_KEY" ]; then
  ok "Deploy key present ($AUTH_KEY)"
  if command -v ssh >/dev/null 2>&1; then
    if ssh -i "$AUTH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes \
         -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new \
         -T git@github.com 2>&1 | grep -qi 'successfully authenticated'; then
      ok "GitHub SSH auth works"
    else
      warn "Could not confirm GitHub SSH auth — ensure id_fastmon_shopware_plugin is a"
      warn "  deploy key on fastmon-dev/shopware-plugin. Test:"
      warn "  ssh -i $AUTH_KEY -o IdentitiesOnly=yes -T git@github.com"
    fi
  fi
else
  err "Deploy key missing: expected $AUTH_KEY"
  err "  Create it (ssh-keygen -t ed25519 -f $AUTH_KEY) and add it as a deploy key"
  err "  (write access) on fastmon-dev/shopware-plugin."
  problems=$((problems + 1))
fi

# 2b. SSH signing key (shared across all fastmon repos).
if [ -f "$SIGN_KEY" ] && [ -f "$SIGN_KEY.pub" ]; then
  ok "Signing key present ($SIGN_KEY[.pub])"
else
  err "Signing key missing: expected $SIGN_KEY and $SIGN_KEY.pub"
  err "  Create it and register it on your GitHub account as a *Signing key*."
  problems=$((problems + 1))
fi

# 3. Git identity (mounted read-only) + per-developer commit email.
if git config --global user.name >/dev/null 2>&1; then
  ok "Git user.name set ($(git config --global user.name))"
else
  err "Git user.name not set — run: git config --global user.name \"Your Name\""
  problems=$((problems + 1))
fi
if [ -n "${FASTMON_GIT_EMAIL:-}" ]; then
  ok "FASTMON_GIT_EMAIL set ($FASTMON_GIT_EMAIL)"
else
  warn "FASTMON_GIT_EMAIL is not exported in your host shell — commits inside the"
  warn "  container will fail with a git config error. Add to your shell profile:"
  warn "  export FASTMON_GIT_EMAIL=you@fastmon.eu"
fi

# 3b. Sibling checkouts (bind-mounted read-only into the container). Missing
#     ones are only a warning: Compose would create empty dirs in their place.
for sib in backend platform-fastmon-collector-app shopware-sctracking; do
  if [ -d "$REPO_PARENT/$sib" ]; then
    ok "Sibling checkout present ($sib)"
  else
    warn "Sibling checkout missing: $REPO_PARENT/$sib — clone it next to shopware_plugin"
  fi
done

# 4. Host port for the shop. Compose publishes the shop on this port; a clash
#    makes `docker compose up` fail with "address already in use".
if command -v lsof >/dev/null 2>&1 && lsof -nP -iTCP:"$SHOP_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  warn "Host port $SHOP_PORT is in use. Either free it or export FASTMON_SHOP_PORT=8090"
  warn "  before opening, then once: fastmon-sw sales-channel:update:domain http://localhost:8090"
else
  ok "Host port $SHOP_PORT free for the shop (http://localhost${SHOP_PORT:+:}$SHOP_PORT)"
fi

printf '\n'
if [ "$problems" -eq 0 ]; then
  ok "${B}Host setup complete — you can open the dev container.$N"
  echo "  First start pulls dockware/shopware (~1.8 GB) and boots MySQL + Shopware;"
  echo "  give it a few minutes, then run \`make sw-install\` in the container."
else
  err "${B}$problems blocking item(s) above — fix and re-run before opening the container.$N"
  exit 1
fi
