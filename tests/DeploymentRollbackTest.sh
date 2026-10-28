#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
bin="$scratch/bin"
mkdir "$bin"
cat > "$bin/mock" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
name=${0##*/}
printf '%s %s\n' "$name" "$*" >> "$DEPLOY_TEST_CALLS"
case "$name" in
    hostname) echo invoices ;;
    sudo)
        [[ ${1:-} != -n ]] || shift
        if [[ ${1:-} == -u ]]; then shift 2; fi
        [[ ${1:-} != chown ]] || exit 0
        exec "$@"
        ;;
    php8.5)
        [[ ${2:-} != "${DEPLOY_TEST_FAILURE:-none}" ]] || exit 23
        ;;
    systemctl|sleep) ;;
    supervisorctl)
        case "${1:-}" in
            pid)
                [[ ${2:-} == sonar-fms-worker ]] || exit 3
                state="$DEPLOY_TEST_CALLS.pid"
                count=0
                [[ ! -f $state ]] || read -r count < "$state"
                echo "$((count + 1))" > "$state"
                if [[ $count == 0 || ${DEPLOY_TEST_FAILURE:-} == worker:stale ]]; then
                    echo 41
                else
                    echo 42
                fi
                ;;
            status)
                [[ ${2:-} == 'sonar-fms-worker:*' ]] || exit 3
                state="$DEPLOY_TEST_CALLS.status"
                count=0
                [[ ! -f $state ]] || read -r count < "$state"
                echo "$((count + 1))" > "$state"
                if [[ ${DEPLOY_TEST_FAILURE:-} == worker:unhealthy ]]; then
                    echo 'sonar-fms-worker BACKOFF exited too quickly'
                    exit 3
                elif [[ ${DEPLOY_TEST_FAILURE:-} == worker:starting && $count -lt 2 ]]; then
                    echo 'sonar-fms-worker STARTING'
                    exit 3
                fi
                echo 'sonar-fms-worker RUNNING pid 42'
                ;;
            restart)
                [[ ${2:-} == 'sonar-fms-worker:*' ]] || exit 3
                echo 'sonar-fms-worker started'
                ;;
            *) exit 3 ;;
        esac
        ;;
    curl)
        if [[ $* == *release-revision.txt* ]]; then echo "$DEPLOY_TEST_REVISION"; fi
        ;;
    *) exit 99 ;;
esac
MOCK
chmod +x "$bin/mock"
for name in hostname sudo php8.5 systemctl sleep supervisorctl curl; do
    ln -s mock "$bin/$name"
done

revision=0123456789012345678901234567890123456789
for failure in migrate:status view:cache horizon:terminate worker:stale worker:unhealthy worker:starting none; do
    case_dir="$scratch/${failure/:/_}"
    base="$case_dir/site"
    mkdir -p "$base/releases/previous" "$base/storage" "$case_dir/home" "$case_dir/payload/public"
    touch "$base/.env"
    ln -s "$base/releases/previous" "$base/live"
    printf '%s\n' "$revision" > "$case_dir/payload/public/release-revision.txt"
    tar -czf "$case_dir/home/site.tar.gz" -C "$case_dir/payload" .
    # Keep the actual deployment control flow; redirect only fixed host paths.
    sed -e "s|^base=/var/www/invoices.ldiglobal.org$|base='$base'|" \
        -e "s|/usr/bin/php8.5|$bin/php8.5|g" \
        "$repo/scripts/deploy.sh" > "$case_dir/deploy.sh"
    result=0
    HOME="$case_dir/home" PATH="$bin:$PATH" DEPLOY_TEST_CALLS="$case_dir/calls.log" \
        DEPLOY_TEST_FAILURE="$failure" DEPLOY_TEST_REVISION="$revision" \
        bash "$case_dir/deploy.sh" "$revision" > "$case_dir/output.log" 2>&1 || result=$?
    if [[ $failure == none || $failure == worker:starting ]]; then
        [[ $result == 0 ]]
        [[ $(readlink -f "$base/live") != "$base/releases/previous" ]]
        [[ $(cat "$base/live/public/release-revision.txt") == "$revision" ]]
        ! grep -q 'Deployment failed' "$case_dir/output.log"
    else
        expected_result=23
        [[ $failure != worker:* ]] || expected_result=1
        [[ $result == "$expected_result" ]]
        [[ $(readlink -f "$base/live") == "$base/releases/previous" ]]
        if [[ $failure == migrate:status ]]; then
            ! grep -q '^supervisorctl restart ' "$case_dir/calls.log"
            ! grep -q 'Deployment failed; restoring' "$case_dir/output.log"
        else
            [[ $(grep -c '^supervisorctl restart ' "$case_dir/calls.log") == 1 ]]
            grep -q 'Deployment failed; restoring' "$case_dir/output.log"
        fi
    fi
    printf 'Deployment scenario passed: %s\n' "$failure"
done
