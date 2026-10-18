#!/usr/bin/env bash
# Run the full VERA stack locally.
#   ./start.sh         jalankan semua layanan (Ctrl+C untuk berhenti)
#   ./start.sh stop    hentikan layanan yang masih berjalan dari sesi sebelumnya
set -euo pipefail

cd "$(dirname "$0")"
ROOT="$(pwd)"
LOG_DIR="$ROOT/storage/logs"
APP_PORT=8000
AI_PORT=8100

stop_all() {
    pkill -f "artisan serve --host=127.0.0.1 --port=$APP_PORT" 2>/dev/null || true
    pkill -f -- "-S 127.0.0.1:$APP_PORT" 2>/dev/null || true
    pkill -f "artisan queue:work redis" 2>/dev/null || true
    pkill -f "artisan schedule:work" 2>/dev/null || true
    pkill -f "uvicorn worker.api:create_app" 2>/dev/null || true
    pkill -f -- "-m worker.runner" 2>/dev/null || true
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        lsof -nP -iTCP:$APP_PORT -iTCP:$AI_PORT -sTCP:LISTEN >/dev/null 2>&1 || return 0
        sleep 0.5
    done
}

if [[ "${1:-}" == "stop" ]]; then
    stop_all
    echo "Semua layanan dihentikan."
    exit 0
fi

env_value() {
    grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

[[ -f .env ]] || { echo "File .env tidak ditemukan. Salin dari .env.example lalu isi."; exit 1; }
[[ -x ai-service/.venv/bin/python ]] || { echo "Virtualenv ai-service/.venv belum ada. Jalankan: cd ai-service && python3 -m venv .venv && .venv/bin/pip install -r requirements.lock"; exit 1; }

if ! pg_isready -q -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)"; then
    echo "PostgreSQL belum jalan, mencoba brew services start..."
    brew services start postgresql@16 >/dev/null
    sleep 3
    pg_isready -q -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)" || { echo "PostgreSQL tidak bisa dihubungi."; exit 1; }
fi

if ! redis-cli ping >/dev/null 2>&1; then
    echo "Redis belum jalan, mencoba brew services start..."
    brew services start redis >/dev/null
    sleep 2
    redis-cli ping >/dev/null 2>&1 || { echo "Redis tidak bisa dihubungi."; exit 1; }
fi

if pgrep -f -- "-m worker.runner" >/dev/null; then
    echo "AI runner dari sesi sebelumnya masih berjalan. Jalankan './start.sh stop' dulu."
    exit 1
fi

for port in $APP_PORT $AI_PORT; do
    if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
        echo "Port $port sudah dipakai. Jalankan './start.sh stop' dulu atau hentikan prosesnya."
        exit 1
    fi
done

YOLOX_FILE=ai-service/models/yolox_nano.onnx
YOLOX_SHA=c789161ed43c8269fcd4e67c67eeeb4e80c622da2eb296a20bc6007bd18a0b7d
if [[ ! -f "$YOLOX_FILE" ]]; then
    echo "Mengunduh model YOLOX-Nano (3.6 MB)..."
    mkdir -p ai-service/models
    curl -fsSL -o "$YOLOX_FILE" https://github.com/Megvii-BaseDetection/YOLOX/releases/download/0.1.1rc0/yolox_nano.onnx
fi
if [[ "$(shasum -a 256 "$YOLOX_FILE" | cut -d' ' -f1)" != "$YOLOX_SHA" ]]; then
    echo "Checksum $YOLOX_FILE tidak cocok; hapus file itu lalu jalankan ulang."
    exit 1
fi

WHOLEBODY_FILE=ai-service/models/yolox_n_wholebody12_640.onnx
WHOLEBODY_SHA=f525570ce7abf0b9b9366db442f04368d551b43a91783403cbe5eb88b115aee1
if [[ ! -f "$WHOLEBODY_FILE" && "${WHOLEBODY:-0}" == "1" ]]; then
    echo "Mengunduh model YOLOX-WholeBody12 (arsip 260 MB, hanya 1 file yang diekstrak)..."
    curl -fsSL https://s3.ap-northeast-2.wasabisys.com/pinto-model-zoo/449_YOLOX-WholeBody12/resources_n.tar.gz \
        | tar -xzO yolox_n_wholebody12_0200_1x3x640x640.onnx > "$WHOLEBODY_FILE"
