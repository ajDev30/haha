const $ = (id) => document.getElementById(id);
const Core = window.PronunciationAssessment;

const els = {
  story: $("story"), storyEditor: $("storyEditor"), editStoryBtn: $("editStoryBtn"),
  startBtn: $("startBtn"), stopBtn: $("stopBtn"), restartBtn: $("restartBtn"), diagBtn: $("diagBtn"), diagModal: $("diagModal"), closeDiagBtn: $("closeDiagBtn"), diagContent: $("diagContent"), resetBtn: $("resetBtn"), locale: $("locale"),
  status: $("statusPill"), hint: $("hint"), timer: $("timer"), meter: $("meterBar"),
  vadDot: $("vadDot"), liveBadge: $("liveBadge"), transcript: $("transcript"), transcriptNotice: $("transcriptNotice"),
  marked: $("story"), storyWordCount: $("storyWordCount"), wordBadge: $("wordBadge"),
  wpm: $("wpm"), readingAccuracy: $("readingAccuracy"), miscueStrip: $("miscueStrip"),
  tooltip: $("tooltip")
};

let mediaStream = null;
let audioContext = null;
let sourceNode = null;
let recorderNode = null;
let silentGain = null;
let analyser = null;
let running = false;
let timerHandle = null;
let startedAt = null;
let realtimePc = null;
let realtimeChannel = null;
let realtimeSocket = null;
let realtimeItems = new Map();
let realtimeOrder = [];
let realtimeConnectPromise = null;
let realtimeError = null;
let realtimeClosed = false;
let realtimeVadState = "off";
let realtimeSpeechSegments = 0;
let realtimeCompletedItems = new Set();
let realtimeServerEvents = [];
let realtimeTranscriptionDelayUsed = "medium";
let localVadConfig = { start_threshold: 0.018, stop_threshold: 0.012, silence_duration_ms: 1500, min_speech_ms: 180 };
let localVadSpeech = false;
let localVadSpeechStartedAt = 0;
let localVadLastLoudAt = 0;
let localVadBufferHasSpeech = false;
let realtimeBridgeReady = false;
let azureSocket = null;
let azureConnectPromise = null;
let azureStopPromise = null;
let azureReady = false;
let azureError = null;
let azureStreamWords = [];
let azureStreamPhrases = [];
let azureStreamRaw = [];
let azureDiagnostics = null;
let azurePendingAudio = [];
let azurePendingBytes = 0;
let azureAcceptingAudio = false;
let azureFinalSeen = false;
let azureDoneSeen = false;
let transcriptTips = [];
let tooltipVisible = false;
let lastAssessment = null;
let finalizingPromise = null;
let recordingSessionId = 0;
let recordingLifecycle = "idle";
let retryPromise = null;

function realtimeChannelIsOpen() {
  return Boolean(realtimeChannel) && (
    realtimeChannel.readyState === "open" ||
    (typeof WebSocket !== "undefined" && realtimeChannel.readyState === WebSocket.OPEN)
  );
}

function isCurrentAttempt(sessionId) {
  return Number(sessionId) === Number(recordingSessionId);
}

function setRetryUi({ visible = false, enabled = false } = {}) {
  if (!els.restartBtn) return;
  els.restartBtn.hidden = !visible;
  els.restartBtn.disabled = !enabled;
}

async function stopLocalAudioCapture() {
  if (window.appMediaRecorder && window.appMediaRecorder.state !== "inactive") { window.appMediaRecorder.stop(); }
  try { mediaStream?.getTracks().forEach(t => t.stop()); } catch (_) {}
  try { recorderNode?.port?.close?.(); } catch (_) {}
  try { recorderNode?.disconnect?.(); } catch (_) {}
  try { sourceNode?.disconnect?.(); } catch (_) {}
  try { analyser?.disconnect?.(); } catch (_) {}
  try { silentGain?.disconnect?.(); } catch (_) {}
  const ctx = audioContext;
  audioContext = null; sourceNode = null; recorderNode = null; silentGain = null; analyser = null; mediaStream = null;
  if (ctx) { try { await ctx.close(); } catch (_) {} }
}


window.originalStory = els.story.textContent.trim();

function setStatus(text) { els.status.textContent = text; }
function escapeHtml(s) { return String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[c])); }
function normalizeTextForDisplay(s) { return String(s || "").replace(/\s+/g, " ").trim(); }

function currentStory() { return normalizeTextForDisplay(els.story.textContent); }
function updateStoryCount() {
  const count = Core.tokenize(currentStory()).length;
  els.storyWordCount.textContent = count;
  els.wordBadge.textContent = `${count} ${count === 1 ? "word" : "words"}`;
}

function updateVadUi() {
  els.vadDot.className = `dot ${realtimeVadState === "speech" ? "speech" : realtimeError ? "error" : ""}`;
  if (realtimeVadState === "speech") els.liveBadge.textContent = "Speaking";
  else if (realtimeError) els.liveBadge.textContent = "Realtime error";
  else if (running) els.liveBadge.textContent = "Listening";
  else els.liveBadge.textContent = "Realtime";
}

function sendRealtimeCommit(reason) {
  if (!localVadBufferHasSpeech) return false;
  if (!realtimeChannelIsOpen() || !realtimeBridgeReady) return false;
  try {
    realtimeChannel.send(JSON.stringify({ type: "input_audio_buffer.commit" }));
    localVadBufferHasSpeech = false;
    if (reason) console.log(`[Realtime VAD] commit reason=${reason}`);
    return true;
  } catch (error) {
    console.warn("[Realtime VAD] commit failed", error);
    return false;
  }
}

function updateLocalVad(rms) {
  if (!running || !realtimeChannelIsOpen() || !realtimeBridgeReady) return;
  const now = performance.now();
  const startThreshold = Number(localVadConfig.start_threshold) || 0.018;
  const stopThreshold = Number(localVadConfig.stop_threshold) || Math.min(0.012, startThreshold);
  const silenceMs = Number(localVadConfig.silence_duration_ms) || 1500;
  const minSpeechMs = Number(localVadConfig.min_speech_ms) || 180;
  const loudEnough = rms >= (localVadSpeech ? stopThreshold : startThreshold);

  if (!localVadSpeech) {
    if (rms >= startThreshold) {
      localVadSpeech = true;
      localVadSpeechStartedAt = now;
      localVadLastLoudAt = now;
      localVadBufferHasSpeech = true;
      realtimeVadState = "speech";
      updateVadUi();
    }
    return;
  }

  if (loudEnough) {
    localVadLastLoudAt = now;
    return;
  }

  if (now - localVadLastLoudAt >= silenceMs && now - localVadSpeechStartedAt >= minSpeechMs) {
    localVadSpeech = false;
    realtimeVadState = "silence";
    updateVadUi();
    sendRealtimeCommit("local_silence");
  }
}

function updateTimer() {
  if (!startedAt) return;
  const seconds = Math.floor((Date.now() - startedAt) / 1000);
  els.timer.textContent = `${String(Math.floor(seconds / 60)).padStart(2,"0")}:${String(seconds % 60).padStart(2,"0")}`;
  if (analyser && running) {
    const data = new Uint8Array(analyser.fftSize);
    analyser.getByteTimeDomainData(data);
    let sum = 0;
    for (const v of data) { const d = (v - 128) / 128; sum += d*d; }
    const rms = Math.sqrt(sum / data.length);
    els.meter.style.width = `${Math.min(100, 8 + rms * 300)}%`;
    updateLocalVad(rms);
  } else {
    els.meter.style.width = "0%";
  }
}
function startTimer() { startedAt = Date.now(); updateTimer(); timerHandle = setInterval(updateTimer, 80); }
function stopTimer() { clearInterval(timerHandle); timerHandle = null; els.meter.style.width = "0%"; }

function realtimeTranscriptText() {
  return realtimeOrder.map(id => realtimeItems.get(id)?.text || "").filter(Boolean).join(" ").replace(/\s+/g, " ").trim();
}


function renderRealtimeLiveText() {
  const text = realtimeTranscriptText();
  if (running || text) {
    els.transcript.innerHTML = text ? renderLiveTokens(text) : '<span class="empty-state">Listening for spoken words…</span>';
  }
  if (text && running) els.hint.textContent = `Realtime is capturing ${Core.tokenize(text).length} spoken words.`;
}

function renderLiveTokens(text) {
  return Core.tokenize(text).map(w => `<span class="transcript-token" tabindex="-1">${escapeHtml(w)}</span>`).join(" ");
}

