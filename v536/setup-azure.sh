#!/usr/bin/env bash
set -euo pipefail

npm install

PYTHON_BIN="${AZURE_PYTHON_BIN:-/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/bin/python}"

if [ ! -x "$PYTHON_BIN" ]; then
  PYTHON_BIN="$(pwd)/venv_asr/bin/python"
fi

if [ ! -x "$PYTHON_BIN" ]; then
  PYTHON_BIN="$(pwd)/.azure-venv/bin/python"
  if [ ! -x "$PYTHON_BIN" ]; then
    python3 -m venv .azure-venv
  fi
fi

"$PYTHON_BIN" -m pip install -r requirements-azure.txt

"$PYTHON_BIN" - <<'PY_CHECK'
import azure.cognitiveservices.speech as speechsdk
import fastapi
import uvicorn
print(f"Azure Speech SDK: {speechsdk.__version__ if hasattr(speechsdk, '__version__') else 'installed'}")
print(f"FastAPI: {fastapi.__version__}")
print(f"Uvicorn: {uvicorn.__version__}")
PY_CHECK

printf '\nAzure Python Speech SDK installed for: %s\n' "$PYTHON_BIN"
printf 'Node proxies /azure-stream to the Python FastAPI WebSocket service at AZURE_SERVICE_HOST:AZURE_SERVICE_PORT.\n'
printf 'Set AZURE_PYTHON_BIN to override the service interpreter at runtime.\n'
