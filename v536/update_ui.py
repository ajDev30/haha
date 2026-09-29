import re

with open('public/index.html', 'r') as f:
    html = f.read()

html = html.replace('<div class="eyebrow">STORY READING ASSESSMENT</div>', '<div class="eyebrow">Phil-IRI Assesment</div>')
html = html.replace('<label class="locale-label">', '<label class="locale-label" hidden>')

top_actions_old = '''        <button id="startBtn" class="primary">● Start</button>
        <button id="stopBtn" class="secondary" disabled>■ Stop</button>
        <button id="retryBtn" class="ghost" hidden>↻ Retry</button>
        <button id="resetBtn" class="ghost">Reset</button>'''
top_actions_new = '''        <button id="diagBtn" class="ghost icon-btn" title="Diagnostics">⚙️</button>'''
html = html.replace(top_actions_old, top_actions_new)

html = html.replace('<div class="metric setup"><span>PRONUNCIATION ENGINE</span><strong>AZURE</strong></div>\n', '')
html = html.replace('          <div class="performance-card"><span>AZURE PRONUNCIATION</span><strong id="azurePronunciation">—</strong></div>\n', '')

reader_grid_end = '''      </div>

      <div class="legend" aria-label="Miscue legend">'''
action_buttons = '''      </div>

      <div class="action-buttons">
        <button id="startBtn" class="primary">▶ Start</button>
        <button id="stopBtn" class="secondary" disabled>⏹ Stop</button>
        <button id="restartBtn" class="ghost" hidden>🔄 Restart</button>
        <button id="resetBtn" class="ghost">Reset</button>
      </div>

      <div class="legend" aria-label="Miscue legend">'''
html = html.replace(reader_grid_end, action_buttons)

diag_old = '''      <details class="raw"><summary>Diagnostics</summary><pre id="rawData">No assessment yet.</pre></details>'''
diag_new = '''      <dialog id="diagModal" class="diag-modal">
        <div class="diag-modal-header">
          <h3>Assessment Diagnostics</h3>
          <button id="closeDiagBtn" class="ghost">Close</button>
        </div>
        <div id="diagContent" class="diag-modal-content">No assessment yet.</div>
      </dialog>'''
html = html.replace(diag_old, diag_new)

with open('public/index.html', 'w') as f:
    f.write(html)


with open('public/style.css', 'r') as f:
    css = f.read()

new_css = '''
.action-buttons { display: flex; justify-content: center; gap: 12px; margin: 24px 0; }
.action-buttons button { min-width: 100px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
.is-recording .pane-heading { opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s; }
.pane-heading { transition: opacity 0.3s ease, visibility 0.3s; }
.icon-btn { padding: 0 8px; font-size: 18px; }
.diag-modal { border: none; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 20px; width: min(600px, 90vw); max-height: 80vh; overflow-y: auto; background: var(--paper); color: var(--ink); margin: auto; }
.diag-modal::backdrop { background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); }
.diag-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding-bottom: 12px; margin-bottom: 16px; }
.diag-modal-header h3 { margin: 0; font-size: 16px; }
.diag-modal-content { font-size: 12px; line-height: 1.6; }
.diag-modal-content .diag-card { border: 1px solid var(--line); padding: 10px; border-radius: 8px; margin-bottom: 12px; background: var(--bg); }
.diag-modal-content .diag-card h4 { margin: 0 0 8px 0; color: var(--blue); }
.diag-modal-content ul { margin: 0; padding-left: 20px; }
'''
css = css + new_css

with open('public/style.css', 'w') as f:
    f.write(css)


with open('public/app.js', 'r') as f:
    app_js = f.read()

app_js = app_js.replace('retryBtn: $("retryBtn")', 'restartBtn: $("restartBtn"), diagBtn: $("diagBtn"), diagModal: $("diagModal"), closeDiagBtn: $("closeDiagBtn"), diagContent: $("diagContent")')
app_js = app_js.replace('els.retryBtn', 'els.restartBtn')
app_js = app_js.replace('azurePronunciation: $("azurePronunciation"), ', '')
app_js = app_js.replace('raw: $("rawData"), ', '')
app_js = app_js.replace('raw: $("rawData")', '')

