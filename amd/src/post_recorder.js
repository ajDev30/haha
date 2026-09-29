// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module: post_recorder.js
 *
 * Manages the post-test reading-assessment recording session by wrapping
 * the existing v536 engine (app.js + assessment-core.js) that post_view.php
 * already loads onto the page.
 *
 * Responsibilities:
 *  - Bridge #pt-* UI buttons to the hidden v536 engine buttons.
 *  - Listen for the 'assessmentComplete' CustomEvent fired by app.js.
 *  - Reveal the comprehension questionnaire once scoring is done.
 *  - Collect questionnaire answers + audio blob and POST to submitUrl.
 *  - On success, expose attemptId and mispronounced_words for the coach.
 *
 * @module     mod_readingassessment/post_recorder
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/notification'], function(Notification) {
    'use strict';

    // -------------------------------------------------------------------------
    // Private state
    // -------------------------------------------------------------------------

    /** @type {object} Module configuration set by init(). */
    var _config = null;

    /** @type {object|null} Last assessment object from assessmentComplete event. */
    var _lastAssessment = null;

    /** @type {object|null} Last raw data object from assessmentComplete event. */
    var _lastData = null;

    /** @type {number|null} Attempt ID returned by the server after submission. */
    var _attemptId = null;

    /** @type {Array} Mispronounced word strings returned by the server. */
    var _mispronounced = [];

    /** @type {boolean} Whether recording is currently in progress. */
    var _recording = false;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Display a status message in the #pt-status element.
     *
     * @param {string} msg   - Human-readable status text.
     * @param {string} [cls] - Optional Bootstrap alert class, e.g. 'alert-info'.
     */
    function setStatus(msg, cls) {
        var el = document.getElementById('pt-status');
        if (!el) {
            return;
        }
        // Reset classes then apply the requested variant (default: info).
        el.className = 'alert ' + (cls || 'alert-info');
        el.textContent = msg;
        el.classList.remove('d-none');
    }

    /**
     * Hide the #pt-status element.
     */
    function clearStatus() {
        var el = document.getElementById('pt-status');
        if (el) {
            el.classList.add('d-none');
        }
    }

    /**
     * Set the disabled state of one of our UI buttons.
     *
     * @param {string}  id       - Element ID without the '#'.
     * @param {boolean} disabled - True to disable.
     */
    function setDisabled(id, disabled) {
        var el = document.getElementById(id);
        if (el) {
            el.disabled = disabled;
        }
    }

    /**
     * Transition the button toolbar into the given state.
     *
     * States:
     *  'idle'      - Ready to start; only Start is enabled.
     *  'recording' - Recording in progress; only Stop is enabled.
     *  'done'      - Scored; Start and Retry available (Stop disabled).
     *
     * @param {string} state
     */
    function setButtonState(state) {
        switch (state) {
            case 'idle':
                setDisabled('pt-start-btn', false);
                setDisabled('pt-stop-btn', true);
                setDisabled('pt-retry-btn', true);
                break;
            case 'recording':
                setDisabled('pt-start-btn', true);
                setDisabled('pt-stop-btn', false);
                setDisabled('pt-retry-btn', true);
                break;
            case 'done':
                setDisabled('pt-start-btn', false);
                setDisabled('pt-stop-btn', true);
                setDisabled('pt-retry-btn', false);
                break;
            case 'submitted':
                setDisabled('pt-start-btn', true);
                setDisabled('pt-stop-btn', true);
                setDisabled('pt-retry-btn', true);
                break;
        }
    }

    // -------------------------------------------------------------------------
    // v536 engine bridge helpers
    // -------------------------------------------------------------------------

    /**
     * Safely click a v536 engine button by ID, logging a warning if absent.
     *
     * @param {string} id - Native v536 button element ID.
     */
    function clickEngine(id) {
        var btn = document.getElementById(id);
        if (btn) {
            btn.click();
        } else {
            // If the engine hasn't rendered yet or ids changed, warn but don't crash.
            /* eslint-disable no-console */
            console.warn('[post_recorder] v536 engine button not found: #' + id);
            /* eslint-enable no-console */
        }
    }

    // -------------------------------------------------------------------------
    // Recording actions
    // -------------------------------------------------------------------------

    /**
     * Begin a new recording attempt.
     * Validates microphone availability before delegating to the v536 engine.
     */
    function startRecording() {
        // Guard against double-clicks.
        if (_recording) {
            return;
        }

        // Quick mic check — navigator.mediaDevices may be absent on HTTP.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Microphone access is not available in this browser or context.', 'alert-danger');
            return;
        }

        _recording = true;
        setButtonState('recording');
        setStatus('Recording… speak clearly into your microphone.', 'alert-warning');

        // Delegate to the v536 engine's own start button.
        clickEngine('startBtn');
    }

    /**
     * Stop the current recording and trigger engine-side scoring.
     */
    function stopRecording() {
        if (!_recording) {
            return;
        }

        setStatus('Processing your recording, please wait…', 'alert-info');
        clickEngine('stopBtn');
        // State transitions happen in the assessmentComplete listener.
    }

    /**
     * Discard the last result and reset to idle so the user can try again.
     */
    function retryRecording() {
        _lastAssessment = null;
        _lastData = null;

        // Hide the questionnaire again so the user re-records first.
        var q = document.getElementById('pt-questionnaire');
        if (q) {
            q.classList.add('d-none');
        }

        _recording = false;
        setButtonState('idle');
        setStatus('Ready to record. Press Start when you are ready.', 'alert-info');

        // Tell the v536 engine to reset its internal state.
        clickEngine('resetBtn');
    }

    // -------------------------------------------------------------------------
    // assessmentComplete handler
    // -------------------------------------------------------------------------

    /**
     * Handle the 'assessmentComplete' CustomEvent dispatched by app.js.
     * Stores results, updates the UI, and reveals the questionnaire section.
     *
     * @param {CustomEvent} event - Event with detail: { assessment, data }.
     */
    function onAssessmentComplete(event) {
        var detail = event.detail || {};
        _lastAssessment = detail.assessment || null;
        _lastData = detail.data || null;
        _recording = false;

        // Summarise key metrics for the status bar.
        var counts = (_lastAssessment && _lastAssessment.counts) ? _lastAssessment.counts : {};
        var misCount = counts.mispronunciation || 0;
        var omCount  = counts.omission || 0;
        var dur      = (_lastData && _lastData.duration) ? Math.round(_lastData.duration / 1000) : 0;

        setStatus(
            'Recording scored! Duration: ' + dur + 's | ' +
            'Mispronunciations: ' + misCount + ' | Omissions: ' + omCount +
            '. Complete the questions below then submit.',
            'alert-success'
        );

        // Reveal the comprehension questionnaire
        var questionnaireEl = document.getElementById('pt-questionnaire');
        if (questionnaireEl) {
            questionnaireEl.style.display = 'block';
            // Scroll to questionnaire so the student knows it's there
            setTimeout(function() {
                questionnaireEl.scrollIntoView({ behavior: 'smooth' });
            }, 300);
        }

        setButtonState('done');


    }

    // -------------------------------------------------------------------------
    // Questionnaire answer collection
    // -------------------------------------------------------------------------

    /**
     * Collect all questionnaire answers from the DOM.
     *
     * Inputs are expected to have name="qa_<questionId>".
     * Radio buttons: only the checked one is collected.
     * Selects/textareas/text inputs: value is taken directly.
     *
     * @returns {object} Map of { questionId: answerValue }.
     */
    function collectAnswers() {
        var answers = {};
        var container = document.getElementById('pt-questionnaire');
        if (!container) {
            return answers;
        }

        // Gather radios (only checked ones).
        var radios = container.querySelectorAll('input[type="radio"][name^="qa_"]:checked');
        radios.forEach(function(r) {
            var qid = r.name.replace(/^qa_/, '');
            answers[qid] = r.value;
        });

        // Gather selects.
        var selects = container.querySelectorAll('select[name^="qa_"]');
        selects.forEach(function(s) {
            var qid = s.name.replace(/^qa_/, '');
            answers[qid] = s.value;
        });

        // Gather textareas.
        var textareas = container.querySelectorAll('textarea[name^="qa_"]');
        textareas.forEach(function(t) {
            var qid = t.name.replace(/^qa_/, '');
            answers[qid] = t.value;
        });

        // Gather text/number inputs (excluding radios already collected).
        var inputs = container.querySelectorAll(
            'input[name^="qa_"]:not([type="radio"]):not([type="checkbox"])'
        );
        inputs.forEach(function(i) {
            var qid = i.name.replace(/^qa_/, '');
            answers[qid] = i.value;
        });

        // Gather checkboxes (joined by comma if multiple share the same name).
        var checkboxes = container.querySelectorAll('input[type="checkbox"][name^="qa_"]:checked');
        checkboxes.forEach(function(c) {
            var qid = c.name.replace(/^qa_/, '');
            if (answers[qid]) {
                answers[qid] += ',' + c.value;
            } else {
                answers[qid] = c.value;
            }
        });

        return answers;
    }

    // -------------------------------------------------------------------------
    // Submission
    // -------------------------------------------------------------------------

    /**
     * Build a FormData payload and POST it to submitUrl.
     *
     * The payload includes:
     *  - sesskey         : Moodle session key (CSRF protection).
     *  - cmid            : Course-module ID.
     *  - assessment_json : JSON-serialised assessment object.
     *  - data_json       : JSON-serialised raw Azure data.
     *  - qa_<id>         : Individual question answers.
     *  - recording       : The audio Blob from window.lastRecordingBlob.
     */
    function submitAttempt() {
        if (!_lastAssessment || !_lastData) {
            setStatus('Please record your reading before submitting.', 'alert-danger');
            return;
        }

        var answers = collectAnswers();

        // Calculate metrics locally
        var wordsCount = (_lastAssessment && _lastAssessment.spokenWords) ? _lastAssessment.spokenWords.length : 0;
        var counts = (_lastAssessment && _lastAssessment.counts) ? _lastAssessment.counts : {};
        var miscuesCount = (counts.mispronunciation || 0) + (counts.omission || 0) + (counts.insertion || 0) + (counts.substitution || 0) + (counts.reversal || 0);
        
        // For accuracy, we need reference words length, not spoken words length
        var refWordsCount = (_lastAssessment && _lastAssessment.referenceWords) ? _lastAssessment.referenceWords.length : 0;
        var accScore = refWordsCount > 0 ? (Math.max(0, refWordsCount - miscuesCount) / refWordsCount) * 100 : 0;
        
        var secs = 0;
        if (_lastData && _lastData.pipeline && _lastData.pipeline.audioDurationSeconds) {
            secs = parseFloat(_lastData.pipeline.audioDurationSeconds);
        } else if (_lastData && _lastData.duration) {
            secs = _lastData.duration / 1000;
        }
        var wpmScore = secs > 0 ? (wordsCount / secs) * 60 : 0;

        // Build FormData.
        var fd = new FormData();
        fd.append('sesskey', _config.sesskey);
        fd.append('cmid', _config.cmid);
        fd.append('assessment_json', JSON.stringify(_lastAssessment));
        fd.append('data_json', JSON.stringify(_lastData));
        fd.append('answers_json', JSON.stringify(answers));
        fd.append('reading_time_ms', _lastData.duration || 0);
        fd.append('accuracy_score', accScore.toFixed(2));
        fd.append('wpm', wpmScore.toFixed(2));

        var transcriptEl = document.getElementById('transcript');
        if (transcriptEl) {
            fd.append('markup_html', transcriptEl.innerHTML);
        }

        // Append questionnaire answers for legacy compatibility if needed.
        Object.keys(answers).forEach(function(qid) {
            fd.append('qa_' + qid, answers[qid]);
        });

        // Attach the audio blob if the v536 engine exposed it.
        var blob = window.lastRecordingBlob;
        if (blob instanceof Blob) {
            fd.append('audio_file', blob, 'reading.webm');
        }

        // Disable the submit button to prevent double-submission.
        setDisabled('pt-submit-btn', true);
        setButtonState('submitted');
        setStatus('Submitting your attempt…', 'alert-info');

        fetch(_config.submitUrl, {
            method: 'POST',
            body: fd,
            // credentials: 'same-origin' is the fetch default.
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Server returned ' + response.status + ' ' + response.statusText);
            }
            return response.json();
        })
        .then(function(json) {
            if (!json || !json.attemptId) {
                throw new Error('Unexpected server response: ' + JSON.stringify(json));
            }

            _attemptId = json.attemptId;
            _mispronounced = Array.isArray(json.mispronounced_words) ? json.mispronounced_words : [];

            setStatus('Attempt saved successfully!', 'alert-success');

            // Fire the coach prompt if there are mispronounced words.
            if (_mispronounced.length > 0) {
                triggerCoach(json);
            } else {
                setStatus('Great job! No mispronounced words detected.', 'alert-success');
                // Even without coach, trigger the event to show results
                triggerCoach(json);
            }
        })
        .catch(function(err) {
            /* eslint-disable no-console */
            console.error('[post_recorder] Submission error:', err);
            /* eslint-enable no-console */
            setStatus('Submission failed: ' + err.message, 'alert-danger');
            setDisabled('pt-submit-btn', false);
            setButtonState('done');
        });
    }

    /**
     * Trigger the pronunciation coach modal via the post_coach module if it
     * has already been initialised on the page, or dispatch a custom event
     * for the page to handle.
     */
    function triggerCoach(serverStats) {
        serverStats = serverStats || {};
        // Dispatch a CustomEvent so post_view.php / post_coach.js can respond.
        var evt = new CustomEvent('readingAssessmentSubmitted', {
            bubbles: true,
            detail: {
                attemptId: _attemptId,
                mispronounced_words: _mispronounced,
                accuracy: serverStats.accuracy,
                wpm: serverStats.wpm,
                comprehension: serverStats.comprehension
            },
        });
        window.dispatchEvent(evt);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Initialise the post-test recorder.
     *
     * @param {object} config
     * @param {number|string} config.cmid        - Course-module ID.
     * @param {string}        config.sesskey     - Moodle sesskey for CSRF.
     * @param {string}        config.passageText - Reading passage plain text.
     * @param {string}        config.locale      - BCP-47 locale, e.g. 'en-US'.
     * @param {string}        config.submitUrl   - URL to post_submit.php.
     * @param {string}        config.coachUrl    - URL to post_coach.php.
     */
    function init(config) {
        _config = config;

        // Set the reading passage on any element the engine uses to display it.
        // (The v536 engine picks up #passage-text or window.PT_PASSAGE_TEXT.)
        if (config.passageText) {
            window.PT_PASSAGE_TEXT = config.passageText;
        }

        // ---- Button wiring ------------------------------------------------
        var startBtn = document.getElementById('pt-start-btn');
        var stopBtn  = document.getElementById('pt-stop-btn');
        var retryBtn = document.getElementById('pt-retry-btn');
        var submitBtn = document.getElementById('pt-submit-btn');

        if (startBtn) {
            startBtn.addEventListener('click', startRecording);
        }
        if (stopBtn) {
            stopBtn.addEventListener('click', stopRecording);
        }
        if (retryBtn) {
            retryBtn.addEventListener('click', retryRecording);
        }
        if (submitBtn) {
            submitBtn.addEventListener('click', submitAttempt);
        }

        // ---- Engine event --------------------------------------------------
        window.addEventListener('assessmentComplete', onAssessmentComplete);

        // ---- Initial UI state ----------------------------------------------
        setButtonState('idle');
        setStatus('Press Start when you are ready to read.', 'alert-info');
    }

    /**
     * Return the last captured result set.
     *
     * @returns {{ assessment: object|null, data: object|null,
     *             attemptId: number|null, mispronounced_words: Array }}
     */
    function getLastResult() {
        return {
            assessment: _lastAssessment,
            data: _lastData,
            attemptId: _attemptId,
            mispronounced_words: _mispronounced,
        };
    }

    return {
        init: init,
        getLastResult: getLastResult,
    };
});
