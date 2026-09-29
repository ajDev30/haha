// Cascading Flow & Comprehension Logic

let currentLevelIdx = 0; // 0 = Independent, 1 = Instructional, 2 = Frustration
const levels = ['Independent', 'Instructional', 'Frustration'];
let currentPassage = null;
let currentQuestions = [];
let currentAssessment = null;
let currentData = null;

function loadPassageLevel(idx) {
    currentLevelIdx = idx;
    const lvlName = levels[idx];
    currentPassage = PASSAGES; // Passages is now the single record
    
    if (!currentPassage) {
        alert("No passage data found for this grade!");
        return;
    }
    
    // Select the correct column based on index
    let passageText = '';
    if (idx === 0) passageText = currentPassage.passage || '';
    else if (idx === 1) passageText = currentPassage.passage_2 || '';
    else if (idx === 2) passageText = currentPassage.passage_3 || '';
    else if (idx === 3) passageText = currentPassage.passage_4 || '';
    
    if (!passageText) {
        console.warn("Passage level " + lvlName + " is empty!");
    }
    
    // Set the story in v536
    const storyEl = document.getElementById('story');
    const storyEditor = document.getElementById('storyEditor');
    if(storyEditor) storyEditor.value = passageText;
    if(storyEl) {
        storyEl.textContent = passageText;
        if (window.originalStory !== undefined) {
            window.originalStory = passageText;
        }
    }
    
    // Parse questions
    let allQ = [];
    try { allQ = JSON.parse(currentPassage.questions_json || '[]'); } catch(e) {}
    currentQuestions = allQ.filter(q => parseInt(q.level_idx || 0) === idx && q.type !== 'description');
    
    // Reset UI
    document.getElementById('comprehension-section').classList.add('d-none');
    
    // If v536 reset exists, call it (we can just click the reset button)
    const resetBtn = document.getElementById('resetBtn');
    if (resetBtn) resetBtn.click();
    
    console.log("Loaded passage: " + lvlName);
}

// Hook into v536 assessmentComplete
window.addEventListener('assessmentComplete', (e) => {
    currentAssessment = e.detail.assessment;
    currentData = e.detail.data;
    
    if (!currentAssessment) return;
    
    // Show Comprehension Test
    renderComprehensionTest();
});

function renderComprehensionTest() {
    const compSection = document.getElementById('comprehension-section');
    const compBody = document.getElementById('comprehension-questions');
    
    compBody.innerHTML = '';
    
    if (currentQuestions.length === 0) {
        compBody.innerHTML = '<p class="text-muted">No comprehension questions for this passage.</p>';
    } else {
        currentQuestions.forEach((q, i) => {
            let html = `<div class="mb-4 q-block" data-idx="${i}">
                <p><strong>Q${i+1}:</strong> ${q.question || q.text || 'Question text missing'}</p>`;
                
            if (q.type === 'multichoice') {
                q.options.forEach((opt, oIdx) => {
                    html += `<div class="form-check">
                        <input class="form-check-input" type="radio" name="q_${i}" value="${oIdx}" id="q_${i}_${oIdx}">
                        <label class="form-check-label" for="q_${i}_${oIdx}">${opt}</label>
                    </div>`;
                });
            } else if (q.type === 'truefalse') {
                html += `<div class="form-check">
                    <input class="form-check-input" type="radio" name="q_${i}" value="true" id="q_${i}_t">
                    <label class="form-check-label" for="q_${i}_t">True</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="q_${i}" value="false" id="q_${i}_f">
                    <label class="form-check-label" for="q_${i}_f">False</label>
                </div>`;
            } else if (q.type === 'enumeration') {
                const count = parseInt(q.count) || 3;
                for(let c=0; c<count; c++) {
                    html += `<input type="text" class="form-control mb-2 enum-ans" name="q_${i}_enum_${c}" placeholder="Item ${c+1}">`;
                }
            } else if (q.type === 'matching') {
                // simple dropdowns for matching
                q.pairs.forEach((p, pIdx) => {
                    html += `<div class="d-flex mb-2 align-items-center">
                        <span class="mr-2">${p.question}</span>
                        <select class="form-control" name="q_${i}_match_${pIdx}">
                            <option value="">Select match...</option>`;
                    // randomize options for display
                    let opts = q.pairs.map(px => px.answer);
                    opts.forEach(opt => {
                        html += `<option value="${opt}">${opt}</option>`;
                    });
                    html += `</select></div>`;
                });
            }
            html += `</div>`;
            compBody.innerHTML += html;
        });
    }
    
    compSection.classList.remove('d-none');
    compSection.scrollIntoView({behavior: 'smooth'});
}

