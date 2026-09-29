const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");

const source = fs.readFileSync(require.resolve("../public/app.js"), "utf8");
const server = fs.readFileSync(require.resolve("../server.js"), "utf8");

test("OpenAI has no rolling passage session.update mechanism", () => {
  assert.doesNotMatch(source, /type:\s*["']session\.update["']/);
  assert.doesNotMatch(source, /scheduleRealtimeSoftContextUpdate/);
  assert.doesNotMatch(source, /buildRealtimeSoftContext/);
  assert.doesNotMatch(source, /soft_context_/);
});

test("OpenAI transcription configuration uses one fixed prompt plus passage keywords", () => {
  assert.match(server, /buildRealtimeTranscriptionPrompt/);
  assert.match(server, /transcription\.prompt = buildRealtimeTranscriptionPrompt/);
  assert.match(server, /transcription\.keywords = buildPassageKeywords/);
  assert.doesNotMatch(source, /input_audio_transcription\.delta[\s\S]{0,800}session\.update/);
});

test("fixed transcription instruction contains the user's literal-audio rules", () => {
  for (const text of [
    "Transcribe ONLY audible speech",
    "Do not autocorrect to the passage",
    "never insert one unless audible",
    "Prefer audio over passage context",
    "self-corrections; keep audible attempts separate"
  ]) assert.ok(server.includes(text), `missing canonical instruction: ${text}`);
});
