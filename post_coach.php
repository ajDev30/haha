<?php
/**
 * post_coach.php — Secure server-side endpoint AND Isolated Pronunciation Coach UI.
 *
 * Handles three API actions:
 *   action=tts       -> Proxy OpenAI TTS, return audio/mpeg stream
 *   action=breakdown -> Call OpenAI to get syllables and phonemes
 *   action=save_coach-> Save a coach practice attempt to the DB
 *
 * If no action is provided, it serves the Isolated Pronunciation Coach UI.
 */

require_once(__DIR__ . '/../../config.php');

try { require_sesskey(); } catch (Exception $e) {}

if (!isloggedin() || isguestuser()) {
    require_login();
}

$raw_input = file_get_contents('php://input');
$json_payload = [];
if (!empty($raw_input)) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $json_payload = $decoded;
        // Merge into $_REQUEST so optional_param works seamlessly for subsequent parameters
        $_REQUEST = array_merge($_REQUEST, $json_payload);
        $_POST = array_merge($_POST, $json_payload);
    }
}

$action = optional_param('action', '', PARAM_ALPHA);
if (empty($action) && isset($json_payload['action'])) {
    $action = clean_param($json_payload['action'], PARAM_ALPHA);
}

$cmid = optional_param('cmid', 0, PARAM_INT);
if (empty($cmid) && isset($json_payload['cmid'])) {
    $cmid = clean_param($json_payload['cmid'], PARAM_INT);
}

$req_sesskey = optional_param('sesskey', '', PARAM_RAW);
if (empty($req_sesskey) && isset($json_payload['sesskey'])) {
    $req_sesskey = $json_payload['sesskey'];
}

// Retrieve config from DB if cmid is provided
$cfg = null;
if ($cmid) {
    $cfg = $DB->get_record('readingassessment_post_cfg', ['cmid' => $cmid]);
}

function pt_get_openai_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $env_path = __DIR__ . '/v536/.env';
    if (!file_exists($env_path)) return '';
    foreach (file($env_path) as $line) {
        if (preg_match('/^AZURE_OPENAI_API_KEY\s*=\s*(.+)$/i', trim($line), $m)) {
            $key = trim($m[1]);
            return $key;
        }
        if (preg_match('/^OPENAI_API_KEY\s*=\s*(.+)$/i', trim($line), $m)) {
            $key = trim($m[1]);
            return $key;
        }
    }
    return '';
}

if ($action !== '') {
    if (!defined('AJAX_SCRIPT')) {
        define('AJAX_SCRIPT', true);
    }
    if (empty($req_sesskey) || $req_sesskey !== sesskey()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid session.']);
        exit;
    }
}

// -------------------------------------------------------------------------
// ACTION: tts
// -------------------------------------------------------------------------
if ($action === 'tts') {
    $text        = optional_param('text', '', PARAM_TEXT);
    
    // Default to DB config if available, otherwise fallback to request params
    $voice       = $cfg ? $cfg->tts_voice : optional_param('voice', 'alloy', PARAM_ALPHA);
    $personality = $cfg ? $cfg->tts_personality : optional_param('personality', '', PARAM_RAW);

    if (empty($text)) {
        http_response_code(400);
        echo json_encode(['error' => 'No text provided.']);
        exit;
    }

    $valid_voices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    if (!in_array($voice, $valid_voices)) $voice = 'alloy';

    $openai_key = pt_get_openai_key();
    if (empty($openai_key)) {
        http_response_code(500);
        echo json_encode(['error' => 'OpenAI API key not configured.']);
        exit;
    }

    $payload = json_encode([
        'model'  => 'tts-1',
        'voice'  => $voice,
        'input'  => $text,
        'instructions' => !empty($personality) ? substr($personality, 0, 500) : 'Speak clearly and warmly.',
    ]);

    // File-based cache for TTS
    $cache_dir = $CFG->dataroot . '/temp/tts_audio';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0777, true);
    }
    $cache_file = $cache_dir . '/pt_tts_' . md5($text . $voice) . '.mp3';

    if (file_exists($cache_file)) {
        header('Content-Type: audio/mpeg');
        header('X-TTS-Cache: hit');
        readfile($cache_file);
        exit;
    }

    $openai_key = pt_get_openai_key();
    if (empty($openai_key)) {
        http_response_code(502);
        echo json_encode(['error' => 'OpenAI credentials not found.']);
        exit;
    }

    $payload = json_encode([
        'model' => 'tts-1-hd',
        'input' => $text,
        'voice' => $voice,
        'response_format' => 'mp3',
        'speed' => 1.0,
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $openai_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || empty($response)) {
        http_response_code(502);
        echo json_encode(['error' => 'OpenAI TTS failed. HTTP ' . $http_code . '. Check your OpenAI API key and permissions.']);
        exit;
    }

    // Cache it (max 500KB)
    if (strlen($response) < 512000) {
        file_put_contents($cache_file, $response);
    }

    header('Content-Type: audio/mpeg');
    header('X-TTS-Cache: miss');
    echo $response;
    exit;
}

