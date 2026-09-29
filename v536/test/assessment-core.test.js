const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const code = fs.readFileSync(path.join(__dirname, "../public/assessment-core.js"), "utf8");
const context = { window: {} };
vm.runInNewContext(code, context);
const Core = context.window.PronunciationAssessment;

function az(words) {
  return words.map((word, index) => ({
    word: word.word ?? word,
    accuracy: word.accuracy ?? 98,
    errorType: word.errorType ?? "None",
    confidence: word.confidence,
    offset: word.offset ?? index * 1000000,
    duration: word.duration ?? 1000000,
    phonemes: word.phonemes || []
  }));
}

function types(a) { return a.ops.map(o => o.type); }

// -------------------------------
// Core alignment / miscues
// -------------------------------

test("exact reading stays clean", () => {
  const a = Core.analyze("Maya sat there", "Maya sat there", az(["Maya", "sat", "there"]));
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.counts.substitution, 0);
  assert.equal(a.counts.repetition, 0);
  assert.equal(a.counts.selfCorrection, 0);
});

test("intentional skip is omission", () => {
  const a = Core.analyze("Maya sat there", "Maya there", az(["Maya", "there"]));
  assert.equal(a.counts.omission, 1);
  assert.equal(a.ops.find(o => o.type === "omit")?.ref, 1);
  assert.equal(a.counts.mispronunciation, 0);
});

test("insertion remains insertion", () => {
  const a = Core.analyze("Maya walked", "Maya very walked", []);
  assert.equal(a.counts.insertion, 1);
  assert.equal(a.counts.selfCorrection, 0);
});

test("substitution remains substitution instead of omission", () => {
  const a = Core.analyze("Maya sat there", "Maya saw there", []);
  assert.equal(a.counts.substitution, 1);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
});

test("nearby omit+insert recovery can become substitution", () => {
  const a = Core.analyze("The little bird sang", "The little word sang", [], { substitutionCost: 2.8 });
  assert.equal(a.counts.substitution, 1);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
});

// -------------------------------
// Azure pronunciation decisions
// -------------------------------

test("Azure owns pronunciation classification; AccuracyScore alone does not trigger a local mispronunciation", () => {
  const a = Core.analyze("fallen flower", "fallen flower", az([
    { word: "fallen", accuracy: 69, errorType: "None" },
    { word: "flower", accuracy: 98, errorType: "None" }
  ]));
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.ops[0].type, "match");
});

test("Azure ErrorType None is authoritative even when AccuracyScore is low", () => {
  const a = Core.analyze(
    "Maya walked",
    "Maya walked",
    az([
      { word: "Maya", accuracy: 41, errorType: "None" },
      { word: "walked", accuracy: 98, errorType: "None" }
    ])
  );
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.ops[0].type, "match");
});

test("Azure explicit Mispronunciation promotes an exact lexical match to pronunciation markup", () => {
  const a = Core.analyze("Maya walked", "Maya walked", az([
    { word: "Maya", accuracy: 82, errorType: "Mispronunciation" },
    { word: "walked", accuracy: 98 }
  ]));
  assert.equal(a.counts.mispronunciation, 1);
  assert.equal(a.counts.substitution, 0);
  assert.equal(a.ops[0].type, "mis");
});

test("A literal lexical substitution stays substitution even when Azure reports Mispronunciation on the replacement", () => {
  const a = Core.analyze("a fallen flower", "a pollen flower", az([
    { word: "a", accuracy: 98 },
    { word: "pollen", accuracy: 42, errorType: "Mispronunciation" },
    { word: "flower", accuracy: 99 }
  ]));
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.counts.substitution, 1);
  assert.equal(a.spokenWords[a.ops.find(o => o.type === "sub")?.spoken]?.word, "pollen");
});