function resetRealtimeState() {
  realtimeItems = new Map();
  realtimeOrder = [];
  realtimeError = null;
  realtimeClosed = false;
  realtimeVadState = "off";
  realtimeSpeechSegments = 0;
  realtimeCompletedItems = new Set();
  realtimeServerEvents = [];
  realtimeTranscriptionDelayUsed = "medium";
  realtimeConnectPromise = null;
  realtimeBridgeReady = false;
  realtimeSocket = null;
  localVadSpeech = false;
  localVadSpeechStartedAt = 0;
  localVadLastLoudAt = 0;
  localVadBufferHasSpeech = false;
  localVadConfig = { start_threshold: 0.018, stop_threshold: 0.012, silence_duration_ms: 1500, min_speech_ms: 180 };
  els.transcript.innerHTML = '<span class="empty-state">Your spoken words will appear here while you read.</span>';
  updateVadUi();
}

function captureRealtimeError(evt) {
  const message = evt?.error?.message || "Realtime API error";
  const param = evt?.error?.param ? ` (${evt.error.param})` : "";
  const code = evt?.error?.code ? ` [${evt.error.code}]` : "";
  realtimeError = `${message}${param}${code}`;
}

function handleRealtimeEvent(evt, sessionId = recordingSessionId) {
  if (!evt || typeof evt.type !== "string" || !isCurrentAttempt(sessionId)) return;
  if (realtimeServerEvents.length < 250) realtimeServerEvents.push(evt);

  if (evt.type === "bridge.ready") {
    realtimeBridgeReady = true;
    realtimeVadState = running ? "silence" : "off";
    console.log(`[Realtime bridge] ready model=${evt.model || "gpt-live-transcribe"} transport=${evt.transport || "websocket"}`);
    // If the reader spoke while the upstream OpenAI socket was still opening,
    // the audio was queued on the server. Commit that pending turn as soon as
    // the upstream is ready and the local VAD has already returned to silence.
    if (!localVadSpeech && localVadBufferHasSpeech) sendRealtimeCommit("bridge_ready_pending");
    updateVadUi(); renderRealtimeLiveText(); return;
  }
  if (evt.type === "bridge.error") {
    captureRealtimeError(evt);
    console.error(`[Realtime bridge error] ${realtimeError}`);
    updateVadUi();
    els.hint.textContent = `Realtime error: ${realtimeError}`;
    return;
  }
  if (evt.type === "bridge.closed") {
    realtimeBridgeReady = false;
    if (running && !realtimeClosed) {
      const reason = evt.reason ? `: ${evt.reason}` : "";
      realtimeError = `Realtime bridge closed (${evt.code ?? "unknown"})${reason}`;
      console.warn(`[Realtime bridge closed] ${realtimeError}`);
      updateVadUi();
    }
    return;
  }

  if (evt.type === "session.created") {
    realtimeVadState = running ? "silence" : "off";
    updateVadUi(); renderRealtimeLiveText(); return;
  }
  if (evt.type === "session.updated") {
    console.log(`[OpenAI Realtime] session.updated acknowledged attempt=${recordingSessionId}`);
    realtimeVadState = running ? realtimeVadState : "off";
    updateVadUi(); renderRealtimeLiveText(); return;
  }
  if (evt.type === "input_audio_buffer\.speech_started") {
    realtimeVadState = "speech"; updateVadUi(); renderRealtimeLiveText(); return;
  }
  if (evt.type === "input_audio_buffer.speech_stopped") {
    realtimeVadState = "silence"; realtimeSpeechSegments += 1; updateVadUi(); renderRealtimeLiveText(); return;
  }
  if (evt.type === "input_audio_buffer.committed") {
    if (evt.item_id && !realtimeItems.has(evt.item_id)) realtimeItems.set(evt.item_id, { text: "", done: false });
    if (evt.item_id && !realtimeOrder.includes(evt.item_id)) realtimeOrder.push(evt.item_id);
    updateVadUi(); renderRealtimeLiveText(); return;
  }

  if (evt.type === "conversation.item.input_audio_transcription.delta") {
    console.debug(`[Realtime transcript delta] item=${evt.item_id || "?"} delta=${JSON.stringify(evt.delta || "")}`);
    if (!realtimeItems.has(evt.item_id)) {
      realtimeItems.set(evt.item_id, { text: "", done: false });
      realtimeOrder.push(evt.item_id);
    }
    realtimeItems.get(evt.item_id).text += evt.delta || "";
    renderRealtimeLiveText();
    return;
  }

  if (evt.type === "conversation.item.input_audio_transcription.completed") {
    console.log(`[Realtime transcript completed] item=${evt.item_id || "?"} text=${JSON.stringify(evt.transcript || "")}`);
    if (!realtimeItems.has(evt.item_id)) {
      realtimeItems.set(evt.item_id, { text: "", done: true });
      realtimeOrder.push(evt.item_id);
    }
    const item = realtimeItems.get(evt.item_id);
    item.text = String(evt.transcript || item.text || "").trim();
    item.done = true;
    item.usage = evt.usage || null;
    item.languages = evt.languages || [];
    realtimeCompletedItems.add(evt.item_id);
    renderRealtimeLiveText();
    return;
  }

  if (evt.type === "conversation.item.input_audio_transcription.failed") {
    captureRealtimeError(evt);
    console.warn("[Realtime transcription failed]", evt);
    updateVadUi(); renderRealtimeLiveText();
    return;
  }

  if (evt.type === "error") {
    captureRealtimeError(evt);
    const details = evt.error || {};
    console.error(`[Realtime error] ${realtimeError}`, details);
    updateVadUi();
    els.hint.textContent = `Realtime error: ${realtimeError}`;
  }
}



function azureWebSocketUrl() {
  const protocol = location.protocol === "https:" ? "wss:" : "ws:";
  return `${protocol}//${location.hostname}:3000/ws/stream`;
}

function handleAzureStreamEvent(evt, sessionId = recordingSessionId) {
  if (!evt || typeof evt.type !== "string" || !isCurrentAttempt(sessionId)) return;
  if (evt.type === "azure.ready") {
    azureReady = true;
    azureError = null;
    console.log(`[Azure Stream] ready mode=${evt.mode || "continuous-scripted"} format=${evt.sampleRate || 16000}Hz/${evt.bitsPerSample || 16}bit/${evt.channels || 1}ch referenceText=${evt.referenceText || "hidden-passage"}`);
    flushAzurePendingAudio();
    if (running) els.hint.textContent = "Reading: OpenAI live transcript + Azure pronunciation assessment are streaming continuously.";
    return;
  }
  if (evt.type === "azure.partial") return;
  if (evt.type === "azure.result") {
    const existing = new Set(azureStreamWords.map(w => `${w.offset ?? ""}|${w.duration ?? ""}|${w.word ?? ""}`));
    for (const word of (Array.isArray(evt.words) ? evt.words : [])) {
      const key = `${word.offset ?? ""}|${word.duration ?? ""}|${word.word ?? ""}`;
      if (!existing.has(key)) { existing.add(key); azureStreamWords.push(word); }
    }
    if (Array.isArray(evt.phrases)) azureStreamPhrases.push(...evt.phrases);
    if (evt.text) azureStreamRaw.push({ text: evt.text, offset: evt.offset ?? null, duration: evt.duration ?? null });
    if (running) els.hint.textContent = `Reading: Azure pronunciation is streaming continuously · ${azureStreamWords.length} words received`;
    return;
  }
  if (evt.type === "azure.error") {
    azureError = evt.error?.message || "Azure streaming error";
    console.error("[Azure Stream error]", evt.error || evt);
    if (running) els.hint.textContent = `Azure streaming error: ${azureError}`;
    return;
  }
  if (evt.type === "azure.final") {
    const result = evt.result || {};
    azureFinalSeen = true;
    if (Array.isArray(result.words) && result.words.length) azureStreamWords = result.words;
    if (Array.isArray(result.phrases) && result.phrases.length) azureStreamPhrases = result.phrases;
    if (Array.isArray(result.raw) && result.raw.length) azureStreamRaw = result.raw;
    if (result.diagnostics) azureDiagnostics = result.diagnostics;
    if (result.error?.message) azureError = result.error.message;
    // Azure.final contains the assessment payload, but the stream is not
    // considered fully finished until Azure.done closes the lifecycle. Keep
    // the finalization promise pending so markup cannot render early.
    return;
  }
  if (evt.type === "azure.aborted") {
    azureReady = false;
    const error = evt.error?.message || evt.reason || "Azure pronunciation finalization was aborted before completion.";
    azureError = error;
    if (azureStopPromise?.resolve) {
      azureStopPromise.resolve({ mode: "python-fastapi-sdk-continuous-push-stream-scripted-aborted", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: { message: error, status: 504 }, finalSeen: azureFinalSeen, doneSeen: false });
      azureStopPromise = null;
    }
    try { azureSocket?.close?.(); } catch (_) {}
    return;
  }
  if (evt.type === "azure.done") {
    azureDoneSeen = true;
    azureReady = false;
    try { azureSocket?.close?.(); } catch (_) {}
    if (azureStopPromise?.resolve) {
      azureStopPromise.resolve({ mode: "python-fastapi-sdk-continuous-push-stream-scripted", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: azureError ? { message: azureError, status: 502 } : null, finalSeen: azureFinalSeen, doneSeen: azureDoneSeen });
      azureStopPromise = null;
    }
  }
}

