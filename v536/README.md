# Azure Pronunciation Reader V5.36

V5.36 uses two independent live streams from the same microphone session:

- **OpenAI Realtime (`gpt-live-transcribe`)** is the visible literal transcript. It is instructed to report only audible speech and preserve mistakes, repetitions, restarts, substitutions, reversals, transpositions, and self-corrections.
- **Azure Speech Pronunciation Assessment** receives raw PCM16/16 kHz/mono audio continuously through a Python Speech SDK service. Azure is hidden from the learner and supplies pronunciation, phoneme, timing, and phrase evidence.

The normal assessment path is **streaming WebSocket audio**. It does not record a WAV and upload it to Azure after the student stops.

## V5.36 architecture

```text
Microphone
   │
   ├──────────────► OpenAI Realtime / WebRTC
   │                         │
   │                         └──► literal transcript
   │
   └──────────────► WebSocket /azure-stream
                             │
                             ▼
                       Node proxy
                             │
                             ▼
                    Python FastAPI service
                    (Python venv + Azure SDK)
                             │
                             ▼
                    PushAudioInputStream
                             │
                             ▼
                  Azure continuous recognition
                             │
                             └──► pronunciation evidence

Reference passage + OpenAI transcript + Azure evidence
                          │
                          ▼
                 assessment-core.js
                          │
                          ├── Omission
                          ├── Substitution
                          ├── Repetition
                          ├── Transposition
                          ├── Reversal
                          ├── Self-correction
                          └── Azure Mispronunciation overlay
```

## Authority split

**OpenAI is the transcript authority.** The transcript shown to the learner is the literal OpenAI transcript, not Azure's recognized text.

**`assessment-core.js` is the structural-miscue authority.** It compares the clean reference passage with the literal spoken sequence and determines omission, substitution, repetition, transposition, reversal, and self-correction.

**Azure is the pronunciation/acoustic authority.** Its word-level `ErrorType`, AccuracyScore, phoneme candidates, and timing evidence are attached to the spoken sequence. Azure is allowed to turn a structurally matching word into a visible `Mispronunciation`. Azure is not allowed to rewrite a substitution, omission, repetition, transposition, reversal, or self-correction into a pronunciation-only result.

This preserves the important distinction between:

```text
Reference: fallen
Student:   pollen
```

When the literal transcript says `pollen`, the structural engine reports **Substitution**. Azure may still provide pronunciation evidence for `pollen`, but that evidence does not promote the structural substitution into `Mispronunciation`.

For a lexical match such as:

```text
Reference: fallen
Student:   fallen
```

Azure can supply a `Mispronunciation` result based on its pronunciation assessment, and that match can be rendered as `Mispronunciation`.

## Azure continuous configuration

The Python service uses the current Azure continuous pronunciation pattern:

- `SpeechConfig(subscription=..., region=...)`
- `PronunciationAssessmentConfig(reference_text=<hidden passage>, granularity=Phoneme, enable_miscue=False)`
- `PushAudioInputStream` with 16 kHz, 16-bit, mono PCM
- `SpeechRecognizer`
- `start_continuous_recognition()`
- `recognizing`, `recognized`, `session_started`, `session_stopped`, and `canceled` callbacks
- detailed JSON / word-level timestamps where supported
- `PronunciationAssessmentResult(result)` as an SDK fallback
- `result.json` / `SpeechServiceResponse_JsonResult` as detailed word-level evidence

Microsoft's current documentation states that continuous pronunciation assessment does not support `EnableMiscue`; omission/insertion detection for continuous streams is therefore handled by comparing the recognized sequence with the reference text. citeturn299159search1turn299159search3

V5.36 intentionally keeps that structural comparison in the existing browser alignment engine instead of creating a second Python structural classifier that could fight the UI's existing miscue logic.

## Why `difflib` is not the Azure authority

The project already has a weighted sequence-alignment engine in `assessment-core.js`. That layer is the single structural comparison authority.

A Python `difflib.SequenceMatcher` pass is not added to the Azure service as a second source of truth. Doing so would create two structural classifiers that could disagree about the same transcript. Azure therefore remains responsible for pronunciation evidence, while the existing JS alignment remains responsible for structural miscues.

## Azure startup and finalization

V5.36 fixes the previous false-ready problem.

The Python service:

1. creates the Azure recognizer and PushAudioInputStream;
2. connects the SDK callbacks;
3. starts continuous recognition;
4. waits for the actual Azure `session_started` callback;
5. only then sends `azure.ready` to the browser;
6. continuously accepts PCM audio while the student reads;
7. on Stop, drains the queued audio and closes the PushAudioInputStream;
8. waits for Azure `session_stopped` / final recognition callbacks;
9. emits `azure.final` and then `azure.done`.

The browser does not reveal assessment markup until `azure.done` is received.

If Azure is canceled or fails, the service emits an explicit error and final lifecycle event rather than leaving the browser waiting forever.

## Audio transport

The browser's AudioWorklet produces raw 16 kHz PCM16 mono chunks. Node proxies those binary chunks over WebSocket to the Python service.

There is no WAV conversion in the normal Azure assessment path. `ffmpeg`, `espeak-ng`, and `libespeak-ng1` may exist in the host environment for other application features, but they are not used to manufacture Azure pronunciation scores.

## Python environment

The Azure Python service dependencies are defined in `requirements-azure.txt`. The Speech SDK is pinned; FastAPI and Uvicorn are required because V5.36 runs Azure pronunciation as a local WebSocket service:

```text
azure-cognitiveservices-speech==1.51.2
fastapi>=0.115,<1
uvicorn[standard]>=0.30,<1
```

Runtime selection order in `server.js` is:

1. `AZURE_PYTHON_BIN`
2. `./.azure-venv/bin/python`
3. `./venv_asr/bin/python`
4. `/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/bin/python`
5. `$HOME/venv_asr/bin/python3`

Run setup with:

```bash
./setup-azure.sh
```

or install directly into the venv you intend to run:

```bash
source /var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/bin/activate
pip install -r requirements-azure.txt
```

Set the normal credentials in `.env`:

```text
OPENAI_API_KEY=...
AZURE_SPEECH_KEY=...
AZURE_SPEECH_REGION=...
```

Optional service settings:

```text
AZURE_PYTHON_BIN=/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/bin/python
AZURE_SERVICE_HOST=127.0.0.1
AZURE_SERVICE_PORT=8011
AZURE_SESSION_START_TIMEOUT_SEC=10
AZURE_SPEECH_FINALIZATION_TIMEOUT_SEC=60
AZURE_FINALIZATION_TIMEOUT_MS=60000
```

## Tests

The test suite checks:

- Python venv / Azure SDK architecture
- continuous PushAudioInputStream usage
- real `session_started` readiness gating
- dedicated audio writer thread
- no WAV upload path
- finalization lifecycle
- separation of OpenAI transcript from Azure pronunciation evidence
- Azure Mispronunciation overlay without overriding structural substitution/omission/repetition/self-correction decisions

Run:

```bash
npm test
```
