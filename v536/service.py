#!/usr/bin/env python3
"""Azure continuous pronunciation-assessment WebSocket service.

Protocol used by the reader:
  1. text  {"type":"start","locale":"en-US","referenceText":"..."}
  2. binary raw PCM16 mono 16 kHz chunks while the learner reads
  3. text  {"type":"stop"}

The browser never uploads a WAV for the normal assessment path. Node proxies
this WebSocket to the Python service. Python owns Azure Speech SDK and feeds a
real PushAudioInputStream into a continuous SpeechRecognizer.

Authority split:
  - OpenAI Realtime = visible literal transcript.
  - Existing JS assessment-core = structural miscues.
  - Azure = pronunciation/phoneme/timing evidence only.

Continuous Azure pronunciation assessment does not use Azure EnableMiscue here;
structural omission/insertion/etc. remain in the existing JS alignment layer.
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import queue
import re
import threading
import time
import uuid
from typing import Any, Dict, List, Optional

from fastapi import FastAPI, WebSocket, WebSocketDisconnect, Request
from fastapi.middleware.cors import CORSMiddleware
import urllib.request
import urllib.error
from fastapi.responses import JSONResponse

try:
    import azure.cognitiveservices.speech as speechsdk
except Exception as exc:  # pragma: no cover
    speechsdk = None
    AZURE_IMPORT_ERROR = str(exc)
else:
    AZURE_IMPORT_ERROR = ""

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("readingassessment.azure")

APP_HOST = os.getenv("AZURE_SERVICE_HOST", os.getenv("STREAM_HOST", "0.0.0.0"))
APP_PORT = int(os.getenv("AZURE_SERVICE_PORT", os.getenv("STREAM_PORT", "8011")))
AZURE_LANG = os.getenv("AZURE_SPEECH_LANGUAGE", "en-US").strip() or "en-US"
SERVICE_BUILD = "v536-fastapi-1"


def load_env_files() -> None:
    candidates = [
        os.path.join(os.path.dirname(__file__), ".env"),
        os.path.join(os.path.dirname(__file__), "../.env"),
        "/var/www/andrew/moodle/moodle/mod/readingassessment/.env",
        "/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/.env",
        "/var/www/andrew/.env",
        os.path.expanduser("~/.env"),
    ]
    for path in candidates:
        if not os.path.isfile(path):
            continue
        try:
            with open(path, "r", encoding="utf-8", errors="ignore") as fh:
                for line in fh:
                    line = line.strip()
                    if not line or line.startswith("#") or "=" not in line:
                        continue
                    key, value = line.split("=", 1)
                    key = key.strip().replace("export ", "", 1)
                    value = value.strip().strip("'\"")
                    if key and value and key not in os.environ:
                        os.environ[key] = value
        except OSError:
            pass


load_env_files()
AZURE_KEY = os.getenv("AZURE_SPEECH_KEY", os.getenv("RA_AZURE_KEY", "")).strip()
AZURE_REGION = os.getenv("AZURE_SPEECH_REGION", os.getenv("RA_AZURE_REGION", "southeastasia")).strip()

app = FastAPI(title="Reading Assessment Azure Speech Service", version="5.36.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.post("/api/realtime/token")
async def get_realtime_token(request: Request):
    try:
        body = await request.json()
        locale = body.get("locale", "en-US")
        passage = body.get("passage", "")
        
        openai_key = os.getenv("AZURE_OPENAI_API_KEY", os.getenv("OPENAI_API_KEY", "")).strip()
        transcription_model = os.getenv("REALTIME_TRANSCRIPTION_MODEL", "whisper-1").strip()
        if not openai_key:
            return JSONResponse({"error": "OPENAI_API_KEY is not configured.", "stage": "configuration"}, status_code=500)
            
        import re
        keywords = list(set([w for w in re.findall(r"[A-Za-z][A-Za-z'’-]*", passage.lower()) if len(w) >= 4]))
            
        session_payload = {
            "type": "transcription",
            "audio": {
                "input": {
                    "transcription": {
                        "model": transcription_model,
                        "prompt": f"You are transcribing a reading assessment for {locale}. The user is reading: {passage}",
                        "keywords": keywords
                    }
                }
            }
        }
        
        req = urllib.request.Request(
            "https://api.openai.com/v1/realtime/client_secrets",
            data=json.dumps({"session": session_payload}).encode("utf-8"),
            headers={
                "Authorization": f"Bearer {openai_key}",
                "Content-Type": "application/json"
            },
            method="POST"
        )
        
        with urllib.request.urlopen(req) as response:
            res_data = json.loads(response.read().decode())
            client_secret = res_data.get("value") or res_data.get("client_secret", {}).get("value")
            
            return {
                "client_secret": client_secret,
                "model": "gpt-live-transcribe",
                "type": "transcription",
                "context": "none",
                "delay": 600
            }
    except urllib.error.HTTPError as e:
        error_body = e.read().decode()
        return JSONResponse({"error": f"OpenAI Error {e.code}: {error_body}"}, status_code=500)
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)



def emit_json(ws_queue: asyncio.Queue, payload: Dict[str, Any], loop: asyncio.AbstractEventLoop) -> None:
    try:
        asyncio.run_coroutine_threadsafe(ws_queue.put(payload), loop)
    except RuntimeError:
        pass


def number(value: Any) -> Optional[float]:
    try:
        result = float(value)
        return result if result == result else None
    except (TypeError, ValueError):
        return None


def normalize_reference_text(text: str) -> str:
    return re.sub(r"\s+", " ", str(text or "")).strip()[:16000]


def normalize_error_type(value: Any) -> str:
    if value is None:
        return "None"
    name = getattr(value, "name", None)
    text = str(name or value)
    if text.startswith("PronunciationAssessmentErrorType."):
        text = text.split(".", 1)[1]
    if text in {"", "0", "NoneType"}:
        return "None"
    return text


def parse_json_words(result: Any) -> tuple[List[Dict[str, Any]], Dict[str, Any]]:
    raw = ""
    try:
        raw = getattr(result, "json", None) or ""
    except Exception:
        raw = ""
    if not raw:
        try:
            raw = result.properties.get(speechsdk.PropertyId.SpeechServiceResponse_JsonResult)
        except Exception:
            raw = ""
    if not raw:
        return [], {}

    try:
        document = json.loads(raw) if isinstance(raw, str) else raw
    except Exception:
        return [], {}
    if not isinstance(document, dict):
        return [], {}

    nbest = document.get("NBest") or []
    top = nbest[0] if nbest else {}
    words: List[Dict[str, Any]] = []
    for item in top.get("Words") or []:
        pa = item.get("PronunciationAssessment") or {}
        offset = number(item.get("Offset"))
        duration = number(item.get("Duration"))
        offset_s = offset / 10_000_000 if offset is not None else None
        duration_s = duration / 10_000_000 if duration is not None else None
        phonemes: List[Dict[str, Any]] = []
        for ph in item.get("Phonemes") or []:
            ppa = ph.get("PronunciationAssessment") or {}
            phonemes.append({
                "phoneme": str(ph.get("Phoneme", "") or ""),
                "accuracy": number(ppa.get("AccuracyScore")),
                "candidates": [
                    {
                        "phoneme": str(candidate.get("Phoneme", "") or ""),
                        "score": number(candidate.get("Score")),
                    }
                    for candidate in (ppa.get("NBestPhonemes") or [])[:5]
                ],
            })
        words.append({
            "word": str(item.get("Word", "") or ""),
            "accuracy": number(pa.get("AccuracyScore")),
            "errorType": normalize_error_type(pa.get("ErrorType", "None")),
            "confidence": number(item.get("Confidence")),
            "offset": offset,
            "duration": duration,
            "offsetSeconds": offset_s,
            "durationSeconds": duration_s,
            "endSeconds": offset_s + duration_s if offset_s is not None and duration_s is not None else None,
            "phonemes": phonemes,
            "syllables": item.get("Syllables") or [],
        })

    phrase_pa = top.get("PronunciationAssessment") or {}
    phrase = {
        "text": top.get("Display") or document.get("DisplayText") or str(getattr(result, "text", "") or ""),
        "confidence": number(top.get("Confidence")),
        "accuracy": number(top.get("AccuracyScore", phrase_pa.get("AccuracyScore"))),
        "pronunciation": number(top.get("PronScore", phrase_pa.get("PronScore"))),
        "fluency": number(top.get("FluencyScore", phrase_pa.get("FluencyScore"))),
        "completeness": number(top.get("CompletenessScore", phrase_pa.get("CompletenessScore"))),
        "prosody": number(top.get("ProsodyScore", phrase_pa.get("ProsodyScore"))),
        "offset": number(document.get("Offset")),
        "duration": number(document.get("Duration")),
    }
    return words, phrase


def parse_sdk_words(result: Any) -> tuple[List[Dict[str, Any]], Dict[str, Any]]:
    if speechsdk is None:
        return [], {}
    try:
        assessment = speechsdk.PronunciationAssessmentResult(result)
    except Exception:
        return [], {}

    words: List[Dict[str, Any]] = []
    for item in getattr(assessment, "words", []) or []:
        phonemes = []
        for ph in getattr(item, "phonemes", []) or []:
            phonemes.append({
                "phoneme": str(getattr(ph, "phoneme", "") or ""),
                "accuracy": number(getattr(ph, "accuracy_score", None)),
                "candidates": [
                    {
                        "phoneme": str(getattr(candidate, "phoneme", "") or ""),
                        "score": number(getattr(candidate, "score", None)),
                    }
                    for candidate in (getattr(ph, "n_best_phonemes", []) or [])[:5]
                ],
            })
        words.append({
            "word": str(getattr(item, "word", "") or ""),
            "accuracy": number(getattr(item, "accuracy_score", None)),
            "errorType": normalize_error_type(getattr(item, "error_type", None)),
            "confidence": None,
            "offset": None,
            "duration": None,
            "offsetSeconds": None,
            "durationSeconds": None,
            "endSeconds": None,
            "phonemes": phonemes,
            "syllables": [],
        })

    phrase = {
        "text": str(getattr(result, "text", "") or ""),
        "confidence": None,
        "accuracy": number(getattr(assessment, "accuracy_score", None)),
        "pronunciation": number(getattr(assessment, "pronunciation_score", None)),
        "fluency": number(getattr(assessment, "fluency_score", None)),
        "completeness": number(getattr(assessment, "completeness_score", None)),
        "prosody": number(getattr(assessment, "prosody_score", None)),
        "offset": number(getattr(result, "offset", None)),
        "duration": number(getattr(result, "duration", None)),
    }
    return words, phrase


def merge_words(primary: List[Dict[str, Any]], fallback: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if not primary:
        return list(fallback or [])
    if not fallback:
        return list(primary)
    merged = [dict(item) for item in primary]
    for idx, fallback_word in enumerate(fallback):
        if idx >= len(merged):
            merged.append(dict(fallback_word))
            continue
        current = merged[idx]
        for key in ("word", "accuracy", "errorType", "confidence", "offset", "duration", "offsetSeconds", "durationSeconds", "endSeconds", "phonemes", "syllables"):
            if current.get(key) in (None, "", []) and fallback_word.get(key) not in (None, "", []):
                current[key] = fallback_word[key]
    return merged


def filter_structural_miscues(words: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    # Azure owns pronunciation evidence. The browser's assessment-core owns
    # structural miscues, so do not let Azure Omission/Insertion overwrite it.
    return [
        word for word in words
        if str(word.get("errorType") or "None").lower() not in {"omission", "insertion"}
    ]


class AzureSession:
    def __init__(self, loop: asyncio.AbstractEventLoop, ws_queue: asyncio.Queue, locale: str, reference_text: str):
        self.loop = loop
        self.ws_queue = ws_queue
        self.locale = locale or AZURE_LANG
        self.reference_text = normalize_reference_text(reference_text)
        self.session_id = str(uuid.uuid4())

        self.recognizer: Any = None
        self.push_stream: Any = None
        self.audio_config: Any = None
        self.speech_config: Any = None
        self.connection: Any = None

        self.audio_queue: "queue.Queue[Optional[bytes]]" = queue.Queue(maxsize=2000)
        self.audio_writer_thread: Optional[threading.Thread] = None
        self.session_started_event = threading.Event()
        self.session_stopped_event = threading.Event()
        self.stop_requested = False
        self.final_emitted = False
        self.started = False
        self.writer_failed = False
        self.last_error: Optional[Dict[str, Any]] = None

        self.audio_bytes_received = 0
        self.recognizing_results = 0
        self.recognized_results = 0
        self.no_match_results = 0
        self.canceled_results = 0
        self.service_connected = False
        self.last_recognized_text = ""
        self.words: List[Dict[str, Any]] = []
        self.phrases: List[Dict[str, Any]] = []
        self.raw: List[Dict[str, Any]] = []
        self.json_word_evidence = 0
        self.sdk_word_evidence = 0
        self.result_json_bytes = 0
        self.lock = threading.Lock()

    def diagnostics(self) -> Dict[str, Any]:
        return {
            "recognizedResults": self.recognized_results,
            "wordEvidence": len(self.words),
            "recognizingResults": self.recognizing_results,
            "noMatchResults": self.no_match_results,
            "canceledResults": self.canceled_results,
            "audioBytesReceived": self.audio_bytes_received,
            "sessionStarted": self.session_started_event.is_set(),
            "sessionStopped": self.session_stopped_event.is_set(),
            "serviceConnected": self.service_connected,
            "lastRecognizedText": self.last_recognized_text,
            "sdkVersion": getattr(speechsdk, "__version__", None) if speechsdk is not None else None,
            "jsonWordEvidence": self.json_word_evidence,
            "sdkWordEvidence": self.sdk_word_evidence,
            "resultJsonBytes": self.result_json_bytes,
            "audioWriterAlive": bool(self.audio_writer_thread and self.audio_writer_thread.is_alive()),
            "writerFailed": self.writer_failed,
        }

    def _queue_emit(self, payload: Dict[str, Any]) -> None:
        emit_json(self.ws_queue, payload, self.loop)

    def start(self) -> None:
        if speechsdk is None:
            raise RuntimeError(f"Azure Speech SDK import failed: {AZURE_IMPORT_ERROR or 'not installed'}")
        if not AZURE_KEY or not AZURE_REGION:
            raise RuntimeError("AZURE_SPEECH_KEY and AZURE_SPEECH_REGION are required.")
        if not self.reference_text:
            raise RuntimeError("The hidden passage reference text is required for Azure pronunciation assessment.")

        self.speech_config = speechsdk.SpeechConfig(subscription=AZURE_KEY, region=AZURE_REGION)
        self.speech_config.speech_recognition_language = self.locale
        try:
            self.speech_config.output_format = speechsdk.OutputFormat.Detailed
        except Exception:
            pass
        try:
            self.speech_config.request_word_level_timestamps()
        except Exception:
            pass
        try:
            self.speech_config.set_property(speechsdk.PropertyId.Speech_SegmentationSilenceTimeoutMs, "1500")
        except Exception:
            pass
        try:
            self.speech_config.set_property(speechsdk.PropertyId.SpeechServiceConnection_EndSilenceTimeoutMs, "3000")
        except Exception:
            pass

        # Continuous pronunciation assessment: hidden ReferenceText + phoneme
        # granularity. EnableMiscue stays false because Azure does not support
        # that feature in continuous mode; JS handles structural miscues.
        pron_config = speechsdk.PronunciationAssessmentConfig(
            reference_text=self.reference_text,
            grading_system=speechsdk.PronunciationAssessmentGradingSystem.HundredMark,
            granularity=speechsdk.PronunciationAssessmentGranularity.Phoneme,
            enable_miscue=False,
        )
        try:
            pron_config.enable_prosody_assessment()
        except Exception:
            pass
        try:
            pron_config.phoneme_alphabet = "IPA"
        except Exception:
            pass
        try:
            pron_config.nbest_phoneme_count = 5
        except Exception:
            pass

        stream_format = speechsdk.audio.AudioStreamFormat(
            samples_per_second=16000,
            bits_per_sample=16,
            channels=1,
        )
        self.push_stream = speechsdk.audio.PushAudioInputStream(stream_format=stream_format)
        self.audio_config = speechsdk.audio.AudioConfig(stream=self.push_stream)
        self.recognizer = speechsdk.SpeechRecognizer(
            speech_config=self.speech_config,
            audio_config=self.audio_config,
        )
        pron_config.apply_to(self.recognizer)

        # Bias Azure recognition toward vocabulary actually present in the hidden
        # passage. This is recognition assistance only; structural miscues remain
        # outside Azure and the reference text is never replaced with recognized text.
        try:
            phrase_grammar = speechsdk.PhraseListGrammar.from_recognizer(self.recognizer)
            seen = set()
            for token in re.findall(r"[A-Za-z][A-Za-z'’-]*", self.reference_text):
                key = token.lower()
                if len(key) >= 2 and key not in seen:
                    seen.add(key)
                    phrase_grammar.addPhrase(token)
        except Exception as exc:
            logger.info("[Azure] PhraseListGrammar unavailable session=%s error=%s", self.session_id, exc)

        # Optional connection diagnostics. This does not replace continuous
        # recognition; it only gives us an observable service connection state.
        try:
            self.connection = speechsdk.Connection.from_recognizer(self.recognizer)
            self.connection.connected.connect(self._on_connected)
            self.connection.disconnected.connect(self._on_disconnected)
            try:
                self.connection.open(True)
            except Exception as exc:
                logger.warning("[Azure] connection pre-open note session=%s error=%s", self.session_id, exc)
        except Exception as exc:
            logger.info("[Azure] connection diagnostics unavailable session=%s error=%s", self.session_id, exc)

        self.recognizer.session_started.connect(self._on_session_started)
        self.recognizer.recognizing.connect(self._on_recognizing)
        self.recognizer.recognized.connect(self._on_recognized)
        self.recognizer.session_stopped.connect(self._on_session_stopped)
        self.recognizer.canceled.connect(self._on_canceled)

        # Start the writer before recognition. It blocks on the queue until the
        # browser begins sending audio. This mirrors Microsoft's push-stream model
        # while keeping network I/O out of the Azure callback threads.
        self.audio_writer_thread = threading.Thread(
            target=self._audio_writer,
            name=f"azure-audio-writer-{self.session_id[:8]}",
            daemon=True,
        )
        self.audio_writer_thread.start()

        logger.info(
            "[Azure] starting continuous PushAudioInputStream session=%s locale=%s sdk=%s",
            self.session_id,
            self.locale,
            getattr(speechsdk, "__version__", "unknown"),
        )
        self.recognizer.start_continuous_recognition()
        self.started = True

        # Do not tell the browser that Azure is ready until Azure itself reports
        # session_started. This removes the old ready-before-session race.
        session_wait = float(os.getenv("AZURE_SESSION_START_TIMEOUT_SEC", "10"))
        if not self.session_started_event.wait(timeout=session_wait):
            try:
                self.recognizer.stop_continuous_recognition()
            except Exception:
                pass
            raise RuntimeError(
                "Azure Speech did not emit session_started within the startup window. "
                f"diagnostics={json.dumps(self.diagnostics(), separators=(',', ':'))}"
            )

        logger.info("[Azure] session_started confirmed session=%s", self.session_id)
        self._queue_emit({
            "type": "azure.ready",
            "mode": "python-fastapi-sdk-continuous-push-stream-scripted",
            "sampleRate": 16000,
            "bitsPerSample": 16,
            "channels": 1,
            "referenceText": self.reference_text[:50] + ("..." if len(self.reference_text) > 50 else ""),
            "enableMiscue": False,
            "sdkVersion": getattr(speechsdk, "__version__", None),
        })

    def _audio_writer(self) -> None:
        try:
            while True:
                chunk = self.audio_queue.get()
                if chunk is None:
                    break
                if not chunk:
                    continue
                self.push_stream.write(chunk)
        except Exception as exc:
            self.writer_failed = True
            self.last_error = {
                "message": f"Azure audio writer failed: {exc}",
                "status": 502,
                "provider": "azure",
            }
            logger.exception("[Azure] audio writer failed session=%s", self.session_id)
            self.session_stopped_event.set()
        finally:
            try:
                self.push_stream.close()
            except Exception:
                pass

    def push(self, audio_bytes: bytes) -> None:
        if not audio_bytes or self.stop_requested or not self.started or self.writer_failed:
            return
        try:
            self.audio_queue.put_nowait(bytes(audio_bytes))
            with self.lock:
                self.audio_bytes_received += len(audio_bytes)
        except queue.Full:
            self.last_error = {
                "message": "Azure audio input queue is full; incoming audio was dropped.",
                "status": 429,
                "provider": "azure",
            }
            logger.warning("[Azure] audio queue full session=%s", self.session_id)

    def stop(self) -> None:
        if self.stop_requested:
            return
        self.stop_requested = True
        try:
            self.audio_queue.put_nowait(None)
        except queue.Full:
            # Make room only during shutdown; the WebSocket has already stopped
            # accepting additional audio at this point.
            try:
                self.audio_queue.get_nowait()
            except queue.Empty:
                pass
            try:
                self.audio_queue.put_nowait(None)
            except queue.Full:
                pass

        join_timeout = float(os.getenv("AZURE_AUDIO_WRITER_DRAIN_TIMEOUT_SEC", "5"))
        if self.audio_writer_thread and self.audio_writer_thread.is_alive():
            self.audio_writer_thread.join(timeout=join_timeout)

        # Closing PushAudioInputStream tells Azure there is no more audio. Wait
        # for Azure to finish its buffered recognition before calling stop(), as
        # in the documented push-stream continuous flow.
        finish_timeout = float(os.getenv("AZURE_SPEECH_FINALIZATION_TIMEOUT_SEC", "60"))
        if not self.session_stopped_event.wait(timeout=finish_timeout):
            logger.warning(
                "[Azure] session_stopped not received within %.1fs; forcing recognizer stop session=%s",
                finish_timeout,
                self.session_id,
            )
            try:
                self.recognizer.stop_continuous_recognition()
            except Exception as exc:
                logger.warning("[Azure] forced stop note session=%s error=%s", self.session_id, exc)
            self.session_stopped_event.wait(timeout=10)

        if not self.final_emitted:
            self._emit_final("stop-safety")

    def _on_connected(self, _evt: Any) -> None:
        self.service_connected = True
        logger.info("[Azure] service connected session=%s", self.session_id)

    def _on_disconnected(self, _evt: Any) -> None:
        self.service_connected = False
        logger.info("[Azure] service disconnected session=%s", self.session_id)

    def _on_session_started(self, evt: Any) -> None:
        self.session_started_event.set()
        logger.info("[Azure] session started id=%s", getattr(evt, "session_id", ""))

    def _on_recognizing(self, evt: Any) -> None:
        self.recognizing_results += 1
        text = str(getattr(evt.result, "text", "") or "").strip()
        if text:
            self._queue_emit({"type": "azure.partial", "text": text})

    def _on_recognized(self, evt: Any) -> None:
        result = getattr(evt, "result", None)
        if result is None:
            return
        reason = getattr(result, "reason", None)
        if speechsdk is None or reason != speechsdk.ResultReason.RecognizedSpeech:
            if speechsdk is not None and reason == getattr(speechsdk.ResultReason, "NoMatch", None):
                self.no_match_results += 1
            return

        self.recognized_results += 1
        text = str(getattr(result, "text", "") or "").strip()
        self.last_recognized_text = text

        try:
            result_json = getattr(result, "json", None) or ""
            self.result_json_bytes = len(result_json.encode("utf-8")) if isinstance(result_json, str) else 0
        except Exception:
            self.result_json_bytes = 0
        json_words, json_phrase = parse_json_words(result)
        sdk_words, sdk_phrase = parse_sdk_words(result)
        self.json_word_evidence += len(json_words)
        self.sdk_word_evidence += len(sdk_words)
        words = filter_structural_miscues(merge_words(json_words, sdk_words))
        phrase = json_phrase if (json_phrase.get("text") or json_words) else sdk_phrase

        self.words.extend(words)
        if phrase and any(phrase.get(key) not in (None, "") for key in ("accuracy", "pronunciation", "fluency", "completeness", "prosody")):
            self.phrases.append(phrase)
        self.raw.append({
            "text": text,
            "offset": number(getattr(result, "offset", None)),
            "duration": number(getattr(result, "duration", None)),
        })

        logger.info(
            "[Azure] recognized session=%s text=%r jsonWords=%d sdkWords=%d emittedWords=%d",
            self.session_id,
            text,
            len(json_words),
            len(sdk_words),
            len(words),
        )

        self._queue_emit({
            "type": "azure.result",
            "sequence": self.recognized_results,
            "text": text,
            "offset": number(getattr(result, "offset", None)),
            "duration": number(getattr(result, "duration", None)),
            "words": words,
            "phrases": [phrase] if phrase else [],
            "raw": [self.raw[-1]],
            "source": "json+sdk" if json_words else ("sdk" if sdk_words else "none"),
        })

    def _on_session_stopped(self, _evt: Any) -> None:
        self.session_stopped_event.set()
        logger.info("[Azure] session stopped session=%s", self.session_id)
        self._emit_final("session-stopped")

    def _on_canceled(self, evt: Any) -> None:
        self.canceled_results += 1
        details_obj = getattr(evt, "cancellation_details", None)
        if details_obj:
            reason_enum = getattr(details_obj, "reason", None)
            error_code = str(getattr(details_obj, "error_code", "") or "")
            details = str(getattr(details_obj, "error_details", "") or "")
            reason = str(reason_enum or "")
            if reason_enum == speechsdk.CancellationReason.EndOfStream:
                logger.info("[Azure] session canceled normally (EndOfStream) session=%s", self.session_id)
                self.session_stopped_event.set()
                self._emit_final("canceled")
                return
        else:
            reason = str(getattr(evt, "reason", "") or "")
            error_code = str(getattr(evt, "error_code", "") or "")
            details = str(getattr(evt, "error_details", "") or "")

        message = details or f"Azure recognition canceled: {reason}"
        self.last_error = {
            "message": message,
            "status": 502,
            "provider": "azure",
            "details": {
                "reason": reason,
                "error_code": error_code,
                "error_details": details,
            },
        }
        logger.warning("[Azure] canceled session=%s reason=%s code=%s details=%s", self.session_id, reason, error_code, details)
        self.session_stopped_event.set()
        self._queue_emit({"type": "azure.error", "error": self.last_error})
        self._emit_final("canceled")

    def _emit_final(self, reason: str) -> None:
        if self.final_emitted:
            return
        self.final_emitted = True
        diagnostics = self.diagnostics()
        logger.info(
            "[Azure] final session=%s reason=%s recognizedResults=%d words=%d sessionStarted=%s",
            self.session_id,
            reason,
            self.recognized_results,
            len(self.words),
            diagnostics["sessionStarted"],
        )
        self._queue_emit({
            "type": "azure.final",
            "result": {
                "mode": "python-fastapi-sdk-continuous-push-stream-scripted",
                "words": self.words,
                "phrases": self.phrases,
                "raw": self.raw,
                "diagnostics": diagnostics,
                "error": self.last_error,
            },
        })
        self._queue_emit({"type": "azure.done"})

    def close(self) -> None:
        try:
            if self.recognizer is not None:
                self.recognizer.stop_continuous_recognition()
        except Exception:
            pass
        try:
            if self.connection is not None:
                self.connection.close()
        except Exception:
            pass
        for obj in (self.recognizer, self.audio_config, self.speech_config):
            try:
                if obj is not None and hasattr(obj, "close"):
                    obj.close()
            except Exception:
                pass


@app.get("/health")
async def health() -> JSONResponse:
    return JSONResponse({
        "status": "ok" if speechsdk is not None else "degraded",
        "service": "readingassessment-azure",
        "version": "5.36.0",
        "build": SERVICE_BUILD,
        "azure_sdk": speechsdk is not None,
        "azure_sdk_version": getattr(speechsdk, "__version__", None) if speechsdk is not None else None,
        "azure_key_set": bool(AZURE_KEY),
        "azure_region": AZURE_REGION,
        "protocol": "websocket-pushstream-continuous",
        "pronunciation_mode": "scripted-hidden-reference-enableMiscueFalse",
    })


@app.websocket("/ws/stream")
async def websocket_stream(websocket: WebSocket) -> None:
    await websocket.accept()
    loop = asyncio.get_running_loop()
    queue_out: asyncio.Queue = asyncio.Queue()
    session: Optional[AzureSession] = None

    async def pump() -> None:
        while True:
            message = await queue_out.get()
            try:
                await websocket.send_json(message)
            except Exception:
                return
            if message.get("type") == "azure.done":
                return

    pump_task = asyncio.create_task(pump())

    try:
        while True:
            message = await websocket.receive()
            if message.get("type") == "websocket.disconnect":
                break

            if message.get("text"):
                try:
                    command = json.loads(message["text"])
                except json.JSONDecodeError:
                    continue
                kind = command.get("type")

                if kind == "start":
                    if session is not None:
                        await websocket.send_json({"type": "azure.error", "error": {"message": "Azure session already started.", "status": 409, "provider": "azure"}})
                        continue

                    locale = str(command.get("locale") or AZURE_LANG).strip() or AZURE_LANG
                    reference_text = command.get("referenceText") or command.get("reference_text") or command.get("passage") or ""
                    candidate = AzureSession(loop, queue_out, locale, str(reference_text))
                    try:
                        await asyncio.to_thread(candidate.start)
                        session = candidate
                        logger.info("[Azure] WebSocket session ready id=%s", candidate.session_id)
                    except Exception as exc:
                        candidate.close()
                        await websocket.send_json({"type": "azure.error", "error": {"message": str(exc), "status": 502, "provider": "azure"}})
                    continue

                if kind == "stop":
                    if session is not None:
                        await asyncio.to_thread(session.stop)
                        # The final snapshot and azure.done are produced by the
                        # SDK callback/safety path. Do not close the WS early.
                        try:
                            await asyncio.wait_for(pump_task, timeout=12.0)
                        except asyncio.TimeoutError:
                            logger.warning("[Azure] output pump did not finish after stop session=%s", session.session_id)
                    break

            if message.get("bytes") and session is not None:
                session.push(bytes(message["bytes"]))

    except WebSocketDisconnect:
        pass
    except Exception as exc:
        logger.exception("[Azure] WebSocket failure: %s", exc)
        try:
            await websocket.send_json({"type": "azure.error", "error": {"message": str(exc), "status": 502, "provider": "azure"}})
        except Exception:
            pass
    finally:
        if session is not None and not session.final_emitted:
            try:
                await asyncio.to_thread(session.stop)
            except Exception:
                pass
        if session is not None:
            session.close()
        if not pump_task.done():
            pump_task.cancel()
            try:
                await pump_task
            except asyncio.CancelledError:
                pass


if __name__ == "__main__":
    import uvicorn
    logger.info(
        "[Azure Service] starting at http://%s:%s SDK=%s region=%s",
        APP_HOST,
        APP_PORT,
        getattr(speechsdk, "__version__", None) if speechsdk is not None else "unavailable",
        AZURE_REGION,
    )
    uvicorn.run(app, host=APP_HOST, port=APP_PORT)