document.getElementById('submitTestBtn').addEventListener('click', async () => {
    // Grade comprehension
    let correct = 0;
    let total = currentQuestions.length;
    let answers = {};
    
    currentQuestions.forEach((q, i) => {
        if (q.type === 'multichoice') {
            const checked = document.querySelector(`input[name="q_${i}"]:checked`);
            if (checked) {
                answers[i] = parseInt(checked.value);
                if (answers[i] === parseInt(q.correct)) correct++;
            }
        } else if (q.type === 'truefalse') {
            const checked = document.querySelector(`input[name="q_${i}"]:checked`);
            if (checked) {
                answers[i] = checked.value;
                const expected = (q.correct === 'true' || q.correct === true);
                const actual = (answers[i] === 'true');
                if (expected === actual) correct++;
            }
        } else if (q.type === 'enumeration') {
            const inputs = document.querySelectorAll(`input[name^="q_${i}_enum_"]`);
            let valid = (q.answers || "").split(',').map(s => s.trim().toLowerCase()).filter(s=>s);
            let userAns = [];
            let qCorrect = 0;
            inputs.forEach(inp => {
                let val = inp.value.trim().toLowerCase();
                if (val && valid.includes(val)) {
                    qCorrect++;
                    // remove to prevent duplicate counting
                    valid = valid.filter(v => v !== val);
                }
                userAns.push(inp.value.trim());
            });
            answers[i] = userAns.join(' | ');
            if (qCorrect >= (parseInt(q.count) || 3)) correct++; // simple threshold
            else correct += (qCorrect / (parseInt(q.count)||3)); // partial points
        } else if (q.type === 'matching') {
            let pAns = {};
            let pCorrect = 0;
            q.pairs.forEach((p, pIdx) => {
                const sel = document.querySelector(`select[name="q_${i}_match_${pIdx}"]`);
                if (sel && sel.value) {
                    pAns[pIdx] = sel.value;
                    if (sel.value === p.answer) pCorrect++;
                }
            });
            answers[i] = pAns;
            correct += (pCorrect / q.pairs.length);
        }
    });
    
    const compScore = total > 0 ? (correct / total) * 100 : 100;
    
    // Get Reading Accuracy & Speed
    let wordsCount = currentAssessment.referenceWords.length;
    let miscuesCount = currentAssessment.counts ? (
        currentAssessment.counts.mispronunciation + 
        currentAssessment.counts.omission + 
        currentAssessment.counts.insertion + 
        currentAssessment.counts.substitution + 
        currentAssessment.counts.reversal
    ) : 0;
    
    let accScore = wordsCount > 0 ? ((wordsCount - miscuesCount) / wordsCount) * 100 : 0;
    
    // Classify
    let classification = "Pending";
    if (accScore >= 97 && compScore >= 80) classification = "Independent";
    else if (accScore >= 90 && compScore >= 59) classification = "Instructional";
    else classification = "Frustration";
    
    if (currentLevelIdx === 2 && classification === "Frustration") {
        classification = "Nonreader";
    }
    
    // Determine if we need to cascade
    let cascadeTo = null;
    if (classification !== "Independent") {
        if (currentLevelIdx === 0) cascadeTo = 1;
        else if (currentLevelIdx === 1) cascadeTo = 2;
    }
    
    // Prepare submission
    const formData = new FormData();
    formData.append('token', API_TOKEN);
    formData.append('level_idx', currentLevelIdx);
    formData.append('accuracy_score', accScore);
    formData.append('comprehension_score', compScore);
    formData.append('classification', classification);
    formData.append('answers_json', JSON.stringify(answers));
    currentAssessment.markup = document.getElementById('transcript').innerHTML;
    formData.append('philiri_json', JSON.stringify(currentAssessment));
    formData.append('evaluation_data', JSON.stringify(currentData));
    
    const readingTimeStr = document.getElementById('timer').textContent;
    const parts = readingTimeStr.split(':');
    let secs = 0;
    if (parts.length === 2) secs = parseInt(parts[0])*60 + parseInt(parts[1]);
    formData.append('reading_time', secs);
    
    const wpmStr = document.getElementById('wpm').textContent;
    formData.append('reading_speed', parseFloat(wpmStr) || 0);
    
    // Append audio
    if (window.lastRecordingBlob) {
        formData.append('audio_file', window.lastRecordingBlob, 'attempt.webm');
    }
    
    document.getElementById('submitTestBtn').disabled = true;
    document.getElementById('submitTestBtn').textContent = 'Saving...';
    
    try {
        const res = await fetch('external_submit.php', { method: 'POST', body: formData });
        const resData = await res.json();
        
        let wpmFinal = parseFloat(wpmStr) || 0;
        showResultModal(cascadeTo, classification, accScore, compScore, wpmFinal);
        
    } catch(err) {
        console.error(err);
        alert("Error saving assessment. Check console.");
    } finally {
        document.getElementById('submitTestBtn').disabled = false;
        document.getElementById('submitTestBtn').textContent = 'Submit & Continue';
    }
});