test("An omission is never promoted to mispronunciation by Azure evidence", () => {
  const a = Core.analyze("Maya sat there", "Maya there", az([
    { word: "Maya", accuracy: 98 },
    { word: "there", accuracy: 42, errorType: "Mispronunciation" }
  ]));
  assert.equal(a.counts.omission, 1);
  assert.equal(a.ops.some(o => o.type === "omit" && o.ref === 1), true);
  assert.equal(a.counts.mispronunciation, 1);
  assert.equal(a.ops.some(o => o.type === "mis" && o.spoken === 1), true);
});

// -------------------------------
// Reversal / transposition
// -------------------------------

test("whole-word reversal is checked before pronunciation classification", () => {
  const a = Core.analyze("the word was here", "the word saw here", az([
    { word: "the", accuracy: 98 },
    { word: "word", accuracy: 98 },
    { word: "saw", accuracy: 35, errorType: "Mispronunciation" },
    { word: "here", accuracy: 98 }
  ]));
  assert.equal(a.counts.reversal, 1);
  assert.equal(a.counts.mispronunciation, 0);
});

test("one adjacent letter swap is reversal", () => {
  const a = Core.analyze("from here", "form here", []);
  assert.equal(a.counts.reversal, 1);
  assert.equal(a.counts.substitution, 0);
});

test("adjacent word transposition is preserved", () => {
  const a = Core.analyze("Maya walked home", "Maya home walked", []);
  assert.equal(a.counts.transposition, 1);
  assert.equal(a.counts.substitution, 0);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
});

test("transposition does not get blocked by an exact anchor", () => {
  const a = Core.analyze("Maya walked home", "Maya home walked to", []);
  assert.equal(a.counts.transposition, 1);
  assert.equal(a.counts.insertion, 1);
});

// -------------------------------
// Compound words
// -------------------------------

test("blue bird spoken as bluebird is one compound match", () => {
  const a = Core.analyze("a tiny blue bird hopping", "a tiny bluebird hopping", az([
    { word: "a" }, { word: "tiny" }, { word: "bluebird" }, { word: "hopping" }
  ]));
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.counts.substitution, 0);
});

test("bluebird remains literal in spokenWords while hidden alignment consumes two reference words", () => {
  const a = Core.analyze("blue bird", "bluebird", []);
  assert.equal(a.spokenWords.length, 1);
  assert.equal(a.spokenWords[0].word, "bluebird");
  const compound = a.ops.find(o => o.type === "compound");
  assert.ok(compound);
  assert.deepEqual(Array.from(compound.refs), [0, 1]);
});

// -------------------------------
// Repetition
// -------------------------------

test("repetition remains repetition even when Azure reports Mispronunciation on the repeated token", () => {
  const a = Core.analyze("Maya walked", "Maya walked walked", az([
    { word: "Maya", accuracy: 98 },
    { word: "walked", accuracy: 98 },
    { word: "walked", accuracy: 35, errorType: "Mispronunciation" }
  ]));
  assert.equal(a.counts.repetition, 1);
  assert.equal(a.counts.mispronunciation, 0);
});

test("single repeated word: later copy is repetition", () => {
  const a = Core.analyze("Maya walked home", "Maya walked walked home", []);
  assert.equal(a.counts.repetition, 1);
  assert.equal(a.counts.insertion, 0);
  const rep = a.ops.find(o => o.type === "repeat");
  assert.equal(rep.spoken, 2);
  assert.equal(rep.repeat.retraceSpoken, 1);
});

test("triple repeated phrase: first copy consumes reference, later copies are repetitions", () => {
  const a = Core.analyze(
    "Maya walked to the garden",
    "Yama walked Yama walked Yama walked to the garden",
    []
  );
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.repetition, 4);
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.hidden.diagnostics.repeatedSpokenWords, 4);
  assert.deepEqual(Array.from(a.ops.filter(o => o.type === "repeat").map(o => o.spoken)), [2, 3, 4, 5]);
});

