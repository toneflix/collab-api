#!/bin/bash

# Starts one of the API's processes. CONTAINER_ROLE picks which: web (default), worker, scheduler or reverb.

set -e

ROLE="${CONTAINER_ROLE:-web}"

php artisan optimize:clear
php artisan optimize

case "$ROLE" in
  web)
    if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
      php artisan migrate --force
      php artisan app:sync-roles
    fi

    php artisan storage:link

    exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
    ;;
  worker)
    exec php artisan queue:work --tries=3 --timeout=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  reverb)
    exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}"
    ;;
  *)
    echo "Unknown CONTAINER_ROLE: $ROLE (use web, worker, scheduler or reverb)" >&2
    exit 1
    ;;
esac
