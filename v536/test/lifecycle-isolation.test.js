const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");

const app = fs.readFileSync(require.resolve("../public/app.js"), "utf8");
const server = fs.readFileSync(require.resolve("../server.js"), "utf8");
const html = fs.readFileSync(require.resolve("../public/index.html"), "utf8");


test("Retry is a distinct cancel-and-restart action", () => {
  assert.match(html, /id="retryBtn"/);
  assert.match(app, /async function retryRecording\(\)/);
  assert.match(app, /recordingSessionId \+= 1/);
  assert.match(app, /cancel.*do not finalize/i);
  assert.match(app, /abortRealtimeSession/);
  assert.match(app, /abortAzureStreaming/);
  assert.match(app, /resetResults\(\);\n    resetRealtimeState\(\)/);
  assert.match(app, /await startRecording\(\)/);
});

test("OpenAI events from an abandoned attempt cannot mutate the current attempt", () => {
  assert.match(app, /function handleRealtimeEvent\(evt, sessionId = recordingSessionId\)/);
  assert.match(app, /!isCurrentAttempt\(sessionId\)/);
  assert.match(app, /handleRealtimeEvent\(JSON\.parse\(event\.data\), sessionId\)/);
  assert.match(app, /dc\.onmessage = event => \{\n    if \(!isCurrentAttempt\(sessionId\)/);
});

test("Azure events from an abandoned attempt cannot mutate the current attempt", () => {
  assert.match(app, /function handleAzureStreamEvent\(evt, sessionId = recordingSessionId\)/);
  assert.match(app, /handleAzureStreamEvent\(evt, sessionId\)/);
  assert.match(app, /socket\.onclose = event => \{\n      if \(!isCurrentAttempt\(sessionId\)/);
});

test("Azure connection keeps its WebSocket handle in scope after readiness", () => {
  const start = app.indexOf("async function connectAzureStreaming(");
  const end = app.indexOf("async function abortAzureStreaming(", start);
  assert.ok(start >= 0 && end > start);
  const block = app.slice(start, end);
  assert.match(block, /const socket = new WebSocket\(azureWebSocketUrl\(\)\);/);
  assert.match(block, /azureSocket = socket;/);
  assert.match(block, /socket !== azureSocket/);
  assert.doesNotMatch(block, /new Promise\(.*\{\s*const socket = new WebSocket/s);
});

test("Retry stops accepting AudioWorklet data before closing transports", () => {
  const start = app.indexOf("async function retryRecording()");
  const block = app.slice(start, start + 2600);
  assert.match(block, /running = false/);
  assert.match(block, /azureAcceptingAudio = false/);
  assert.match(block, /await stopLocalAudioCapture\(\)/);
  assert.match(block, /abortRealtimeSession/);
  assert.match(block, /abortAzureStreaming/);
});

test("Azure timeout aborts without fabricating azure.done", () => {
  const start = server.indexOf('stopTimer = setTimeout(() => {');
  const end = server.indexOf('      }, Number(process.env.AZURE_FINALIZATION_TIMEOUT_MS || 60000));', start);
  assert.ok(start >= 0 && end > start);
  const timeoutBlock = server.slice(start, end);
  assert.match(timeoutBlock, /azure\.error/);
  assert.match(timeoutBlock, /azure\.aborted/);
  assert.doesNotMatch(timeoutBlock, /send\(\{[^}]*type:\s*["']azure\.done["']/s);
});

test("Client reveals assessment only when Azure done is true", () => {
  assert.match(app, /const azureFinished = Boolean\(azureFinal\?\.doneSeen \?\? azureDoneSeen\)/);
  assert.match(app, /if \(!azureFinished\)/);
  assert.match(app, /renderAssessment\(data\)/);
});
