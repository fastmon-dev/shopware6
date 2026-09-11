#!/usr/bin/env bash
# fastmon-sw: run Shopware's bin/console inside the sibling shop container.
#
#     fastmon-sw plugin:refresh
#     fastmon-sw plugin:install --activate FastmonCollector
#     fastmon-sw cache:clear
#     fastmon-sw --shell            # interactive shell in /var/www/html
#     fastmon-sw --wait             # block until the shop reports healthy
#
# The dev container has no docker socket (by design), so we go through the SSH
# daemon dockware ships for exactly this purpose (user/password dockware/dockware,
# local dev network only). Host keys are not pinned: the shop container is
# recreated freely and its key changes every time.
set -euo pipefail

HOST="${FASTMON_SHOP_HOST:-shopware}"
SSH=(sshpass -p dockware ssh
     -o StrictHostKeyChecking=no
     -o UserKnownHostsFile=/dev/null
     -o LogLevel=ERROR
     -o ConnectTimeout=5
     "dockware@${HOST}")

case "${1:-}" in
  --shell)
    exec "${SSH[@]}" -t 'cd /var/www/html && exec bash -l'
    ;;
  --wait)
    # The shop is "ready" when Shopware answers over HTTP. dockware's own
    # healthcheck is only visible to docker, not from inside the network.
    printf 'waiting for http://%s ' "$HOST"
    for _ in $(seq 1 120); do
      if curl -fsS -o /dev/null --max-time 3 "http://${HOST}/" 2>/dev/null; then
        echo ' ready.'; exit 0
      fi
      printf '.'; sleep 5
    done
    echo ' timed out after 10 min.' >&2; exit 1
    ;;
  ""|-h|--help)
    sed -n '2,12p' "$0"; exit 0
    ;;
  *)
    # Quote each argument so options with spaces survive the remote shell.
    args=$(printf ' %q' "$@")
    exec "${SSH[@]}" "cd /var/www/html && php bin/console${args}"
    ;;
esac