fi
if [[ -f "$WHOLEBODY_FILE" ]]; then
    if [[ "$(shasum -a 256 "$WHOLEBODY_FILE" | cut -d' ' -f1)" != "$WHOLEBODY_SHA" ]]; then
        echo "Checksum $WHOLEBODY_FILE tidak cocok; hapus file itu lalu jalankan ulang."
        exit 1
    fi
else
    echo "Model overhead YOLOX-WholeBody12 belum ada (opsional). Unduh dengan: WHOLEBODY=1 ./start.sh"
fi

PPE_FILE=ai-service/models/hairnet_gloves_yolov8.onnx
PPE_SHA=028bf2f5c42ea4c68f6b988ab26209a5e92d3618efc1257e5ff7116725b3866b
PPE_PT=ai-service/models/hairnet_gloves_yolov8.pt
if [[ ! -f "$PPE_FILE" && "${KITCHEN_PPE:-0}" == "1" ]]; then
    echo "Menyiapkan model APD dapur (hairnet/gloves YOLOv8 → ONNX)..."
    mkdir -p ai-service/models
    if [[ ! -f "$PPE_PT" ]]; then
        curl -fsSL -o "$PPE_PT" \
            https://huggingface.co/KhawlaQuraan/hairnet-gloves-yolov8-v6/resolve/main/best.pt
    fi
    if [[ -x ai-service/.venv/bin/python ]]; then
        PY=ai-service/.venv/bin/python
    else
        PY=python3
    fi
    "$PY" - <<'PY'
from pathlib import Path
from ultralytics import YOLO
pt = Path("ai-service/models/hairnet_gloves_yolov8.pt")
out = Path("ai-service/models/hairnet_gloves_yolov8.onnx")
path = YOLO(str(pt)).export(format="onnx", imgsz=640, simplify=True, opset=12)
Path(path).replace(out)
print("exported", out)
PY
fi
if [[ -f "$PPE_FILE" ]]; then
    if [[ "$(shasum -a 256 "$PPE_FILE" | cut -d' ' -f1)" != "$PPE_SHA" ]]; then
        echo "Checksum $PPE_FILE tidak cocok; hapus file itu lalu jalankan ulang dengan KITCHEN_PPE=1."
        exit 1
    fi
else
    echo "Model APD dapur belum ada (opsional). Unduh/export dengan: KITCHEN_PPE=1 ./start.sh"
fi

if [[ ! -f public/build/manifest.json ]]; then
    echo "Build frontend belum ada, menjalankan npm run build..."
    npm run build
fi

php artisan migrate --force --no-interaction >/dev/null
php artisan db:seed --class=SystemSeeder --force --no-interaction >/dev/null

export AI_SERVICE_TOKEN="$(env_value AI_SERVICE_TOKEN)"
mkdir -p "$LOG_DIR"

trap 'echo; echo "Menghentikan layanan..."; kill $(jobs -p) 2>/dev/null; wait 2>/dev/null; exit 0' INT TERM

php -d upload_max_filesize=600M -d post_max_size=620M -d memory_limit=512M \
    artisan serve --host=127.0.0.1 --port=$APP_PORT >"$LOG_DIR/serve.log" 2>&1 &
php artisan queue:work redis --tries=3 --timeout=900 >"$LOG_DIR/queue.log" 2>&1 &
php artisan schedule:work >"$LOG_DIR/schedule.log" 2>&1 &
(cd ai-service && exec .venv/bin/uvicorn worker.api:create_app --factory --host 127.0.0.1 --port $AI_PORT) >"$LOG_DIR/ai-api.log" 2>&1 &
(cd ai-service && exec .venv/bin/python -m worker.runner) >"$LOG_DIR/ai-runner.log" 2>&1 &

sleep 4
php artisan system:status || true

cat <<EOF

VERA is running at http://127.0.0.1:$APP_PORT
Log: storage/logs/{serve,queue,schedule,ai-api,ai-runner}.log
Tekan Ctrl+C untuk menghentikan semua layanan.
EOF

wait
