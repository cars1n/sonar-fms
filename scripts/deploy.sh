#!/usr/bin/env bash
set -Eeuo pipefail

revision=${1:?A full Git revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(hostname) == invoices ]] || { echo 'Wrong deployment host'; exit 1; }
base=/var/www/invoices.ldiglobal.org
release="$base/releases/$(date -u +%Y%m%d-%H%M%S)-${revision:0:12}"
previous=$(readlink -f "$base/live")
archive="$HOME/site.tar.gz"
test -f "$archive"
test -x /usr/bin/php8.5
sudo -n systemctl is-active --quiet php8.5-fpm
sudo -n mkdir -p "$release"
sudo -n tar -xzf "$archive" -C "$release"
sudo -n chown -R www-data:www-data "$release"
sudo -n ln -s "$base/.env" "$release/.env"
sudo -n ln -s "$base/storage" "$release/storage"
sudo -n mkdir -p "$release/bootstrap/cache"
sudo -n chown www-data:www-data "$release/bootstrap/cache"
test "$(cat "$release/public/release-revision.txt")" = "$revision"

artisan() { sudo -n -u www-data /usr/bin/php8.5 "$release/artisan" "$@"; }
cd "$release"
sudo -n -u www-data /usr/bin/php8.5 -r 'require "vendor/autoload.php";'
artisan package:discover --ansi
artisan migrate:status --pending=1
artisan config:cache
artisan route:cache
# Views use persistent storage, so warm them only after switching releases.
artisan storage:link

rollback() {
    trap - ERR
    echo "Deployment failed; restoring $previous"
    sudo -n ln -s "$previous" "$base/live.rollback"
    sudo -n mv -Tf "$base/live.rollback" "$base/live"
    sudo -n systemctl reload php8.5-fpm
    sudo -n supervisorctl restart 'sonar-fms-worker:*'
}
trap rollback ERR
sudo -n ln -s "$release" "$base/live.next"
sudo -n mv -Tf "$base/live.next" "$base/live"
sudo -n systemctl reload php8.5-fpm
artisan view:cache
previous_worker_pid=$(sudo -n supervisorctl pid sonar-fms-worker)
artisan horizon:terminate
wait_for_worker() {
    local status pid attempt
    for ((attempt = 0; attempt < 45; attempt++)); do
        status=$(sudo -n supervisorctl status 'sonar-fms-worker:*') || status=${status:-unavailable}
        pid=$(sudo -n supervisorctl pid sonar-fms-worker) || pid=0
        if [[ $status == *' RUNNING '* && $pid =~ ^[1-9][0-9]*$ && $pid != "$previous_worker_pid" ]]; then
            printf '%s\n' "$status"
            return 0
        fi
        sleep 2
    done
    printf 'Horizon did not restart within 90 seconds: %s (PID %s, previous %s)\n' "$status" "$pid" "$previous_worker_pid" >&2
    return 1
}
wait_for_worker
curl -kfsS --resolve invoices.lifepluslearning.com:443:127.0.0.1 https://invoices.lifepluslearning.com/login -o /dev/null
test "$(curl -kfsS --resolve invoices.lifepluslearning.com:443:127.0.0.1 https://invoices.lifepluslearning.com/release-revision.txt)" = "$revision"
trap - ERR
rm -f "$archive"
echo "DEPLOYED $revision using $(/usr/bin/php8.5 -r 'echo PHP_VERSION;')"
