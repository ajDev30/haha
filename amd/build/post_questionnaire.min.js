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
 * AMD module: post_questionnaire.js
 *
 * Renders the comprehension questionnaire into a named container element and
 * exposes a getAnswers() helper that returns all current answer values.
 *
 * Supported question types (qtype):
 *  - 'description'     : Informational paragraph; no answer collected.
 *  - 'multiple_choice' : Radio-button list built from options_json.
 *  - 'true_false'      : Two-radio True / False question.
 *  - 'enumeration'     : Textarea for free-text item listing.
 *  - 'matching'        : Two-column layout; left items paired with dropdowns.
 *
 * Each question is wrapped in a Bootstrap 4 .card element.
 * Input names follow the convention  qa_<questionId>  so post_recorder.js
 * can collect them with a single querySelectorAll.
 *
 * @module     mod_readingassessment/post_questionnaire
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Safely escape a string for inclusion as HTML text content.
     *
     * @param  {string} str
     * @returns {string}
     */
    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str || '')));
        return d.innerHTML;
    }

    /**
     * Parse options_json, returning an empty array on failure.
     *
     * options_json may arrive as a JSON string or an already-parsed array.
     *
     * @param  {string|Array} raw
     * @returns {Array}
     */
    function parseOptions(raw) {
        if (Array.isArray(raw)) {
            return raw;
        }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Question-type renderers
    // Each renderer returns an HTMLElement representing the card body content.
    // -------------------------------------------------------------------------

    /**
     * Render a 'description' question — display-only paragraph, no input.
     *
     * @param  {object} q - Question object.
     * @returns {HTMLElement}
     */
    function renderDescription(q, arrIdx) {
        var p = document.createElement('p');
        p.className = 'mb-0 text-muted';
        p.textContent = q.qtext;
        return p;
    }

    /**
     * Render a 'multiple_choice' question with Bootstrap 4 custom radios.
     *
     * @param  {object} q - Question object.
     * @param  {number} arrIdx - Array index of the question.
     * @returns {HTMLElement}
     */
    function renderMultipleChoice(q, arrIdx) {
        var wrap = document.createElement('div');
        var options = parseOptions(q.options || q.pairs);

        if (options.length === 0) {
            wrap.innerHTML = '<p class="text-muted">No options defined for this question.</p>';
            return wrap;
        }

        options.forEach(function(opt, idx) {
            var value = (typeof opt === 'object') ? (opt.value || opt.text || opt) : opt;
            var label = (typeof opt === 'object') ? (opt.text || opt.value || opt) : opt;
            var radioId = 'qa_' + arrIdx + '_' + idx;

            var div = document.createElement('div');
            div.className = 'form-check mb-2';

            var input = document.createElement('input');
            input.type = 'radio';
            input.className = 'form-check-input';
            input.id = radioId;
            input.name = 'qa_' + arrIdx;
            input.value = value;

            var lbl = document.createElement('label');
            lbl.className = 'form-check-label';
            lbl.htmlFor = radioId;
            lbl.textContent = label;

            div.appendChild(input);
            div.appendChild(lbl);
            wrap.appendChild(div);
        });

        return wrap;
    }

    /**
     * Render a 'true_false' question with True and False radio buttons.
     *
     * @param  {object} q - Question object.
     * @param  {number} arrIdx - Array index of the question.
     * @returns {HTMLElement}
     */
    function renderTrueFalse(q, arrIdx) {
        var wrap = document.createElement('div');
        var trueId  = 'qa_' + arrIdx + '_true';
        var falseId = 'qa_' + arrIdx + '_false';

        [
            { id: trueId,  value: 'true',  text: 'True'  },
            { id: falseId, value: 'false', text: 'False' },
        ].forEach(function(opt) {
            var div = document.createElement('div');
            div.className = 'form-check form-check-inline mb-2';

            var input = document.createElement('input');
            input.type = 'radio';
            input.className = 'form-check-input';
            input.id = opt.id;
            input.name = 'qa_' + arrIdx;
            input.value = opt.value;

            var lbl = document.createElement('label');
            lbl.className = 'form-check-label';
            lbl.htmlFor = opt.id;
            lbl.textContent = opt.text;

            div.appendChild(input);
            div.appendChild(lbl);
            wrap.appendChild(div);
        });

        return wrap;
    }

    /**
     * Render an 'enumeration' question with a textarea for free-form listing.
     *
     * @param  {object} q - Question object.
     * @param  {number} arrIdx - Array index of the question.
     * @returns {HTMLElement}
     */
    function renderEnumeration(q, arrIdx) {
        var wrap = document.createElement('div');

        var hint = document.createElement('small');
        hint.className = 'd-block text-muted mb-1';
        hint.textContent = 'List each item on a new line.';
        wrap.appendChild(hint);

        var ta = document.createElement('textarea');
        ta.className = 'form-control';
        ta.name = 'qa_' + arrIdx;
        ta.id = 'qa_field_' + arrIdx;
        ta.rows = 4;
        ta.placeholder = 'Item 1\nItem 2\nItem 3…';
        wrap.appendChild(ta);

        return wrap;
    }

    /**
     * Render a 'matching' question: left-side labels paired with right-side
     * dropdown <select> elements whose options are drawn from options_json.
     *
     * @param  {object} q - Question object.
     * @param  {number} arrIdx - Array index of the question.
     * @returns {HTMLElement}
     */
    function renderMatching(q, arrIdx) {
        var wrap = document.createElement('div');
        var options = parseOptions(q.options || q.pairs);

        if (options.length === 0) {
            wrap.innerHTML = '<p class="text-muted">No matching pairs defined.</p>';
            return wrap;
        }

        var isPaired = (typeof options[0] === 'object' && options[0] !== null &&
                        ('left' in options[0] || 'right' in options[0]));
        var allChoices = isPaired ? [] : options.slice();

        var table = document.createElement('table');
        table.className = 'table table-sm table-borderless mb-0';

        var tbody = document.createElement('tbody');

        options.forEach(function(opt, idx) {
            var leftLabel, rightChoices;

            if (isPaired) {
                leftLabel    = opt.left  || ('Item ' + (idx + 1));
                rightChoices = Array.isArray(opt.right) ? opt.right : allChoices;
            } else {
                leftLabel    = opt;
                rightChoices = allChoices;
            }

            var tr = document.createElement('tr');

            var tdLeft = document.createElement('td');
            tdLeft.className = 'align-middle font-weight-bold w-50';
            tdLeft.textContent = leftLabel;
            tr.appendChild(tdLeft);

            var tdRight = document.createElement('td');
            tdRight.className = 'align-middle w-50';

            var sel = document.createElement('select');
            sel.className = 'form-control form-control-sm';
            sel.name = 'qa_' + arrIdx + '_' + idx;
            sel.id = 'qa_field_' + arrIdx + '_' + idx;

            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— select —';
            placeholder.disabled = true;
            placeholder.selected = true;
            sel.appendChild(placeholder);

            rightChoices.forEach(function(choice) {
                var opt2 = document.createElement('option');
                var choiceVal = (typeof choice === 'object') ? (choice.value || choice.text) : choice;
                var choiceTxt = (typeof choice === 'object') ? (choice.text || choice.value) : choice;
                opt2.value = choiceVal;
                opt2.textContent = choiceTxt;
                sel.appendChild(opt2);
            });

            tdRight.appendChild(sel);
            tr.appendChild(tdRight);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }

    /**
     * Build a Bootstrap 4 .card element for a single question.
     *
     * @param  {object} q     - Question data object.
     * @param  {number} index - 1-based question index (for display label).
     * @param  {number} arrIdx - 0-based array index.
     * @returns {HTMLElement}
     */
    function buildQuestionCard(q, index, arrIdx) {
        var block = document.createElement('div');
        block.className = 'mb-4 q-block';
        block.dataset.questionIdx = arrIdx;
        block.dataset.qtype = q.type;

        if (q.type !== 'description') {
            var questionText = document.createElement('p');
            questionText.innerHTML = '<strong>Q' + index + ':</strong> ' + esc(q.qtext);
            
            if (q.points > 0) {
                var badge = document.createElement('span');
                badge.className = 'badge badge-secondary ml-2';
                badge.title = 'Points';
                badge.textContent = q.points + ' pt' + (q.points !== 1 ? 's' : '');
                questionText.appendChild(badge);
            }
            block.appendChild(questionText);
        }

        var content;
        switch (q.type) {
            case 'description':
                content = renderDescription(q, arrIdx);
                break;
            case 'multiple_choice':
                content = renderMultipleChoice(q, arrIdx);
                break;
            case 'true_false':
                content = renderTrueFalse(q, arrIdx);
                break;
            case 'enumeration':
                content = renderEnumeration(q, arrIdx);
                break;
            case 'matching':
                content = renderMatching(q, arrIdx);
                break;
            default:
                content = document.createElement('div');
                content.innerHTML =
                    '<p class="text-warning small mb-1">Unknown question type: ' +
                    esc(q.type) + '. Rendering as text input.</p>';
                var fallback = document.createElement('input');
                fallback.type = 'text';
                fallback.className = 'form-control';
                fallback.name = 'qa_' + arrIdx;
                content.appendChild(fallback);
        }

        block.appendChild(content);
        return block;
    }

    /**
     * Collect all current answer values from the rendered questionnaire.
     */
    function getAnswers(containerId, questions) {
        var container = document.getElementById(containerId);
        var answers = {};

        if (!container || !Array.isArray(questions)) {
            return answers;
        }

        questions.forEach(function(q, arrIdx) {
            if (q.type === 'description') {
                return;
            }

            if (q.type === 'matching') {
                var parts = [];
                var idx = 0;
                while (true) {
                    var sel = container.querySelector(
                        'select[name="qa_' + arrIdx + '_' + idx + '"]'
                    );
                    if (!sel) {
                        break;
                    }
                    parts.push(String(idx) + ':' + sel.value);
                    idx++;
                }
                answers[arrIdx] = parts.join('|');
                return;
            }

            var radio = container.querySelector(
                'input[type="radio"][name="qa_' + arrIdx + '"]:checked'
            );
            if (radio) {
                answers[arrIdx] = radio.value;
                return;
            }

            var ta = container.querySelector('textarea[name="qa_' + arrIdx + '"]');
            if (ta) {
                answers[arrIdx] = ta.value;
                return;
            }

            var inp = container.querySelector(
                'input[name="qa_' + arrIdx + '"]:not([type="radio"]):not([type="checkbox"])'
            );
            if (inp) {
                answers[arrIdx] = inp.value;
            }
        });

        return answers;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Render the questionnaire into the specified container element.
     *
     * Clears any existing content first, then appends one Bootstrap 4 card
     * per question. Skips rendering if the container element is not found.
     *
     * @param {string} containerId - ID of the target DOM element.
     * @param {Array}  questions   - Array of question objects:
     *   [{id, qtype, qtext, options_json, points}, ...]
     */
    function init(containerId, questions) {
        var container = document.getElementById(containerId);
        if (!container) {
            /* eslint-disable no-console */
            console.warn('[post_questionnaire] Container not found: #' + containerId);
            /* eslint-enable no-console */
            return;
        }

        if (!Array.isArray(questions) || questions.length === 0) {
            container.innerHTML =
                '<p class="text-muted">No comprehension questions for this passage.</p>';
            return;
        }

        // Clear previous renders.
        container.innerHTML = '';

        // Track the 1-based numbering counter (skip 'description' from count).
        var qNum = 0;

        questions.forEach(function(q, arrIdx) {
            if (q.type !== 'description') {
                qNum++;
            }
            var card = buildQuestionCard(q, qNum, arrIdx);
            container.appendChild(card);
        });
    }

    /**
     * Return a factory-style getAnswers function bound to a specific container
     * and question list.  Called after init().
     *
     * Usage (called externally):
     *   var qa = require('mod_readingassessment/post_questionnaire');
     *   qa.init('my-container', questions);
     *   var answers = qa.getAnswers('my-container', questions);
     *
     * @param {string} containerId
     * @param {Array}  questions
     * @returns {object}
     */
    function getAnswersPublic(containerId, questions) {
        return getAnswers(containerId, questions);
    }

    return {
        init: init,
        getAnswers: getAnswersPublic,
    };
});
