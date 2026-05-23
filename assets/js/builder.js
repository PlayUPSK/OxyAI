(function () {
  "use strict";

  const config = window.oxyaiOxygen || {};
  const strings = config.strings || {};
  const parentWindow = window.parent || window;
  const parentDoc = parentWindow.document || document;
  const currentDoc = document;
  let modal = null;
  let lastJson = "";
  let initialized = false;
  let chatMessages = [];
  let capturedContext = null;
  let currentPostId = null;
  let currentMode = "ai";

  // ===== REQUEST =====

  function request(path, payload) {
    return fetch(config.restUrl + path, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "content-type": "application/json",
        "x-wp-nonce": config.nonce,
      },
      body: JSON.stringify(payload || {}),
    }).then(async function (response) {
      const data = await response.json();
      if (!response.ok || data.success === false) {
        throw new Error(data.message || strings.failed || "Request failed.");
      }
      return data;
    });
  }

  // ===== MARKUP =====

  function panelTemplate() {
    return `
      <div class="ox-overlay" hidden>
        <div class="ox-overlay__backdrop" data-ox-close></div>
        <aside class="ox-panel" role="dialog" aria-modal="true" aria-labelledby="ox-panel-title">

          <header class="ox-panel__head">
            <div class="ox-panel__brand">
              <div class="ox-panel__mark" aria-hidden="true">Ox</div>
              <div>
                <p class="ox-panel__eyebrow">OxyAI</p>
                <h2 id="ox-panel-title" class="ox-panel__title">AI for Oxygen</h2>
              </div>
            </div>
            <button type="button" class="ox-panel__close" data-ox-close aria-label="Close"></button>
          </header>

          <nav class="ox-modes" role="tablist" aria-label="OxyAI mode">
            <button type="button" class="ox-mode-tab is-active" role="tab" aria-selected="true" tabindex="0" id="ox-tab-ai" aria-controls="ox-panel-ai" data-ox-mode-tab="ai">
              <span class="ox-mode-tab__icon" aria-hidden="true">✦</span>
              <span>Generate</span>
            </button>
            <button type="button" class="ox-mode-tab" role="tab" aria-selected="false" tabindex="-1" id="ox-tab-paste" aria-controls="ox-panel-paste" data-ox-mode-tab="paste">
              <span class="ox-mode-tab__icon" aria-hidden="true">⌘</span>
              <span>Paste</span>
            </button>
            <button type="button" class="ox-mode-tab" role="tab" aria-selected="false" tabindex="-1" id="ox-tab-edit" aria-controls="ox-panel-edit" data-ox-mode-tab="edit">
              <span class="ox-mode-tab__icon" aria-hidden="true">✎</span>
              <span>Edit selected</span>
            </button>
          </nav>

          <div class="ox-panel__body">

            <div class="ox-statusbar" data-ox-status>Ready</div>

            <!-- Codex handoff -->
            <div class="ox-handoff" data-ox-handoff hidden>
              <div class="ox-handoff__head">
                <span class="ox-handoff__icon" aria-hidden="true">↘</span>
                <div>
                  <strong>Codex staged a payload</strong>
                  <p data-ox-handoff-summary>Generated source is staged for this page.</p>
                </div>
              </div>
              <div class="ox-handoff__actions">
                <button type="button" class="ox-btn ox-btn--ghost" data-ox-handoff-load>Load fields</button>
                <button type="button" class="ox-btn ox-btn--primary" data-ox-handoff-apply>Convert & Insert</button>
                <button type="button" class="ox-btn ox-btn--success" data-ox-handoff-save>Save to page</button>
              </div>
            </div>

            <!-- AI MODE -->
            <section class="ox-mode-panel" data-ox-mode-panel="ai" id="ox-panel-ai" role="tabpanel" aria-labelledby="ox-tab-ai" tabindex="0">
              <label class="ox-field">
                <span class="ox-field__label">What should I build?</span>
                <textarea class="ox-textarea ox-textarea--prompt" data-ox-prompt placeholder="e.g. Pricing section with three tiers, monthly/yearly toggle, and a featured plan…"></textarea>
              </label>

              <label class="ox-field">
                <span class="ox-field__label">Site inspiration (optional)</span>
                <select class="ox-select" data-ox-site-inspiration>
                  <option value="">No inspiration</option>
                </select>
              </label>

              <details class="ox-disclosure">
                <summary>Refine with Plan or Triple Shot</summary>
                <div class="ox-disclosure__body">
                  <p class="ox-field__hint">Plan Mode asks clarifying questions first. Triple Shot returns three variations to pick from.</p>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="ox-btn ox-btn--soft" data-ox-plan>Plan first</button>
                    <button type="button" class="ox-btn ox-btn--soft" data-ox-triple-shot>Triple Shot</button>
                  </div>
                </div>
              </details>

              <div class="ox-plan" data-ox-plan-result hidden></div>
              <div class="ox-variants" data-ox-variants hidden></div>
            </section>

            <!-- PASTE MODE -->
            <section class="ox-mode-panel" data-ox-mode-panel="paste" id="ox-panel-paste" role="tabpanel" aria-labelledby="ox-tab-paste" tabindex="0" hidden>
              <div class="ox-source">
                <div class="ox-source__tabs" role="tablist" aria-label="Source language">
                  <button type="button" class="ox-source__tab is-active" role="tab" id="ox-source-tab-html" aria-controls="ox-source-panel-html" aria-selected="true" tabindex="0" data-ox-source-tab="html">HTML</button>
                  <button type="button" class="ox-source__tab" role="tab" id="ox-source-tab-css" aria-controls="ox-source-panel-css" aria-selected="false" tabindex="-1" data-ox-source-tab="css">CSS</button>
                  <button type="button" class="ox-source__tab" role="tab" id="ox-source-tab-js" aria-controls="ox-source-panel-js" aria-selected="false" tabindex="-1" data-ox-source-tab="js">JS</button>
                </div>
                <div class="ox-source__panel" data-ox-source-panel="html" id="ox-source-panel-html" role="tabpanel" aria-labelledby="ox-source-tab-html">
                  <textarea class="ox-textarea ox-textarea--code" data-ox-html placeholder="Paste HTML here…"></textarea>
                </div>
                <div class="ox-source__panel" data-ox-source-panel="css" id="ox-source-panel-css" role="tabpanel" aria-labelledby="ox-source-tab-css" hidden>
                  <textarea class="ox-textarea ox-textarea--code" data-ox-css placeholder="Optional CSS — kept as CSS Code when needed."></textarea>
                </div>
                <div class="ox-source__panel" data-ox-source-panel="js" id="ox-source-panel-js" role="tabpanel" aria-labelledby="ox-source-tab-js" hidden>
                  <textarea class="ox-textarea ox-textarea--code" data-ox-js placeholder="Optional JavaScript."></textarea>
                </div>
              </div>

              <details class="ox-disclosure">
                <summary>Conversion options</summary>
                <div class="ox-disclosure__body">
                  <p class="ox-field__hint">Defaults work for most pasted markup.</p>
                  <div class="ox-chips">
                    <label class="ox-chip"><input type="checkbox" data-ox-safe> Safe mode</label>
                    <label class="ox-chip"><input type="checkbox" data-ox-inline checked> Map styles</label>
                    <label class="ox-chip"><input type="checkbox" data-ox-css-code checked> Keep CSS</label>
                    <label class="ox-chip"><input type="checkbox" data-ox-selectors checked> Register classes</label>
                  </div>
                </div>
              </details>
            </section>

            <!-- EDIT MODE -->
            <section class="ox-mode-panel" data-ox-mode-panel="edit" id="ox-panel-edit" role="tabpanel" aria-labelledby="ox-tab-edit" tabindex="0" hidden>
              <div class="ox-target">
                <div class="ox-target__head">
                  <div style="flex:1;min-width:0;">
                    <span class="ox-target__eyebrow">Target</span>
                    <p class="ox-target__summary" data-ox-target-summary>No element captured yet.</p>
                  </div>
                </div>
                <div class="ox-target__actions">
                  <select class="ox-select" data-ox-target-mode>
                    <option value="selected">Selected</option>
                    <option value="page">Whole page</option>
                    <option value="custom">Custom only</option>
                  </select>
                  <button type="button" class="ox-btn ox-btn--soft" data-ox-capture-target>Capture target</button>
                </div>
              </div>

              <label class="ox-field">
                <span class="ox-field__label">What should change?</span>
                <textarea class="ox-textarea" data-ox-prompt-edit placeholder="e.g. Make this hero more premium but keep the CTA and classes."></textarea>
              </label>

              <label class="ox-field">
                <span class="ox-field__label">Context notes (optional)</span>
                <textarea class="ox-textarea" data-ox-context-notes placeholder="What to preserve, what should not change…" style="min-height:60px;"></textarea>
              </label>

              <details class="ox-disclosure">
                <summary>Chat with OxyAI about this element</summary>
                <div class="ox-disclosure__body">
                  <div class="ox-chat" data-ox-chat-log aria-live="polite"></div>
                  <div class="ox-chat-input">
                    <textarea class="ox-textarea" data-ox-chat-input placeholder="Ask for refinements…"></textarea>
                    <div class="ox-chat-input__actions">
                      <button type="button" class="ox-btn" data-ox-chat-send>Send</button>
                      <button type="button" class="ox-btn ox-btn--soft" data-ox-chat-apply>Apply as replacement</button>
                    </div>
                  </div>
                </div>
              </details>
            </section>

            <!-- SHARED: paste-mode also benefits from options; edit-mode reuses options from paste -->
          </div>

          <footer class="ox-panel__footer">
            <!-- Primary action per mode -->
            <div class="ox-footer-row" data-ox-footer="ai">
              <button type="button" class="ox-btn ox-btn--primary ox-btn--block" data-ox-generate>Generate source →</button>
            </div>
            <div class="ox-footer-row" data-ox-footer="paste" hidden>
              <button type="button" class="ox-btn ox-btn--primary ox-btn--block" data-ox-convert>Convert & Insert →</button>
            </div>
            <div class="ox-footer-row" data-ox-footer="edit" hidden>
              <button type="button" class="ox-btn ox-btn--primary ox-btn--block" data-ox-edit>Generate replacement →</button>
            </div>

            <details class="ox-disclosure">
              <summary>More actions</summary>
              <div class="ox-disclosure__body">
                <div class="ox-footer-secondary">
                  <select class="ox-select" data-ox-apply-operation>
                    <option value="append">Append to page</option>
                    <option value="replace_node">Replace captured node</option>
                    <option value="replace">Replace whole page</option>
                  </select>
                  <div class="ox-footer-secondary__buttons">
                    <button type="button" class="ox-btn ox-btn--success" data-ox-direct-apply>Save to page</button>
                    <button type="button" class="ox-btn ox-btn--ghost" data-ox-copy>Copy JSON</button>
                  </div>
                </div>
                <p class="ox-help"><strong>Save to page</strong> writes Oxygen data directly and creates an automatic restore backup.</p>
              </div>
            </details>
          </footer>

        </aside>
      </div>
    `;
  }

  function buildModal() {
    ensureParentStyles();

    if (modal || parentDoc.getElementById("oxyai-builder-modal")) {
      modal = parentDoc.getElementById("oxyai-builder-modal");
      return modal;
    }

    modal = parentDoc.createElement("div");
    modal.id = "oxyai-builder-modal";
    modal.innerHTML = panelTemplate();
    parentDoc.body.appendChild(modal);

    // Close handlers
    modal.querySelectorAll("[data-ox-close]").forEach((button) => {
      button.addEventListener("click", close);
    });

    // Mode tabs
    modal.querySelectorAll("[data-ox-mode-tab]").forEach((button) => {
      button.addEventListener("click", function () {
        setMode(button.getAttribute("data-ox-mode-tab") || "ai");
      });
    });

    // Source tabs (paste mode)
    modal.querySelectorAll("[data-ox-source-tab]").forEach((button) => {
      button.addEventListener("click", function () {
        setSourceTab(button.getAttribute("data-ox-source-tab") || "html");
      });
    });

    // Arrow-key navigation across role="tablist" groups
    modal.querySelectorAll("[role='tablist']").forEach((tablist) => {
      tablist.addEventListener("keydown", handleTablistKeys);
    });

    // Actions
    field("[data-ox-generate]").addEventListener("click", runGenerate);
    field("[data-ox-plan]").addEventListener("click", runPlan);
    field("[data-ox-triple-shot]").addEventListener("click", runTripleShot);
    field("[data-ox-edit]").addEventListener("click", runEditSelected);
    field("[data-ox-convert]").addEventListener("click", runConvert);
    field("[data-ox-copy]").addEventListener("click", copyJson);
    field("[data-ox-capture-target]").addEventListener("click", captureTarget);
    field("[data-ox-chat-send]").addEventListener("click", sendChat);
    field("[data-ox-chat-apply]").addEventListener("click", applyChatReplacement);
    field("[data-ox-handoff-load]").addEventListener("click", loadHandoff);
    field("[data-ox-handoff-apply]").addEventListener("click", applyHandoff);
    field("[data-ox-handoff-save]").addEventListener("click", saveHandoffToPage);
    field("[data-ox-direct-apply]").addEventListener("click", saveSourceToPage);

    // Keyboard shortcuts inside panel
    modal.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        close();
        return;
      }
      if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
        event.preventDefault();
        triggerPrimaryAction();
      }
    });

    return modal;
  }

  function triggerPrimaryAction() {
    if (currentMode === "ai") return runGenerate();
    if (currentMode === "paste") return runConvert();
    if (currentMode === "edit") return runEditSelected();
    return null;
  }

  // ===== HELPERS =====

  function field(selector) {
    return modal.querySelector(selector);
  }

  function source() {
    return {
      html: (field("[data-ox-html]")?.value || "").trim(),
      css: (field("[data-ox-css]")?.value || "").trim(),
      js: (field("[data-ox-js]")?.value || "").trim(),
    };
  }

  function options() {
    return {
      safeMode: !!field("[data-ox-safe]")?.checked,
      inlineStyles: !!field("[data-ox-inline]")?.checked,
      includeCssElement: !!field("[data-ox-css-code]")?.checked,
      useSelectors: !!field("[data-ox-selectors]")?.checked,
      wrapInContainer: true,
    };
  }

  function getPrompt(forEdit) {
    if (forEdit) {
      return (field("[data-ox-prompt-edit]")?.value || "").trim();
    }
    return (field("[data-ox-prompt]")?.value || "").trim();
  }

  function aiInput(prompt) {
    return {
      prompt: prompt || getPrompt(currentMode === "edit"),
      siteInspiration: field("[data-ox-site-inspiration]")?.value || "",
    };
  }

  function status(message, level) {
    const el = field("[data-ox-status]");
    if (!el) return;
    el.textContent = message || (strings.ready || "Ready");
    el.classList.remove("is-working", "is-success", "is-error");
    if (level === "working") el.classList.add("is-working");
    else if (level === "success") el.classList.add("is-success");
    else if (level === "error") el.classList.add("is-error");
  }

  // ===== HANDOFF =====

  async function refreshHandoff() {
    currentPostId = detectCurrentPostId();
    if (!currentPostId) return;

    try {
      const response = await fetch(config.restUrl + "/codex/page/" + currentPostId, {
        credentials: "same-origin",
        headers: { "x-wp-nonce": config.nonce },
      });
      if (!response.ok) return;
      const data = await response.json();
      const handoff = data.handoff || null;
      const panel = field("[data-ox-handoff]");
      if (!panel) return;
      panel.hidden = !handoff;
      if (handoff) {
        panel.__oxyaiHandoff = handoff;
        field("[data-ox-handoff-summary]").textContent =
          "Payload staged " + (handoff.createdAt || "") + (handoff.prompt ? " · " + handoff.prompt.slice(0, 80) : "");
      }
    } catch (error) {
      // best-effort
    }
  }

  async function refreshSiteInspirations() {
    const select = field("[data-ox-site-inspiration]");
    if (!select) return;

    try {
      const response = await fetch(config.restUrl + "/site-inspirations", {
        credentials: "same-origin",
        headers: { "x-wp-nonce": config.nonce },
      });
      const data = await response.json();
      const inspirations = Array.isArray(data.siteInspirations) ? data.siteInspirations : [];
      if (!inspirations.length) return;

      const current = select.value;
      select.innerHTML = `<option value="">No inspiration</option>` + inspirations.map((item) => {
        const slug = item.slug || item.id || "";
        const label = item.name || item.title || slug;
        return `<option value="${escapeAttribute(slug)}">${escapeHtml(label)}</option>`;
      }).join("");
      select.value = inspirations.some((item) => (item.slug || item.id || "") === current) ? current : "";
    } catch (error) {
      // keep defaults
    }
  }

  function loadHandoff() {
    const handoff = field("[data-ox-handoff]")?.__oxyaiHandoff;
    if (!handoff || !handoff.source) return;
    if (field("[data-ox-html]")) field("[data-ox-html]").value = handoff.source.html || "";
    if (field("[data-ox-css]")) field("[data-ox-css]").value = handoff.source.css || "";
    if (field("[data-ox-js]")) field("[data-ox-js]").value = handoff.source.js || "";
    setMode("paste");
    status("Codex handoff loaded into source fields.", "success");
  }

  async function applyHandoff() {
    loadHandoff();
    await runConvert();
  }

  async function saveHandoffToPage() {
    loadHandoff();
    await saveSourceToPage();
  }

  // ===== MODE / TABS =====

  function setMode(mode) {
    currentMode = mode || "ai";

    modal.querySelectorAll("[data-ox-mode-tab]").forEach((button) => {
      const active = button.getAttribute("data-ox-mode-tab") === currentMode;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
      button.setAttribute("tabindex", active ? "0" : "-1");
    });

    modal.querySelectorAll("[data-ox-mode-panel]").forEach((panel) => {
      panel.hidden = panel.getAttribute("data-ox-mode-panel") !== currentMode;
    });

    modal.querySelectorAll("[data-ox-footer]").forEach((row) => {
      row.hidden = row.getAttribute("data-ox-footer") !== currentMode;
    });

    if (currentMode === "ai") field("[data-ox-prompt]")?.focus({ preventScroll: true });
    if (currentMode === "paste") field("[data-ox-html]")?.focus({ preventScroll: true });
    if (currentMode === "edit") {
      renderChat();
      field("[data-ox-prompt-edit]")?.focus({ preventScroll: true });
    }
  }

  function setSourceTab(tab) {
    modal.querySelectorAll("[data-ox-source-tab]").forEach((button) => {
      const active = button.getAttribute("data-ox-source-tab") === tab;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
      button.setAttribute("tabindex", active ? "0" : "-1");
    });
    modal.querySelectorAll("[data-ox-source-panel]").forEach((panel) => {
      panel.hidden = panel.getAttribute("data-ox-source-panel") !== tab;
    });
  }

  function handleTablistKeys(event) {
    const key = event.key;
    if (key !== "ArrowRight" && key !== "ArrowLeft" && key !== "Home" && key !== "End") {
      return;
    }
    const tablist = event.currentTarget;
    const tabs = Array.from(tablist.querySelectorAll("[role='tab']"));
    if (!tabs.length) return;
    const active = parentDoc.activeElement || currentDoc.activeElement;
    const currentIndex = tabs.indexOf(active);
    if (currentIndex < 0) return;
    let nextIndex = currentIndex;
    if (key === "ArrowRight") nextIndex = (currentIndex + 1) % tabs.length;
    if (key === "ArrowLeft") nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
    if (key === "Home") nextIndex = 0;
    if (key === "End") nextIndex = tabs.length - 1;
    event.preventDefault();
    tabs[nextIndex].focus();
    tabs[nextIndex].click();
  }

  // ===== ACTIONS =====

  async function runGenerate() {
    try {
      const input = aiInput();
      if (!input.prompt) {
        status("Write a prompt first.", "error");
        return;
      }
      status(strings.working || "Working…", "working");
      const data = await request("/generate", { ...input, context: getSelectedContext() });
      const generated = data.source || {};
      if (field("[data-ox-html]")) field("[data-ox-html]").value = generated.html || "";
      if (field("[data-ox-css]")) field("[data-ox-css]").value = generated.css || "";
      if (field("[data-ox-js]")) field("[data-ox-js]").value = generated.js || "";
      status(strings.generated || "Source generated — review in Paste.", "success");
      setMode("paste");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  async function runPlan() {
    try {
      const input = aiInput();
      if (!input.prompt) {
        status("Write a prompt before Plan Mode.", "error");
        return;
      }
      status("Planning…", "working");
      const data = await request("/plan", { ...input, context: getSelectedContext() });
      renderPlan(data.plan || {});
      status(data.plan?.status === "ready" ? "Plan ready." : "Answer the questions to refine.", "success");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  function renderPlan(plan) {
    const target = field("[data-ox-plan-result]");
    if (!target) return;
    const questions = Array.isArray(plan?.questions) ? plan.questions : [];
    target.hidden = false;
    target.innerHTML = `
      <div class="ox-plan__head">
        <strong>${escapeHtml(plan?.status === "ready" ? "Ready to generate" : "Plan questions")}</strong>
        <p>${escapeHtml(plan?.summary || "Review the plan before generating.")}</p>
      </div>
      ${questions.map(renderPlanQuestion).join("")}
      <div style="display:flex;justify-content:flex-end;">
        <button type="button" class="ox-btn ox-btn--primary" data-ox-use-plan>Use planned prompt</button>
      </div>
    `;
    target.querySelector("[data-ox-use-plan]")?.addEventListener("click", function () {
      const additions = collectPlanAnswers(target, questions);
      const readyPrompt = (plan?.readyPrompt || aiInput().prompt || "").trim();
      const next = [readyPrompt, additions ? "User choices:\n" + additions : ""].filter(Boolean).join("\n\n");
      if (field("[data-ox-prompt]")) field("[data-ox-prompt]").value = next;
      status("Plan applied — generate when ready.", "success");
    });
  }

  async function runTripleShot() {
    try {
      const input = aiInput();
      if (!input.prompt) {
        status("Write a prompt before Triple Shot.", "error");
        return;
      }
      status("Generating three variants…", "working");
      const data = await request("/triple-shot", { ...input, context: getSelectedContext() });
      renderVariants(data.variants || []);
      status("Three variants ready.", "success");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  function renderVariants(variants) {
    const target = field("[data-ox-variants]");
    if (!target) return;
    const normalized = Array.isArray(variants) ? variants : [];
    target.hidden = false;
    target.innerHTML = normalized.length
      ? normalized.map((variant, index) => `
          <div class="ox-variants__item">
            <div>
              <strong>${escapeHtml(variant.name || "Variant " + (index + 1))}</strong>
              <span>${escapeHtml(variant.slug || "")}</span>
            </div>
            <button type="button" class="ox-btn ox-btn--soft" data-ox-use-variant="${index}">Use</button>
          </div>
        `).join("")
      : "<p>No variants returned.</p>";
    target.querySelectorAll("[data-ox-use-variant]").forEach((button) => {
      button.addEventListener("click", function () {
        const variant = normalized[parseInt(button.getAttribute("data-ox-use-variant") || "0", 10)] || {};
        const generated = variant.source || {};
        if (field("[data-ox-html]")) field("[data-ox-html]").value = generated.html || "";
        if (field("[data-ox-css]")) field("[data-ox-css]").value = generated.css || "";
        if (field("[data-ox-js]")) field("[data-ox-js]").value = generated.js || "";
        setMode("paste");
        status("Variant loaded — convert & insert when ready.", "success");
      });
    });
  }

  function planQuestionId(question, index) {
    const src = String(question.id || question.label || "question");
    let hash = 0;
    for (let i = 0; i < src.length; i++) {
      hash = ((hash << 5) - hash + src.charCodeAt(i)) | 0;
    }
    return `q-${index}-${Math.abs(hash).toString(36)}`;
  }

  function renderPlanQuestion(question, index) {
    const id = planQuestionId(question, index);
    const originalId = question.id || question.label || "question";
    const type = question.type === "multi_choice" ? "checkbox" : "radio";
    const choices = Array.isArray(question.options) ? question.options : [];
    const controls = choices.length
      ? choices.map((option) => `<label class="ox-plan__choice"><input type="${type}" name="ox-plan-${id}" value="${escapeAttribute(option)}"> ${escapeHtml(option)}</label>`).join("")
      : `<input type="text" class="ox-input" data-ox-plan-text="${id}" placeholder="Type an answer…">`;
    return `
      <div class="ox-plan__q" data-ox-question="${id}" data-ox-question-id="${escapeAttribute(originalId)}">
        <strong>${escapeHtml(question.label || "Question")}</strong>
        ${question.why ? `<p>${escapeHtml(question.why)}</p>` : ""}
        <div class="ox-plan__choices">${controls}</div>
        ${question.allowCustom && choices.length ? `<input type="text" class="ox-input" data-ox-plan-custom="${id}" placeholder="Optional custom answer…">` : ""}
      </div>
    `;
  }

  function collectPlanAnswers(container, questions) {
    return questions.map((question, index) => {
      const id = planQuestionId(question, index);
      const section = container.querySelector(`[data-ox-question="${id}"]`);
      if (!section) return "";
      const checked = Array.from(section.querySelectorAll("input[type='radio']:checked, input[type='checkbox']:checked"))
        .map((input) => input.value)
        .filter(Boolean);
      const text = section.querySelector(`[data-ox-plan-text="${id}"]`)?.value || "";
      const custom = section.querySelector(`[data-ox-plan-custom="${id}"]`)?.value || "";
      const answer = checked.concat([text, custom]).map((value) => value.trim()).filter(Boolean).join(", ");
      return answer ? `${question.label}: ${answer}` : "";
    }).filter(Boolean).join("\n");
  }

  async function runConvert() {
    try {
      status(strings.working || "Working…", "working");
      const data = await request("/convert", { ...source(), options: options() });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status(strings.inserted || "Pasted into Oxygen.", "success");
        setTimeout(close, 400);
        return;
      }
      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.", "success");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  async function saveSourceToPage() {
    try {
      currentPostId = detectCurrentPostId();
      if (!currentPostId) {
        status("Could not detect this page's ID.", "error");
        return;
      }
      status("Saving to page…", "working");
      const payload = {
        ...source(),
        options: options(),
        operation: field("[data-ox-apply-operation]").value || "append",
        targetNodeId: selectedNodeIdForApply(),
      };
      const data = await request("/codex/page/" + currentPostId + "/apply", payload);
      status("Saved · backup " + (data.backupId || "created") + ".", "success");
      await refreshHandoff();
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  async function runEditSelected() {
    try {
      const prompt = getPrompt(true);
      if (!prompt) {
        status("Describe what should change.", "error");
        return;
      }
      status(strings.working || "Working…", "working");
      const data = await request("/generate-and-convert", {
        ...aiInput(prompt),
        context: getSelectedContext(),
        options: options(),
      });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status("Replacement pasted into Oxygen.", "success");
        setTimeout(close, 400);
        return;
      }
      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.", "success");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  function captureTarget() {
    const mode = field("[data-ox-target-mode]").value || "selected";
    capturedContext = getSelectedContext(mode);
    updateTargetSummary(capturedContext);
    appendChat("system", "Target captured — I'll use this Oxygen context for follow-up edits.");
  }

  async function sendChat() {
    const input = field("[data-ox-chat-input]");
    const message = input.value.trim();
    if (!message) {
      status("Write a chat message first.", "error");
      return;
    }

    input.value = "";
    appendChat("user", message);
    status(strings.working || "Working…", "working");

    try {
      const data = await request("/generate", {
        ...aiInput(buildChatPrompt(message, false)),
        context: buildChatContext(),
      });
      const generated = data.source || {};
      if (field("[data-ox-html]")) field("[data-ox-html]").value = generated.html || "";
      if (field("[data-ox-css]")) field("[data-ox-css]").value = generated.css || "";
      if (field("[data-ox-js]")) field("[data-ox-js]").value = generated.js || "";
      appendChat("assistant", "Updated HTML/CSS/JS in source fields. Switch to Paste to review, then Convert & Insert or Apply as replacement.");
      status(strings.generated || "Source generated.", "success");
    } catch (error) {
      appendChat("assistant", error?.message || strings.failed || "Request failed.");
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  async function applyChatReplacement() {
    const input = field("[data-ox-chat-input]");
    const message = input.value.trim() || "Apply the latest requested chat changes to the captured target.";
    if (input.value.trim()) {
      appendChat("user", message);
      input.value = "";
    }

    status(strings.working || "Working…", "working");
    try {
      const data = await request("/generate-and-convert", {
        ...aiInput(buildChatPrompt(message, true)),
        context: buildChatContext(),
        options: options(),
      });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      appendChat("assistant", "Replacement payload ready. I'll paste it into Oxygen or copy it as fallback.");
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status("Replacement pasted into Oxygen.", "success");
        return;
      }
      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.", "success");
    } catch (error) {
      appendChat("assistant", error?.message || strings.failed || "Request failed.");
      status(error?.message || strings.failed || "Request failed.", "error");
    }
  }

  function appendChat(role, content) {
    chatMessages.push({ role, content, at: new Date().toISOString() });
    chatMessages = chatMessages.slice(-20);
    renderChat();
  }

  function renderChat() {
    const log = field("[data-ox-chat-log]");
    if (!log) return;
    if (!chatMessages.length) {
      log.innerHTML = '<div class="ox-chat__empty">Capture a target and ask for a change.</div>';
      return;
    }
    log.innerHTML = chatMessages
      .map((message) => {
        const safeRole = String(message.role || "system").replace(/[^a-z]/g, "");
        return `<div class="ox-chat__msg ox-chat__msg--${escapeAttr(safeRole)}"><span class="ox-chat__role">${escapeHtml(message.role)}</span>${escapeHtml(message.content)}</div>`;
      })
      .join("");
    log.scrollTop = log.scrollHeight;
  }

  function buildChatPrompt(message, replacement) {
    const notes = field("[data-ox-context-notes]").value.trim();
    return [
      replacement ? "Create a replacement Oxygen-compatible source bundle for the captured target." : "Generate Oxygen-compatible HTML/CSS/JS for the requested builder change.",
      message,
      notes ? "User context notes: " + notes : "",
      "Preserve the target's intent, stable class names, links, and content unless the user explicitly asks to change them.",
      "Do not introduce dynamic WordPress bindings or server-side code.",
    ].filter(Boolean).join("\n\n");
  }

  function buildChatContext() {
    return {
      targetMode: field("[data-ox-target-mode]").value || "selected",
      captured: capturedContext || getSelectedContext(field("[data-ox-target-mode]").value || "selected"),
      notes: field("[data-ox-context-notes]").value.trim(),
      messages: chatMessages,
    };
  }

  function pasteIntoBuilder(json) {
    try {
      const data = new DataTransfer();
      data.setData("text/plain", json);
      const event = new ClipboardEvent("paste", {
        clipboardData: data,
        bubbles: true,
        cancelable: true,
      });
      const target = parentDoc.activeElement || parentDoc;
      target.dispatchEvent(event);
      if (!event.defaultPrevented) {
        parentDoc.dispatchEvent(event);
      }
      return event.defaultPrevented;
    } catch (error) {
      return false;
    }
  }

  async function copyJson() {
    if (!lastJson) {
      status("Convert first before copying JSON.", "error");
      return;
    }
    await navigator.clipboard.writeText(lastJson);
    status(strings.copied || "Copied to clipboard.", "success");
  }

  // ===== OPEN / CLOSE =====

  function open() {
    buildModal();
    const overlay = field(".ox-overlay");
    overlay.hidden = false;
    requestAnimationFrame(() => overlay.classList.add("is-open"));
    setMode("ai");
    refreshSiteInspirations();
    refreshHandoff();
  }

  function close() {
    if (!modal) return;
    const overlay = field(".ox-overlay");
    overlay.classList.remove("is-open");
    setTimeout(() => { overlay.hidden = true; }, 240);
  }

  // ===== LAUNCHER =====

  function buildButton() {
    if (parentDoc.getElementById("oxyai-builder-launch")) return;
    const button = parentDoc.createElement("button");
    button.id = "oxyai-builder-launch";
    button.type = "button";
    button.title = "OxyAI · Ctrl/⌘ ⇧ Y";
    button.innerHTML = "<span>OxyAI</span>";
    button.addEventListener("click", open);
    parentDoc.body.appendChild(button);
  }

  function ensureParentStyles() {
    if (parentDoc === currentDoc || !config.builderCssUrl || parentDoc.getElementById("oxyai-builder-css")) {
      return;
    }
    const link = parentDoc.createElement("link");
    link.id = "oxyai-builder-css";
    link.rel = "stylesheet";
    link.href = config.builderCssUrl;
    parentDoc.head.appendChild(link);
  }

  // ===== CONTEXT =====

  function getSelectedContext(mode) {
    if (mode === "custom") {
      return {
        mode,
        note: "Use only the user's context notes and chat messages. No Oxygen element context was requested.",
      };
    }

    try {
      const app = parentDoc.querySelector(".v-application");
      const store =
        app?.__vue__?.$store ||
        app?.__vue_app__?.config?.globalProperties?.$store ||
        parentWindow?.breakdanceStore ||
        null;

      if (!store) {
        return {
          mode: mode || "selected",
          note: "No Oxygen store was detected. Treat this as a fresh insertion unless user notes provide context.",
          domSelection: getDomSelectionContext(),
        };
      }

      const state = store.state || {};
      const selectedFromGetters =
        store.getters?.selectedNodeId ||
        store.getters?.["oxygen/selectedNodeId"] ||
        store.getters?.["builder/selectedNodeId"] ||
        null;
      const selectedNodeId =
        selectedFromGetters ||
        state.selectedNodeId ||
        state?.oxygen?.selectedNodeId ||
        state?.ui?.selectedNodeId ||
        state?.builder?.selectedNodeId ||
        null;

      return {
        mode: mode || "selected",
        selectedNodeId,
        postId: detectCurrentPostId(),
        selectedElement:
          state?.elements?.elements?.[selectedNodeId] ||
          state?.document?.nodes?.[selectedNodeId] ||
          state?.tree?.nodes?.[selectedNodeId] ||
          null,
        documentKeys: Object.keys(state).slice(0, 30),
        domSelection: getDomSelectionContext(),
        note:
          "Best-effort Oxygen selection context. Preserve dynamic WordPress bindings only if they are explicitly present; otherwise return a static replacement source bundle.",
      };
    } catch (error) {
      return {
        mode: mode || "selected",
        note: "Selection context extraction failed.",
        domSelection: getDomSelectionContext(),
      };
    }
  }

  function getDomSelectionContext() {
    try {
      const selection = parentWindow.getSelection ? String(parentWindow.getSelection()) : "";
      const active = parentDoc.activeElement;
      return {
        selectedText: selection.slice(0, 2000),
        activeTag: active?.tagName || "",
        activeId: active?.id || "",
        activeClass: active?.className || "",
      };
    } catch (error) {
      return {};
    }
  }

  function detectCurrentPostId() {
    const params = new URLSearchParams(parentWindow.location.search || window.location.search || "");
    const keys = ["post", "post_id", "id", "page_id", "ct_builder"];
    for (const key of keys) {
      const value = parseInt(params.get(key) || "", 10);
      if (Number.isFinite(value) && value > 0) {
        return value;
      }
    }
    const bodyPostId = parentDoc.body?.className?.match(/postid-(\d+)/);
    if (bodyPostId) {
      return parseInt(bodyPostId[1], 10);
    }
    return null;
  }

  function updateTargetSummary(context) {
    const target = field("[data-ox-target-summary]");
    if (!target) return;
    if (!context) {
      target.textContent = "No element captured yet.";
      return;
    }
    const parts = [
      context.mode || "selected",
      context.selectedNodeId ? "node " + context.selectedNodeId : "",
      context.domSelection?.activeTag ? "<" + String(context.domSelection.activeTag).toLowerCase() + ">" : "",
      context.domSelection?.selectedText ? "text selected" : "",
    ].filter(Boolean);
    target.textContent = parts.length ? parts.join(" · ") : "Context captured.";
  }

  function selectedNodeIdForApply() {
    if ((field("[data-ox-apply-operation]").value || "append") !== "replace_node") {
      return null;
    }
    const context = capturedContext || getSelectedContext("selected");
    const nodeId = parseInt(context?.selectedNodeId || "", 10);
    return Number.isFinite(nodeId) && nodeId > 0 ? nodeId : null;
  }

  // ===== ESCAPE =====

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/'/g, "&#039;");
  }

  function escapeAttr(value) {
    return String(value).replace(/[^a-z0-9_-]/gi, "");
  }

  // ===== INIT =====

  function init() {
    if (initialized) return;
    initialized = true;

    buildModal();
    buildButton();
    refreshHandoff();

    getListeningTargets().forEach((target) => {
      target.addEventListener("keydown", handleShortcut, true);
    });

    parentWindow.oxyaiOxygenOpen = open;
    window.oxyaiOxygenOpen = open;
  }

  function handleShortcut(event) {
    if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === "y") {
      event.preventDefault();
      event.stopPropagation();
      open();
    }
  }

  function getListeningTargets() {
    const targets = [window, currentDoc];
    if (parentWindow !== window) targets.push(parentWindow);
    if (parentDoc !== currentDoc) targets.push(parentDoc);
    return Array.from(new Set(targets)).filter(Boolean);
  }

  if (currentDoc.readyState === "loading") {
    currentDoc.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  if (parentDoc !== currentDoc) {
    if (parentDoc.readyState === "loading") {
      parentDoc.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
  }
})();