// -------------------------------------------------------------------------
// ACTION: breakdown
// -------------------------------------------------------------------------
if ($action === 'breakdown') {
    $word = optional_param('word', '', PARAM_TEXT);
    if (empty($word)) {
        http_response_code(400);
        echo json_encode(['error' => 'No word provided.']);
        exit;
    }

    $openai_key = pt_get_openai_key();
    if (empty($openai_key)) {
        echo json_encode([
            'word' => $word,
            'respelling' => $word,
            'ipa' => "/$word/",
            'syllables' => [$word],
            'blending_steps' => [
                ['label' => 'Whole Word', 'formula' => "[ $word ]", 'spoken_target' => $word]
            ]
        ]);
        exit;
    }

    $prompt = "You are a reading coach. Provide a progressive phonetic blending breakdown for the word '$word'. You must build the word up sound by sound (additive blending), just like teaching phonics. Return strictly JSON format like this:
    {
      \"word\": \"fish\",
      \"respelling\": \"f-i-sh\",
      \"ipa\": \"/fɪʃ/\",
      \"syllables\": [\"fish\"],
      \"blending_steps\": [
        { \"label\": \"Sound 1\", \"formula\": \"f = /fff/\", \"spoken_target\": \"fff\" },
        { \"label\": \"Blend 1\", \"formula\": \"f + i = /fih/\", \"spoken_target\": \"fih\" },
        { \"label\": \"Blend 2\", \"formula\": \"f + i + sh = /fish/\", \"spoken_target\": \"fish\" },
        { \"label\": \"Whole Word\", \"formula\": \"[ fish ]\", \"spoken_target\": \"fish\" }
      ]
    }";

    $payload = json_encode([
        'model' => 'gpt-3.5-turbo',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object']
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $openai_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            echo $data['choices'][0]['message']['content'];
            exit;
        }
    }
    
    // FALLBACK IF OPENAI FAILS OR KEY RESTRICTED (e.g. 403)
    // Generate a simple progressive breakdown locally
    $letters = str_split(strtolower($word));
    $steps = [];
    $accum = '';
    foreach ($letters as $idx => $l) {
        $accum .= $l;
        if ($idx === 0) {
            $steps[] = ['label' => "Sound " . ($idx+1), 'formula' => "$l = /$l$l$l/", 'spoken_target' => $l];
        } else if ($idx < count($letters) - 1) {
            $prev = substr($accum, 0, -1);
            $steps[] = ['label' => "Blend " . $idx, 'formula' => "$prev + $l = /$accum/", 'spoken_target' => $accum];
        }
    }
    $steps[] = ['label' => 'Whole Word', 'formula' => "[ $word ]", 'spoken_target' => $word];

    echo json_encode([
        'word' => $word,
        'respelling' => implode('-', $letters),
        'ipa' => "/$word/",
        'syllables' => [$word],
        'blending_steps' => $steps
    ]);
    exit;
}

// -------------------------------------------------------------------------
// ACTION: save_coach
// -------------------------------------------------------------------------
if ($action === 'save_coach') {
    $attid       = required_param('attid', PARAM_INT);
    $word        = optional_param('word', '', PARAM_TEXT);
    $stage       = optional_param('stage', '', PARAM_TEXT);
    $score       = optional_param('score', 0.0, PARAM_FLOAT);
    
    $attempt = $DB->get_record('readingassessment_post_att', ['id' => $attid, 'userid' => $USER->id]);
    if ($attempt) {
        $record = new stdClass();
        $record->attid        = $attid;
        $record->target_word  = clean_param($word, PARAM_TEXT);
        $record->phonemes_json= optional_param('phonemes_json', '[]', PARAM_RAW);
        $record->stage        = clean_param($stage, PARAM_TEXT);
        $record->azure_json   = optional_param('azure_json', '{}', PARAM_RAW);
        $record->score        = round((float)$score, 2);
        $record->mastered     = optional_param('mastered', 0, PARAM_INT);
        $record->timecreated  = time();
        $DB->insert_record('readingassessment_post_coach', $record);
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
// Isolated Pronunciation Coach UI (HTML)
// -------------------------------------------------------------------------
$PAGE->set_url('/mod/readingassessment/post_coach.php');
if ($cmid) {
    $cm = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
    $PAGE->set_cm($cm);
    $PAGE->set_context(context_module::instance($cmid));
} else {
    $PAGE->set_context(context_system::instance());
}
$PAGE->set_title('Pronunciation Coach');
$PAGE->set_heading('Isolated Pronunciation Coach');

// Hide Moodle header/footer if opened in an iframe modal
$hideheader = optional_param('hideheader', 0, PARAM_INT);
if ($hideheader) {
    $PAGE->set_pagelayout('embedded');
}

echo $OUTPUT->header();

$target_word = optional_param('word', '', PARAM_TEXT);
$words_param = optional_param('words', '', PARAM_TEXT);
if ($words_param && !$target_word) {
    $words = explode(',', $words_param);
    $target_word = trim($words[0]);
}

?>
<style>
.coach-container {
    max-width: 600px; margin: <?php echo $hideheader ? '0' : '40px'; ?> auto; padding: 25px;
    background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    font-family: system-ui, -apple-system, sans-serif;
}
.ra-isolation-header { font-weight: 700; color: #4f46e5; margin-bottom: 15px; font-size: 1.1rem; display:flex; justify-content: space-between; align-items:center;}
.ra-target-word-display { font-size: 3rem; font-weight: 900; text-align: center; color: #111827; margin: 20px 0; }
.ra-phonetic-badge-container { display: flex; justify-content: center; gap: 15px; margin-bottom: 25px; }
.ra-phonetic-respelling { background: #f3f4f6; padding: 5px 12px; border-radius: 6px; font-size: 0.95rem; font-weight: 600; }
.ra-ipa-guide { color: #6b7280; font-family: monospace; font-size: 1.1rem; padding: 5px 0; }
.ra-blending-step-row { 
    display: flex; align-items: center; justify-content: space-between; 
    padding: 12px 15px; background: #f8fafc; border: 1px solid #e2e8f0; 
    border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s;
}
.ra-blending-step-row:hover { background: #f1f5f9; transform: translateY(-1px); }
.ra-step-badge { font-size: 0.85rem; font-weight: 700; color: #0ea5e9; text-transform: uppercase; }
.ra-step-formula { font-size: 1.1rem; font-weight: 800; letter-spacing: 0.05em; color: #334155; }
.ra-step-sound-btn { font-size: 0.9rem; font-weight: 700; color: #10b981; background: #d1fae5; padding: 4px 10px; border-radius: 99px; }
.ra-blending-actions { display: flex; justify-content: center; gap: 15px; margin-top: 25px; }
.ra-listen-btn, .ra-next-btn {
    background: #4f46e5; color: white; border: none; padding: 12px 20px;
    border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer;
}
.ra-listen-btn:hover { background: #4338ca; }
.ra-next-btn { background: #10b981; }
.ra-next-btn:hover { background: #059669; }
.ra-isolation-prompt { text-align: center; color: #64748b; margin-top: 20px; font-size: 0.95rem; }
.word-input-group { display: <?php echo $words_param ? 'none' : 'flex'; ?>; gap: 10px; margin-bottom: 30px; }
.word-input-group input { flex: 1; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1.1rem; }
.word-input-group button { background: #111827; color: white; border: none; padding: 0 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
#loading-spinner { display: none; text-align: center; margin: 30px 0; font-weight: bold; color: #6b7280; }
</style>

<div class="coach-container">
    <div class="word-input-group">
        <input type="text" id="coach-word-input" value="<?php echo s($target_word); ?>" placeholder="Enter a word to practice...">
        <button onclick="startWordFlow()">Practice</button>
    </div>
    
    <div id="coach-content">
        <!-- Dynamic state machine content will be rendered here -->
    </div>
</div>

<script>
const SESSKEY = "<?php echo sesskey(); ?>";
const CMID = <?php echo $cmid ? $cmid : 0; ?>;
let wordsList = <?php echo $words_param ? json_encode(explode(',', $words_param)) : '[]'; ?>;
let currentWordIndex = 0;
let currentWord = "";

// State Machine variables
let activeAudio = null;
let streamSocket = null;
let audioContext = null;
let mediaStream = null;
let workletNode = null;
let isRecording = false;

function getWebSocketUrl() {
    const protocol = location.protocol === "https:" ? "wss:" : "ws:";
    return `${protocol}//${location.hostname}:3000/ws/stream`;
}

async function startWordFlow(specificWord = null) {
    currentWord = (specificWord || document.getElementById('coach-word-input').value.trim()).toLowerCase();
    if (!currentWord) return;
    
    // State 1: Modeling
    renderModelingState();
    playAudio(currentWord, () => {
        if (!isRecording && document.getElementById('coach-action-btn')) {
            renderAwaitingState();
        }
    });
}

function renderModelingState() {
    let progressHtml = wordsList.length > 0 ? `<span style="font-size:0.9rem; color:#64748b;">Word ${currentWordIndex + 1} of ${wordsList.length}</span>` : '';
    document.getElementById('coach-content').innerHTML = `
        <div class="ra-isolation-header">
            <span>👩‍🏫 How to pronounce:</span>
            ${progressHtml}
        </div>
        <div class="ra-target-word-display">${currentWord}</div>
        <div class="ra-blending-actions" style="margin-top: 40px;">
            <button class="ra-listen-btn" style="background:#64748b; cursor:default;" id="coach-action-btn" disabled>
                <span>🔊</span> Playing...
            </button>
        </div>
        <div id="coach-feedback" style="text-align:center; margin-top:20px;"></div>
    `;
}

function renderAwaitingState() {
    const btn = document.getElementById('coach-action-btn');
    if(btn) {
        btn.disabled = false;
        btn.innerHTML = `<span>🎤</span> Record Attempt`;
        btn.style.background = '#4f46e5';
        btn.onclick = startRecordingAttempt;
    }
}

async function startRecordingAttempt() {
    if (isRecording) return;
    if (activeAudio) activeAudio.pause();
    
    const btn = document.getElementById('coach-action-btn');
    btn.innerHTML = "🔴 Listening... (Click to stop)";
    btn.style.background = '#ef4444';
    btn.onclick = stopRecordingAttempt;
    document.getElementById('coach-feedback').innerHTML = "Speak now...";

    try {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            await audioContext.audioWorklet.addModule('v536/public/pcm-processor.js');
        }
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }
        
        mediaStream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
        const source = audioContext.createMediaStreamSource(mediaStream);
        workletNode = new AudioWorkletNode(audioContext, 'reader-recorder');
        
        streamSocket = new WebSocket(getWebSocketUrl());
        
        await new Promise((resolve, reject) => {
            streamSocket.onopen = resolve;
            streamSocket.onerror = reject;
        });

        streamSocket.send(JSON.stringify({ type: "start", locale: "en-US", referenceText: currentWord }));
        
        workletNode.port.onmessage = (event) => {
            if (streamSocket.readyState === WebSocket.OPEN) {
                try { streamSocket.send(event.data.pcm16_16k); } catch(e){}
            }
        };
        
        source.connect(workletNode);
        workletNode.connect(audioContext.destination);
        isRecording = true;
        
        let doneSeen = false;
        
        streamSocket.onmessage = (event) => {
            if (typeof event.data !== "string") return;
            const data = JSON.parse(event.data);
            if (data.type === "azure.done") {
                doneSeen = true;
                handleAssessmentResult(data);
                cleanupRecording();
            }
        };
        
        streamSocket.onclose = () => {
            if (!doneSeen) {
                cleanupRecording();
                document.getElementById('coach-feedback').innerHTML = `<span style="color:red">Assessment failed. Try again.</span>`;
                renderAwaitingState();
            }
        };

    } catch (err) {
        console.error(err);
        cleanupRecording();
        document.getElementById('coach-feedback').innerHTML = `<span style="color:red">Microphone access denied or WebSocket error.</span>`;
        renderAwaitingState();
    }
}

function stopRecordingAttempt() {
    if (!isRecording) return;
    document.getElementById('coach-feedback').innerHTML = "✨ Analyzing...";
    document.getElementById('coach-action-btn').innerHTML = "⏳ Wait...";
    document.getElementById('coach-action-btn').disabled = true;
    document.getElementById('coach-action-btn').style.background = '#64748b';
    
    if (streamSocket && streamSocket.readyState === WebSocket.OPEN) {
        streamSocket.send(JSON.stringify({ type: "stop" }));
    }
}

function cleanupRecording() {
    isRecording = false;
    if (workletNode) {
        workletNode.disconnect();
        workletNode = null;
    }
    if (mediaStream) {
        mediaStream.getTracks().forEach(t => t.stop());
        mediaStream = null;
    }
    if (streamSocket && streamSocket.readyState === WebSocket.OPEN) {
        try { streamSocket.close(); } catch(e){}
    }
}

function handleAssessmentResult(data) {
    let accuracy = 0;
    if (data.words && data.words.length > 0) {
        const target = data.words.find(w => w.Word.toLowerCase() === currentWord.toLowerCase());
        accuracy = target ? target.AccuracyScore : data.words[0].AccuracyScore;
    }
    
    if (accuracy >= 80) {
        // Mastered!
        let nextBtnHtml = '';
        if (currentWordIndex < wordsList.length - 1) {
            nextBtnHtml = `<button class="ra-next-btn" style="margin-top:15px;" onclick="nextWord()">Next Word ➡️</button>`;
        } else {
            nextBtnHtml = `<button class="ra-next-btn" style="margin-top:15px;" onclick="finishSession()">Finish 🎉</button>`;
        }
        document.getElementById('coach-action-btn').style.display = 'none';
        document.getElementById('coach-feedback').innerHTML = `
            <div style="color:#10b981; font-weight:900; font-size: 2rem; margin:10px 0;">🎉 MASTERED!</div>
            <div style="color:#059669; font-weight:600; font-size: 1.1rem; margin-bottom:15px;">Accuracy: ${accuracy}%</div>
            ${nextBtnHtml}
        `;
    } else {
        // Not mastered -> Scaffolding!
        document.getElementById('coach-feedback').innerHTML = `<div style="color:#f59e0b; font-weight:bold; font-size:1.2rem;">Needs practice! (Accuracy: ${accuracy}%)</div><div style="font-size:0.9rem; margin-top:5px; color:#6b7280;">Loading AI Scaffolding...</div>`;
        loadScaffolding();
    }
}

async function loadScaffolding() {
    try {
        const res = await fetch(`post_coach.php?action=breakdown&sesskey=${SESSKEY}&cmid=${CMID}&word=${encodeURIComponent(currentWord)}`);
        const data = await res.json();
        
        const steps = data.blending_steps || [];
        const spokenTargets = steps.map(s => s.spoken_target);
        
        const instructions = spokenTargets.join(", ... ") + ". Now say the whole word: " + currentWord;
        
        const stepsHtml = steps.map(st => `
            <div class="ra-blending-step-row">
                <span class="ra-step-badge">${st.label}</span>
                <span class="ra-step-formula">${st.formula}</span>
            </div>
        `).join('');
        
        document.getElementById('coach-feedback').innerHTML = `
            <div style="color:#f59e0b; font-weight:bold; font-size:1.2rem; margin-bottom:15px;">Listen to the breakdown...</div>
            <div class="ra-blending-steps-container" style="text-align:left; max-width:400px; margin:0 auto 20px;">
                ${stepsHtml}
            </div>
        `;
        
        const btn = document.getElementById('coach-action-btn');
        btn.innerHTML = "🔊 Playing Breakdown...";
        btn.style.background = '#64748b';
        btn.disabled = true;
        
        playAudio(instructions, () => {
            renderAwaitingState();
        });
        
    } catch (e) {
        console.error(e);
        document.getElementById('coach-feedback').innerHTML = `<div style="color:red; text-align:center;">Failed to load breakdown. Try again.</div>`;
        renderAwaitingState();
    }
}

function playAudio(text, onEnded = null) {
    if (activeAudio) activeAudio.pause();
    activeAudio = new Audio(`post_coach.php?action=tts&sesskey=${SESSKEY}&cmid=${CMID}&text=${encodeURIComponent(text)}`);
    if (onEnded) activeAudio.onended = onEnded;
    activeAudio.play().catch(e => {
        if (e.name !== 'AbortError') console.error(e);
        if (onEnded) onEnded();
    });
}

function nextWord() {
    currentWordIndex++;
    if (currentWordIndex < wordsList.length) {
        document.getElementById('coach-word-input').value = wordsList[currentWordIndex];
        startWordFlow(wordsList[currentWordIndex]);
    }
}

function finishSession() {
    document.getElementById('coach-content').innerHTML = `
        <div class="ra-target-word-display" style="color:#10b981;">🎉 Session Complete!</div>
        <div class="ra-isolation-prompt">You can close this window now.</div>
    `;
}

if (wordsList.length > 0) {
    startWordFlow(wordsList[0]);
} else if (document.getElementById('coach-word-input').value) {
    startWordFlow();
}
</script>

<?php echo $OUTPUT->footer(); ?>
