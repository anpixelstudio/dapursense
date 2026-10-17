# Deploy GCP — Cloud Run + Cloud SQL + Gemini (AI Builder Cup)

Stack target: **Cloud Run** (web), **Cloud SQL Postgres**, **Gemini** (Gen AI), storage lokal di container untuk demo (GCS opsional).

## Mode Gen AI

| `AI_PROVIDER`    | Key              | Perilaku                               |
| ---------------- | ---------------- | -------------------------------------- |
| `stub` (default) | tidak perlu      | Jawaban contoh; UI & endpoint jalan    |
| `gemini`         | `GEMINI_API_KEY` | Panggil Google Generative Language API |

```bash
AI_PROVIDER=stub
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
```

## Endpoint Gen AI

| Method | Path                               | Fungsi                            |
| ------ | ---------------------------------- | --------------------------------- |
| GET    | `/assistant`                       | Halaman chat SOP + laporan harian |
| POST   | `/assistant/chat`                  | Chat SOP                          |
| POST   | `/assistant/daily-report`          | Laporan 7 hari                    |
| POST   | `/assistant/events/{id}/summarize` | Ringkas 1 anomali                 |

## 1. Provision (sekali)

Prasyarat: `gcloud` login, project dengan **billing**.

```bash
export PROJECT_ID=your-gcp-project
export REGION=asia-southeast1
export DB_PASSWORD='strong-password-here'
# optional: export APP_KEY="$(cd kitchen-intelligence && php artisan key:generate --show)"
# optional: export GEMINI_API_KEY=AIza...

chmod +x deploy/cloudrun/*.sh
./deploy/cloudrun/provision.sh
```

Skrip membuat / memastikan:

- API: Cloud Run, Artifact Registry, Cloud Build, Secret Manager, Cloud SQL, Storage, Generative Language
- Artifact Registry `dapursense`
- Cloud SQL Postgres `dapursense-pg` + DB `kitchen_intelligence` + user `dapursense`
- Secret `db-password` (+ `app-key` / `gemini-api-key` jika di-export)
- IAM: runtime SA = `cloudsql.client` + `secretAccessor`

Opsional bucket GCS:

```bash
CREATE_GCS_BUCKET=1 ./deploy/cloudrun/provision.sh
```

## 2. Deploy Cloud Run

```bash
export PROJECT_ID=your-gcp-project
export REGION=asia-southeast1
export CLOUD_SQL_CONNECTION="${PROJECT_ID}:${REGION}:dapursense-pg"
export APP_KEY="$(php artisan key:generate --show)"
# optional Gemini:
# export GEMINI_API_KEY=AIza...

./deploy/cloudrun/deploy.sh
```

Deploy akan:

- build image dari `Dockerfile` via Cloud Build
- attach Cloud SQL (`--add-cloudsql-instances`)
- set env hackathon: `QUEUE/CACHE/SESSION=database`, `FILESYSTEM_DISK=local`, `CLOUD_RUN=true`, `FORCE_HTTPS=true`
- secrets: `APP_KEY`, `DB_PASSWORD`, (+ `GEMINI_API_KEY` jika ada)
- `MIGRATE_ON_BOOT=1` pada boot pertama

Setelah migrate sukses, deploy ulang dengan `MIGRATE_ON_BOOT=0`.

Contoh env lengkap: `.env.cloudrun.example`.

## 3. Colok Gemini

1. [Google AI Studio](https://aistudio.google.com/apikey) → buat key  
2. Lokal:

```bash
AI_PROVIDER=gemini
GEMINI_API_KEY=AIza...
php artisan config:clear
```

3. Cloud Run: `export GEMINI_API_KEY=...` lalu jalankan `deploy.sh` lagi (Secret Manager + `AI_PROVIDER=gemini`).

## Lokal tanpa key

```bash
AI_PROVIDER=stub
php artisan serve
# buka /assistant
```

## Storage

| Mode                         | Kapan                         |
| ---------------------------- | ----------------------------- |
| `FILESYSTEM_DISK=local`      | Demo Cloud Run (default)      |
| `FILESYSTEM_DISK=gcs`        | Produksi / media besar (HMAC) |

Disk `gcs` = S3-compat ke `storage.googleapis.com` (lihat `config/filesystems.php`). Butuh package `league/flysystem-aws-s3-v3` (sudah di `composer.json`).

## Checklist submit hackathon

- [ ] URL Cloud Run live (`/up` OK)
- [ ] Cloud SQL terhubung + migrate
- [ ] `AI_PROVIDER=gemini` + key di Secret Manager
- [ ] Demo: ringkas anomali + chat SOP + laporan harian
- [ ] Repo public + README menyebut Gemini model
- [ ] Video ≤ 3 menit + deck PDF

## Catatan arsitektur

- Deteksi orang tetap YOLOX (worker terpisah) — computer vision, bukan Gen AI juri.
- **Gen AI juri** = Gemini via `/assistant` (bobot Technical Merit & Gen AI).
- Worker AI Python belum masuk image Cloud Run ini; fokus = web + Gemini + Cloud SQL.
