import re

with open('public/assessment-core.js', 'r') as f:
    core = f.read()

# Replace azure omission recovery loop
old_loop = """    const azureOmissionRecovery = { ops, recovered: [] };
    for (let i = 0; i < ops.length; i++) {
      const op = ops[i];
      if ((op.type === 'omit' || op.type === 'sub') && op.ref != null) {
        const refWord = refs[op.ref];
        if (refWord && AZURE_OMISSION_CORROBORATION_WORDS.has(refWord.norm)) {
          const azureEv = azureByReference.get(op.ref);
          if (azureEv && String(azureEv.errorType || '').toLowerCase() === 'none') {
            op.type = 'azure-recovered';
            op.azureRecovery = true;
            azureOmissionRecovery.recovered.push(op.ref);
          }
        }
      }
    }"""
new_loop = """    const azureOmissionRecovery = { ops, recovered: [] };
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
    }"""
if old_loop in core:
    core = core.replace(old_loop, new_loop)
else:
    print("Warning: old_loop not found in assessment-core.js")

# Replace summarize switch
old_switch = """    for (const op of ops || []) {
      switch (op.type) {
        case "mis": counts.mispronunciation++; break;
        case "omit": counts.omission++; break;"""
new_switch = """    for (const op of ops || []) {
      switch (op.type) {
        case "mis": 
        case "azure-mis-omit": counts.mispronunciation++; break;
        case "omit": counts.omission++; break;"""
if old_switch in core:
    core = core.replace(old_switch, new_switch)
else:
    print("Warning: old_switch not found in assessment-core.js")

with open('public/assessment-core.js', 'w') as f:
    f.write(core)


with open('public/app.js', 'r') as f:
    app = f.read()

# Replace omit loop
old_omit_loop = """  for (const op of assessment.ops) {
    if (op.type === "omit" && op.ref != null) {
      omissions.push(op);
      continue;
    }
    if (op.spoken != null) bySpoken.set(Number(op.spoken), op);
  }"""
new_omit_loop = """  for (const op of assessment.ops) {
    if ((op.type === "omit" || op.type === "azure-mis-omit") && op.ref != null) {
      omissions.push(op);
      continue;
    }
    if (op.spoken != null) bySpoken.set(Number(op.spoken), op);
  }"""
if old_omit_loop in app:
    app = app.replace(old_omit_loop, new_omit_loop)
else:
    print("Warning: old_omit_loop not found in app.js")

# Replace render omissions
old_render_om = """    const oms = omissions.filter(o => o.ref === rIdx);
    for (const om of oms) {
      const rw = assessment.referenceWords[om.ref];
      const span = document.createElement("span");
      span.className = "mark om";
      span.textContent = " ";
      span.setAttribute("data-omitted", rw.word);
      container.appendChild(span);
    }"""
new_render_om = """    const oms = omissions.filter(o => o.ref === rIdx);
    for (const om of oms) {
      const rw = assessment.referenceWords[om.ref];
      const span = document.createElement("span");
      span.className = om.type === "azure-mis-omit" ? "mark mis" : "mark om";
      span.textContent = om.type === "azure-mis-omit" ? rw.word : " ";
      if (om.type === "azure-mis-omit") {
         const ev = assessment.hidden.azureByReference.get(om.ref);
         if (ev) attachTooltip(span, ev);
      } else {
         span.setAttribute("data-omitted", rw.word);
      }
      container.appendChild(span);
      if (om.type === "azure-mis-omit") container.appendChild(document.createTextNode(" "));
    }"""
if old_render_om in app:
    app = app.replace(old_render_om, new_render_om)
else:
    print("Warning: old_render_om not found in app.js")

with open('public/app.js', 'w') as f:
    f.write(app)
