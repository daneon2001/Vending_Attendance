#!/usr/bin/env bash
# Run on the future Linux beta host ONLY. No automatic DB rollback.
set -euo pipefail
umask 027
root=${1:?deploy root required}
release_id=${2:?release id required}
url=${3:?beta HTTPS origin required}
[[ "$root" =~ ^/srv/vending-beta(/[a-zA-Z0-9_-]+)?$ ]] || exit 64
[[ "$release_id" =~ ^[a-f0-9]{40}$ ]] || exit 64
[[ "$url" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ ]] || exit 64
[[ $(realpath -e "$root") == "$root" ]] || exit 64
exec 9>"$root/.deploy.lock"
flock -n 9 || { echo 'Another beta deployment is active' >&2; exit 1; }
release="$root/releases/$release_id"
shared="$root/shared"
[[ -d "$release" && ! -L "$release" && $(realpath -e "$release") == "$release" ]] || exit 64
[[ -f "$shared/.env" && -x "$shared/hooks/backup-db" ]] || { echo 'Private environment and successful backup hook are mandatory' >&2; exit 1; }
[[ ! -e "$release/.env" && ! -e "$release/storage" ]] || exit 64
mkdir -p "$shared/private/beta-onboarding" "$shared/support-private" "$shared/public" "$shared/logs"
mkdir -p "$release/storage/app" "$release/storage/framework/cache/data" "$release/storage/framework/sessions" "$release/storage/framework/views" "$release/bootstrap/cache"
ln -s "$shared/.env" "$release/.env"
ln -s "$shared/private" "$release/storage/app/private"
ln -s "$shared/support-private" "$release/storage/app/support-private"
ln -s "$shared/public" "$release/storage/app/public"
ln -s "$shared/logs" "$release/storage/logs"
cd "$release"
composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
composer check-platform-reqs --no-dev
php scripts/deployment/assert-beta.php "$url"
[[ -f public/build/manifest.json ]] || { echo 'Frontend build artifact missing' >&2; exit 1; }
# The operator-owned hook must create and verify an actual remote DB backup.
# It receives only the release id; it reads credentials privately and logs no secrets.
"$shared/hooks/backup-db" "$release_id"
php artisan package:discover --ansi
php artisan migrate --force
php artisan optimize
ln -s "$release" "$root/.current-$release_id"
mv -Tf "$root/.current-$release_id" "$root/current"
bash scripts/deployment/healthcheck.sh "$url"
echo 'Beta deployment and post-switch readiness passed'
