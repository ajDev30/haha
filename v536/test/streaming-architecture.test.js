const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");

const root = require("node:path").join(__dirname, "..");
const server = fs.readFileSync(require.resolve("../server.js"), "utf8");
const service = fs.readFileSync(require.resolve("../service.py"), "utf8");
const app = fs.readFileSync(require.resolve("../public/app.js"), "utf8");
const core = fs.readFileSync(require.resolve("../public/assessment-core.js"), "utf8");
const worklet = fs.readFileSync(require.resolve("../public/pcm-processor.js"), "utf8");
const pkg = JSON.parse(fs.readFileSync(require.resolve("../package.json"), "utf8"));
const reqs = fs.readFileSync(require.resolve("../requirements-azure.txt"), "utf8");

test("Azure uses the Python FastAPI WebSocket service, not a Node Azure SDK worker", () => {
  assert.match(server, /service\.py/);
  assert.match(server, /azureServiceWsUrl/);
  assert.match(server, /\/azure-stream/);
  assert.doesNotMatch(server, /azure_worker\.py/);
  assert.equal(pkg.dependencies["microsoft-cognitiveservices-speech-sdk"], undefined);
  assert.match(reqs, /azure-cognitiveservices-speech==1\.51\.2/);
});

test("Azure uses continuous PushAudioInputStream with scripted reference text and EnableMiscue disabled", () => {
  assert.match(service, /PushAudioInputStream/);
  assert.match(service, /SpeechRecognizer/);
  assert.match(service, /PronunciationAssessmentConfig/);
  assert.match(service, /reference_text=self\.reference_text/);
  assert.match(service, /enable_miscue=False/);
  assert.match(service, /start_continuous_recognition\(\)/);
  assert.match(service, /request_word_level_timestamps/);
  assert.match(service, /SpeechServiceResponse_JsonResult|result\.json/);
});

test("Azure ready is gated on the actual Azure session_started callback", () => {
  assert.match(service, /self\.session_started_event\.wait\(timeout=session_wait\)/);
  assert.match(service, /session_started\.connect\(self\._on_session_started\)/);
  assert.match(service, /Do not tell the browser that Azure is ready until Azure itself reports/);
});

test("Audio writes use a dedicated queue-backed writer thread", () => {
  assert.match(service, /audio_writer_thread/);
  assert.match(service, /threading\.Thread\(/);
  assert.match(service, /self\.audio_queue\.get\(\)/);
  assert.match(service, /self\.push_stream\.write\(chunk\)/);
});

test("Normal Azure path contains no WAV upload or ffmpeg conversion", () => {
  assert.doesNotMatch(server, /multer/);
  assert.doesNotMatch(app, /\/api\/analyze/);
  assert.doesNotMatch(service, /wave\.open|ffmpeg|\.wav/);
});

test("Normal Stop closes the live Azure stream and waits for Azure finalization", () => {
  assert.match(app, /stopAzureStreaming/);
  assert.match(server, /JSON\.stringify\(\{ type: "stop" \}\)/);
  assert.match(service, /self\.session_stopped_event\.wait\(timeout=finish_timeout\)/);
  assert.match(service, /azure\.final/);
  assert.match(service, /azure\.done/);
});

test("Browser sends raw 16 kHz PCM16 chunks to Azure", () => {
  assert.match(worklet, /16000/);
  assert.match(worklet, /Int16Array/);
  assert.match(app, /pcm16_16k/);
  assert.match(server, /raw 16 kHz mono PCM16 chunks/);
});

test("OpenAI and Azure consume the same microphone session independently", () => {
  assert.match(app, /connectRealtime\(story, els\.locale\.value, mediaStream, sessionId\)/);
  assert.match(app, /connectAzureStreaming\(story, els\.locale\.value, sessionId\)/);
  assert.match(app, /referenceText: String\(referenceText \|\| ""\)/);
  assert.match(app, /openaiTranscriptionPasses: 1/);
});

test("Structural miscue logic remains in assessment-core and Azure is an evidence overlay", () => {
  assert.match(core, /Only a structural match can become a Mispronunciation/);
  assert.match(core, /op\.type === 'match'/);
  assert.match(core, /detectSelfCorrections\(refs, spoken, azureBySpoken/);
  assert.doesNotMatch(core, /suppressAzureCorroboratedOmissions/);
});

test("OpenAI literal-transcription prompt remains fixed and keyword-only", () => {
  assert.match(server, /gpt-live-transcribe/);
  assert.match(server, /buildRealtimeTranscriptionPrompt/);
  assert.match(server, /transcription\.keywords = buildPassageKeywords/);
  assert.doesNotMatch(app, /type:\s*["']session\.update["']/);
});
