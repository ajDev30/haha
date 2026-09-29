(function (global) {
  "use strict";

  const DEFAULTS = {
    substitutionCost: 1.05,
    omissionCost: 1.15,
    insertionCost: 1.10,
    exactCost: 0,
    omissionSubstitutionSimilarity: 0.62,
    reversalCost: 0.30,
    singleLetterSwapCost: 0.38,

    // Repetition/repair settings for the hidden assessment layer.
    selfCorrectionMaxGapSeconds: 3.0,
    selfCorrectionMaxTokenGap: 2,
    // Allow a short chain of successive near-target attempts before the final repair.
    // Example: "cool cold cold could" -> one self-correction episode.
    selfCorrectionMaxAttemptCount: 4,
    selfCorrectionMaxAttemptSpan: 6,
    selfCorrectionMinSimilarityGain: 0.10,
    selfCorrectionStrongSimilarity: 0.65,
    contextualStrongSimilarity: 0.84,
    contextualMinSimilarityGain: 0.05,
    phraseRepeatMinLength: 2,
    phraseRepeatMaxLength: 5,

    // Stable-anchor settings. Anchors are only exact, low-ambiguity matches;
    // they are used to stop one bad region from shifting all later alignment.
    anchorLookahead: 24,
    minAnchorTokenLength: 4
  };

  const REPAIR_MARKERS = new Set([
    "no", "not", "sorry", "wait", "oops", "mean", "actually", "rather", "i", "meant"
  ]);

  const COMMON_WORDS = new Set([
    "a", "an", "the", "and", "or", "but", "to", "of", "in", "on", "at", "for", "from",
    "by", "with", "as", "is", "was", "were", "be", "been", "her", "his", "their", "there",
    "that", "this", "it", "its", "she", "he", "they", "we", "i", "you", "me", "my", "our",
    "then", "so", "very", "not"
  ]);

  // Very short function words are easy for live ASR to drop. Azure is now
  // configured with the hidden passage as ReferenceText, so a strong exact
  // Azure word result at the expected slot is an independent audio corroborator.
  // It may suppress a false omission, but it never rewrites the visible OpenAI
  // transcript.
  const AZURE_OMISSION_CORROBORATION_WORDS = new Set([
    "a", "an", "the", "and", "or", "but", "to", "of", "in", "on", "at",
    "for", "from", "by", "with", "as", "is", "was", "were", "be", "been",
    "her", "his", "their", "there", "that", "this", "it", "its", "she",
    "he", "they", "we", "i", "you", "me", "my", "our", "then"
  ]);

  function normalizeWord(word) {
    return String(word || "")
      .toLowerCase()
      .replace(/[’']/g, "")
      .replace(/[^a-z0-9]+/g, "")
      .trim();
  }

  function tokenize(text) {
    return String(text || "")
      .split(/\s+/)
      .map(w => w.replace(/^[^\w’']+|[^\w’']+$/g, ""))
      .filter(Boolean);
  }

  function safeWords(words) {
    return (words || []).map((w, i) => {
      if (typeof w === "string") return { word: w, norm: normalizeWord(w), index: i };
      return { ...w, word: String(w.word || ""), norm: w.norm || normalizeWord(w.word), index: i };
    });
  }

  function numericScore(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return null;
    return n <= 1.0001 ? n * 100 : n;
  }

  function azureOffsetSeconds(word) {
    const explicit = Number(word?.offsetSeconds);
    if (Number.isFinite(explicit) && explicit >= 0) return explicit;
    const n = Number(word?.offset);
    if (!Number.isFinite(n) || n < 0) return null;
    return n > 10000 ? n / 10000000 : n / 1000;
  }

  function azureDurationSeconds(word) {
    const explicit = Number(word?.durationSeconds);
    if (Number.isFinite(explicit) && explicit > 0) return explicit;
    const n = Number(word?.duration);
    if (!Number.isFinite(n) || n <= 0) return null;
    return n > 10000 ? n / 10000000 : n / 1000;
  }

  function azureTiming(word) {
    const startSeconds = azureOffsetSeconds(word);
    const durationSeconds = azureDurationSeconds(word);
    if (!Number.isFinite(startSeconds)) return null;
    const endSeconds = Number.isFinite(durationSeconds) && durationSeconds > 0
      ? startSeconds + durationSeconds
      : null;
    return { startSeconds, durationSeconds, endSeconds };
  }

  function azureGapSeconds(a, b) {
    const oa = Number(a?.offset), da = Number(a?.duration), ob = Number(b?.offset);
    if (![oa, da, ob].every(Number.isFinite)) return null;
    const raw = ob - (oa + da);
    return raw >= 0 ? raw / 10000000 : 0;
  }

  function isSingleAdjacentSwap(expected, spoken) {
    const a = normalizeWord(expected), b = normalizeWord(spoken);
    if (a.length < 3 || a.length !== b.length) return false;
    const diffs = [];
    for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) diffs.push(i);
    if (diffs.length !== 2) return false;
    const [i, j] = diffs;
    return i + 1 === j && a[i] === b[j] && a[j] === b[i];
  }

  // Reversal is intentionally checked before pronunciation classification.
  // Examples: was -> saw, dog -> god, from -> form.
  function isReverseToken(expected, spoken) {
    const a = normalizeWord(expected);
    const b = normalizeWord(spoken);
    if (a.length < 3 || a.length !== b.length) return false;
    if (a.split("").reverse().join("") === b) return true;
    return isSingleAdjacentSwap(a, b);
  }

  function lexicalSimilarity(a, b) {
    const x = normalizeWord(a), y = normalizeWord(b);
    if (!x || !y) return 0;
    if (x === y) return 1;
    const max = Math.max(x.length, y.length);
    let prev = Array.from({ length: y.length + 1 }, (_, i) => i);
    for (let i = 1; i <= x.length; i++) {
      const row = [i];
      for (let j = 1; j <= y.length; j++) {
        const cost = x[i - 1] === y[j - 1] ? 0 : 1;
        row[j] = Math.min(row[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
      }
      prev = row;
    }
    return 1 - prev[y.length] / max;
  }

  function isCompoundEquivalent(a, b) {
    const x = normalizeWord(a), y = normalizeWord(b);
    return Boolean(x && y && (x.replace(/s$/, "") === y.replace(/s$/, "")));
  }

  function scoreMatch(ref, spoken) {
    const a = ref.norm || normalizeWord(ref.word);
    const b = spoken.norm || normalizeWord(spoken.word);
    if (a === b) return 0;
    if (isCompoundEquivalent(a, b)) return 0.15;
    if (isReverseToken(a, b)) return isSingleAdjacentSwap(a, b) ? DEFAULTS.singleLetterSwapCost : DEFAULTS.reversalCost;
    const similarity = lexicalSimilarity(a, b);
    if (similarity >= 0.90) return 0.20;
    if (similarity >= 0.75) return 0.55;
    if (similarity >= 0.55) return 0.92;
    return DEFAULTS.substitutionCost;
  }

  // Generic weighted monotonic alignment. This is the local solver used inside
  // hidden anchor-bounded regions and is never fed repetition copies that were
  // already identified as non-consuming spoken events.
  function alignWords(referenceWords, spokenWords, options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const r = safeWords(referenceWords);
    const s = safeWords(spokenWords);
    const m = r.length, n = s.length;
    const dp = Array.from({ length: m + 1 }, () => Array(n + 1).fill(Infinity));
    const back = Array.from({ length: m + 1 }, () => Array(n + 1).fill(null));
    dp[0][0] = 0;
    for (let i = 1; i <= m; i++) { dp[i][0] = dp[i - 1][0] + cfg.omissionCost; back[i][0] = { type: "omit" }; }
    for (let j = 1; j <= n; j++) { dp[0][j] = dp[0][j - 1] + cfg.insertionCost; back[0][j] = { type: "insert" }; }

    for (let i = 1; i <= m; i++) {
      for (let j = 1; j <= n; j++) {
        const same = r[i - 1].norm === s[j - 1].norm;
        let matchType = same ? "match" : "sub";
        let matchCost = dp[i - 1][j - 1] + (same ? cfg.exactCost : scoreMatch(r[i - 1], s[j - 1]));

        if (!same && isReverseToken(r[i - 1].word, s[j - 1].word)) {
          matchType = "reverse";
          matchCost = Math.min(
            matchCost,
            dp[i - 1][j - 1] + (isSingleAdjacentSwap(r[i - 1].word, s[j - 1].word) ? cfg.singleLetterSwapCost : cfg.reversalCost)
          );
        }

        let best = matchCost;
        let step = { type: matchType };
        const omit = dp[i - 1][j] + cfg.omissionCost;
        const insert = dp[i][j - 1] + cfg.insertionCost;
        if (omit < best) { best = omit; step = { type: "omit" }; }
        if (insert < best) { best = insert; step = { type: "insert" }; }

        // Reference phrase -> one spoken token, e.g. blue bird -> bluebird.
        if (i >= 2) {
          const refCompound = normalizeWord(`${r[i - 2].word}${r[i - 1].word}`);
          if (refCompound && refCompound === s[j - 1].norm) {
            const cost = dp[i - 2][j - 1] + 0.05;
            if (cost <= best) { best = cost; step = { type: "compound", refs: [i - 2, i - 1], spoken: j - 1 }; }
          }
        }

        // Adjacent word transposition: A B -> B A.
        if (i >= 2 && j >= 2 &&
            r[i - 2].norm === s[j - 1].norm &&
            r[i - 1].norm === s[j - 2].norm &&
            r[i - 2].norm !== r[i - 1].norm) {
          const cost = dp[i - 2][j - 2] + 0.55;
          if (cost < best) { best = cost; step = { type: "trans", refs: [i - 2, i - 1], spokens: [j - 2, j - 1] }; }
        }

        dp[i][j] = best;
        back[i][j] = step;
      }
    }

    const ops = [];
    let i = m, j = n;
    while (i > 0 || j > 0) {
      const step = back[i][j];
      if (!step) break;
      if (step.type === "trans") {
        ops.push({ type: "trans", refs: step.refs.slice(), spokens: step.spokens.slice() }); i -= 2; j -= 2;
      } else if (step.type === "compound") {
        ops.push({ type: "compound", refs: step.refs.slice(), spoken: step.spoken }); i -= 2; j -= 1;
      } else if (["match", "sub", "reverse"].includes(step.type)) {
        ops.push({ type: step.type, ref: i - 1, spoken: j - 1 }); i--; j--;
      } else if (step.type === "omit") {
        ops.push({ type: "omit", ref: i - 1 }); i--;
      } else {
        ops.push({ type: "insert", spoken: j - 1, position: i }); j--;
      }
    }
    return ops.reverse();
  }

  function findReferencePhraseStarts(refs, phrase) {
    const out = [];
    const p = phrase.map(normalizeWord);
    for (let i = 0; i + p.length <= refs.length; i++) {
      const a = refs.slice(i, i + p.length).map(w => w.norm);
      if (a.join("|") === p.join("|")) out.push(i);
    }
    return out;
  }

  function buildStableAnchors(referenceWords, spokenUnits, options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const refs = safeWords(referenceWords);
    const units = safeWords(spokenUnits);
    const refCounts = new Map();
    const pairCounts = new Map();
    const refIndexByNorm = new Map();
    const blockedAnchors = new Set((cfg.anchorBlocked || []).map(Number));

    for (let i = 0; i < refs.length; i++) {
      const norm = refs[i].norm;
      refCounts.set(norm, (refCounts.get(norm) || 0) + 1);
      if (!refIndexByNorm.has(norm)) refIndexByNorm.set(norm, i);
    }
    for (let i = 0; i + 1 < refs.length; i++) {
      const key = `${refs[i].norm}|${refs[i + 1].norm}`;
      pairCounts.set(key, (pairCounts.get(key) || 0) + 1);
    }

    const uniqueRef = (norm, after = -1) => {
      if (!norm || refCounts.get(norm) !== 1) return -1;
      const idx = refIndexByNorm.get(norm);
      return idx != null && idx > after ? idx : -1;
    };

    // Reject an anchor that would immediately create a backwards reference
    // jump relative to a later strong exact token. This matters for true
    // transpositions such as reference "walked home" / spoken "home walked".
    const crossesFutureStrongToken = (spokenIndex, refIndex) => {
      const end = Math.min(units.length, spokenIndex + 7);
      for (let j = spokenIndex + 1; j < end; j++) {
        const nextNorm = units[j]?.norm;
        const nextRef = uniqueRef(nextNorm, -1);
        if (nextRef >= 0 && nextRef < refIndex) return true;
        if (j + 1 < end && units[j + 1]?.norm) {
          const pairKey = `${nextNorm}|${units[j + 1].norm}`;
          if (pairCounts.get(pairKey) === 1) {
            const pairRef = refs.findIndex((r, idx) => r.norm === nextNorm && refs[idx + 1]?.norm === units[j + 1].norm);
            if (pairRef >= 0 && pairRef < refIndex) return true;
          }
        }
      }
      return false;
    };

    const anchors = [];
    let lastRef = -1;
    let lastSpoken = -1;

    for (let i = 0; i < units.length; i++) {
      const first = units[i]?.norm;
      if (!first || i <= lastSpoken || blockedAnchors.has(i)) continue;

      // Prefer a unique exact two-word anchor, but only when it is not a
      // crossing anchor against a later strong token.
      if (i + 1 < units.length && units[i + 1]?.norm && !blockedAnchors.has(i + 1)) {
        const pairKey = `${first}|${units[i + 1].norm}`;
        if (pairCounts.get(pairKey) === 1) {
          const rStart = refs.findIndex((r, idx) => idx > lastRef && r.norm === first && refs[idx + 1]?.norm === units[i + 1].norm);
          if (rStart >= 0 && rStart - lastRef <= cfg.anchorLookahead + 4 && !crossesFutureStrongToken(i, rStart)) {
            anchors.push({ refStart: rStart, spokenStart: i, length: 2, strength: 2 });
            lastRef = rStart + 1;
            lastSpoken = i + 1;
            i++;
            continue;
          }
        }
      }

      if (COMMON_WORDS.has(first) || first.length < cfg.minAnchorTokenLength || refCounts.get(first) !== 1) continue;
      const rStart = uniqueRef(first, lastRef);
      if (rStart < 0) continue;
      if (crossesFutureStrongToken(i, rStart)) continue;
      anchors.push({ refStart: rStart, spokenStart: i, length: 1, strength: 1 });
      lastRef = rStart;
      lastSpoken = i;
    }

    const out = [];
    let prevRefEnd = -1, prevSpokenEnd = -1;
    for (const a of anchors) {
      if (a.refStart <= prevRefEnd || a.spokenStart <= prevSpokenEnd) continue;
      out.push(a);
      prevRefEnd = a.refStart + a.length - 1;
      prevSpokenEnd = a.spokenStart + a.length - 1;
    }
    return out;
  }

  function alignWithAnchors(referenceWords, spokenUnits, options = {}) {
    const refs = safeWords(referenceWords);
    const units = safeWords(spokenUnits);
    if (!refs.length && !units.length) return { ops: [], anchors: [] };
    const anchors = buildStableAnchors(refs, units, options);
    const ops = [];
    let refCursor = 0;
    let spokenCursor = 0;

    for (const anchor of anchors) {
      const refEnd = anchor.refStart;
      const spokenEnd = anchor.spokenStart;
      if (refEnd > refCursor || spokenEnd > spokenCursor) {
        const segR = refs.slice(refCursor, refEnd);
        const segS = units.slice(spokenCursor, spokenEnd);
        const localOps = alignWords(segR, segS, options);
        for (const op of localOps) {
          if (op.type === "trans") {
            ops.push({ ...op, refs: op.refs.map(x => refCursor + x), spokens: op.spokens.map(x => segS[x].index) });
          } else if (op.type === "compound") {
            ops.push({ ...op, refs: op.refs.map(x => refCursor + x), spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index });
          } else if (op.ref != null && op.spoken != null) {
            ops.push({ ...op, ref: refCursor + op.ref, spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index });
          } else if (op.ref != null) {
            ops.push({ ...op, ref: refCursor + op.ref });
          } else {
            ops.push({ ...op, spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index, position: refCursor + Number(op.position || 0) });
          }
        }
      }

      for (let k = 0; k < anchor.length; k++) {
        ops.push({ type: "match", ref: anchor.refStart + k, spoken: units[anchor.spokenStart + k].originalSpokenIndex ?? units[anchor.spokenStart + k].index, anchor: true });
      }
      refCursor = anchor.refStart + anchor.length;
      spokenCursor = anchor.spokenStart + anchor.length;
    }

    if (refCursor < refs.length || spokenCursor < units.length) {
      const segR = refs.slice(refCursor);
      const segS = units.slice(spokenCursor);
      const localOps = alignWords(segR, segS, options);
      for (const op of localOps) {
        if (op.type === "trans") {
          ops.push({ ...op, refs: op.refs.map(x => refCursor + x), spokens: op.spokens.map(x => segS[x].index) });
        } else if (op.type === "compound") {
          ops.push({ ...op, refs: op.refs.map(x => refCursor + x), spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index });
        } else if (op.ref != null && op.spoken != null) {
          ops.push({ ...op, ref: refCursor + op.ref, spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index });
        } else if (op.ref != null) {
          ops.push({ ...op, ref: refCursor + op.ref });
        } else {
          ops.push({ ...op, spoken: segS[op.spoken]?.originalSpokenIndex ?? segS[op.spoken]?.index, position: refCursor + Number(op.position || 0) });
        }
      }
    }

    return { ops: ops.sort((a, b) => {
      const sa = a.spoken != null ? Number(a.spoken) : Number.MAX_SAFE_INTEGER;
      const sb = b.spoken != null ? Number(b.spoken) : Number.MAX_SAFE_INTEGER;
      const ra = a.ref != null ? Number(a.ref) : Number(a.refs?.[0] ?? a.position ?? Number.MAX_SAFE_INTEGER);
      const rb = b.ref != null ? Number(b.ref) : Number(b.refs?.[0] ?? b.position ?? Number.MAX_SAFE_INTEGER);
      return sa - sb || ra - rb;
    }), anchors };
  }

  function alignAzureSequenceToSpoken(spokenWords, azureWords) {
    const spoken = safeWords(spokenWords), azure = safeWords(azureWords);
    const m = spoken.length, n = azure.length;
    if (!m || !n) return new Map();
    const dp = Array.from({ length: m + 1 }, () => Array(n + 1).fill(Infinity));
    const back = Array.from({ length: m + 1 }, () => Array(n + 1).fill(null));
    const gap = 0.92;
    dp[0][0] = 0;
    for (let i = 1; i <= m; i++) { dp[i][0] = i * gap; back[i][0] = "drop-spoken"; }
    for (let j = 1; j <= n; j++) { dp[0][j] = j * gap; back[0][j] = "drop-azure"; }
    for (let i = 1; i <= m; i++) {
      for (let j = 1; j <= n; j++) {
        const sim = lexicalSimilarity(spoken[i - 1].word, azure[j - 1].word);
        const mc = sim >= 0.90 ? 0 : sim >= 0.60 ? 0.25 : sim >= 0.35 ? 0.65 : 1.00;
        const a = dp[i - 1][j - 1] + mc;
        const b = dp[i - 1][j] + gap;
        const c = dp[i][j - 1] + gap;
        const best = Math.min(a, b, c);
        dp[i][j] = best;
        back[i][j] = best === a ? "pair" : best === b ? "drop-spoken" : "drop-azure";
      }
    }
    const map = new Map();
    let i = m, j = n;
    while (i > 0 || j > 0) {
      const step = back[i][j];
      if (!step) break;
      if (step === "pair") {
        const sim = lexicalSimilarity(spoken[i - 1].word, azure[j - 1].word);
        if (sim >= 0.25) map.set(i - 1, azure[j - 1]);
        i--; j--;
      } else if (step === "drop-spoken") i--;
      else j--;
    }
    return map;
  }

  function mapAzureEvidence(referenceWords, azureWords) {
    // Backwards-compatible reference-index map used by the UI as a fallback.
    const refs = safeWords(referenceWords);
    const az = safeWords(azureWords);
    const map = new Map();
    if (!refs.length || !az.length) return map;
    const ops = alignWords(refs, az, { omissionCost: 1.18, insertionCost: 1.08 });
    for (const op of ops) {
      if (op.ref == null || op.spoken == null) continue;
      if (!["match", "sub", "reverse"].includes(op.type)) continue;
      const aw = az[op.spoken];
      if (aw) map.set(Number(op.ref), aw);
    }
    return map;
  }

  // Azure owns the pronunciation decision. Do not impose an application-side
  // AccuracyScore threshold; in scripted pronunciation assessment Azure returns
  // the word-level ErrorType that represents its own pronunciation judgment.

  // FIRST ITERATION

  function isAzureMispronunciation(evidence) {
    if (!evidence) return false;
    return String(evidence.errorType || "").toLowerCase() === "mispronunciation";
  }
  //
  // 2nd ITERATION
//     function isAzureMispronunciation(evidence) {
//   if (!evidence) return false;
//
//   // Define your custom minimum accuracy for a correct pronunciation
//   const CUSTOM_THRESHOLD = 30; 
//   const accuracy = numericScore(evidence.accuracy);
//
//   // Evaluate against the raw score, ignoring Azure's default errorType
//   if (accuracy !== null) {
//       return accuracy < CUSTOM_THRESHOLD;
//   }
//
//   // Fallback just in case accuracy is missing
//   return String(evidence.errorType || "").toLowerCase() === "mispronunciation";
// }  

// function isAzureMispronunciation(evidence) {
//   if (!evidence) return false;
//
//   // 1. Overall word threshold (e.g., fails immediately if the average is bad)
//
//   const WORD_THRESHOLD = 40; 
//
//   // 2. Critical phoneme threshold (e.g., fails if ANY single sound is terrible)
//   const PHONEME_THRESHOLD = 15; 
//
//   const wordAccuracy = numericScore(evidence.accuracy);
//
//   // Check overall word score first
//   if (wordAccuracy !== null && wordAccuracy < WORD_THRESHOLD) {
//       return true;
//   }
//
//
//   // Check individual phonemes for severe errors
//   if (evidence.phonemes && Array.isArray(evidence.phonemes)) {
//       for (const p of evidence.phonemes) {
//           const phonemeAccuracy = numericScore(p.accuracy);
//           // If a student completely drops a sound (like the 't' in rabbit at 0), flag as mispronunciation
//           if (phonemeAccuracy !== null && phonemeAccuracy < PHONEME_THRESHOLD) {
//               return true; 
//           }
//       }
//   }
//
//   // Fallback to Azure's default if needed
//   return String(evidence.errorType || "").toLowerCase() === "mispronunciation";
// }




  function errorEvidence(evidence) {
    return isAzureMispronunciation(evidence);
  }

  // Prefer Azure evidence that belongs to the exact spoken token. If the
  // sequence mapper could not attach one, fall back to evidence aligned to the
  // reference slot. This is especially important for substitutions where Azure
  // may align its pronunciation result directly to the expected reference word.
  function selectAzureEvidenceForOp(op, spokenWords, bySpoken, byReference) {
    if (!op || op.spoken == null) return null;
    const spoken = safeWords(spokenWords)[Number(op.spoken)];
    const direct = bySpoken?.get?.(Number(op.spoken)) || null;
    const ref = op.ref != null ? (byReference?.get?.(Number(op.ref)) || null) : null;
    if (direct) {
      const directWord = normalizeWord(direct.word);
      const spokenWord = normalizeWord(spoken?.word);
      if (spokenWord && directWord === spokenWord) return direct;
    }
    if (ref) {
      const refWord = normalizeWord(ref.word);
      const spokenWord = normalizeWord(spoken?.word);
      if (spokenWord && refWord === spokenWord) return ref;
    }
    // Never attach reference-slot pronunciation evidence to a lexical
    // substitution unless the Azure token itself matches the spoken word.
    // Otherwise a reference score for "fallen" could be incorrectly displayed
    // on a spoken replacement such as "pollen".
    if (op.type === 'sub' || op.type === 'reverse') return direct || null;
    return direct || ref || null;
  }

  function attachAzurePronunciation(op, evidence) {
    if (!op) return op;
    const pronunciation = evidence ? {
      errorType: evidence.errorType || 'None',
      accuracy: numericScore(evidence.accuracy),
      confidence: Number.isFinite(Number(evidence.confidence)) ? Number(evidence.confidence) : null
    } : null;
    if (!pronunciation) return op;
    const out = { ...op, evidence, pronunciation };
    if (isAzureMispronunciation(evidence)) {
      out.azurePronunciation = 'Mispronunciation';
      // Only a structural match can become a Mispronunciation. Structural
      // classes (substitution, omission, repetition, transposition, reversal,
      // self-correction) remain untouched; Azure is attached as secondary
      // pronunciation evidence.
      if (op.type === 'match') {
        out.alignmentType = op.type;
        out.type = 'mis';
      }
    }
    return out;
  }

  function isRepairMarker(word) {
    return REPAIR_MARKERS.has(normalizeWord(word));
  }

  function bestReferenceForToken(spokenWord, refs, preferredIndex = 0) {
    const norm = normalizeWord(spokenWord);
    if (!norm) return { refIndex: -1, similarity: 0 };
    let best = { refIndex: -1, similarity: 0, distance: Infinity };
    for (let r = 0; r < refs.length; r++) {
      const sim = lexicalSimilarity(spokenWord, refs[r].word);
      if (sim < 0.45) continue;
      const distance = Math.abs(r - preferredIndex);
      if (sim > best.similarity || (sim === best.similarity && distance < best.distance)) {
        best = { refIndex: r, similarity: sim, distance };
      }
      if (sim === 1 && r >= preferredIndex) break;
    }
    return best;
  }

  // Align a hidden/contextual transcript to the raw spoken sequence. The
  // contextual transcript is auxiliary evidence only; it can never replace the
  // raw spoken word displayed to the learner.
  function alignAuxiliaryTranscriptToSpoken(spokenWords, auxiliaryWords) {
    const spoken = safeWords(spokenWords), auxiliary = safeWords(auxiliaryWords);
    const map = new Map();
    if (!spoken.length || !auxiliary.length) return map;
    const ops = alignWords(spoken, auxiliary, { omissionCost: 1.18, insertionCost: 1.02, substitutionCost: 1.08 });
    for (const op of ops) {
      if (op.type === 'compound' && op.spoken != null && Array.isArray(op.refs)) {
        const auxWord = auxiliary[op.spoken];
        for (const rawIndex of op.refs) if (auxWord) map.set(Number(rawIndex), auxWord);
      } else if (op.spoken != null && op.ref != null && ['match','sub','reverse'].includes(op.type)) {
        const auxWord = auxiliary[op.ref];
        if (auxWord) map.set(Number(op.spoken), auxWord);
      } else if (Array.isArray(op.spokens) && op.spokens.length && Array.isArray(op.refs)) {
        for (let k = 0; k < Math.min(op.spokens.length, op.refs.length); k++) {
          const auxWord = auxiliary[Number(op.refs[k])];
          if (auxWord) map.set(Number(op.spokens[k]), auxWord);
        }
      }
    }
    return map;
  }

  function detectSelfCorrections(referenceWords, spokenWords, azureBySpoken, provisionalOps, repeatResult, contextualBySpoken = new Map(), options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    const opsBySpoken = new Map();
    for (const op of provisionalOps || []) {
      if (op.spoken != null) opsBySpoken.set(Number(op.spoken), op);
      if (Array.isArray(op.spokens)) {
        op.spokens.forEach((idx, k) => opsBySpoken.set(Number(idx), { ...op, ref: op.refs?.[k] ?? op.ref, spoken: Number(idx) }));
      }
    }

    const repeated = repeatResult?.claimedRepeated || new Set();
    const result = [];
    const claimed = new Set();
    const referenceClaims = new Set();

    const getRefIndex = (op) => {
      if (!op) return -1;
      if (op.ref != null) return Number(op.ref);
      if (Array.isArray(op.refs) && op.refs.length) return Number(op.refs[0]);
      if (op.position != null && Number.isFinite(Number(op.position))) {
        const pos = Number(op.position);
        if (pos >= 0 && pos < refs.length) return pos;
      }
      return -1;
    };

    const inferReferenceNearTarget = (spokenIndex, targetRef) => {
      const candidates = [targetRef - 1, targetRef, targetRef + 1].filter(r => r >= 0 && r < refs.length);
      let bestRef = -1, bestScore = 0;
      for (const candidate of candidates) {
        const sim = lexicalSimilarity(spoken[spokenIndex]?.word, refs[candidate]?.word);
        if (sim > bestScore) { bestScore = sim; bestRef = candidate; }
      }
      return { refIndex: bestRef, similarity: bestScore };
    };

    const getSimilarity = (spokenIndex, refIndex) => {
      if (spokenIndex == null || refIndex < 0 || !refs[refIndex]) return 0;
      return lexicalSimilarity(spoken[spokenIndex]?.word, refs[refIndex].word);
    };

    const azureBetter = (a, b) => {
      const aa = numericScore(azureBySpoken?.get(a)?.accuracy);
      const bb = numericScore(azureBySpoken?.get(b)?.accuracy);
      return Number.isFinite(aa) && Number.isFinite(bb) && (bb - aa >= 10);
    };

    const hasAzureRepairEvidence = (indices, correctionIndex, refIndex) => {
      const correctionAzure = azureBySpoken?.get(correctionIndex) || null;
      const correctionScore = numericScore(correctionAzure?.accuracy);
      const correctionHasAzureMispronunciation = String(correctionAzure?.errorType || "").toLowerCase() === "mispronunciation";
      for (const idx of indices) {
        const ev = azureBySpoken?.get(idx) || null;
        if (!ev) continue;
        const evAcc = numericScore(ev.accuracy);
        if (errorEvidence(ev) && (!Number.isFinite(correctionScore) || correctionScore - evAcc >= 5 || correctionHasAzureMispronunciation)) {
          return true;
        }
        if (azureBetter(idx, correctionIndex)) return true;
      }
      return false;
    };

    for (let j = 0; j < spoken.length; j++) {
      // Repetition candidates are normally removed before repair detection. A
      // near-target repeated token is allowed back into a repair CHAIN only when
      // there is another non-exact attempt leading to a later strong correction.
      // This is what lets "cool cold cold could" be one SC while "walked walked"
      // remains an ordinary repetition.
      if (claimed.has(j)) continue;
      const currentOp = opsBySpoken.get(j);
      const refIndex = getRefIndex(currentOp);
      if (refIndex < 0) continue;

      const currentSim = getSimilarity(j, refIndex);
      const currentStrong = currentSim >= 0.86 || currentOp?.type === 'match' || currentOp?.type === 'self-corrected';
      if (!currentStrong) continue;

      // Collect a contiguous suffix of plausible attempts toward the same
      // reference slot. Repair markers/retrace tokens may appear between them.
      const attempts = [];
      const markers = [];
      let scan = j - 1;
      while (scan >= 0 && (j - scan) <= cfg.selfCorrectionMaxAttemptSpan &&
             attempts.length < cfg.selfCorrectionMaxAttemptCount) {
        if (claimed.has(scan)) break;
        const word = spoken[scan];
        if (!word) break;

        if (isRepairMarker(word.word) || (options.retraceSpoken && options.retraceSpoken.has(scan))) {
          markers.unshift(scan);
          scan--;
          continue;
        }

        const op = opsBySpoken.get(scan);
        let earlierRef = getRefIndex(op);
        let sim = earlierRef >= 0 ? getSimilarity(scan, earlierRef) : 0;
        if (earlierRef < 0 || earlierRef !== refIndex) {
          const inferred = inferReferenceNearTarget(scan, refIndex);
          if (inferred.refIndex === refIndex) {
            earlierRef = inferred.refIndex;
            sim = inferred.similarity;
          }
        }

        // A repair chain must remain phonetically/lexically related to the SAME
        // reference slot. If an unrelated word appears, stop the chain here.
        if (earlierRef !== refIndex || sim < 0.45) break;

        attempts.unshift({
          index: scan,
          ref: earlierRef,
          similarity: sim,
          op,
          repeated: repeated.has(scan),
          azure: azureBySpoken?.get(scan) || null,
          contextual: contextualBySpoken?.get(scan) || null
        });
        scan--;
      }

      if (!attempts.length) continue;
      const allClaimedRepeatCandidates = attempts.filter(a => a.repeated);
      const nonExactAttemptExists = attempts.some(a => a.similarity < 0.98);

      // Pure exact copies are repetition, even when Azure confidence improves.
      // A repair chain is only allowed to reclaim repeated tokens when there is
      // at least one genuinely non-exact attempt in the same chain (e.g.
      // "cool cold cold could").
      if (!nonExactAttemptExists && !markers.length) continue;

      const earlierWrongByOp = attempts.some(a => [
        'sub', 'mis', 'reverse', 'insert', 'uncertain-match', 'uncertain'
      ].includes(String(a.op?.type)));
      const minAttemptSim = Math.min(...attempts.map(a => a.similarity));
      const bestAttemptSim = Math.max(...attempts.map(a => a.similarity));
      const similarityGain = currentSim - bestAttemptSim;
      const overallSimilarityGain = currentSim - minAttemptSim;
      const explicitRepair = markers.length > 0;
      const azureRepair = hasAzureRepairEvidence(attempts.map(a => a.index), j, refIndex);

      // Context can confirm the final target, but never rewrites the raw token.
      const contextualA = attempts.length ? attempts[attempts.length - 1].contextual : null;
      const contextualB = contextualBySpoken?.get(j) || null;
      const contextualTargetA = contextualA ? lexicalSimilarity(contextualA.word, refs[refIndex]?.word) : 0;
      const contextualTargetB = contextualB ? lexicalSimilarity(contextualB.word, refs[refIndex]?.word) : 0;
      const contextImprovesFirstAttempt = contextualTargetA >= cfg.contextualStrongSimilarity &&
        contextualA?.norm !== spoken[attempts[0].index]?.norm;
      const contextConfirmsCorrection = contextualTargetB >= cfg.contextualStrongSimilarity;
      const contextRepair = contextImprovesFirstAttempt && contextConfirmsCorrection &&
        overallSimilarityGain >= cfg.contextualMinSimilarityGain;

      const hasStrongRepairEvidence = explicitRepair ||
        similarityGain >= cfg.selfCorrectionMinSimilarityGain ||
        overallSimilarityGain >= cfg.selfCorrectionMinSimilarityGain ||
        earlierWrongByOp || azureRepair || contextRepair;

      // A chain without a repair cue still needs a real progression toward the
      // target. This prevents unrelated insertions from becoming SC merely due
      // to proximity to a correct word.
      if (!hasStrongRepairEvidence) continue;
      if (!explicitRepair && currentSim < 0.86) continue;
      if (referenceClaims.has(refIndex)) continue;

      const firstIndex = attempts[0].index;
      const firstAzure = azureBySpoken?.get(firstIndex) || null;
      const currentAzure = azureBySpoken?.get(j) || null;
      const gapSeconds = azureGapSeconds(firstAzure, currentAzure);
      if (gapSeconds != null && gapSeconds > cfg.selfCorrectionMaxGapSeconds) continue;

      const reason = explicitRepair ? 'repair-marker' :
        (contextRepair ? 'context-assisted-repair' :
          (azureRepair ? 'azure-improved-repair' :
            (attempts.length > 1 ? 'progressive-repair-chain' : 'wrong-then-correct')));

      result.push({
        id: `sc-${firstIndex}-${j}-${refIndex}`,
        reparandums: attempts.map(a => a.index),
        markers: markers.slice(),
        correction: j,
        reference: refIndex,
        reason,
        gapSeconds,
        candidateSimilarity: minAttemptSim,
        correctionSimilarity: currentSim,
        accuracyBefore: numericScore(firstAzure?.accuracy),
        accuracyAfter: numericScore(currentAzure?.accuracy),
        durationBefore: azureDurationSeconds(firstAzure),
        durationAfter: azureDurationSeconds(currentAzure),
        contextualBefore: contextualA?.word || null,
        contextualAfter: contextualB?.word || null,
        contextualTargetScoreBefore: contextualTargetA,
        contextualTargetScoreAfter: contextualTargetB,
        attempts: attempts.map(a => ({
          spoken: a.index,
          word: spoken[a.index]?.word || '',
          reference: refIndex,
          similarity: a.similarity,
          repeatedBeforeRepairDetection: a.repeated,
          azureAccuracy: numericScore(a.azure?.accuracy),
          azureErrorType: a.azure?.errorType || null,
          contextualWord: a.contextual?.word || null
        }))
      });

      // Reclaimed repeated tokens are no longer repetitions; they are part of the
      // same self-correction episode. Remove them from the repeat result so the
      // final op builder cannot add a duplicate repeat annotation later.
      for (const candidate of allClaimedRepeatCandidates) {
        repeatResult?.claimedRepeated?.delete(candidate.index);
      }

      attempts.forEach(a => claimed.add(a.index));
      markers.forEach(x => claimed.add(x));
      claimed.add(j);
      referenceClaims.add(refIndex);
    }
    return result;
  }

  function referenceHasConsecutivePhrase(refs, phrase) {
    return findReferencePhraseStarts(refs, phrase).some(start => {
      const secondStart = start + phrase.length;
      if (secondStart + phrase.length > refs.length) return false;
      return refs.slice(secondStart, secondStart + phrase.length).map(w => w.norm).join("|") === phrase.map(normalizeWord).join("|");
    });
  }

  function detectPhraseRepetitions(referenceWords, spokenWords, protectedSpoken = new Set(), options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    const claimed = new Set(protectedSpoken);
    const groups = [];
    const maxLen = Math.min(cfg.phraseRepeatMaxLength, Math.floor(spoken.length / 2));

    // Longest first prevents "Yama walked Yama walked Yama walked" from being
    // split into several one-word repetitions when the two-word retrace is clear.
    for (let len = maxLen; len >= 1; len--) {
      for (let start = 0; start + (len * 2) <= spoken.length; start++) {
        const firstIdx = Array.from({ length: len }, (_, k) => start + k);
        if (firstIdx.some(idx => claimed.has(idx))) continue;
        const phrase = firstIdx.map(idx => spoken[idx].norm);
        if (phrase.some(Boolean) === false || phrase.some(x => !x)) continue;

        let copies = 1;
        while (start + (copies + 1) * len <= spoken.length) {
          const candidate = spoken.slice(start + copies * len, start + (copies + 1) * len).map(w => w.norm);
          if (candidate.join("|") !== phrase.join("|")) break;
          if (candidate.some(x => !x)) break;
          if (candidate.map((_, k) => start + copies * len + k).some(idx => claimed.has(idx))) break;
          copies++;
        }
        if (copies < 2) continue;

        // If the same phrase is genuinely present twice consecutively in the
        // reference, both copies may be legitimate and should not be marked.
        if (referenceHasConsecutivePhrase(refs, phrase)) continue;

        const repeatedIndexes = [];
        for (let c = 1; c < copies; c++) {
          for (let k = 0; k < len; k++) repeatedIndexes.push(start + c * len + k);
        }
        if (repeatedIndexes.some(idx => claimed.has(idx))) continue;

        const groupId = `rp-${start}-${len}`;
        groups.push({
          groupId,
          firstStart: start,
          repeatStart: start + len,
          len,
          copies,
          phrase: spoken.slice(start, start + len).map(w => w.word).join(" "),
          firstIndexes: firstIdx.slice(),
          repeatedIndexes: repeatedIndexes.slice()
        });
        repeatedIndexes.forEach(idx => claimed.add(idx));

        // Claim the whole span so nested shorter patterns cannot steal tokens.
        for (let idx = start; idx < start + len * copies; idx++) claimed.add(idx);
      }
    }
    return { groups, claimedRepeated: new Set(groups.flatMap(g => g.repeatedIndexes)) };
  }


  // Repair-aware order correction. Handles a common reading pattern where the
  // reader says the NEXT reference word early, self-corrects the CURRENT word,
  // and then continues. Example:
  //   reference: ... there could always be ...
  //   spoken:    ... there always cool could be ...
  // Here "cool → could" is self-correction and "always" is an out-of-order
  // spoken word, so we attach ref+1 to the early word and label it transposition
  // instead of generating an omission for "always".
  function repairAwareReorder(ops, referenceWords, spokenWords, selfCorrections) {
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    const out = ops.slice();
    for (const ep of selfCorrections || []) {
      const ref = Number(ep.reference);
      const correction = Number(ep.correction);
      if (!Number.isInteger(ref) || !Number.isInteger(correction) || ref < 0 || correction < 0) continue;
      const nextRef = ref + 1;
      if (nextRef >= refs.length) continue;

      // Look immediately before the reparandum for a word that exactly matches
      // the reference word after the repaired target. Allow at most one token
      // between that word and the reparandum.
      const earliest = Math.max(0, Math.min(...(ep.reparandums || [correction])) - 2);
      let candidateSpoken = -1;
      for (let j = earliest; j < correction; j++) {
        if (j === correction || !spoken[j]) continue;
        if (normalizeWord(spoken[j].word) === normalizeWord(refs[nextRef].word)) {
          // Do not steal a token already owned by another strong operation.
          const existing = out.find(op => Number(op.spoken) === j && ["match","sub","reverse","self-corrected"].includes(op.type));
          if (!existing) { candidateSpoken = j; break; }
        }
      }
      if (candidateSpoken < 0) continue;

      // Convert the candidate's insert/other provisional op into a transcript
      // token bound to the later reference word with a transposition annotation.
      for (let i = out.length - 1; i >= 0; i--) {
        const op = out[i];
        if (Number(op.spoken) !== candidateSpoken) continue;
        if (op.type === "omit") continue;
        out[i] = {
          ...op,
          type: "trans",
          ref: nextRef,
          spoken: candidateSpoken,
          transposition: {
            refs: [ref, nextRef],
            spokens: [correction, candidateSpoken],
            reason: "next-reference-word-spoken-before-self-correction"
          }
        };
        break;
      }

      // Remove the omission that was generated for ref+1. It is accounted for by
      // the transposed spoken token now attached to that reference position.
      for (let i = out.length - 1; i >= 0; i--) {
        if (out[i].type === "omit" && Number(out[i].ref) === nextRef) out.splice(i, 1);
      }
    }
    return out;
  }

  function resolveAdjacentOmitInsertPairs(ops, referenceWords, spokenWords, options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    const out = [];
    for (let i = 0; i < ops.length; i++) {
      const a = ops[i], b = ops[i + 1];
      const omitInsert = a?.type === "omit" && b?.type === "insert";
      const insertOmit = a?.type === "insert" && b?.type === "omit";
      if (!omitInsert && !insertOmit) { out.push(a); continue; }
      const omit = omitInsert ? a : b;
      const insert = omitInsert ? b : a;
      const ref = refs[omit.ref], sp = spoken[insert.spoken];
      const positionClose = Math.abs(Number(insert.position) - Number(omit.ref)) <= 1 ||
        Math.abs(Number(insert.position) - Number(omit.ref + 1)) <= 1;
      const similarity = lexicalSimilarity(ref?.word, sp?.word);
      if (ref && sp && positionClose && similarity >= cfg.omissionSubstitutionSimilarity) {
        out.push({ type: "sub", ref: omit.ref, spoken: insert.spoken, recoveredFrom: "omit+insert" });
        i += 1;
      } else {
        out.push(a);
        if (i + 1 < ops.length) { out.push(b); i += 1; }
      }
    }
    return out;
  }

  function miscuedTypeWithAzure(op, evidence, cfg) {
    if (["reverse", "trans", "repeat", "omit", "insert", "self-repair", "self-marker", "self-corrected"].includes(op.type)) return op.type;
    if (op.type === "match" || op.type === "sub") {
      if (op.type === "reverse") return "reverse";
      // Azure is pronunciation-only. A structural substitution remains a
      // substitution even when Azure reports poor pronunciation evidence for
      // the spoken replacement.
      if (op.type === "match" && isAzureMispronunciation(evidence)) return "mis";
      return op.type;
    }
    return op.type;
  }

  function detectAdjacentTranspositions(referenceWords, spokenWords) {
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    const pairs = [];
    for (let i = 0; i + 1 < spoken.length; i++) {
      if (!spoken[i].norm || !spoken[i + 1].norm) continue;
      for (let r = 0; r + 1 < refs.length; r++) {
        if (refs[r].norm === spoken[i + 1].norm && refs[r + 1].norm === spoken[i].norm && refs[r].norm !== refs[r + 1].norm) {
          pairs.push({ spoken: [i, i + 1], refs: [r, r + 1] });
          break;
        }
      }
    }
    return pairs;
  }

  function buildHiddenLayer(referenceWords, spokenWords, azureWords, options = {}) {
    const cfg = { ...DEFAULTS, ...options };
    const refs = safeWords(referenceWords);
    const spoken = safeWords(spokenWords);
    const azure = safeWords(azureWords);
    const contextual = safeWords(options.contextualWords || []);
    const azureBySpoken = alignAzureSequenceToSpoken(spoken, azure);
    const azureByReference = mapAzureEvidence(refs, azure);
    const contextualBySpoken = alignAuxiliaryTranscriptToSpoken(spoken, contextual);

    // 1) Detect repetitions first. This protects repeated copies from being
    // misclassified as self-corrections later.
    const emptyRepair = { groups: [], claimedRepeated: new Set() };
    const repeatResult = detectPhraseRepetitions(refs, spoken, new Set(), cfg);
    let repeatBySpoken = new Map();
    for (const g of repeatResult.groups) {
      for (const spokenIndex of g.repeatedIndexes) repeatBySpoken.set(spokenIndex, g);
    }

    // 2) Build a first-pass reference alignment without self-correction rules.
    // Repetition copies do not consume reference positions. Forced transposition
    // tokens are blocked from stable anchors but remain available to the DP.
    const consumingUnits = [];
    for (let i = 0; i < spoken.length; i++) {
      if (repeatBySpoken.has(i)) continue;
      consumingUnits.push({ ...spoken[i], originalSpokenIndex: i, index: i });
    }

    const forcedTranspositions = detectAdjacentTranspositions(refs, spoken);
    const blockedOriginal = new Set(forcedTranspositions.flatMap(p => p.spoken));
    const anchorBlocked = new Set();
    consumingUnits.forEach((u, pos) => {
      if (blockedOriginal.has(Number(u.originalSpokenIndex))) anchorBlocked.add(pos);
    });

    const preliminary = alignWithAnchors(refs, consumingUnits, { ...cfg, anchorBlocked: Array.from(anchorBlocked) });
    let preliminaryOps = resolveAdjacentOmitInsertPairs(preliminary.ops.slice(), refs, spoken, cfg);

    // ── Retrace Detection for Self-Corrections ──
    const retraceSpoken = new Set();
    for (let i = 0; i < preliminaryOps.length; i++) {
      if (preliminaryOps[i].type === 'insert') {
        let j = i;
        while (j < preliminaryOps.length && preliminaryOps[j].type === 'insert') j++;
        const inserts = preliminaryOps.slice(i, j);
        const P = inserts[0].position;
        if (P != null) {
          let bestMatchLen = 0;
          for (let len = 1; len <= Math.min(inserts.length, P); len++) {
            const suffix = inserts.slice(inserts.length - len).map(op => normalizeWord(spoken[op.spoken].word));
            const refSlice = refs.slice(P - len, P).map(w => normalizeWord(w.word));
            if (suffix.join("|") === refSlice.join("|")) {
              bestMatchLen = len;
            }
          }
          if (bestMatchLen > 0) {
            for (let k = 0; k < bestMatchLen; k++) {
              const opIdx = j - bestMatchLen + k;
              const spkIdx = preliminaryOps[opIdx].spoken;
              retraceSpoken.add(spkIdx);
              repeatResult.groups.push({
                groupId: 'rt-' + spkIdx,
                firstStart: P - bestMatchLen,
                repeatStart: spkIdx,
                len: 1,
                copies: 2,
                phrase: spoken[spkIdx].word,
                firstIndexes: [],
                repeatedIndexes: [spkIdx]
              });
              repeatResult.claimedRepeated.add(spkIdx);
            }
          }
        }
        i = j - 1;
      }
    }

    // 3) Detect self-corrections against the *same provisional reference slot*.
    // This is much more stable than guessing a reference slot from the raw text.
    // Structural self-correction detection is based on the literal spoken
    // sequence and contextual transcript only. Azure must not change the
    // structural classification.
    const selfCorrections = detectSelfCorrections(refs, spoken, azureBySpoken, preliminaryOps, repeatResult, contextualBySpoken, { ...cfg, retraceSpoken });

    // Self-correction detection may reclaim a later copy that the repetition
    // detector initially claimed. Rebuild the live repeat map after that pass.
    const selfCorrectionSpokenIndexes = new Set(selfCorrections.flatMap(ep => [
      ...(ep.reparandums || []), ...(ep.markers || [])
    ]).map(Number));
    if (selfCorrectionSpokenIndexes.size) {
      repeatResult.groups = repeatResult.groups
        .map(g => {
          const repeatedIndexes = (g.repeatedIndexes || []).filter(idx => !selfCorrectionSpokenIndexes.has(Number(idx)));
          if (!repeatedIndexes.length) return null;
          return {
            ...g,
            repeatedIndexes,
            copies: g.len > 0 ? 1 + Math.floor(repeatedIndexes.length / g.len) : g.copies
          };
        })
        .filter(Boolean);
      repeatResult.claimedRepeated = new Set(repeatResult.groups.flatMap(g => g.repeatedIndexes));
      repeatBySpoken = new Map();
      for (const g of repeatResult.groups) {
        for (const spokenIndex of g.repeatedIndexes) repeatBySpoken.set(spokenIndex, g);
      }
    }

    const protectedRepair = new Set();
    for (const ep of selfCorrections) {
      ep.reparandums.forEach(i => protectedRepair.add(i));
      ep.markers.forEach(i => protectedRepair.add(i));
    }

    // 4) Remove reparandums/repair markers from the reference-consuming sequence
    // and re-run the alignment. The correction itself consumes the reference slot.
    const finalUnits = [];
    for (let i = 0; i < spoken.length; i++) {
      if (repeatBySpoken.has(i)) continue;
      const ep = selfCorrections.find(x => x.reparandums.includes(i) || x.markers.includes(i));
      if (ep) continue;
      finalUnits.push({ ...spoken[i], originalSpokenIndex: i, index: i });
    }

    const finalBlockedOriginal = new Set(forcedTranspositions.flatMap(p => p.spoken));
    const finalAnchorBlocked = new Set();
    finalUnits.forEach((u, pos) => {
      if (finalBlockedOriginal.has(Number(u.originalSpokenIndex)) && !protectedRepair.has(Number(u.originalSpokenIndex))) {
        finalAnchorBlocked.add(pos);
      }
    });

    const aligned = alignWithAnchors(refs, finalUnits, { ...cfg, anchorBlocked: Array.from(finalAnchorBlocked) });
    let ops = resolveAdjacentOmitInsertPairs(aligned.ops.slice(), refs, spoken, cfg);

    // Repair-aware reorder runs after self-correction is known. This prevents
    // sequences such as "always cool could" from turning the correctly spoken
    // "always" into an omission while the repair is being attached to "could".
    ops = repairAwareReorder(ops, refs, spoken, selfCorrections);

    // 5) Azure is pronunciation-only. Do not let Azure suppress or create
    // structural omission/insertion/substitution/repetition events.
    // EXCEPT for specific function words that ASR drops frequently, where
    // Azure's hidden sequence matching provides an independent audio corroborator.
    const azureOmissionRecovery = { ops, recovered: [] };
    for (let i = 0; i < ops.length; i++) {
      const op = ops[i];
      if ((op.type === 'omit' || op.type === 'sub') && op.ref != null) {
        const azureEv = azureByReference.get(op.ref);
        if (azureEv) {
          const et = String(azureEv.errorType || '').toLowerCase();
          if (et === 'none') {
            op.type = 'azure-recovered';
            op.azureRecovery = true;
            azureOmissionRecovery.recovered.push(op.ref);
          } else if (et === 'mispronunciation') {
            if (op.type === 'omit') op.type = 'azure-mis-omit';
            else if (op.type === 'sub') op.type = 'mis';
            op.azureRecovery = true;
            azureOmissionRecovery.recovered.push(op.ref);
          }
        }
      }
    }

    // 6) Attach repeat events. Only later copies are marked; the first copy stays
    // available to the ordinary reference alignment.
    for (const [spokenIndex, group] of repeatBySpoken.entries()) {
      const baseRef = (() => {
        for (const op of ops) {
          if (op.spoken == null) continue;
          if (Number(op.spoken) === Number(group.firstIndexes?.[0] ?? group.firstStart)) {
            return op.ref ?? op.refs?.[0] ?? null;
          }
        }
        return null;
      })();
      ops.push({
        type: 'repeat',
        spoken: spokenIndex,
        ref: baseRef,
        position: baseRef == null ? refs.length : Number(baseRef) + 1,
        spokenWord: spoken[spokenIndex]?.word || '',
        repeat: {
          groupId: group.groupId,
          retraceSpoken: group.firstStart,
          repeatStartSpoken: group.repeatStart,
          length: group.len,
          copies: group.copies,
          phrase: group.phrase,
          source: 'hidden-sequence'
        }
      });
    }

    // 7) Attach the self-correction annotations: reparandum and any explicit
    // repair markers are visual events, while the corrected token keeps the
    // underlying reference alignment.
    const correctionIndexes = new Map(selfCorrections.map(ep => [ep.correction, ep]));
    for (const ep of selfCorrections) {
      for (const i of ep.reparandums) ops.push({ type: 'self-repair', spoken: i, ref: ep.reference, selfCorrection: { ...ep, role: 'reparandum' } });
      for (const i of ep.markers) ops.push({ type: 'self-marker', spoken: i, ref: ep.reference, selfCorrection: { ...ep, role: 'marker' } });
    }
    ops = ops.map(op => {
      if (op.spoken == null || !correctionIndexes.has(Number(op.spoken))) return op;
      const ep = correctionIndexes.get(Number(op.spoken));
      return { ...op, type: 'self-corrected', ref: ep.reference, selfCorrection: { ...ep, role: 'correction' } };
    });

    ops.sort((a, b) => {
      const sa = a.spoken != null ? Number(a.spoken) : Number.MAX_SAFE_INTEGER;
      const sb = b.spoken != null ? Number(b.spoken) : Number.MAX_SAFE_INTEGER;
      const ra = a.ref != null ? Number(a.ref) : Number(a.refs?.[0] ?? a.position ?? Number.MAX_SAFE_INTEGER);
      const rb = b.ref != null ? Number(b.ref) : Number(b.refs?.[0] ?? b.position ?? Number.MAX_SAFE_INTEGER);
      return sa - sb || ra - rb;
    });

    const evidenceBySpoken = new Map();
    for (const [spokenIndex, evidence] of azureBySpoken.entries()) {
      evidenceBySpoken.set(spokenIndex, {
        ...evidence,
        timing: azureTiming(evidence)
      });
    }

    return {
      ops,
      anchors: aligned.anchors,
      repeatGroups: repeatResult.groups,
      selfCorrections,
      evidenceBySpoken,
      azureByReference,
      contextualBySpoken,
      consumingSpokenIndexes: finalUnits.map(w => w.originalSpokenIndex),
      diagnostics: {
        referenceWords: refs.length,
        spokenWords: spoken.length,
        consumingSpokenWords: finalUnits.length,
        anchors: aligned.anchors.length,
        repeatGroups: repeatResult.groups.length,
        repeatedSpokenWords: repeatResult.groups.reduce((n, g) => n + g.repeatedIndexes.length, 0),
        selfCorrections: selfCorrections.length,
        forcedTranspositions: forcedTranspositions.length,
        repairAwareReorders: ops.filter(op => op.type === "trans" && op.transposition?.reason === "next-reference-word-spoken-before-self-correction").length,
        azureEvidenceWords: evidenceBySpoken.size,
        azureOmissionRecoveries: 0,
        contextualEvidenceWords: contextualBySpoken.size,
        contextualTranscriptWords: contextual.length,
        architecture: 'literal live transcript + passage-keywords + azure-evidence + reference-alignment'
      }
    };
  }

  function summarize(ops) {
    const counts = {
      mispronunciation: 0, omission: 0, insertion: 0, substitution: 0,
      repetition: 0, transposition: 0, reversal: 0, uncertain: 0, selfCorrection: 0
    };
    let definiteErrors = 0;
    for (const op of ops || []) {
      switch (op.type) {
        case "mis": 
        case "azure-mis-omit": counts.mispronunciation++; break;
        case "omit": counts.omission++; break;
        case "insert": counts.insertion++; break;
        case "sub": counts.substitution++; break;
        case "repeat": counts.repetition++; break;
        case "trans": counts.transposition++; break;
        case "reverse": counts.reversal++; break;
        case "uncertain-match": case "uncertain": counts.uncertain++; break;
        case "self-corrected": counts.selfCorrection++; break;
      }
      if (["mis", "omit", "insert", "sub", "repeat", "trans", "reverse"].includes(op.type)) definiteErrors++;
    }
    return { counts, definiteErrors };
  }

  function finalizeReversals(ops, referenceWords, spokenWords) {
    const refs = safeWords(referenceWords), spoken = safeWords(spokenWords);
    return ops.map(op => {
      if (op.type !== "sub" || op.ref == null || op.spoken == null) return op;
      if (isReverseToken(refs[op.ref]?.word, spoken[op.spoken]?.word)) return { ...op, type: "reverse" };
      return op;
    });
  }

  function analyze(referenceText, spokenText, azureWords = [], options = {}) {
    const referenceWords = safeWords(tokenize(referenceText));
    // The raw realtime sequence is the preferred spoken source when supplied.
    // This lets the hidden layer recover repetitions even if another final text
    // representation has compressed them.
    const sourceText = String(options.realtimeText || spokenText || "").trim();
    const spokenWords = safeWords(tokenize(sourceText));
    if (!referenceWords.length) {
      return { referenceWords, spokenWords, ops: [], counts: summarize([]).counts, definiteErrors: 0, selfCorrections: [], hidden: { diagnostics: {} } };
    }
    if (!spokenWords.length) {
      const omissions = referenceWords.map((_, ref) => ({ type: "omit", ref }));
      return {
        referenceWords,
        spokenWords,
        ops: omissions,
        ...summarize(omissions),
        selfCorrections: [],
        hidden: { diagnostics: { referenceWords: referenceWords.length, spokenWords: 0, consumingSpokenWords: 0, anchors: 0, repeatGroups: 0, repeatedSpokenWords: 0, selfCorrections: 0, forcedTranspositions: 0, azureEvidenceWords: 0 } }
      };
    }

    const cfg = { ...DEFAULTS, ...options, contextualWords: safeWords(tokenize(options.contextualText || "")) };
    const hidden = buildHiddenLayer(referenceWords, spokenWords, azureWords, cfg);
    let ops = hidden.ops.slice();

    // Attach Azure evidence to spoken events first, then classify. This is the
    // key distinction between "what the student said" and "how it matches the
    // scripted passage".
    ops = ops.map(op => {
      if (op.spoken == null) return op;
      const evidence = selectAzureEvidenceForOp(op, spokenWords, hidden.evidenceBySpoken, hidden.azureByReference);
      const contextualEvidence = hidden.contextualBySpoken.get(Number(op.spoken)) || null;
      let next = evidence ? attachAzurePronunciation(op, evidence) : op;
      if (contextualEvidence) next = { ...next, contextualEvidence };
      return next;
    });

    ops = finalizeReversals(ops, referenceWords, spokenWords);

    // Azure is authoritative only for pronunciation. Structural classifications
    // such as repetition/self-correction remain structural, with Azure's
    // Mispronunciation result carried as an independent secondary layer.

    // Ensure a self-correction never becomes a repetition/miscue during the
    // final cleanup pass.
    const selfCorrectionSpoken = new Set(hidden.selfCorrections.flatMap(ep => [...ep.reparandums, ...ep.markers, ep.correction]));
    ops = ops.filter((op, idx, arr) => {
      if (op.type !== "repeat") return true;
      return !selfCorrectionSpoken.has(Number(op.spoken));
    });

    const summary = summarize(ops);
    return {
      referenceWords,
      spokenWords,
      ops,
      ...summary,
      selfCorrections: hidden.selfCorrections,
      evidenceBySpoken: hidden.evidenceBySpoken,
      hidden
    };
  }

  global.PronunciationAssessment = {
    normalizeWord,
    tokenize,
    alignWords,
    analyze,
    isReverseToken,
    mapAzureEvidence,
    azureOffsetSeconds,
    azureDurationSeconds,
    azureTiming,
    detectSelfCorrections,
    detectPhraseRepetitions,
    isAzureMispronunciation,
    selectAzureEvidenceForOp
  };
})(window);
