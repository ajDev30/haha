const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");

const app = fs.readFileSync(require.resolve("../public/app.js"), "utf8");
const server = fs.readFileSync(require.resolve("../server.js"), "utf8");


test("assessment markup is withheld until Azure done", () => {
  assert.match(app, /const azureFinished = Boolean\(azureFinal\?\.doneSeen \?\? azureDoneSeen\)/);
  assert.match(app, /if \(!azureFinished\)/);
  assert.match(app, /Assessment markup was withheld/);
  assert.match(app, /renderAssessment\(data\)/);
});

test("Azure final does not resolve the stop promise before azure.done", () => {
  const finalBlockStart = app.indexOf('if (evt.type === "azure.final")');
  const doneBlockStart = app.indexOf('if (evt.type === "azure.done")');
  assert.ok(finalBlockStart >= 0 && doneBlockStart > finalBlockStart);
  const finalBlockEnd = app.indexOf('  if (evt.type === "azure.aborted")', finalBlockStart);
  const finalBlock = app.slice(finalBlockStart, finalBlockEnd);
  assert.doesNotMatch(finalBlock, /azureStopPromise\.resolve/);
  assert.match(finalBlock, /azureFinalSeen = true/);
  const abortedBlock = app.slice(finalBlockEnd, doneBlockStart);
  assert.match(abortedBlock, /azureStopPromise\.resolve/);
  assert.match(abortedBlock, /doneSeen: false/);
  assert.match(app.slice(doneBlockStart, doneBlockStart + 900), /azureStopPromise\.resolve/);
});

test("Retry is disabled throughout finalization and start is guarded against re-entry", () => {
  assert.match(app, /let finalizingPromise = null/);
  assert.match(app, /if \(finalizingPromise\) \{/);
  assert.match(app, /els\.startBtn\.disabled = true/);
  assert.match(app, /els\.resetBtn\.disabled = true/);
  assert.match(app, /finalizingPromise = \(async \(\) => \{/);
  assert.match(app, /finalizingPromise = null/);
});

test("Late AudioWorklet audio is blocked immediately when stopping", () => {
  const stopIndex = app.indexOf('async function stopRecording()');
  assert.ok(stopIndex >= 0);
  const stopBlock = app.slice(stopIndex, stopIndex + 6500);
  assert.match(stopBlock, /azureAcceptingAudio = false/);
  assert.match(stopBlock, /stopLocalAudioCapture\(\)/);
  assert.match(stopBlock, /stopLocalAudioCapture\(\)/);
});

test("Server treats Azure stop as a finalization transaction", () => {
  assert.match(server, /JSON\.stringify\(\{ type: "stop" \}\)/);
  assert.match(server, /AZURE_FINALIZATION_TIMEOUT_MS/);
  assert.match(server, /azure\.error/);
  assert.match(server, /azure\.aborted/);
  assert.match(server, /upstream\?\.close/);
});
