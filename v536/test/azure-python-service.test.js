const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const service = fs.readFileSync(path.join(__dirname, "../service.py"), "utf8");
const server = fs.readFileSync(path.join(__dirname, "../server.js"), "utf8");
const setup = fs.readFileSync(path.join(__dirname, "../setup-azure.sh"), "utf8");
const reqs = fs.readFileSync(path.join(__dirname, "../requirements-azure.txt"), "utf8");

test("Azure runtime is pinned to the Python Speech SDK in a venv", () => {
  assert.match(reqs, /^azure-cognitiveservices-speech==1\.51\.2\s*$/m);
  assert.match(reqs, /^fastapi>=0\.115,<1\s*$/m);
  assert.match(reqs, /^uvicorn\[standard\]>=0\.30,<1\s*$/m);
  assert.match(server, /AZURE_PYTHON_BIN/);
  assert.match(server, /venv_asr/);
  assert.match(setup, /-m pip install -r requirements-azure\.txt/);
  assert.match(setup, /import fastapi/);
  assert.match(setup, /import uvicorn/);
});

test("Python service implements the Azure continuous Speech SDK sample pattern", () => {
  for (const pattern of [
    /speechsdk\.SpeechConfig/,
    /speechsdk\.SpeechRecognizer/,
    /speechsdk\.audio\.PushAudioInputStream/,
    /speechsdk\.PronunciationAssessmentConfig/,
    /speechsdk\.PronunciationAssessmentResult\(result\)/,
    /start_continuous_recognition\(\)/,
    /session_stopped\.connect/,
    /canceled\.connect/
  ]) assert.match(service, pattern);
});

test("Continuous mode uses the hidden passage for pronunciation scoring but leaves structural miscues to JS", () => {
  assert.match(service, /reference_text=self\.reference_text/);
  assert.match(service, /enable_miscue=False/);
  assert.match(service, /filter_structural_miscues/);
  assert.match(service, /Azure owns pronunciation evidence/);
});

test("Azure word evidence includes accuracy, error type, timing and phoneme candidates", () => {
  for (const pattern of [
    /accuracy/, /errorType/, /offsetSeconds/, /durationSeconds/, /phonemes/, /NBestPhonemes/
  ]) assert.match(service, pattern);
});

test("The service has explicit diagnostics for the old zero-recognized-results failure", () => {
  for (const field of [
    "recognizedResults", "wordEvidence", "jsonWordEvidence", "sdkWordEvidence",
    "audioBytesReceived", "sessionStarted", "sessionStopped", "serviceConnected", "writerFailed"
  ]) assert.match(service, new RegExp(field.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
});

test("Azure service startup reuses an already-healthy 8011 service and rejects failed child startup", () => {
  assert.match(server, /existing healthy service detected; reusing url=/);
  assert.match(server, /if \(await azureServiceHealthy\(\)\)/);
  assert.match(server, /exited before becoming ready/);
  assert.match(server, /service process exited before becoming healthy/);
  assert.match(server, /AZURE_SERVICE_PORT_IN_USE/);
  assert.match(server, /v536-fastapi-1/);
  assert.match(server, /tcpPortInUse/);
  assert.match(service, /SERVICE_BUILD = "v536-fastapi-1"/);
});