test("repeated phrase in the trees in the trees is repetition, not insertion", () => {
  const a = Core.analyze(
    "she listened in the trees then she",
    "she listened in the trees in the trees then she",
    []
  );
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.repetition, 3);
  assert.deepEqual(Array.from(a.ops.filter(o => o.type === "repeat").map(o => o.spoken)), [5, 6, 7]);
});

test("multiple repetitions do not get promoted to pronunciation miscues by Azure", () => {
  const a = Core.analyze(
    "Maya walked to the garden",
    "Yama walked Yama walked Yama walked to the garden",
    az([
      { word: "Yama", accuracy: 41, errorType: "Mispronunciation" },
      { word: "walked", accuracy: 98 },
      { word: "Yama", accuracy: 90 },
      { word: "walked", accuracy: 98 },
      { word: "Yama", accuracy: 88 },
      { word: "walked", accuracy: 98 },
      { word: "to", accuracy: 98 },
      { word: "the", accuracy: 98 },
      { word: "garden", accuracy: 98 }
    ])
  );
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.repetition, 4);
  assert.equal(a.counts.mispronunciation, 0);
});

test("raw realtime transcript is the preferred spoken source", () => {
  const a = Core.analyze("Maya walked home", "Maya walked home", [], {
    realtimeText: "Maya walked walked home"
  });
  assert.equal(a.spokenWords.map(w => w.word).join(" "), "Maya walked walked home");
  assert.equal(a.counts.repetition, 1);
});

test("the hidden layer reports how many spoken tokens were excluded from reference consumption", () => {
  const a = Core.analyze("Maya walked", "Maya walked walked", []);
  assert.equal(a.hidden.diagnostics.spokenWords, 3);
  assert.equal(a.hidden.diagnostics.consumingSpokenWords, 2);
});

// -------------------------------
// Self-correction
// -------------------------------

test("wrong similar attempt followed by expected word is self-correction", () => {
  const a = Core.analyze("Maya walked home", "Maya walk walked home", az([
    { word: "Maya", accuracy: 98 },
    { word: "walk", accuracy: 58, errorType: "Mispronunciation" },
    { word: "walked", accuracy: 97 },
    { word: "home", accuracy: 98 }
  ]));
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.counts.substitution, 0);
  assert.equal(a.counts.repetition, 0);
  assert.equal(a.ops.find(o => o.type === "self-repair")?.spoken, 1);
  assert.equal(a.ops.find(o => o.type === "self-corrected")?.spoken, 2);
});

test("explicit repair marker enables self-correction", () => {
  const a = Core.analyze("Maya walked home", "Maya walk no walked home", []);
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.insertion, 0);
  assert.ok(a.ops.some(o => o.type === "self-marker" && o.spoken === 2));
});

test("ordinary unrelated insertion is not self-correction", () => {
  const a = Core.analyze("Maya walked home", "Maya very walked home", []);
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.insertion, 1);
});

test("two nearby phonetic attempts before the target can yield one self-correction event", () => {
  const a = Core.analyze("picked up a", "peek peck picked up a", []);
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.insertion, 1);
  const repair = a.ops.find(o => o.type === "self-repair");
  const corrected = a.ops.find(o => o.type === "self-corrected");
  assert.equal(repair?.spoken, 1);
  assert.equal(corrected?.spoken, 2);
  assert.equal(repair?.ref, corrected?.ref);
});

test("ordinary exact duplicate stays repetition", () => {
  const a = Core.analyze("Maya walked home", "Maya walked walked home", az([
    { word: "Maya", accuracy: 98 },
    { word: "walked", accuracy: 90 },
    { word: "walked", accuracy: 91 },
    { word: "home", accuracy: 98 }
  ]));
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.repetition, 1);
});

test("exact duplicate with strong Azure improvement is still repetition when there is no explicit repair cue", () => {
  const a = Core.analyze("Maya walked home", "Maya walked walked home", az([
    { word: "Maya", accuracy: 98, confidence: 0.98, offset: 0, duration: 3000000 },
    { word: "walked", accuracy: 55, confidence: 0.60, offset: 5000000, duration: 9000000 },
    { word: "walked", accuracy: 95, confidence: 0.90, offset: 15000000, duration: 4000000 },
    { word: "home", accuracy: 98, confidence: 0.98, offset: 20000000, duration: 3000000 }
  ]));
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.repetition, 1);
});