function flushAzurePendingAudio() {
  if (!azureSocket || azureSocket.readyState !== WebSocket.OPEN || !azureReady) return;
  while (azurePendingAudio.length) {
    const chunk = azurePendingAudio.shift();
    azurePendingBytes = Math.max(0, azurePendingBytes - chunk.byteLength);
    try { azureSocket.send(chunk); } catch (error) { console.warn("[Azure Stream] queued audio send failed", error); break; }
  }
}

function sendAzurePcm16(chunkBuffer) {
  if (!azureAcceptingAudio || !chunkBuffer || !chunkBuffer.byteLength) return;
  if (azureSocket && azureSocket.readyState === WebSocket.OPEN && azureReady) {
    try { azureSocket.send(chunkBuffer); return; } catch (_) {}
  }
  const MAX_PENDING = 4 * 1024 * 1024;
  if (azurePendingBytes + chunkBuffer.byteLength <= MAX_PENDING) {
    azurePendingAudio.push(chunkBuffer.slice(0));
    azurePendingBytes += chunkBuffer.byteLength;
  }
}

function resetAzureStreamState() {
  try { azureSocket?.close?.(); } catch (_) {}
  azureSocket = null;
  azureConnectPromise = null;
  azureStopPromise = null;
  azureReady = false;
  azureError = null;
  azureStreamWords = [];
  azureStreamPhrases = [];
  azureStreamRaw = [];
  azureDiagnostics = null;
  azurePendingAudio = [];
  azurePendingBytes = 0;
  azureAcceptingAudio = false;
  azureFinalSeen = false;
  azureDoneSeen = false;
}

async function connectAzureStreaming(referenceText, locale, sessionId) {
  resetAzureStreamState();
  azureAcceptingAudio = true;
  if (!window.WebSocket) throw new Error("This browser does not support WebSocket streaming.");
  const socket = new WebSocket(azureWebSocketUrl());
  azureSocket = socket;
  azureConnectPromise = new Promise((resolve, reject) => {
    socket.binaryType = "arraybuffer";
    let settled = false;
    const succeed = () => { if (!settled) { settled = true; resolve(); } };
    const fail = error => { azureError = error?.message || String(error); if (!settled) { settled = true; reject(error instanceof Error ? error : new Error(String(error))); } };
    socket.onopen = () => {
      console.log("[Azure Stream] WebSocket connected; starting continuous pronunciation assessment");
      socket.send(JSON.stringify({ type: "start", locale, referenceText: String(referenceText || "") }));
    };
    socket.onmessage = event => {
      if (typeof event.data !== "string") return;
      try {
        const evt = JSON.parse(event.data);
        handleAzureStreamEvent(evt, sessionId);
        if (evt.type === "azure.ready") succeed();
        if (evt.type === "azure.error" && !azureReady) fail(new Error(evt.error?.message || "Azure streaming setup failed"));
      } catch (error) { console.warn("[Azure Stream] invalid server event", error); }
    };
    socket.onerror = () => fail(new Error("Azure streaming WebSocket error"));
    socket.onclose = event => {
      if (!isCurrentAttempt(sessionId)) return;
      if (!settled && !azureReady) fail(new Error(`Azure streaming WebSocket closed before ready (code=${event.code})`));
      azureReady = false;
      if (azureStopPromise?.resolve && !azureDoneSeen) {
        azureStopPromise.resolve({ mode: "stream-closed-before-done", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, error: { message: `Azure streaming connection closed before azure.done (code=${event.code}).`, status: 502 }, finalSeen: azureFinalSeen, doneSeen: false });
        azureStopPromise = null;
      }
    };
  });
  await azureConnectPromise;
  if (!isCurrentAttempt(sessionId) || socket !== azureSocket || !running) return;
  flushAzurePendingAudio();
}

async function abortAzureStreaming(sessionId = recordingSessionId) {
  azureAcceptingAudio = false;
  const socket = azureSocket;
  // Detach globals first so a close/onmessage race cannot be mistaken for the
  // new attempt. The server will stop its worker when this socket closes.
  if (isCurrentAttempt(sessionId)) {
    azureSocket = null;
    azureReady = false;
  }
  try { socket?.close?.(1000, "retry-cancel"); } catch (_) {}
  azurePendingAudio = [];
  azurePendingBytes = 0;
}

function stopAzureStreaming() {
  azureAcceptingAudio = false;
  const socket = azureSocket;
  if (!socket) return Promise.resolve({ mode: "not-connected", words: [], phrases: [], raw: [], error: null, finalSeen: false, doneSeen: false });
  if (azureStopPromise) return azureStopPromise.promise;
  const stopState = { finalSeen: azureFinalSeen, doneSeen: azureDoneSeen };
  stopState.promise = new Promise(resolve => { stopState.resolve = resolve; });
  azureStopPromise = stopState;
  if (azureDoneSeen) {
    stopState.resolve({ mode: "python-fastapi-sdk-continuous-push-stream-scripted", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: azureError ? { message: azureError, status: 502 } : null, finalSeen: azureFinalSeen, doneSeen: true });
    azureStopPromise = null;
    return stopState.promise;
  }
  if (socket.readyState === WebSocket.OPEN) {
    try { socket.send(JSON.stringify({ type: "stop" })); }
    catch (error) {
      stopState.resolve({ mode: "stream-error", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: { message: "Could not send Azure stop command.", status: 502 }, finalSeen: azureFinalSeen, doneSeen: azureDoneSeen });
      azureStopPromise = null;
    }
  } else {
    stopState.resolve({ mode: "stream-closed", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: azureError ? { message: azureError, status: 502 } : null, finalSeen: azureFinalSeen, doneSeen: azureDoneSeen });
    azureStopPromise = null;
  }
  return stopState.promise;
}

