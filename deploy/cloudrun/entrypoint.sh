#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-8080}"

if [[ -z "${APP_KEY:-}" ]]; then
  echo "APP_KEY is required (Secret Manager app-key or env)." >&2
  exit 1
fi

# Config cleared at boot so Cloud Run secrets/env apply without rebuild.
php artisan config:clear -q || true
php artisan package:discover -q || true

# Optional: run migrations when MIGRATE_ON_BOOT=1 (hackathon first boot).
if [[ "${MIGRATE_ON_BOOT:-0}" == "1" ]]; then
  echo "MIGRATE_ON_BOOT=1 — running migrations..."
  php artisan migrate --force
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT}"