test("exact duplicate remains repetition even when Azure reports a pronunciation problem", () => {
  const a = Core.analyze("Maya walked home", "Maya walked walked home", az([
    { word: "Maya", accuracy: 98 },
    { word: "walked", accuracy: 45, errorType: "Mispronunciation" },
    { word: "walked", accuracy: 96 },
    { word: "home", accuracy: 98 }
  ]));
  // The conservative repeat-first rule intentionally keeps exact duplicates as
  // repetition unless a repair cue exists; this protects repeated reading.
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.repetition, 1);
});

test("self-correction too late to qualify is not self-correction", () => {
  const a = Core.analyze("Maya walked home", "Maya walk walked home", az([
    { word: "Maya", accuracy: 98, offset: 0, duration: 1000000 },
    { word: "walk", accuracy: 58, errorType: "Mispronunciation", offset: 2000000, duration: 1000000 },
    { word: "walked", accuracy: 97, offset: 120000000, duration: 1000000 },
    { word: "home", accuracy: 98, offset: 125000000, duration: 1000000 }
  ]));
  assert.equal(a.counts.selfCorrection, 0);
  assert.equal(a.counts.substitution + a.counts.insertion + a.counts.mispronunciation > 0, true);
});

test("self-correction stays tied to the same reference slot", () => {
  const a = Core.analyze("Maya walked home", "Maya walk walked home", []);
  const repair = a.ops.find(o => o.type === "self-repair");
  const corrected = a.ops.find(o => o.type === "self-corrected");
  assert.equal(repair.ref, corrected.ref);
  assert.equal(repair.ref, 1);
});

// -------------------------------
// Robustness / architecture checks
// -------------------------------

test("literal-transcription architecture keeps the live spoken transcript as the assessment source", () => {
  const raw = "Yama walked Yama walked";
  const a = Core.analyze("Maya walked", raw, []);
  assert.equal(a.spokenWords.map(w => w.word).join(" "), raw);
});

test("literal-transcription architecture exposes Azure evidence without changing visible spoken tokens", () => {
  const a = Core.analyze("Maya walked", "Maya walk walked", []);
  assert.equal(a.hidden.diagnostics.architecture, "literal live transcript + passage-keywords + azure-evidence + reference-alignment");
  assert.equal(a.spokenWords.length, 3);
});

test("progressive multi-attempt repair chain groups repeated near-target attempts into one self-correction", () => {
  const a = Core.analyze(
    "there could always",
    "there cool cold cold could always",
    az([
      { word: "there", accuracy: 98 },
      { word: "cool", accuracy: 52, errorType: "Mispronunciation" },
      { word: "cold", accuracy: 61, errorType: "Mispronunciation" },
      { word: "cold", accuracy: 64, errorType: "Mispronunciation" },
      { word: "could", accuracy: 95 },
      { word: "always", accuracy: 98 }
    ])
  );
  assert.equal(a.spokenWords.map(w => w.word).join(" "), "there cool cold cold could always");
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.repetition, 0);
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.counts.omission, 0);
  const sc = a.selfCorrections[0];
  assert.deepEqual(Array.from(sc.reparandums), [1, 2, 3]);
  assert.equal(sc.correction, 4);
  assert.equal(sc.reference, 1);
  assert.equal(sc.attempts.length, 3);
  assert.ok(a.ops.some(o => o.type === "self-repair" && o.spoken === 1));
  assert.ok(a.ops.some(o => o.type === "self-repair" && o.spoken === 2));
  assert.ok(a.ops.some(o => o.type === "self-repair" && o.spoken === 3));
  assert.ok(a.ops.some(o => o.type === "self-corrected" && o.spoken === 4));
});

