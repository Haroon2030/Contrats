#!/bin/bash
set -e

if [ "${APP_ENV:-local}" = "production" ] && [ -n "${DATABASE_URL:-}" ]; then
  echo "[entrypoint] Running database migrations..."
  if php /var/www/html/database/migrate.php; then
    echo "[entrypoint] Migrations completed."
  else
    echo "[entrypoint] WARNING: Migration failed — starting Apache anyway."
    echo "[entrypoint] Fix pending migrations and redeploy."
  fi
fi

exec "$@"
