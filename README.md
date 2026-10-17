# Anpixel DapurSense

**Kitchen CCTV intelligence for PPE & SOP compliance** — built for [Google Cloud AI Builder Cup 2026](https://aibuildercup.com/) (Hack2skill · JAPAC).

| | |
| --- | --- |
| **Theme** | **Manufacturing — Intelligent Operations & Industrial Efficiency** |
| **Problem** | Commercial kitchens still rely on manual spot-checks for hair covers, gloves, and restricted zones. Missed PPE raises food-safety risk and audit cost. |
| **Solution** | Upload CCTV clips → computer vision detects people & kitchen PPE → rules engine opens reviewable anomalies → **Gemini** turns evidence into SOP answers and daily ops reports. |
| **Repo** | https://github.com/anpixelstudio/dapursense |
| **Deploy target** | Google Cloud **Cloud Run** + Cloud SQL + **Gemini** |

> Secondary fit: *Retail & Commerce* (multi-outlet F&B operations). Primary pitch: **Manufacturing / industrial kitchen ops**.

---

## Why this fits AI Builder Cup judging

| Criterion (weight) | How DapurSense addresses it |
| --- | --- |
| **Technical Merit & Gen AI (40%)** | End-to-end stack: YOLOX person + YOLOv8 hairnet/gloves (ONNX), IoPPE association, temporal rules, Laravel product UI. **Gemini** powers `/assistant` chat, anomaly summarize, and locked daily reports (`AI_PROVIDER=gemini`). |
| **Problem Alignment & Impact (25%)** | Real F&B / central kitchen pain: PPE compliance, restricted-zone dwell, review workflow, CSV/PDF reports for supervisors. |
| **Innovation & Creativity (25%)** | Vision + Gen AI together: detections become evidence; Gemini explains SOP and drafts owner-ready todos—not a chatbot bolted on alone. |
| **UX & Solution Design (10%)** | English UI, monitor wall, timeline, shadcn forms/dialogs, simulation mode for demos without real PPE models. |

### Mandatory submission checklist

| Requirement | Status in this repo |
| --- | --- |
| Google AI (Gemini / Gemma / agentic platform) | ✅ Gemini assistant (`app/Domain/Ai`, `/assistant`) |
| Deploy on Cloud Run / Firebase / GCP | ✅ Scripts + guide: `deploy/cloudrun/`, `docs/GCP.md` |
| Public GitHub | ✅ this repository |
| Working prototype | ✅ local `./start.sh` + Cloud Run path |
| Demo video ≤ 3 min (EN) | ⬜ record from Monitor / Analysis / Assistant |
| Proposal deck PDF (EN) | ⬜ use this README + `docs/ai-builder-cup-status.html` as source |

All judge-facing materials must be in **English** (product UI is English-only).

---

## Product overview

**Anpixel DapurSense** helps kitchen supervisors:

1. **Ingest** CCTV uploads per camera source  
2. **Analyse** with AI models (person + optional PPE) against drawn zones  
3. **Review** anomalies (PPE missing, restricted zone, unassessable PPE)  
4. **Ask Gemini** about SOP, people counts, and period reports  
5. **Export** CSV/PDF compliance reports  

### Architecture (short)

```
Browser (React / Inertia)
        │
        ▼
Laravel 12 API + Postgres + Redis
        │
        ├─► Gemini (Gen AI assistant / summarize / daily report)
        │
        └─► Python AI worker (FastAPI)
                ├─ YOLOX-Nano / WholeBody (person)
                └─ YOLOv8 hairnet+gloves ONNX + IoPPE → hair_cover / glove
```

| Layer | Tech |
| --- | --- |
| Web | Laravel 12, Inertia, React, Tailwind, shadcn/ui |
| Vision | OpenCV DNN + ONNX Runtime (`ai-service/`) |
| Gen AI | Google Gemini (`AI_PROVIDER=gemini`) |
| Data | PostgreSQL, Redis (optional locally; DB queue on Cloud Run) |
| Cloud | Artifact Registry, Cloud Build, Cloud Run, Cloud SQL, Secret Manager |

---

## Features

- **Video & Analysis** — upload, validate, preview, run analysis (actual or simulation)
- **Zones** — prep / stove / sink / cleaning / restricted polygons per camera
- **PPE** — `yolox-kitchen-ppe` model: hair cover & gloves (present/missing); apron/mask not in model yet
- **Anomalies & review** — open → acknowledge / confirm / dismiss / resolve with evidence
- **Monitor & uptime** — multi-camera wall + device heartbeats
- **Reports** — on-demand & scheduled CSV/PDF
- **Assistant** — Gemini SOP Q&A, anomaly summarize, daily operations report (jailbreak-hardened prompts)

---

## Quick start (local)

### Prerequisites

- PHP 8.4+, Composer, Node 22+, PostgreSQL, Redis (optional), FFmpeg  
- Python 3.12+ for `ai-service`

### Setup

```bash
cp .env.example .env
# set DB_* and APP_KEY (php artisan key:generate)

composer install
cd ai-service && python3 -m venv .venv && .venv/bin/pip install -r requirements.lock && cd ..
npm install && npm run build

# Person model is downloaded by start.sh; kitchen PPE (optional):
KITCHEN_PPE=1 ./start.sh   # or download/export hairnet_gloves_yolov8.onnx once
./start.sh
```

Open **http://127.0.0.1:8000**

### Gen AI

```env
AI_PROVIDER=stub          # demo answers without a key
# AI_PROVIDER=gemini
# GEMINI_API_KEY=your_key
# GEMINI_MODEL=gemini-2.5-flash
```

| Mode | When to use |
| --- | --- |
| `stub` | Local/demo without Google key |
| `gemini` | Hackathon judging & live Cloud Run |

### Demo path for judges (≤ 3 minutes)

1. Monitor / Videos — show kitchen CCTV context  
2. Run analysis with **`yolox-kitchen-ppe`** (or **simulation** if models not present)  
3. Open an anomaly — evidence + review  
4. **Assistant** — ask SOP / generate daily report (with Gemini key)  
5. Mention Cloud Run + Gemini on GCP  

Sample kitchen stock clips (links only; MP4s gitignored): `samples/kitchen/README.md`

---

## Deploy on Google Cloud

Full guide: **[`docs/GCP.md`](docs/GCP.md)**

```bash
export PROJECT_ID=your-gcp-project
export REGION=asia-southeast1
export DB_PASSWORD='…'
export GEMINI_API_KEY='…'   # required for Gen AI scoring

./deploy/cloudrun/provision.sh
export CLOUD_SQL_CONNECTION="${PROJECT_ID}:${REGION}:dapursense-pg"
export APP_KEY="$(php artisan key:generate --show)"
./deploy/cloudrun/deploy.sh
```

Set `AI_PROVIDER=gemini` on the Cloud Run service so judges see real Gen AI.

---

## AI models

| Model name | Runtime | Detects |
| --- | --- | --- |
| `anpixel-simulator` | simulation | Scripted people + hair/apron (demo) |
| `opencv-hog-person` | opencv | Person only |
| `yolox-nano-person` | onnx | Person only |
| `yolox-wholebody12-n` | onnx-wholebody | Person (overhead-friendly) |
| **`yolox-kitchen-ppe`** | onnx-kitchen-ppe | Person + **hair_cover** + **glove** |

PPE ONNX is optional; install with `KITCHEN_PPE=1 ./start.sh` (exports from Hugging Face weights). See `database/seeders/SystemSeeder.php`.

---

## Tests

```bash
php artisan test
cd ai-service && .venv/bin/pytest -q
```

---

## Project layout

```
kitchen-intelligence/
├── app/Domain/Ai/          # Gemini + stub assistants, safety prompts
├── ai-service/worker/      # Vision pipeline, kitchen PPE, rules
├── deploy/cloudrun/        # GCP provision & deploy
├── docs/GCP.md             # Cloud Run + Gemini deploy guide
├── docs/ai-builder-cup-status.html
├── resources/js/           # React (Inertia) English UI
└── samples/kitchen/        # Stock kitchen video sources (README)
```

---

## Team & brand

- **Product:** Anpixel DapurSense  
- **Studio:** Anpixel  
- **Event:** Google Cloud AI Builder Cup 2026  
- **Eligibility reminder:** working professionals, teams of 2–4 (not students)

---

## License & attribution

Application code: see repository license / Anpixel rights.  
Vision weights: YOLOX (Apache-2.0), YOLOX-WholeBody12 (Apache-2.0 / PINTO0309), hairnet-gloves YOLOv8 (MIT / KhawlaQuraan).  
Stock samples: Mixkit — check their license before public marketing use.

---

## Links

- Hackathon: https://aibuildercup.com/  
- Themes: https://aibuildercup.com/themes.html  
- This repo: https://github.com/anpixelstudio/dapursense  
- Deploy notes: [`docs/GCP.md`](docs/GCP.md)