test("two identical near-target attempts before the correct word can be one self-correction", () => {
  const a = Core.analyze(
    "there could always",
    "there cold cold could always",
    az([
      { word: "there", accuracy: 98 },
      { word: "cold", accuracy: 55, errorType: "Mispronunciation" },
      { word: "cold", accuracy: 63, errorType: "Mispronunciation" },
      { word: "could", accuracy: 95 },
      { word: "always", accuracy: 98 }
    ])
  );
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.repetition, 0);
  assert.deepEqual(Array.from(a.selfCorrections[0].reparandums), [1, 2]);
  assert.equal(a.selfCorrections[0].correction, 3);
});

test("cool-to-could self-correction works without a second contextual transcript", () => {
  const a = Core.analyze(
    "there could always be",
    "there cool could always",
    az(["there", "cool", "could", "always"])
  );
  assert.equal(a.spokenWords.map(w => w.word).join(" "), "there cool could always");
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.repetition, 0);
  assert.equal(a.counts.omission, 1);
  assert.ok(a.ops.some(o => o.type === "self-repair" && o.spoken === 1 && o.ref === 1));
  assert.ok(a.ops.some(o => o.type === "self-corrected" && o.spoken === 2 && o.ref === 1));
  assert.ok(a.ops.some(o => o.type === "match" && o.spoken === 3 && o.ref === 2));
});

test("weak Azure recognition does not erase a real short-word omission", () => {
  const a = Core.analyze(
    "The path led to a small bench",
    "The path led a small bench",
    az([
      { word: "The", accuracy: 98, confidence: 0.99 },
      { word: "path", accuracy: 98, confidence: 0.98 },
      { word: "led", accuracy: 98, confidence: 0.98 },
      { word: "a", accuracy: 98, confidence: 0.80 },
      { word: "small", accuracy: 98, confidence: 0.98 },
      { word: "bench", accuracy: 98, confidence: 0.98 }
    ]));
  assert.equal(a.counts.omission, 1);
  assert.equal(a.hidden.diagnostics.azureOmissionRecoveries, 0);
});



test("Azure Mispronunciation stays secondary on repetition and self-correction", () => {
  const repeated = Core.analyze("the dog ran", "the dog dog ran", az([
    { word: "the", accuracy: 98 },
    { word: "dog", accuracy: 98, errorType: "None" },
    { word: "dog", accuracy: 54, errorType: "Mispronunciation" },
    { word: "ran", accuracy: 98 }
  ]));
  const rep = repeated.ops.find(o => o.type === "repeat");
  assert.equal(rep.spoken, 2);
  assert.equal(rep.azurePronunciation, "Mispronunciation");
  assert.equal(repeated.counts.repetition, 1);
  assert.equal(repeated.counts.mispronunciation, 0);

  const repair = Core.analyze("there could always", "there cool could always", az([
    { word: "there", accuracy: 98 },
    { word: "cool", accuracy: 45, errorType: "Mispronunciation" },
    { word: "could", accuracy: 98, errorType: "None" },
    { word: "always", accuracy: 98 }
  ]));
  const attempt = repair.ops.find(o => o.type === "self-repair");
  const correction = repair.ops.find(o => o.type === "self-corrected");
  assert.ok(attempt);
  assert.ok(correction);
  assert.equal(attempt.azurePronunciation, "Mispronunciation");
  assert.equal(repair.counts.selfCorrection, 1);
});

test("Azure does not turn a well-pronounced lexical substitution into mispronunciation", () => {
  const a = Core.analyze(
    "a fallen flower",
    "a pollen flower",
    az([
      { word: "a", accuracy: 98, confidence: 0.99 },
      { word: "pollen", accuracy: 96, confidence: 0.95, errorType: "None" },
      { word: "flower", accuracy: 98, confidence: 0.99 }
    ]),
    { realtimeText: "a pollen flower" }
  );
  const pollen = a.ops.find(o => o.spoken === 1);
  assert.equal(pollen.type, "sub");
  assert.equal(a.counts.mispronunciation, 0);
  assert.equal(a.counts.substitution, 1);
});