async function connectOpenAITranscription({ referenceText = "", locale, stream, sessionId = recordingSessionId }) {
  if (!window.RTCPeerConnection) throw new Error("This browser does not support WebRTC.");
  const tokenEndpoint = "http://localhost:3000/api/realtime/token";
  const payload = { locale, passage: referenceText };
  console.log("[OpenAI Realtime] transcription connect model=gpt-live-transcribe context=keywords-only");

  const tokenResponse = await fetch(tokenEndpoint, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  const tokenBody = await tokenResponse.text();
  let tokenData = null;
  try { tokenData = tokenBody ? JSON.parse(tokenBody) : null; } catch (_) {}
  if (!tokenResponse.ok) throw new Error(tokenData?.error || tokenBody || `HTTP ${tokenResponse.status}`);
  const ephemeralKey = tokenData?.client_secret;
  if (!ephemeralKey) throw new Error("Realtime token response did not contain client_secret.");

  const pc = new RTCPeerConnection();
  const dc = pc.createDataChannel("oai-events");
  if (!isCurrentAttempt(sessionId) || !running) {
    try { dc.close?.(); } catch (_) {}
    try { pc.close?.(); } catch (_) {}
    throw new Error("OpenAI transcription attempt was canceled.");
  }
  realtimePc = pc;
  realtimeChannel = dc;
  realtimeSocket = null;

  dc.onopen = () => {
    if (!isCurrentAttempt(sessionId) || !running || (recordingLifecycle !== "starting" && recordingLifecycle !== "recording")) {
      try { dc.close?.(); } catch (_) {}
      return;
    }
    realtimeBridgeReady = true;
    realtimeVadState = running ? "silence" : "off";
    realtimeError = null;
    console.log(`[OpenAI Realtime] WebRTC data channel open model=gpt-live-transcribe type=transcription context=keywords-only delay=${tokenData?.delay || "medium"}`);
    if (!localVadSpeech && localVadBufferHasSpeech) sendRealtimeCommit("datachannel_ready_pending");
    updateVadUi();
    renderRealtimeLiveText();
  };

  dc.onmessage = event => {
    if (!isCurrentAttempt(sessionId) || typeof event.data !== "string") return;
    try { handleRealtimeEvent(JSON.parse(event.data), sessionId); }
    catch (error) { console.warn("[Realtime] bad data-channel event", error); }
  };
  dc.onerror = event => {
    if (!isCurrentAttempt(sessionId)) return;
    realtimeError = "Realtime data channel error";
    console.error("[OpenAI Realtime] data channel error", event);
    updateVadUi();
  };
  dc.onclose = () => {
    if (!isCurrentAttempt(sessionId)) return;
    realtimeBridgeReady = false;
    if (running && !realtimeClosed) {
      realtimeError = realtimeError || "Realtime data channel closed";
      console.warn(`[OpenAI Realtime] ${realtimeError}`);
      updateVadUi();
    }
  };

  pc.onconnectionstatechange = () => {
    if (!isCurrentAttempt(sessionId)) return;
    console.log(`[OpenAI Realtime] WebRTC connectionState=${pc.connectionState}`);
    if (["failed", "disconnected"].includes(pc.connectionState) && running) {
      realtimeError = `Realtime WebRTC connection ${pc.connectionState}`;
      updateVadUi();
    }
  };
  pc.addEventListener("iceconnectionstatechange", () => console.log(`[OpenAI Realtime] ICE state=${pc.iceConnectionState}`));

  for (const track of stream.getAudioTracks()) pc.addTrack(track, stream);
  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  if (pc.iceGatheringState !== "complete") {
    await new Promise(resolve => {
      const timer = setTimeout(resolve, 2500);
      const done = () => {
        if (pc.iceGatheringState === "complete") {
          clearTimeout(timer); pc.removeEventListener("icegatheringstatechange", done); resolve();
        }
      };
      pc.addEventListener("icegatheringstatechange", done);
    });
  }
  const localSdp = pc.localDescription?.sdp;
  if (!localSdp) throw new Error("WebRTC offer SDP was not generated.");
  const sdpResponse = await fetch("https://api.openai.com/v1/realtime/calls", {
    method: "POST",
    headers: { "Authorization": `Bearer ${ephemeralKey}`, "Content-Type": "application/sdp", "Accept": "application/sdp" },
    body: localSdp
  });
  const answerSdp = await sdpResponse.text();
  if (!sdpResponse.ok) throw new Error(`OpenAI Realtime WebRTC ${sdpResponse.status}: ${answerSdp || "No error body"}`);
  if (!isCurrentAttempt(sessionId) || !running) {
    try { dc.close?.(); } catch (_) {}
    try { pc.close?.(); } catch (_) {}
    throw new Error("OpenAI transcription attempt was canceled.");
  }
  await pc.setRemoteDescription({ type: "answer", sdp: answerSdp });
  console.log("[OpenAI Realtime] WebRTC SDP connected model=gpt-live-transcribe context=keywords-only turn_detection=null");
  await new Promise((resolve, reject) => {
    if (dc.readyState === "open") return resolve();
    const onOpen = () => { cleanup(); resolve(); };
    const onClose = () => { cleanup(); reject(new Error("Realtime data channel closed before it became ready.")); };
    const timer = setTimeout(() => { cleanup(); reject(new Error("Realtime data channel did not become ready within 8 seconds.")); }, 8000);
    const cleanup = () => { clearTimeout(timer); dc.removeEventListener("open", onOpen); dc.removeEventListener("close", onClose); };
    dc.addEventListener("open", onOpen, { once: true });
    dc.addEventListener("close", onClose, { once: true });
  });
  if (!isCurrentAttempt(sessionId) || !running) {
    try { dc.close?.(); } catch (_) {}
    try { pc.close?.(); } catch (_) {}
    throw new Error("OpenAI transcription attempt was canceled.");
  }
  console.log("[OpenAI Realtime] transcription READY model=gpt-live-transcribe context=keywords-only");
  realtimeTranscriptionDelayUsed = tokenData?.delay || "medium";
  return { transcriptionModel: "gpt-live-transcribe", transport: "webrtc_transcription", context: "keywords-only", delay: realtimeTranscriptionDelayUsed };
}

async function connectRealtime(referenceText, locale, stream, sessionId) {
  return connectOpenAITranscription({ referenceText, locale, stream, sessionId });
}

async function abortRealtimeSession(sessionId = recordingSessionId) {
  const channel = realtimeChannel;
  const pc = realtimePc;
  realtimeClosed = true;
  realtimeBridgeReady = false;
  localVadSpeech = false;
  localVadBufferHasSpeech = false;
  try { channel?.close?.(); } catch (_) {}
  try { pc?.close?.(); } catch (_) {}
  if (isCurrentAttempt(sessionId)) {
    realtimeChannel = null;
    realtimeSocket = null;
    realtimePc = null;
  }
}

async function closeRealtimeSession() {
  const channel = realtimeChannel;
  const pc = realtimePc;
  if (!channel || channel.readyState !== "open") {
    realtimeClosed = true;
    realtimeBridgeReady = false;
    try { pc?.close?.(); } catch (_) {}
    return;
  }
  if (localVadBufferHasSpeech && realtimeBridgeReady) {
    try { channel.send(JSON.stringify({ type: "input_audio_buffer.commit" })); } catch (_) {}
  }
  const before = realtimeCompletedItems.size;
  const start = performance.now();
  while (performance.now() - start < 5200) {
    if (realtimeCompletedItems.size > before) break;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  await new Promise(resolve => setTimeout(resolve, 250));
  realtimeClosed = true;
  realtimeBridgeReady = false;
  try { channel.close?.(); } catch (_) {}
  try { pc?.close?.(); } catch (_) {}
  realtimeChannel = null;
  realtimeSocket = null;
  realtimePc = null;
}

async function commitRealtimeAudioAndClose() {
  const before = realtimeCompletedItems.size;
  if (localVadBufferHasSpeech) sendRealtimeCommit("stop");
  const start = performance.now();
  while (performance.now() - start < 5200 && realtimeCompletedItems.size === before) {
    await new Promise(r => setTimeout(r, 100));
  }
  await closeRealtimeSession();
}

async function startRecording() {
  if (running || retryPromise) return;
  if (finalizingPromise) {
    els.hint.textContent = "Please wait for the previous Azure pronunciation assessment to finish.";
    return;
  }
  recordingSessionId += 1;
  const sessionId = recordingSessionId;
  recordingLifecycle = "starting";
  resetResults(); resetRealtimeState();
  updateStoryCount();
  setStatus("Connecting");
  els.hint.textContent = "Connecting OpenAI literal transcript + Azure… Please wait before reading.";

  mediaStream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
  window.lastRecordingBlob = null;
  for (const track of mediaStream.getAudioTracks()) track.enabled = false;
  audioContext = new (window.AudioContext || window.webkitAudioContext)();
  if (!audioContext.audioWorklet) throw new Error("This browser does not support AudioWorklet recording.");
  await audioContext.audioWorklet.addModule("v536/public/pcm-processor.js?v=4.5.36");
  sourceNode = audioContext.createMediaStreamSource(mediaStream);
  analyser = audioContext.createAnalyser(); analyser.fftSize = 512;
  recorderNode = new AudioWorkletNode(audioContext, "reader-recorder");
  silentGain = audioContext.createGain(); silentGain.gain.value = 0;
  recorderNode.port.onmessage = event => {
    if (!isCurrentAttempt(sessionId) || !running || recordingLifecycle !== "recording") return;
    const payload = event.data || {};
    if (payload.pcm16_16k) sendAzurePcm16(payload.pcm16_16k);
  };
  sourceNode.connect(analyser); sourceNode.connect(recorderNode); recorderNode.connect(silentGain); silentGain.connect(audioContext.destination);

  running = true;
  els.startBtn.disabled = true; els.stopBtn.disabled = true;
  els.resetBtn.disabled = true;
  els.editStoryBtn.disabled = true;
  els.locale.disabled = true;
  setRetryUi({ visible: false, enabled: false });
  const story = currentStory();
  azureAcceptingAudio = true;
  azureConnectPromise = connectAzureStreaming(story, els.locale.value, sessionId).catch(error => {
    if (isCurrentAttempt(sessionId)) {
      azureError = error.message || String(error);
      console.error("[Azure Stream] connect failed:", error);
    }
    return null;
  });
  realtimeConnectPromise = connectRealtime(story, els.locale.value, mediaStream, sessionId).catch(error => {
    if (isCurrentAttempt(sessionId)) {
      realtimeError = error.message || String(error);
      console.error("[OpenAI Realtime] connect failed:", error);
    }
    return null;
  });
  const realtimeReady = await realtimeConnectPromise;
  if (!isCurrentAttempt(sessionId)) return;
  if (!realtimeReady) {
    running = false;
    recordingLifecycle = "idle";
    els.startBtn.disabled = false; els.stopBtn.disabled = true;
    els.resetBtn.disabled = false;
    els.editStoryBtn.disabled = false;
    els.locale.disabled = false;
    setRetryUi({ visible: false, enabled: false });
    await stopLocalAudioCapture();
    throw new Error(`OpenAI transcription session failed to initialize: ${realtimeError || "unknown error"}`);
  }
  els.transcript.innerHTML = `<div class="countdown-overlay">3</div>`;
  for (let i = 3; i > 0; i--) {
    if (!running) return;
    els.hint.textContent = `Starting in ${i}...`;
    els.transcript.innerHTML = `<div class="countdown-overlay">${i}</div>`;
    await new Promise(r => setTimeout(r, 1000));
  }
  if (!running) return;
  els.hint.textContent = "Recording...";
  els.transcript.innerHTML = '<span class="empty-state">Your spoken words will appear here while you read.</span>';
  await azureConnectPromise;
  if (!isCurrentAttempt(sessionId) || !running) return;
  if (mediaStream) {
    for (const track of mediaStream.getAudioTracks()) track.enabled = true;
    window.appMediaRecorderChunks = [];
    window.appMediaRecorder = new MediaRecorder(mediaStream);
    window.appMediaRecorder.ondataavailable = e => { if (e.data.size > 0) window.appMediaRecorderChunks.push(e.data); };
    window.appMediaRecorder.onstop = () => { window.lastRecordingBlob = new Blob(window.appMediaRecorderChunks, { type: "audio/webm" }); };
    window.appMediaRecorder.start();
  }
  startedAt = Date.now();
  recordingLifecycle = "recording";
  document.body.classList.add("is-recording");
  els.startBtn.disabled = true; els.stopBtn.disabled = false;
  setRetryUi({ visible: true, enabled: true });
  setStatus("Recording");
  els.hint.textContent = "Reading: Stop to finish and assess · Retry to discard this attempt and start over.";
  startTimer();
}

async function stopRecording() {
  if (!running) return finalizingPromise;
  if (finalizingPromise) return finalizingPromise;

  const sessionId = recordingSessionId;
  recordingLifecycle = "finalizing";
  setRetryUi({ visible: false, enabled: false });
  const durationSeconds = startedAt ? Math.max(0, (Date.now() - startedAt) / 1000) : null;
  running = false;
  // Disable retry/reset immediately. A new recording must not replace the
  // current Azure session while its pronunciation callbacks are still
  // finalizing. This is the key protection against stop -> immediate retry
  // races and late AudioWorklet chunks reaching a closing worker.
  els.startBtn.disabled = true;
  els.stopBtn.disabled = true;
  els.resetBtn.disabled = true;
  els.editStoryBtn.disabled = true;
  els.locale.disabled = true;
  stopTimer();
  setStatus("Finalizing");
  els.hint.textContent = "Plss wait a moment finalizing";
  els.hint.textContent = "Finalizing Azure pronunciation assessment and the OpenAI transcript… Please wait before retrying.";

  finalizingPromise = (async () => {
    // Stop the source immediately. AudioWorklet messages can still arrive for a
    // short moment, but azureAcceptingAudio is cut off below before any wait.
    azureAcceptingAudio = false;
    await stopLocalAudioCapture();

    // Let OpenAI finish the already-buffered transcript. This does not reopen
    // or accept new microphone audio because the source and Azure acceptance
    // gate were shut down above.
    await Promise.allSettled([realtimeConnectPromise || Promise.resolve(null), azureConnectPromise || Promise.resolve(null)]);
    await commitRealtimeAudioAndClose();

    els.hint.textContent = "Waiting for Azure's final pronunciation result…";
    const azureFinalPromise = stopAzureStreaming();
    let azureFinal = null;
    try {
      azureFinal = await Promise.race([
        azureFinalPromise,
        new Promise(resolve => setTimeout(() => resolve({
          mode: "python-fastapi-sdk-continuous-push-stream-scripted-client-timeout",
          words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics,
          error: { message: "Azure finalization did not return within the client safety window; assessment remains withheld.", status: 504 },
          finalSeen: azureFinalSeen, doneSeen: false
        }), 65000))
      ]);
    } catch (error) {
      azureFinal = { mode: "python-fastapi-sdk-continuous-push-stream-scripted-error", words: azureStreamWords, phrases: azureStreamPhrases, raw: azureStreamRaw, diagnostics: azureDiagnostics, error: { message: error.message || String(error), status: 502 }, finalSeen: azureFinalSeen, doneSeen: azureDoneSeen };
    }

    // Azure finalization is considered complete only after azure.done.
    // azure.final carries the result payload, but markup remains hidden until
    // the transport/worker lifecycle also reports done. This prevents partial
    // pronunciation evidence from changing the learner-facing markup.
    const azureFinished = Boolean(azureFinal?.doneSeen ?? azureDoneSeen);
    els.hint.textContent = azureFinished
      ? "Cross-referencing OpenAI transcript, passage, and Azure pronunciation evidence…"
      : "Azure did not finish pronunciation finalization; assessment markup is being withheld.";
    const rtText = realtimeTranscriptText();
    const data = {
      realtime: {
        transcript: rtText,
        enabled: Boolean(rtText),
        model: "gpt-live-transcribe",
        requestedModel: "gpt-live-transcribe",
        context: "keywords-only",
        delay: realtimeTranscriptionDelayUsed,
        vad: localVadConfig
      },
      azure: { mode: azureFinal?.mode || "python-fastapi-sdk-continuous-push-stream-scripted", words: azureFinal?.words || [], phrases: azureFinal?.phrases || [], raw: azureFinal?.raw || [], diagnostics: azureFinal?.diagnostics || azureDiagnostics, error: azureFinal?.error || null, finalSeen: Boolean(azureFinal?.finalSeen ?? azureFinalSeen), doneSeen: Boolean(azureFinal?.doneSeen ?? azureDoneSeen) },
      pipeline: {
        realtimeTranscription: "gpt-live-transcribe",
        requestedRealtimeTranscription: "gpt-live-transcribe",
        realtimeTranscriptionContext: "keywords-only",
        realtimeTranscriptionDelay: realtimeTranscriptionDelayUsed,
        realtimeVAD: localVadConfig,
        batchTranscription: "disabled",
        pronunciation: azureFinal?.error ? "partial" : "Azure Speech Pronunciation Assessment",
        comparison: "reference passage + OpenAI literal transcript + continuous Azure scripted pronunciation/timing evidence",
        audioDurationSeconds: durationSeconds,
        azureStreaming: true,
        azureReferenceText: "hidden-passage",
        azureFinalSeen: Boolean(azureFinal?.finalSeen ?? azureFinalSeen),
        azureDoneSeen: Boolean(azureFinal?.doneSeen ?? azureDoneSeen),
        openaiTranscriptionPasses: 1,
        note: "Assessment markup is withheld until Azure finalization completes. OpenAI remains the visible literal transcript; Azure supplies pronunciation evidence only."
      },
      errors: [
        ...(azureFinal?.error ? [{ provider: "azure", message: azureFinal.error.message || String(azureFinal.error), status: azureFinal.error.status ?? null, details: null }] : [])
      ]
    };

    if (!azureFinished) {
      // Never reveal omission/substitution/repetition/self-correction or Azure
      // pronunciation markup from an incomplete Azure session. Keep the plain
      // literal transcript visible and allow a clean retry after this session
      // has been fully torn down.
      els.transcript.innerHTML = rtText
        ? renderLiveTokens(rtText)
        : '<span class="empty-state">No Realtime transcript was captured.</span>';
      els.transcriptNotice.hidden = false;
      els.transcriptNotice.textContent = "Azure pronunciation assessment did not finish. Assessment markup was withheld to avoid showing incomplete results. You may retry now.";
      renderDiagnostics(data, null);
      setStatus("Partial");
      els.hint.textContent = "Azure did not finish. Assessment markup was withheld; the previous session is closed and Retry is ready.";
      return;
    }

    if (!rtText) {
      els.transcript.innerHTML = '<span class="empty-state">No Realtime transcript was captured.</span>';
      els.transcriptNotice.hidden = false;
      els.transcriptNotice.textContent = "Realtime transcription was unavailable. Azure streamed continuously, but Reading Accuracy is not calculated from an empty transcript.";
      els.readingAccuracy.textContent = "—";
      setStatus(data.errors.length ? "Partial" : "Complete");
      els.hint.textContent = data.errors.length ? data.errors.map(e => `${e.provider}: ${e.message}`).join(" | ") : "Azure finished streaming, but OpenAI did not return a transcript.";
      renderDiagnostics(data, null);
      return;
    }

    renderAssessment(data);
    if (data.errors?.length) {
      setStatus("Partial");
      els.hint.textContent = data.errors.map(e => `${e.provider}: ${e.message}`).join(" | ");
    } else {
      setStatus("Complete");
      els.hint.textContent = "Assessment complete. Azure pronunciation was finalized before markup was revealed.";
    }
  })().finally(() => {
    // Do not re-enable Retry until this entire finalization transaction is
    // over. A stale AudioWorklet/WebSocket event can therefore never reset or
    // replace the just-finished assessment.
    if (sessionId === recordingSessionId) {
      finalizingPromise = null;
      recordingLifecycle = "idle";
      els.startBtn.disabled = false;
      els.stopBtn.disabled = true;
      setRetryUi({ visible: false, enabled: false });
      els.resetBtn.disabled = false;
      els.editStoryBtn.disabled = false;
      els.locale.disabled = false;
    }
  });
  return finalizingPromise;
}

async function retryRecording() {
  if (!running || finalizingPromise || retryPromise) return;
  const oldSessionId = recordingSessionId;
  retryPromise = (async () => {
    // Invalidate every callback from the attempt we are abandoning BEFORE any
    // asynchronous cleanup begins. Late OpenAI/Azure events from that attempt
    // must never mutate the new attempt.
    recordingSessionId += 1;
    recordingLifecycle = "retrying";
  document.body.classList.remove("is-recording");
    running = false;
    setRetryUi({ visible: true, enabled: false });
    els.startBtn.disabled = true;
    els.stopBtn.disabled = true;
    els.resetBtn.disabled = true;
    els.editStoryBtn.disabled = true;
    els.locale.disabled = true;
    stopTimer();
    els.hint.textContent = "Discarding this attempt and starting a fresh reading…";
    console.log(`[Retry] cancel attempt=${oldSessionId}`);
    setStatus("Retrying");

    azureAcceptingAudio = false;
    await stopLocalAudioCapture();

    // Cancel, do not finalize: no commit, no Azure stop command, no assessment.
    await Promise.allSettled([
      abortRealtimeSession(oldSessionId),
      abortAzureStreaming(oldSessionId)
    ]);

    // Clear all per-attempt state only after the old transports have been
    // detached/closed. The passage itself is static and is preserved.
    resetResults();
    resetRealtimeState();
    startedAt = null;

    setRetryUi({ visible: false, enabled: false });
    els.resetBtn.disabled = false;
    els.editStoryBtn.disabled = false;
    els.locale.disabled = false;
    recordingLifecycle = "idle";
  })().finally(async () => {
    retryPromise = null;
    // Start a brand-new attempt only after the previous attempt has been
    // invalidated and its local transports have been closed.
    if (recordingSessionId !== oldSessionId) {
      try { await startRecording(); } catch (error) {
        console.error("[Retry] fresh recording start failed:", error);
        recordingLifecycle = "idle";
        els.startBtn.disabled = false;
        els.stopBtn.disabled = true;
        els.resetBtn.disabled = false;
        els.editStoryBtn.disabled = false;
        els.locale.disabled = false;
        setStatus("Error");
        els.hint.textContent = error.message || "Could not start a fresh reading.";
      }
    }
  });
  return retryPromise;
}

function resetResults() {
  document.body.classList.remove("is-recording");
  resetAzureStreamState();

  els.timer.textContent = "00:00";
  els.wpm.textContent = "—";
  els.readingAccuracy.textContent = "—";
    els.miscueStrip.innerHTML = "";
  if(els.diagContent) els.diagContent.textContent = "No assessment yet.";
  els.transcript.innerHTML = '<span class="empty-state">Your spoken words will appear here while you read.</span>';
  els.transcriptNotice.hidden = true;
  els.transcriptNotice.textContent = "";
  lastAssessment = null;
  hideTooltip();
}


function formatAudioTime(seconds, includeHours = false) {
  const n = Number(seconds);
  if (!Number.isFinite(n) || n < 0) return null;
  const hours = Math.floor(n / 3600);
  const minutes = Math.floor((n % 3600) / 60);
  const secs = n % 60;
  if (includeHours || hours > 0) {
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${secs.toFixed(2).padStart(5, "0")}`;
  }
  return `${String(minutes).padStart(2, "0")}:${secs.toFixed(2).padStart(5, "0")}`;
}

function transcriptAzureEvidence(assessment, spokenIndex, refIndex, azureMap) {
  const bySpoken = assessment?.evidenceBySpoken;
  if (bySpoken && typeof bySpoken.get === "function") {
    const direct = bySpoken.get(Number(spokenIndex));
    if (direct) return direct;
  }
  return refIndex != null ? azureMap.get(refIndex) : null;
}

function buildTooltipData(assessment, data, op, spokenIndex, azureMap) {
  const spokenWord = assessment.spokenWords[spokenIndex]?.word || op?.spokenWord || "";
  let refIndex = op?.ref;
  let expectedWord = "";
  if (op?.type === "compound" && Array.isArray(op.refs)) {
    refIndex = op.refs[0];
    expectedWord = op.refs.map(r => assessment.referenceWords[r]?.word || "").filter(Boolean).join(" ");
  } else if (refIndex == null && Array.isArray(op?.refs)) {
    const match = op.refs.findIndex((_, idx) => Number(op.spokens?.[idx]) === spokenIndex);
    if (match >= 0) refIndex = op.refs[match];
  }
  if (!expectedWord && refIndex != null) expectedWord = assessment.referenceWords[refIndex]?.word || "";
  const evidence = op?.evidence || transcriptAzureEvidence(assessment, spokenIndex, refIndex, azureMap);
  return {
    expectedWord,
    spokenWord,
    type: op?.type || "match",
    op,
    evidence,
  };
}

function phonemeDetails(evidence) {
  const phonemes = Array.isArray(evidence?.phonemes) ? evidence.phonemes : [];
  const rows = [];
  for (const ph of phonemes) {
    const expected = ph?.Phoneme ?? ph?.phoneme ?? "";
    const pa = ph?.PronunciationAssessment || ph?.pronunciationAssessment || {};
    const candidates = Array.isArray(ph?.candidates) ? ph.candidates : (Array.isArray(pa.NBestPhonemes) ? pa.NBestPhonemes : []);
    const top = candidates[0];
    const spoken = top?.Phoneme ?? top?.phoneme ?? "";
    const score = top?.Score ?? top?.score ?? ph?.accuracy ?? ph?.Accuracy ?? pa.AccuracyScore;
    rows.push({ expected, spoken, score });
  }
  return rows;
}

function tooltipHtml(info, assessment) {
  const e = info.evidence;
  const labelMap = { mis: "Mispronunciation", omit: "Omission", sub: "Substitution", repeat: "Repetition", trans: "Transposition", reverse: "Reversal", insert: "Insertion", "self-repair": "Self-correction (first attempt)", "self-marker": "Self-correction marker", "self-corrected": "Self-correction (corrected)", "uncertain-match": "Review" };
  const structuralName = labelMap[info.type] || "No reading error";
  const azureMispronunciation = String(e?.errorType || "").toLowerCase() === "mispronunciation";
  const assessmentName = azureMispronunciation && !["mis"].includes(info.type)
    ? `${structuralName} + Azure Mispronunciation`
    : (labelMap[info.type] || (e?.errorType && e.errorType !== "None" ? e.errorType : "No reading error"));
  const accuracy = e && Number.isFinite(Number(e.accuracy)) ? Number(e.accuracy) : null;
  const errorType = e?.errorType || "None";
  const phonemes = phonemeDetails(e);
  const expectedPh = phonemes.map(x => x.expected).filter(Boolean).join(" ");
  const spokenPh = phonemes.map(x => x.spoken || "—").join(" ");
  const scoreClass = "";
  let html = `<h4>Azure pronunciation</h4><div class="tip-grid">
    <div class="tip-label">Assessment</div><div class="tip-value">${escapeHtml(assessmentName)}</div>
    <div class="tip-label">Expected</div><div class="tip-value">${escapeHtml(info.expectedWord || "—")}</div>
    <div class="tip-label">Spoken</div><div class="tip-value">${escapeHtml(info.spokenWord || "—")}</div>
    <div class="tip-label">Accuracy</div><div class="tip-value ${scoreClass}">${accuracy == null ? "—" : accuracy.toFixed(1)}</div>
    <div class="tip-label">Azure error</div><div class="tip-value">${escapeHtml(errorType)}</div>
  </div>`;

  const timing = e?.timing || Core.azureTiming?.(e);
  if (timing && Number.isFinite(Number(timing.startSeconds))) {
    const startSeconds = Number(timing.startSeconds);
    const endSeconds = Number.isFinite(Number(timing.endSeconds)) ? Number(timing.endSeconds) : null;
    const startLabel = formatAudioTime(startSeconds, startSeconds >= 3600);
    const endLabel = endSeconds != null ? formatAudioTime(endSeconds, endSeconds >= 3600) : null;
    html += `<div class="tip-time"><div class="tip-label">Audio timestamp</div><div class="tip-value"><code>${escapeHtml(startLabel)}${endLabel ? ` – ${escapeHtml(endLabel)}` : ""}</code></div></div>`;
  }

  if (info.type === "self-repair" || info.type === "self-marker" || info.type === "self-corrected") {
    const sc = info.op?.selfCorrection || {};
    const reasonMap = {"wrong-then-correct":"Wrong attempt followed by target correction", "improved-repeat":"Repeated attempt improved", "repair-marker":"Explicit repair marker detected"};
    html += `<div class="tip-self"><div class="tip-label">Self-correction</div><div class="tip-value">${escapeHtml(reasonMap[sc.reason] || "Immediate correction")}</div><div class="tip-label">Corrected word</div><div class="tip-value">${escapeHtml(info.expectedWord || info.spokenWord || "—")}</div><div class="tip-label">Original attempt</div><div class="tip-value">${escapeHtml(info.op?.selfCorrection?.reparandums?.map(i => assessment?.spokenWords?.[i]?.word).join(" → ") || "—")}</div></div>`;
  }

  if (e) {
    html += `<div class="tip-phonemes"><div class="tip-label">Expected phonemes (IPA)</div><div class="tip-value">${escapeHtml(expectedPh || "Not returned")}</div><div class="tip-label" style="margin-top:4px">Spoken phonemes (Azure top candidate)</div><div class="tip-value">${escapeHtml(spokenPh || "Not returned")}</div>`;
    for (const row of phonemes.slice(0, 8)) {
      html += `<div class="tip-phoneme-row"><span>${escapeHtml(row.expected || "—")} → ${escapeHtml(row.spoken || "—")}</span><span>${row.score == null ? "—" : Number(row.score).toFixed(0)}</span></div>`;
    }
    html += `</div>`;
  } else {
    html += `<div class="tip-note">Azure did not provide direct word-level pronunciation evidence for this spoken token.</div>`;
  }
  return html;
}

function transcriptClassForType(type) {
  return type === "mis" ? "mis" :
    type === "sub" ? "sub" :
    type === "reverse" ? "rev" :
    type === "repeat" ? "rep" :
    type === "trans" ? "trans" :
    type === "self-repair" ? "self-repair" :
    type === "self-marker" ? "self-marker" :
    type === "self-corrected" ? "self-corrected" :
    type === "insert" ? "ins" :
    type === "uncertain-match" ? "uncertain" : "";
}

function renderTranscriptMarkup(assessment, data) {
  const azureWords = data.azure?.words || [];
  const azureMap = Core.mapAzureEvidence(assessment.referenceWords, azureWords);
  const spokenWords = assessment.spokenWords;
  const bySpoken = new Map();
  const omissions = [];

  for (const op of assessment.ops) {
    if ((op.type === "omit" || op.type === "azure-mis-omit") && op.ref != null) {
      omissions.push(op);
      continue;
    }
    if (op.spoken != null) bySpoken.set(Number(op.spoken), op);
    if (Array.isArray(op.spokens)) {
      op.spokens.forEach((idx, k) => {
        bySpoken.set(Number(idx), { ...op, ref: op.refs?.[k] ?? op.ref, spoken: Number(idx) });
      });
    }
  }

  // Insert omission markers before the first spoken token whose reference slot
  // is at/after the omitted reference. This keeps ALL visual scoring markup in
  // the transcript pane while the expected story stays clean.
  const omissionAtSpoken = new Map();
  for (const op of omissions) {
    let insertAt = spokenWords.length;
    for (const [spokenIndex, spokenOp] of bySpoken.entries()) {
      const ref = spokenOp?.ref != null ? Number(spokenOp.ref) : null;
      if (ref != null && ref >= Number(op.ref)) {
        insertAt = Math.min(insertAt, Number(spokenIndex));
      }
    }
    if (!omissionAtSpoken.has(insertAt)) omissionAtSpoken.set(insertAt, []);
    omissionAtSpoken.get(insertAt).push(op);
  }

  transcriptTips = [];
  const html = [];
  for (let i = 0; i <= spokenWords.length; i++) {
    for (const omitOp of omissionAtSpoken.get(i) || []) {
      const refIndex = Number(omitOp.ref);
      const expectedWord = assessment.referenceWords[refIndex]?.word || "";
      const info = buildTooltipData(assessment, data, omitOp, Math.max(0, Math.min(i, spokenWords.length - 1)), azureMap);
      info.expectedWord = expectedWord;
      info.spokenWord = "";
      const tipIndex = transcriptTips.push(info) - 1;
      if (omitOp.type === "azure-mis-omit") {
        html.push(`<span class="transcript-token mis" data-tip-index="${tipIndex}" data-tip-html="${escapeHtml(tooltipHtml(info, assessment))}" data-expected="${escapeHtml(expectedWord)}" tabindex="0" title="Mispronunciation">${escapeHtml(expectedWord)}</span>`);
      } else {
        html.push(`<span class="transcript-token omit-placeholder" data-tip-index="${tipIndex}" data-tip-html="${escapeHtml(tooltipHtml(info, assessment))}" tabindex="0" title="Omission">${escapeHtml(expectedWord)}</span>`);
      }
    }
    if (i === spokenWords.length) break;

    const word = spokenWords[i];
    const op = bySpoken.get(i) || { type: "match", spoken: i };
    const info = buildTooltipData(assessment, data, op, i, azureMap);
    const tipIndex = transcriptTips.push(info) - 1;
    const classes = [transcriptClassForType(op.type)];
    if (String(op.azurePronunciation || "").toLowerCase() === "mispronunciation" && op.type !== "mis") classes.push("azure-mis");
    const attrs = [];

    if (op.type === "mis" || op.type === "sub" || op.type === "reverse") {
      const expected = info.expectedWord || "";
      if (expected) attrs.push(`data-expected="${escapeHtml(expected)}"`);
    }
    if (op.type === "self-repair") {
      const correctionIndex = Number(op.selfCorrection?.correction);
      if (Number.isFinite(correctionIndex)) attrs.push(`data-correction-index="${correctionIndex}"`);
    }
    if (op.type === "self-corrected") {
      const attemptText = (op.selfCorrection?.reparandums || [])
        .map(idx => spokenWords[Number(idx)]?.word || "")
        .filter(Boolean)
        .join(" → ");
      if (attemptText) attrs.push(`data-selfattempt="${escapeHtml(attemptText)}"`);
    }
    if (op.type === "repeat" && op.repeat) {
      attrs.push(`data-retrace="${Number(op.repeat.retraceSpoken ?? Math.max(0, i - 1))}"`);
      attrs.push(`data-repeat-start="${Number(op.repeat.repeatStartSpoken ?? i)}"`);
      attrs.push(`data-repeat-length="${Number(op.repeat.length || 1)}"`);
    }
    if (op.type === "trans" && Array.isArray(op.spokens)) {
      attrs.push(`data-trans-pair="${op.spokens.map(Number).join(",")}"`);
    }
    if (op.azurePronunciation) attrs.push(`data-azure-error="${escapeHtml(op.azurePronunciation)}"`);
    if (op.alignmentType) attrs.push(`data-alignment-type="${escapeHtml(op.alignmentType)}"`);

    const azureEvidence = assessment.evidenceBySpoken?.get?.(i) ||
      transcriptAzureEvidence(assessment, i, op?.ref, azureMap) || null;
    const timing = azureEvidence?.timing || Core.azureTiming?.(azureEvidence);
    if (timing && Number.isFinite(Number(timing.startSeconds))) {
      attrs.push(`data-audio-start="${Number(timing.startSeconds).toFixed(3)}"`);
      if (Number.isFinite(Number(timing.endSeconds))) attrs.push(`data-audio-end="${Number(timing.endSeconds).toFixed(3)}"`);
      if (Number.isFinite(Number(timing.durationSeconds))) attrs.push(`data-audio-duration="${Number(timing.durationSeconds).toFixed(3)}"`);
    }

    const tokenInner = escapeHtml(word.word);
    html.push(`<span class="transcript-token ${classes.filter(Boolean).join(" ")}" data-tip-index="${tipIndex}" data-tip-html="${escapeHtml(tooltipHtml(info, assessment))}" tabindex="0" ${attrs.join(" ")}>${tokenInner}</span>`);
  }
  return html.join(" ") || '<span class="empty-state">No Realtime transcript was captured.</span>';
}

function renderMarkedPassage(_assessment) {
  // The expected story is the clean reference. All scoring/reading markup is
  // intentionally rendered in the transcript pane so the learner's actual
  // spoken sequence is the thing being annotated.
  const plain = normalizeTextForDisplay(els.story.textContent);
  els.story.textContent = plain;
}

function miscuesForAccuracy(counts) {
  return (counts.mispronunciation || 0) + (counts.omission || 0) + (counts.substitution || 0) +
    (counts.repetition || 0) + (counts.transposition || 0) + (counts.reversal || 0);
}

function renderMiscueStrip(counts) {
  const items = [
    ["Mispronunciation","mis",counts.mispronunciation], ["Omission","om",counts.omission],
    ["Substitution","sub",counts.substitution], ["Repetition","rep",counts.repetition],
    ["Transposition","trans",counts.transposition], ["Reversal","rev",counts.reversal],
    ["Self-correction","self",counts.selfCorrection || 0]
  ];
  els.miscueStrip.innerHTML = items.map(([label, cls, value]) => `<span class="miscue-chip ${cls}">${label}<b>${value}</b></span>`).join("");
}

function renderPerformance(assessment, data) {
  const duration = Number(data.pipeline?.audioDurationSeconds);
  const spokenCount = assessment.spokenWords.length;
  const wpm = Number.isFinite(duration) && duration > 0 ? spokenCount / (duration / 60) : null;
  const miscues = miscuesForAccuracy(assessment.counts);
  const words = assessment.referenceWords.length;
  const accuracy = words > 0 ? Math.max(0, ((words - miscues) / words) * 100) : null;

  els.wpm.textContent = wpm == null ? "—" : wpm.toFixed(0);
  els.readingAccuracy.textContent = accuracy == null ? "—" : `${accuracy.toFixed(1)}%`;
  renderMiscueStrip(assessment.counts);

}

function avg(values) { const v = values.filter(n => Number.isFinite(n)); return v.length ? v.reduce((a,b)=>a+b,0)/v.length : null; }

function renderAssessment(data) {
  updateStoryCount();

  const realtimeText = data.realtime?.transcript || realtimeTranscriptText();
  const azureWords = data.azure?.words || [];
  const azureText = (data.azure?.raw || []).map(x => x.text || x.json?.NBest?.[0]?.Display).filter(Boolean).join(" ");

  if (!realtimeText) {
    els.transcript.innerHTML = azureText
      ? `<div class="transcript-fallback-label">Azure recognition (shown only as a diagnostic fallback)</div><div class="transcript-fallback">${escapeHtml(azureText)}</div>`
      : '<span class="empty-state">No Realtime transcript was captured.</span>';
    els.transcriptNotice.hidden = false;
    els.transcriptNotice.textContent = `Realtime transcription was unavailable. Azure pronunciation was still evaluated, but Reading Accuracy is not calculated from an empty transcript.`;
    const emptyAssessment = { referenceWords: Core.safeWords ? Core.safeWords(Core.tokenize(currentStory())) : Core.tokenize(currentStory()).map((w,i)=>({word:w,norm:Core.normalizeWord(w),index:i})), spokenWords: [], ops: [], counts: {mispronunciation:0,omission:0,insertion:0,substitution:0,repetition:0,transposition:0,reversal:0,uncertain:0}, definiteErrors:0 };
    lastAssessment = emptyAssessment;
    renderPerformance(emptyAssessment, data);
    els.readingAccuracy.textContent = "—";
    renderDiagnostics(data, typeof assessment !== "undefined" ? assessment : (typeof lastAssessment !== "undefined" ? lastAssessment : null));
    return;
  }

  const assessment = Core.analyze(currentStory(), realtimeText, azureWords, {
    realtimeText,
  });
  lastAssessment = assessment;
  renderMarkedPassage(assessment);
  els.transcript.innerHTML = renderTranscriptMarkup(assessment, data);
  els.transcriptNotice.hidden = true;
  renderPerformance(assessment, data);
  renderDiagnostics(data, typeof assessment !== "undefined" ? assessment : (typeof lastAssessment !== "undefined" ? lastAssessment : null));
  window.dispatchEvent(new CustomEvent('assessmentComplete', { detail: { data, assessment: typeof assessment !== 'undefined' ? assessment : lastAssessment } }));
}

function positionTooltip(clientX, clientY) {
  if (!tooltipVisible) return;
  const pad = 10;
  const rect = els.tooltip.getBoundingClientRect();
  let left = clientX + 14;
  let top = clientY + 14;
  if (left + rect.width > window.innerWidth - pad) left = clientX - rect.width - 14;
  if (top + rect.height > window.innerHeight - pad) top = clientY - rect.height - 14;
  els.tooltip.style.left = `${Math.max(pad, left)}px`;
  els.tooltip.style.top = `${Math.max(pad, top)}px`;
}
function showTooltip(index, clientX, clientY) {
  const info = transcriptTips[Number(index)];
  if (!info) return;
  els.tooltip.innerHTML = tooltipHtml(info, lastAssessment);
  els.tooltip.hidden = false;
  tooltipVisible = true;
  requestAnimationFrame(() => positionTooltip(clientX, clientY));
}
function showTooltipForElement(el) {
  const info = transcriptTips[Number(el.dataset.tipIndex)];
  if (!info) return;
  els.tooltip.innerHTML = tooltipHtml(info, lastAssessment);
  els.tooltip.hidden = false;
  tooltipVisible = true;
  const rect = el.getBoundingClientRect();
  positionTooltip(rect.left + rect.width / 2, rect.bottom + 6);
}
function hideTooltip() { tooltipVisible = false; els.tooltip.hidden = true; }

els.transcript.addEventListener("pointerover", (event) => {
  const token = event.target.closest?.(".transcript-token[data-tip-index]");
  if (!token || !els.transcript.contains(token)) return;
  showTooltip(Number(token.dataset.tipIndex), event.clientX, event.clientY);
});
els.transcript.addEventListener("pointermove", (event) => {
  const token = event.target.closest?.(".transcript-token[data-tip-index]");
  if (token) positionTooltip(event.clientX, event.clientY);
});
els.transcript.addEventListener("pointerout", (event) => {
  const token = event.target.closest?.(".transcript-token[data-tip-index]");
  const next = event.relatedTarget?.closest?.(".transcript-token[data-tip-index]");
  if (token && next !== token) hideTooltip();
});
els.transcript.addEventListener("focusin", event => {
  const token = event.target.closest?.(".transcript-token[data-tip-index]");
  if (token) showTooltipForElement(token);
});
els.transcript.addEventListener("focusout", event => {
  if (!event.relatedTarget || !event.relatedTarget.closest?.(".transcript-token[data-tip-index]")) hideTooltip();
});
window.addEventListener("scroll", hideTooltip, { passive: true });
window.addEventListener("resize", hideTooltip);

els.editStoryBtn.addEventListener("click", () => {
  if (els.storyEditor.hidden) {
    els.storyEditor.value = els.story.textContent.trim();
    els.story.hidden = true; els.storyEditor.hidden = false; els.editStoryBtn.querySelector("strong").textContent = "Save";
  } else {
    els.story.textContent = els.storyEditor.value.trim() || window.originalStory;
    els.storyEditor.hidden = true; els.story.hidden = false; els.editStoryBtn.querySelector("strong").textContent = "Edit";
    updateStoryCount();
  }
});

els.startBtn.addEventListener("click", () => startRecording().catch(error => {
  console.error(error);
  running = false;
  recordingLifecycle = "idle";
  document.body.classList.remove("is-recording");
  setRetryUi({ visible: false, enabled: false });
  stopTimer();
  stopLocalAudioCapture();
  els.startBtn.disabled = false;
  els.stopBtn.disabled = true;
  els.resetBtn.disabled = false;
  els.editStoryBtn.disabled = false;
  els.locale.disabled = false;
  setStatus("Error");
  els.hint.textContent = error.message || "Microphone permission failed.";
}));
els.stopBtn.addEventListener("click", stopRecording);
if (els.restartBtn) els.restartBtn.addEventListener("click", () => retryRecording().catch(error => console.error("[Retry] failed", error)));
els.resetBtn.addEventListener("click", () => {
  if (finalizingPromise) {
    els.hint.textContent = "Please wait for Azure finalization to finish before resetting.";
    return;
  }
  recordingSessionId += 1;
  recordingLifecycle = "idle";
  running = false;
  stopLocalAudioCapture();
  try { realtimeChannel?.close(); } catch (_) {}
  try { realtimePc?.close(); } catch (_) {}
  try { azureSocket?.close?.(); } catch (_) {}
  stopTimer(); resetResults(); resetRealtimeState();
  setRetryUi({ visible: false, enabled: false });
  els.story.textContent = window.originalStory;
  els.storyEditor.hidden = true; els.story.hidden = false;
  els.editStoryBtn.querySelector("strong").textContent = "Edit";
  els.startBtn.disabled = false; els.stopBtn.disabled = true;
  setStatus("Ready"); els.hint.textContent = "Ready. Press Start and read the passage.";
  updateStoryCount();
});

// Initial display.
setRetryUi({ visible: false, enabled: false });
updateStoryCount();

function renderDiagnostics(data, assessment) {
  if (!data) return;
  const rawData = JSON.stringify({ ...data, realtimeServerEvents: typeof realtimeServerEvents !== 'undefined' ? realtimeServerEvents : [] }, null, 2);
  const html = `<pre style="max-height:60vh;overflow:auto;background:#0b1020;color:#d9e1ff;padding:12px;border-radius:9px;font-size:10px;margin:0;">${rawData}</pre>`;
  if(els.diagContent) els.diagContent.innerHTML = html;
}

if(els.diagBtn) els.diagBtn.addEventListener("click", () => els.diagModal.showModal());
if(els.closeDiagBtn) els.closeDiagBtn.addEventListener("click", () => els.diagModal.close());
