const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync(require.resolve("../server.js"), "utf8");
const start = source.indexOf("function cleanPassage");
const end = source.indexOf("function buildPassageKeywords");
assert.ok(start >= 0 && end > start, "Realtime prompt helper region not found");

const helperSource = [
  source.slice(start, end),
  "module.exports = { cleanPassage, buildRealtimeTranscriptionPrompt, REALTIME_TRANSCRIPTION_PROMPT_MAX };"
].join("\n");

const sandbox = { module: { exports: {} }, exports: {}, String, Set, Math };
vm.runInNewContext(helperSource, sandbox, { filename: "server-realtime-prompt-test.js" });
const { buildRealtimeTranscriptionPrompt, REALTIME_TRANSCRIPTION_PROMPT_MAX } = sandbox.module.exports;

test("literal transcription prompt never exceeds 1024 characters", () => {
  const prompt = buildRealtimeTranscriptionPrompt();
  assert.ok(prompt.length <= REALTIME_TRANSCRIPTION_PROMPT_MAX);
  assert.equal(REALTIME_TRANSCRIPTION_PROMPT_MAX, 1024);
});

test("prompt uses the exact canonical literal-transcription instruction", () => {
  const prompt = buildRealtimeTranscriptionPrompt();
  const expected = [
    "Student is reading a supplied passage. Use passage context only to improve recognition of names, vocabulary, and word boundaries.",
    "Transcribe ONLY audible speech. Do not autocorrect to the passage, invent or delete words, reorder, paraphrase, or summarize.",
    "Preserve repeats, restarts, substitutions, reversals, transpositions, and self-corrections; keep audible attempts separate.",
    "Pay attention to short function words (a, an, the, to, in, of, and, with), but never insert one unless audible. Prefer audio over passage context."
  ].join(" ");
  assert.equal(prompt, expected);
});

test("prompt contains no rolling passage excerpt", () => {
  const prompt = buildRealtimeTranscriptionPrompt();
  assert.doesNotMatch(prompt, /Nearby passage/);
  assert.doesNotMatch(prompt, /soft context/i);
});
