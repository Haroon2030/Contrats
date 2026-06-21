#!/bin/bash
set -e

if [ "${APP_ENV:-local}" = "production" ] && [ -n "${DATABASE_URL:-}" ]; then
  echo "[entrypoint] Running database migrations..."
  php /var/www/html/database/migrate.php || {
    echo "[entrypoint] Migration failed — container startup aborted."
    exit 1
  }
fi

exec "$@"