test("no reference text means no assessment operations", () => {
  const a = Core.analyze("", "hello hello", []);
  assert.equal(a.ops.length, 0);
  assert.equal(a.counts.selfCorrection, 0);
});

test("empty transcript produces reference omissions", () => {
  const a = Core.analyze("Maya sat there", "", []);
  assert.equal(a.counts.omission, 3);
});

test("Reading Accuracy denominator is reference words and self-correction is not a miscue", () => {
  const a = Core.analyze("Maya walked home", "Maya walk walked home", []);
  const miscues = a.counts.mispronunciation + a.counts.omission + a.counts.substitution +
    a.counts.repetition + a.counts.transposition + a.counts.reversal;
  assert.equal(miscues, 0);
  assert.equal(a.referenceWords.length, 3);
});

test("insertion is visible but not part of the six-miscue count", () => {
  const a = Core.analyze("Maya walked", "Maya very walked", []);
  const six = a.counts.mispronunciation + a.counts.omission + a.counts.substitution +
    a.counts.repetition + a.counts.transposition + a.counts.reversal;
  assert.equal(a.counts.insertion, 1);
  assert.equal(six, 0);
});

assert.ok(types(Core.analyze("Maya walked", "Maya walked", [])).length > 0);


test("repair-aware reorder prevents next-word omission after self-correction", () => {
  const a = Core.analyze(
    "that even in a familiar place there could always be something new to discover",
    "that even in a familiar place there always cool could be something new to discover",
    []
  );
  assert.equal(a.counts.selfCorrection, 1);
  assert.equal(a.counts.omission, 0);
  assert.equal(a.counts.insertion, 0);
  assert.equal(a.counts.transposition, 1);
  assert.ok(a.ops.some(o => o.type === "trans" && o.ref === 8 && o.spoken === 7));
});

test("retraced self-correction is one SC, not an insertion cascade", () => {
  const reference = "The dog ran fast";
  const spoken = "The dog rat the dog ran fast";
  const result = Core.analyze(reference, spoken, az(["The", "dog", "rat", "the", "dog", "ran", "fast"]), { realtimeText: spoken });
  assert.equal(result.counts.insertion, 0);
  assert.equal(result.counts.repetition, 0);
  assert.equal(result.counts.selfCorrection, 1);
  const ops = result.ops.filter(o => o.type !== "match");
  assert.equal(ops.length, 4);
  assert.equal(ops[3].type, "self-corrected");
  assert.equal(ops[0].type, "self-repair"); // rat
  assert.equal(ops[1].type, "self-marker"); // the
  assert.equal(ops[2].type, "self-marker"); // dog
});

test("pure phrase repetition remains repetition, not SC", () => {
  const reference = "She looked at the bird";
  const spoken = "She looked at the, looked at the bird";
  const result = Core.analyze(reference, spoken, az(["She", "looked", "at", "the", "looked", "at", "the", "bird"]), { realtimeText: spoken });
  assert.equal(result.counts.selfCorrection, 0);
  assert.equal(result.counts.repetition, 3);
});


test('Azure timing is attached to the individual spoken token', () => {
  const assessment = Core.analyze(
    'the cat sat',
    'the cat sat',
    [
      { word: 'the', offset: 10000000, duration: 5000000, accuracy: 96, errorType: 'None' },
      { word: 'cat', offset: 16000000, duration: 4000000, accuracy: 94, errorType: 'None' },
      { word: 'sat', offset: 21000000, duration: 3000000, accuracy: 92, errorType: 'None' }
    ]
  );
  const cat = assessment.evidenceBySpoken?.get?.(1);
  assert.ok(cat);
  assert.equal(cat.timing.startSeconds, 1.6);
  assert.equal(cat.timing.durationSeconds, 0.4);
  assert.equal(cat.timing.endSeconds, 2.0);
});