// Capture Audio Blob from v536 (Requires hooking MediaRecorder)
const OriginalMediaRecorder = window.MediaRecorder;
window.MediaRecorder = class extends OriginalMediaRecorder {
    constructor(stream, options) {
        super(stream, options);
        this.recordedChunks = [];
        this.addEventListener('dataavailable', e => {
            if (e.data.size > 0) this.recordedChunks.push(e.data);
        });
        this.addEventListener('stop', () => {
            window.lastRecordingBlob = new Blob(this.recordedChunks, { type: 'audio/webm' });
        });
    }
};

// Start initialization
window.addEventListener('DOMContentLoaded', () => {
    // Wait slightly for PASSAGES
    setTimeout(() => {
        if (typeof PASSAGES !== 'undefined') {
            loadPassageLevel(0);
        }
    }, 500);
});

function showResultModal(cascadeTo, classification, accScore, compScore, wpm) {
    // Remove existing if any
    let existing = document.getElementById('resultModalOverlay');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'resultModalOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:40;display:flex;align-items:center;justify-content:center;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;padding:20px;border-radius:8px;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    
    const title = cascadeTo !== null 
        ? "Assessment Level Complete" 
        : "Assessment Finished";
        
    const desc = cascadeTo !== null 
        ? `You have been classified as <strong>${classification}</strong>. You will now take the next level assessment to determine your final standing.`
        : `You have completed the assessment! Final Classification: <strong>${classification}</strong>.`;

    const transcriptHtml = document.getElementById('transcript') ? document.getElementById('transcript').innerHTML : '';
    
    modal.innerHTML = `
        <h3 style="margin-top:0;">${title}</h3>
        <p>${desc}</p>
        <div style="display:flex; gap:15px; margin: 15px 0;">
            <div style="background:#f1f3f4; padding:10px; border-radius:6px; flex:1; text-align:center;">
                <div style="font-size:12px; color:#5f6368; text-transform:uppercase;">Speed</div>
                <div style="font-size:18px; font-weight:bold;">${wpm} <span style="font-size:12px;font-weight:normal;">WPM</span></div>
            </div>
            <div style="background:#f1f3f4; padding:10px; border-radius:6px; flex:1; text-align:center;">
                <div style="font-size:12px; color:#5f6368; text-transform:uppercase;">Accuracy</div>
                <div style="font-size:18px; font-weight:bold;">${accScore.toFixed(1)}%</div>
            </div>
            <div style="background:#f1f3f4; padding:10px; border-radius:6px; flex:1; text-align:center;">
                <div style="font-size:12px; color:#5f6368; text-transform:uppercase;">Comprehension</div>
                <div style="font-size:18px; font-weight:bold;">${compScore.toFixed(1)}%</div>
            </div>
        </div>
        <div style="margin-top:20px;">
            <h5 style="margin-bottom:8px;">Reading Miscues Breakdown</h5>
            <div id="modalTranscriptContainer" style="border:1px solid #e0e0e0; padding:15px; border-radius:6px; font-size:1.1rem; line-height:1.6; background:#fafafa; max-height:200px; overflow-y:auto;">
                ${transcriptHtml}
            </div>
        </div>
        <div style="text-align:right; margin-top:20px;">
            <button id="modalContinueBtn" class="btn btn-primary">Continue</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const modalTranscript = document.getElementById('modalTranscriptContainer');
    
    // Bind tooltip listeners using the global showTooltip function from app.js
    modalTranscript.addEventListener("pointerover", (event) => {
        const token = event.target.closest?.(".transcript-token[data-tip-index]");
        if (!token || !modalTranscript.contains(token)) return;
        if (typeof showTooltip === 'function') {
            showTooltip(Number(token.dataset.tipIndex), event.clientX, event.clientY);
        }
    });
    modalTranscript.addEventListener("pointermove", (event) => {
        const token = event.target.closest?.(".transcript-token[data-tip-index]");
        if (token && typeof positionTooltip === 'function') {
            positionTooltip(event.clientX, event.clientY);
        }
    });
    modalTranscript.addEventListener("pointerout", (event) => {
        const token = event.target.closest?.(".transcript-token[data-tip-index]");
        const next = event.relatedTarget?.closest?.(".transcript-token[data-tip-index]");
        if (token && next !== token && typeof hideTooltip === 'function') {
            hideTooltip();
        }
    });
    
    document.getElementById('modalContinueBtn').addEventListener('click', () => {
        overlay.remove();
        if (cascadeTo !== null) {
            loadPassageLevel(cascadeTo);
        } else {
            window.location.href = "external.php";
        }
    });
}
