#!/usr/bin/env bash
# Provision GCP for VERA (Cloud SQL Postgres + optional GCS + secrets).
#
# Usage:
#   export PROJECT_ID=your-gcp-project
#   export REGION=asia-southeast1
#   export DB_PASSWORD='choose-a-strong-password'
#   ./deploy/cloudrun/provision.sh
#
# Optional:
#   CREATE_GCS_BUCKET=1 GCS_BUCKET=vera-media-$PROJECT_ID ./deploy/cloudrun/provision.sh
#
# Note: default SQL/user/AR ids keep `dapursense-*` when already provisioned;
# override with SQL_INSTANCE=vera-pg AR_REPO=vera for greenfield installs.

set -euo pipefail

PROJECT_ID="${PROJECT_ID:?Set PROJECT_ID}"
REGION="${REGION:-asia-southeast1}"
AR_REPO="${AR_REPO:-vera}"
SQL_INSTANCE="${SQL_INSTANCE:-dapursense-pg}"
SQL_TIER="${SQL_TIER:-db-f1-micro}"
DB_NAME="${DB_NAME:-kitchen_intelligence}"
DB_USER="${DB_USER:-dapursense}"
DB_PASSWORD="${DB_PASSWORD:?Set DB_PASSWORD (will be stored in Secret Manager)}"
CREATE_GCS_BUCKET="${CREATE_GCS_BUCKET:-0}"
GCS_BUCKET="${GCS_BUCKET:-vera-media-${PROJECT_ID}}"

CONNECTION_NAME="${PROJECT_ID}:${REGION}:${SQL_INSTANCE}"

echo "==> Project: $PROJECT_ID  Region: $REGION"
gcloud config set project "$PROJECT_ID" >/dev/null

echo "==> Enabling APIs"
gcloud services enable \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  cloudbuild.googleapis.com \
  secretmanager.googleapis.com \
  sqladmin.googleapis.com \
  storage.googleapis.com \
  generativelanguage.googleapis.com \
  --project="$PROJECT_ID"

echo "==> Artifact Registry ($AR_REPO)"
gcloud artifacts repositories describe "$AR_REPO" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1 \
  || gcloud artifacts repositories create "$AR_REPO" \
       --repository-format=docker --location="$REGION" --project="$PROJECT_ID"

echo "==> Cloud SQL Postgres ($SQL_INSTANCE / $SQL_TIER)"
if gcloud sql instances describe "$SQL_INSTANCE" --project="$PROJECT_ID" >/dev/null 2>&1; then
  echo "    Instance already exists — skipping create"
else
  gcloud sql instances create "$SQL_INSTANCE" \
    --database-version=POSTGRES_15 \
    --tier="$SQL_TIER" \
    --region="$REGION" \
    --storage-size=10GB \
    --storage-type=SSD \
    --availability-type=ZONAL \
    --root-password="$DB_PASSWORD" \
    --project="$PROJECT_ID"
fi

echo "==> Database + user"
gcloud sql databases describe "$DB_NAME" --instance="$SQL_INSTANCE" --project="$PROJECT_ID" >/dev/null 2>&1 \
  || gcloud sql databases create "$DB_NAME" --instance="$SQL_INSTANCE" --project="$PROJECT_ID"

if gcloud sql users list --instance="$SQL_INSTANCE" --project="$PROJECT_ID" --format='value(name)' | grep -qx "$DB_USER"; then
  gcloud sql users set-password "$DB_USER" \
    --instance="$SQL_INSTANCE" --password="$DB_PASSWORD" --project="$PROJECT_ID"
else
  gcloud sql users create "$DB_USER" \
    --instance="$SQL_INSTANCE" --password="$DB_PASSWORD" --project="$PROJECT_ID"
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

echo "==> Secrets (db-password)"
upsert_secret db-password "$DB_PASSWORD"

if [[ -n "${APP_KEY:-}" ]]; then
  echo "==> Secrets (app-key)"
  upsert_secret app-key "$APP_KEY"
fi

if [[ -n "${GEMINI_API_KEY:-}" ]]; then
  echo "==> Secrets (gemini-api-key)"
  upsert_secret gemini-api-key "$GEMINI_API_KEY"
fi

# Allow Cloud Run runtime SA to read secrets + connect to Cloud SQL
PROJECT_NUMBER="$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')"
RUNTIME_SA="${RUNTIME_SA:-${PROJECT_NUMBER}-compute@developer.gserviceaccount.com}"

echo "==> IAM for runtime SA: $RUNTIME_SA"
gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:${RUNTIME_SA}" \
  --role="roles/cloudsql.client" \
  --condition=None \
  --quiet >/dev/null || true

for secret in db-password app-key gemini-api-key; do
  if gcloud secrets describe "$secret" --project="$PROJECT_ID" >/dev/null 2>&1; then
    gcloud secrets add-iam-policy-binding "$secret" \
      --member="serviceAccount:${RUNTIME_SA}" \
      --role="roles/secretmanager.secretAccessor" \
      --project="$PROJECT_ID" \
      --quiet >/dev/null || true
  fi
done

if [[ "$CREATE_GCS_BUCKET" == "1" ]]; then
  echo "==> GCS bucket gs://$GCS_BUCKET"
  gcloud storage buckets describe "gs://${GCS_BUCKET}" >/dev/null 2>&1 \
    || gcloud storage buckets create "gs://${GCS_BUCKET}" --location="$REGION" --project="$PROJECT_ID"
  gcloud storage buckets add-iam-policy-binding "gs://${GCS_BUCKET}" \
    --member="serviceAccount:${RUNTIME_SA}" \
    --role="roles/storage.objectAdmin" \
    --quiet >/dev/null || true
fi

cat <<EOF

Provision OK.

  CONNECTION_NAME=$CONNECTION_NAME
  DB_NAME=$DB_NAME
  DB_USER=$DB_USER
  DB_HOST=/cloudsql/$CONNECTION_NAME

Next:
  export PROJECT_ID=$PROJECT_ID
  export REGION=$REGION
  export CLOUD_SQL_CONNECTION=$CONNECTION_NAME
  export DB_DATABASE=$DB_NAME
  export DB_USERNAME=$DB_USER
  # APP_KEY from: php artisan key:generate --show  (or use secret app-key)
  export APP_KEY="base64:..."
  # optional Gemini:
  # export GEMINI_API_KEY=AIza...
  ./deploy/cloudrun/deploy.sh

EOF