app_js = app_js.replace('els.azurePronunciation.textContent = "—";\\n', '')
app_js = app_js.replace('els.azurePronunciation.textContent = "—";\n', '')
app_js = app_js.replace('  const phrasePron = avg((data.azure?.phrases || []).map(p => Number(p.pronunciation)));\n  els.azurePronunciation.textContent = phrasePron == null ? "—" : phrasePron.toFixed(1);\n', '')

app_js = app_js.replace('els.raw.textContent = "No assessment yet.";', 'if(els.diagContent) els.diagContent.textContent = "No assessment yet.";')
app_js = app_js.replace('els.raw.textContent = JSON.stringify(data, null, 2);', 'renderDiagnostics(data, null);')
app_js = app_js.replace('els.raw.textContent = JSON.stringify({ ...data, realtimeServerEvents }, null, 2);', 'renderDiagnostics(data, typeof assessment !== "undefined" ? assessment : (typeof lastAssessment !== "undefined" ? lastAssessment : null));')

app_js = app_js.replace('  recordingLifecycle = "recording";', '  recordingLifecycle = "recording";\n  document.body.classList.add("is-recording");')
app_js = app_js.replace('  running = false;\n  recordingLifecycle = "idle";', '  running = false;\n  recordingLifecycle = "idle";\n  document.body.classList.remove("is-recording");')
app_js = app_js.replace('  recordingLifecycle = "retrying";', '  recordingLifecycle = "retrying";\n  document.body.classList.remove("is-recording");')
app_js = app_js.replace('function resetResults() {', 'function resetResults() {\n  document.body.classList.remove("is-recording");')
app_js = app_js.replace('  setStatus("Finalizing");', '  setStatus("Finalizing");\n  els.hint.textContent = "Plss wait a moment finalizing";')

countdown_logic = '''  els.hint.textContent = "Starting in 3...";
  for (let i = 3; i > 0; i--) {
    if (!running) return;
    els.hint.textContent = `Starting in ${i}...`;
    await new Promise(r => setTimeout(r, 1000));
  }
  if (!running) return;
  els.hint.textContent = "Recording...";
  await azureConnectPromise;'''
app_js = app_js.replace('''  await Promise.all([
    azureConnectPromise,
    new Promise(resolve => setTimeout(resolve, 3000))
  ]);''', countdown_logic)

diag_func = '''
function renderDiagnostics(data, assessment) {
  if (!data) return;
  const d = assessment?.hidden?.diagnostics || {};
  let html = `<div class="diag-card"><h4>Architecture</h4><ul><li>${d.architecture || 'OpenAI + Azure'}</li></ul></div>`;
  if (d.referenceWords != null) {
    html += `<div class="diag-card"><h4>Assessment Stats</h4><ul>
      <li><strong>Reference Words:</strong> ${d.referenceWords}</li>
      <li><strong>Spoken Words (OpenAI):</strong> ${d.spokenWords}</li>
      <li><strong>Azure Pronunciation Words:</strong> ${d.azureEvidenceWords}</li>
      <li><strong>Self Corrections:</strong> ${d.selfCorrections}</li>
      <li><strong>Omissions Suppressed by Azure:</strong> ${d.azureOmissionRecoveries || 0}</li>
    </ul></div>`;
  }
  const errors = data.errors || [];
  if (errors.length) {
    html += `<div class="diag-card"><h4>Errors</h4><ul>` + errors.map(e => `<li><b>${e.provider}:</b> ${e.message}</li>`).join('') + `</ul></div>`;
  }
  if(els.diagContent) els.diagContent.innerHTML = html;
}

if(els.diagBtn) els.diagBtn.addEventListener("click", () => els.diagModal.showModal());
if(els.closeDiagBtn) els.closeDiagBtn.addEventListener("click", () => els.diagModal.close());
'''
app_js += diag_func

with open('public/app.js', 'w') as f:
    f.write(app_js)
