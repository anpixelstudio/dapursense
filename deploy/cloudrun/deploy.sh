#!/usr/bin/env bash
# Deploy VERA web to Cloud Run + Cloud SQL (+ optional Gemini).
#
# Prereq: ./deploy/cloudrun/provision.sh (or existing Cloud SQL instance)
#
# Usage:
#   export PROJECT_ID=your-gcp-project
#   export REGION=asia-southeast1
#   export CLOUD_SQL_CONNECTION=PROJECT:REGION:INSTANCE
#   export APP_KEY="$(php artisan key:generate --show)"
#   ./deploy/cloudrun/deploy.sh
#
# Optional Gemini:
#   export GEMINI_API_KEY=AIza...
#   ./deploy/cloudrun/deploy.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PROJECT_ID="${PROJECT_ID:?Set PROJECT_ID}"
REGION="${REGION:-asia-southeast1}"
SERVICE="${SERVICE:-vera}"
# Artifact Registry repo id (product brand). Cloud SQL instance/user may still use legacy ids.
AR_REPO="${AR_REPO:-vera}"
SQL_INSTANCE="${SQL_INSTANCE:-dapursense-pg}"
CLOUD_SQL_CONNECTION="${CLOUD_SQL_CONNECTION:-${PROJECT_ID}:${REGION}:${SQL_INSTANCE}}"
DB_DATABASE="${DB_DATABASE:-kitchen_intelligence}"
DB_USERNAME="${DB_USERNAME:-dapursense}"
IMAGE="${REGION}-docker.pkg.dev/${PROJECT_ID}/${AR_REPO}/${SERVICE}:latest"
MIGRATE_ON_BOOT="${MIGRATE_ON_BOOT:-1}"
MEMORY="${MEMORY:-1Gi}"
CPU="${CPU:-1}"

if [[ -z "${APP_KEY:-}" ]]; then
  if [[ -f .env ]]; then
    # shellcheck disable=SC1091
    set -a; source .env; set +a
  fi
fi

upsert_secret() {
  local name="$1"
  local value="$2"
  if gcloud secrets describe "$name" --project="$PROJECT_ID" >/dev/null 2>&1; then
    printf '%s' "$value" | gcloud secrets versions add "$name" --data-file=- --project="$PROJECT_ID"
  else
    printf '%s' "$value" | gcloud secrets create "$name" --data-file=- --project="$PROJECT_ID"
  fi
}

echo "==> Enabling APIs"
gcloud services enable \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  cloudbuild.googleapis.com \
  secretmanager.googleapis.com \
  sqladmin.googleapis.com \
  sql-component.googleapis.com \
  --project="$PROJECT_ID"

echo "==> Artifact Registry ($AR_REPO)"
gcloud artifacts repositories describe "$AR_REPO" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1 \
  || gcloud artifacts repositories create "$AR_REPO" \
       --repository-format=docker --location="$REGION" --project="$PROJECT_ID"

# Prefer Secret Manager for APP_KEY
if [[ -n "${APP_KEY:-}" ]]; then
  echo "==> Storing APP_KEY in Secret Manager"
  upsert_secret app-key "$APP_KEY"
elif ! gcloud secrets describe app-key --project="$PROJECT_ID" >/dev/null 2>&1; then
  echo "Set APP_KEY (php artisan key:generate --show) or create secret app-key first." >&2
  exit 1
fi

if [[ -n "${DB_PASSWORD:-}" ]]; then
  echo "==> Storing DB_PASSWORD in Secret Manager"
  upsert_secret db-password "$DB_PASSWORD"
elif ! gcloud secrets describe db-password --project="$PROJECT_ID" >/dev/null 2>&1; then
  echo "Set DB_PASSWORD or run provision.sh first (secret db-password)." >&2
  exit 1
fi

# IAM: default compute SA needs secretAccessor + cloudsql.client
PROJECT_NUMBER="$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')"
RUNTIME_SA="${RUNTIME_SA:-${PROJECT_NUMBER}-compute@developer.gserviceaccount.com}"
gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:${RUNTIME_SA}" \
  --role="roles/cloudsql.client" \
  --condition=None \
  --quiet >/dev/null || true
for secret in app-key db-password gemini-api-key; do
  if gcloud secrets describe "$secret" --project="$PROJECT_ID" >/dev/null 2>&1; then
    gcloud secrets add-iam-policy-binding "$secret" \
      --member="serviceAccount:${RUNTIME_SA}" \
      --role="roles/secretmanager.secretAccessor" \
      --project="$PROJECT_ID" \
      --quiet >/dev/null || true
  fi
done

echo "==> Build & push"
gcloud builds submit --tag "$IMAGE" --project="$PROJECT_ID"

# Hackathon profile: database drivers, local disk (ephemeral OK for demo), HTTPS
AI_PROVIDER_VALUE=stub
GEMINI_MODEL_VALUE="${GEMINI_MODEL:-gemini-2.5-flash}"
SECRETS="APP_KEY=app-key:latest,DB_PASSWORD=db-password:latest"

if [[ -n "${GEMINI_API_KEY:-}" ]]; then
  echo "==> Storing GEMINI_API_KEY in Secret Manager"
  upsert_secret gemini-api-key "$GEMINI_API_KEY"
  AI_PROVIDER_VALUE=gemini
  SECRETS="${SECRETS},GEMINI_API_KEY=gemini-api-key:latest"
elif gcloud secrets describe gemini-api-key --project="$PROJECT_ID" >/dev/null 2>&1; then
  AI_PROVIDER_VALUE=gemini
  SECRETS="${SECRETS},GEMINI_API_KEY=gemini-api-key:latest"
fi

ENV_VARS="$(cat <<EOF | tr '\n' ',' | sed 's/,$//'
APP_NAME=VERA
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr
CLOUD_RUN=true
FORCE_HTTPS=true
DB_CONNECTION=pgsql
DB_HOST=/cloudsql/${CLOUD_SQL_CONNECTION}
DB_PORT=5432
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
KITCHEN_STORAGE_DISK=local
AI_PROVIDER=${AI_PROVIDER_VALUE}
GEMINI_MODEL=${GEMINI_MODEL_VALUE}
MIGRATE_ON_BOOT=${MIGRATE_ON_BOOT}
EOF
)"

# Optional public URL override after first deploy
if [[ -n "${APP_URL:-}" ]]; then
  ENV_VARS="${ENV_VARS},APP_URL=${APP_URL}"
fi

echo "==> Deploy Cloud Run (Cloud SQL: $CLOUD_SQL_CONNECTION)"
gcloud run deploy "$SERVICE" \
  --image="$IMAGE" \
  --region="$REGION" \
  --project="$PROJECT_ID" \
  --platform=managed \
  --allow-unauthenticated \
  --port=8080 \
  --memory="$MEMORY" \
  --cpu="$CPU" \
  --min-instances=0 \
  --max-instances=3 \
  --add-cloudsql-instances="$CLOUD_SQL_CONNECTION" \
  --set-env-vars="$ENV_VARS" \
  --set-secrets="$SECRETS"

URL="$(gcloud run services describe "$SERVICE" --region="$REGION" --project="$PROJECT_ID" --format='value(status.url)')"

# Set APP_URL to the live service URL if not provided
if [[ -z "${APP_URL:-}" ]]; then
  gcloud run services update "$SERVICE" \
    --region="$REGION" \
    --project="$PROJECT_ID" \
    --update-env-vars="APP_URL=${URL}" \
    --quiet
fi

echo ""
echo "Deployed: $URL"
echo "Health:   $URL/up"
echo "Assistant:$URL/assistant"
echo ""
echo "AI_PROVIDER=$AI_PROVIDER_VALUE  |  Cloud SQL=$CLOUD_SQL_CONNECTION"
echo "After first successful migrate, set MIGRATE_ON_BOOT=0 on next deploy to skip boot migrations."
echo "See docs/GCP.md"
