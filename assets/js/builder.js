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

  function buildModal() {
    ensureParentStyles();

    if (modal || parentDoc.getElementById("oxyai-builder-modal")) {
      modal = parentDoc.getElementById("oxyai-builder-modal");
      return modal;
    }

    modal = parentDoc.createElement("div");
    modal.id = "oxyai-builder-modal";
    modal.innerHTML = `
      <div class="oxyai-builder-overlay" hidden>
        <div class="oxyai-builder-dialog" role="dialog" aria-modal="true" aria-labelledby="oxyai-builder-title">
          <div class="oxyai-builder-head">
            <div>
              <p>OxyAI Oxygen</p>
              <h2 id="oxyai-builder-title">Generate or Import</h2>
            </div>
            <button type="button" data-oxyai-close aria-label="Close">&times;</button>
          </div>
          <div class="oxyai-builder-modes">
            <button type="button" class="is-active" data-oxyai-builder-mode="paste">Paste code</button>
            <button type="button" data-oxyai-builder-mode="generate">Generate</button>
            <button type="button" data-oxyai-builder-mode="edit">Replace selected</button>
            <button type="button" data-oxyai-builder-mode="chat">Chat</button>
          </div>
          <p class="oxyai-builder-help" data-oxyai-help>Paste source code, generate with AI, or chat against the selected Oxygen element. Results stay editable after conversion.</p>
          <div class="oxyai-builder-direction">
            <label>Site inspiration
              <select data-oxyai-site-inspiration>
                <option value="">No inspiration</option>
                <option value="editorial-luxury">Editorial Luxury</option>
                <option value="technical-saas">Technical SaaS</option>
                <option value="warm-marketplace">Warm Marketplace</option>
                <option value="bold-launch">Bold Launch</option>
                <option value="minimal-product">Minimal Product</option>
              </select>
            </label>
            <div>
              <button type="button" data-oxyai-plan>Plan first</button>
              <button type="button" data-oxyai-triple-shot>Triple Shot</button>
            </div>
          </div>
          <div class="oxyai-builder-plan" data-oxyai-plan-result hidden></div>
          <div class="oxyai-builder-variants" data-oxyai-variants hidden></div>
          <section class="oxyai-builder-chat" data-oxyai-chat hidden>
            <div class="oxyai-builder-target">
              <div>
                <span>Target</span>
                <strong data-oxyai-target-summary>No element captured yet.</strong>
              </div>
              <div class="oxyai-builder-target-actions">
                <select data-oxyai-target-mode>
                  <option value="selected">Selected element</option>
                  <option value="page">Whole page context</option>
                  <option value="custom">Custom context only</option>
                </select>
                <button type="button" data-oxyai-capture-target>Capture target</button>
              </div>
            </div>
            <label>Context notes<textarea data-oxyai-context-notes placeholder="Optional: explain what this element is, what to preserve, or what should not change."></textarea></label>
            <div class="oxyai-builder-chat-log" data-oxyai-chat-log aria-live="polite"></div>
            <div class="oxyai-builder-chat-input">
              <textarea data-oxyai-chat-input placeholder="Ask OxyAI to modify the target, explain a change, or generate a new section..."></textarea>
              <div>
                <button type="button" data-oxyai-chat-send>Send</button>
                <button type="button" data-oxyai-chat-apply>Apply as replacement</button>
              </div>
            </div>
          </section>
          <label>AI prompt<textarea data-oxyai-prompt placeholder="Describe what to build or how to edit the selected section..."></textarea></label>
          <div class="oxyai-builder-grid">
            <label class="is-html">HTML<textarea data-oxyai-html placeholder="Paste HTML here..."></textarea></label>
            <label>CSS<textarea data-oxyai-css placeholder="Optional CSS..."></textarea></label>
            <label>JavaScript<textarea data-oxyai-js placeholder="Optional JS..."></textarea></label>
          </div>
          <div class="oxyai-builder-options">
            <label><input type="checkbox" data-oxyai-safe> Safe mode</label>
            <label><input type="checkbox" data-oxyai-inline checked> Map styles</label>
            <label><input type="checkbox" data-oxyai-css-code checked> Include CSS Code</label>
            <label><input type="checkbox" data-oxyai-selectors> Use selectors</label>
          </div>
          <div class="oxyai-builder-savebar">
            <label>Page action
              <select data-oxyai-apply-operation>
                <option value="append">Append to page</option>
                <option value="replace_node">Replace captured node</option>
                <option value="replace">Replace whole page</option>
              </select>
            </label>
            <p>Save to Page writes Oxygen data directly and creates an OxyAI restore backup.</p>
          </div>
          <div class="oxyai-builder-option-notes">
            <span>Safe mode strips scripts and handlers.</span>
            <span>Map styles writes supported CSS as Oxygen properties.</span>
            <span>CSS Code preserves complex selectors and media rules.</span>
            <span>Selectors preserves classes while selector-library registration is guarded.</span>
          </div>
          <div class="oxyai-builder-status" data-oxyai-status>Ready.</div>
          <section class="oxyai-codex-handoff" data-oxyai-handoff hidden>
            <div>
              <strong>Codex handoff ready</strong>
              <p data-oxyai-handoff-summary>Generated source is staged for this page.</p>
            </div>
            <div>
              <button type="button" data-oxyai-handoff-load>Load into fields</button>
              <button type="button" data-oxyai-handoff-apply>Convert & Insert</button>
              <button type="button" data-oxyai-handoff-save>Save to Page</button>
            </div>
          </section>
          <div class="oxyai-builder-actions">
            <button type="button" data-oxyai-generate>Generate</button>
            <button type="button" data-oxyai-edit>Generate Replacement</button>
            <button type="button" data-oxyai-convert>Convert & Insert</button>
            <button type="button" data-oxyai-direct-apply>Save to Page</button>
            <button type="button" data-oxyai-copy>Copy JSON</button>
          </div>
        </div>
      </div>
    `;
    parentDoc.body.appendChild(modal);

    modal.querySelector("[data-oxyai-close]").addEventListener("click", close);
    modal.querySelector(".oxyai-builder-overlay").addEventListener("click", function (event) {
      if (event.target === event.currentTarget) close();
    });
    modal.querySelector("[data-oxyai-generate]").addEventListener("click", runGenerate);
    modal.querySelector("[data-oxyai-plan]").addEventListener("click", runPlan);
    modal.querySelector("[data-oxyai-triple-shot]").addEventListener("click", runTripleShot);
    modal.querySelector("[data-oxyai-edit]").addEventListener("click", runEditSelected);
    modal.querySelector("[data-oxyai-convert]").addEventListener("click", runConvert);
    modal.querySelector("[data-oxyai-copy]").addEventListener("click", copyJson);
    modal.querySelector("[data-oxyai-capture-target]").addEventListener("click", captureTarget);
    modal.querySelector("[data-oxyai-chat-send]").addEventListener("click", sendChat);
    modal.querySelector("[data-oxyai-chat-apply]").addEventListener("click", applyChatReplacement);
    modal.querySelector("[data-oxyai-handoff-load]").addEventListener("click", loadHandoff);
    modal.querySelector("[data-oxyai-handoff-apply]").addEventListener("click", applyHandoff);
    modal.querySelector("[data-oxyai-handoff-save]").addEventListener("click", saveHandoffToPage);
    modal.querySelector("[data-oxyai-direct-apply]").addEventListener("click", saveSourceToPage);
    modal.querySelectorAll("[data-oxyai-builder-mode]").forEach((button) => {
      button.addEventListener("click", function () {
        setMode(button.getAttribute("data-oxyai-builder-mode") || "paste");
      });
    });

    return modal;
  }

  function field(selector) {
    return modal.querySelector(selector);
  }

  function source() {
    return {
      html: field("[data-oxyai-html]").value.trim(),
      css: field("[data-oxyai-css]").value.trim(),
      js: field("[data-oxyai-js]").value.trim(),
    };
  }

  function options() {
    return {
      safeMode: field("[data-oxyai-safe]").checked,
      inlineStyles: field("[data-oxyai-inline]").checked,
      includeCssElement: field("[data-oxyai-css-code]").checked,
      useSelectors: field("[data-oxyai-selectors]").checked,
      wrapInContainer: true,
    };
  }

  function aiInput(prompt) {
    return {
      prompt: prompt || field("[data-oxyai-prompt]").value.trim(),
      siteInspiration: field("[data-oxyai-site-inspiration]")?.value || "",
    };
  }

  function status(message, isError) {
    const el = field("[data-oxyai-status]");
    el.textContent = message;
    el.classList.toggle("is-error", !!isError);
  }

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
      const panel = field("[data-oxyai-handoff]");
      if (!panel) return;
      panel.hidden = !handoff;
      if (handoff) {
        panel.__oxyaiHandoff = handoff;
        field("[data-oxyai-handoff-summary]").textContent =
          "Payload staged " + (handoff.createdAt || "") + (handoff.prompt ? " from prompt: " + handoff.prompt.slice(0, 90) : "");
      }
    } catch (error) {
      // Handoff detection is best-effort and should never block the builder.
    }
  }

  function loadHandoff() {
    const handoff = field("[data-oxyai-handoff]")?.__oxyaiHandoff;
    if (!handoff || !handoff.source) return;
    field("[data-oxyai-html]").value = handoff.source.html || "";
    field("[data-oxyai-css]").value = handoff.source.css || "";
    field("[data-oxyai-js]").value = handoff.source.js || "";
    setMode("paste");
    status("Codex handoff loaded into source fields.");
  }

  async function applyHandoff() {
    loadHandoff();
    await runConvert();
  }

  async function saveHandoffToPage() {
    loadHandoff();
    await saveSourceToPage();
  }

  function setMode(mode) {
    modal.querySelectorAll("[data-oxyai-builder-mode]").forEach((button) => {
      button.classList.toggle("is-active", button.getAttribute("data-oxyai-builder-mode") === mode);
    });

    const help = field("[data-oxyai-help]");
    if (mode === "generate") {
      help.textContent = "Write a prompt, generate strict HTML/CSS/JS, then convert the result into Oxygen.";
      field("[data-oxyai-chat]").hidden = true;
      field("[data-oxyai-prompt]").focus();
      return;
    }
    if (mode === "edit") {
      help.textContent = "Select a static Oxygen subtree, describe the change, then generate a replacement payload.";
      field("[data-oxyai-chat]").hidden = true;
      field("[data-oxyai-prompt]").focus();
      return;
    }
    if (mode === "chat") {
      help.textContent = "Chat with OxyAI using the selected Oxygen element as context. Capture a target, then ask for changes.";
      field("[data-oxyai-chat]").hidden = false;
      renderChat();
      field("[data-oxyai-chat-input]").focus();
      return;
    }
    field("[data-oxyai-chat]").hidden = true;
    help.textContent = "Paste source code, then Convert & Insert. Use Safe mode for untrusted snippets.";
    field("[data-oxyai-html]").focus();
  }

  async function runGenerate() {
    try {
      status(strings.working || "Working...");
      const prompt = field("[data-oxyai-prompt]").value.trim();
      const data = await request("/generate", { ...aiInput(prompt), context: getSelectedContext() });
      const generated = data.source || {};
      field("[data-oxyai-html]").value = generated.html || "";
      field("[data-oxyai-css]").value = generated.css || "";
      field("[data-oxyai-js]").value = generated.js || "";
      status(strings.generated || "AI source generated.");
      setMode("paste");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  async function runPlan() {
    try {
      const input = aiInput();
      if (!input.prompt) {
        status("Write a prompt before using Plan Mode.", true);
        return;
      }

      status("Planning generation...");
      const data = await request("/plan", { ...input, context: getSelectedContext() });
      renderPlan(data.plan || {});
      status(data.plan?.status === "ready" ? "Plan is ready." : "Plan questions ready.");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  function renderPlan(plan) {
    const target = field("[data-oxyai-plan-result]");
    if (!target) return;
    const questions = Array.isArray(plan?.questions) ? plan.questions : [];
    target.hidden = false;
    target.innerHTML = `
      <strong>${escapeHtml(plan?.status === "ready" ? "Ready to generate" : "Answer before generating")}</strong>
      <p>${escapeHtml(plan?.summary || "Review the plan before generating.")}</p>
      ${questions.map(renderPlanQuestion).join("")}
      <button type="button" data-oxyai-use-plan>Use planned prompt</button>
    `;
    target.querySelector("[data-oxyai-use-plan]")?.addEventListener("click", function () {
      const additions = collectPlanAnswers(target, questions);
      const readyPrompt = (plan?.readyPrompt || aiInput().prompt || "").trim();
      field("[data-oxyai-prompt]").value = [readyPrompt, additions ? "User choices:\n" + additions : ""].filter(Boolean).join("\n\n");
      status("Plan applied to the prompt.");
    });
  }

  async function runTripleShot() {
    try {
      const input = aiInput();
      if (!input.prompt) {
        status("Write a prompt before using Triple Shot.", true);
        return;
      }

      status("Generating three variants...");
      const data = await request("/triple-shot", { ...input, context: getSelectedContext() });
      renderVariants(data.variants || []);
      status("Triple Shot variants ready.");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  function renderVariants(variants) {
    const target = field("[data-oxyai-variants]");
    if (!target) return;
    const normalized = Array.isArray(variants) ? variants : [];
    target.hidden = false;
    target.innerHTML = normalized.length
      ? normalized.map((variant, index) => `
          <section>
            <div>
              <strong>${escapeHtml(variant.name || "Variant " + (index + 1))}</strong>
              <span>${escapeHtml(variant.slug || "")}</span>
            </div>
            <button type="button" data-oxyai-use-variant="${index}">Use</button>
          </section>
        `).join("")
      : "<p>No variants returned.</p>";
    target.querySelectorAll("[data-oxyai-use-variant]").forEach((button) => {
      button.addEventListener("click", function () {
        const variant = normalized[parseInt(button.getAttribute("data-oxyai-use-variant") || "0", 10)] || {};
        const generated = variant.source || {};
        field("[data-oxyai-html]").value = generated.html || "";
        field("[data-oxyai-css]").value = generated.css || "";
        field("[data-oxyai-js]").value = generated.js || "";
        setMode("paste");
        status("Variant loaded into source fields.");
      });
    });
  }

  function renderPlanQuestion(question) {
    const id = escapeAttr(question.id || question.label || "question");
    const type = question.type === "multi_choice" ? "checkbox" : "radio";
    const options = Array.isArray(question.options) ? question.options : [];
    const controls = options.length
      ? options.map((option, index) => `<label><input type="${type}" name="oxyai-builder-plan-${id}" value="${escapeHtml(option)}"${index === 0 && type === "radio" ? " checked" : ""}> ${escapeHtml(option)}</label>`).join("")
      : `<input type="text" data-oxyai-plan-text="${id}" placeholder="Type an answer...">`;
    return `
      <section data-oxyai-question="${id}">
        <span>${escapeHtml(question.label || "Question")}</span>
        ${question.why ? `<p>${escapeHtml(question.why)}</p>` : ""}
        <div>${controls}</div>
        ${question.allowCustom && options.length ? `<input type="text" data-oxyai-plan-custom="${id}" placeholder="Optional custom answer...">` : ""}
      </section>
    `;
  }

  function collectPlanAnswers(container, questions) {
    return questions.map((question) => {
      const id = escapeAttr(question.id || question.label || "question");
      const section = container.querySelector(`[data-oxyai-question="${id}"]`);
      if (!section) return "";
      const checked = Array.from(section.querySelectorAll("input[type='radio']:checked, input[type='checkbox']:checked"))
        .map((input) => input.value)
        .filter(Boolean);
      const text = section.querySelector(`[data-oxyai-plan-text="${id}"]`)?.value || "";
      const custom = section.querySelector(`[data-oxyai-plan-custom="${id}"]`)?.value || "";
      const answer = checked.concat([text, custom]).map((value) => value.trim()).filter(Boolean).join(", ");
      return answer ? `${question.label}: ${answer}` : "";
    }).filter(Boolean).join("\n");
  }

  async function runConvert() {
    try {
      status(strings.working || "Working...");
      const data = await request("/convert", { ...source(), options: options() });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status(strings.inserted || "Converted JSON pasted into Oxygen.");
        close();
        return;
      }

      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  async function saveSourceToPage() {
    try {
      currentPostId = detectCurrentPostId();
      if (!currentPostId) {
        status("Could not detect the current WordPress page ID.", true);
        return;
      }

      status("Saving converted Oxygen data to this page...");
      const payload = {
        ...source(),
        options: options(),
        operation: field("[data-oxyai-apply-operation]").value || "append",
        targetNodeId: selectedNodeIdForApply(),
      };
      const data = await request("/codex/page/" + currentPostId + "/apply", payload);
      status(
        "Saved to page. Backup " + (data.backupId || "created") + ". Refresh Oxygen if the canvas does not update immediately."
      );
      await refreshHandoff();
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  async function runEditSelected() {
    try {
      status(strings.working || "Working...");
      const prompt = field("[data-oxyai-prompt]").value.trim();
      const data = await request("/generate-and-convert", {
        ...aiInput(prompt),
        context: getSelectedContext(),
        options: options(),
      });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status("Replacement payload pasted into Oxygen.");
        close();
        return;
      }
      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.");
    } catch (error) {
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  function captureTarget() {
    const mode = field("[data-oxyai-target-mode]").value || "selected";
    capturedContext = getSelectedContext(mode);
    updateTargetSummary(capturedContext);
    appendChat("system", "Target captured. I will use this Oxygen context for follow-up edits.");
  }

  async function sendChat() {
    const input = field("[data-oxyai-chat-input]");
    const message = input.value.trim();
    if (!message) {
      status("Write a chat message first.", true);
      return;
    }

    input.value = "";
    appendChat("user", message);
    status(strings.working || "Working...");

    try {
      const data = await request("/generate", {
        ...aiInput(buildChatPrompt(message, false)),
        context: buildChatContext(),
      });
      const generated = data.source || {};
      field("[data-oxyai-html]").value = generated.html || "";
      field("[data-oxyai-css]").value = generated.css || "";
      field("[data-oxyai-js]").value = generated.js || "";
      appendChat("assistant", "I generated updated HTML/CSS/JS in the source fields. Review it, then Convert & Insert or Apply as replacement.");
      status(strings.generated || "AI source generated.");
    } catch (error) {
      appendChat("assistant", error?.message || strings.failed || "Request failed.");
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  async function applyChatReplacement() {
    const input = field("[data-oxyai-chat-input]");
    const message = input.value.trim() || "Apply the latest requested chat changes to the captured target.";
    if (input.value.trim()) {
      appendChat("user", message);
      input.value = "";
    }

    status(strings.working || "Working...");
    try {
      const data = await request("/generate-and-convert", {
        ...aiInput(buildChatPrompt(message, true)),
        context: buildChatContext(),
        options: options(),
      });
      const oxygen = data.oxygen || {};
      lastJson = oxygen.rawJson || JSON.stringify({ element: oxygen.element || null });
      appendChat("assistant", "Replacement payload is ready. I will paste it into Oxygen or copy it as a fallback.");
      const inserted = pasteIntoBuilder(lastJson);
      if (inserted) {
        status("Replacement payload pasted into Oxygen.");
        return;
      }
      await navigator.clipboard.writeText(lastJson);
      status(strings.copied || "Copied to clipboard.");
    } catch (error) {
      appendChat("assistant", error?.message || strings.failed || "Request failed.");
      status(error?.message || strings.failed || "Request failed.", true);
    }
  }

  function appendChat(role, content) {
    chatMessages.push({
      role,
      content,
      at: new Date().toISOString(),
    });
    chatMessages = chatMessages.slice(-20);
    renderChat();
  }

  function renderChat() {
    const log = field("[data-oxyai-chat-log]");
    if (!log) return;
    if (!chatMessages.length) {
      log.innerHTML = '<div class="oxyai-chat-empty">Capture a target and ask for a change. Example: "Make this hero more premium but keep the CTA and classes."</div>';
      return;
    }
    log.innerHTML = chatMessages
      .map((message) => `<div class="oxyai-chat-message is-${escapeAttr(message.role)}"><strong>${escapeHtml(message.role)}</strong><p>${escapeHtml(message.content)}</p></div>`)
      .join("");
    log.scrollTop = log.scrollHeight;
  }

  function buildChatPrompt(message, replacement) {
    const notes = field("[data-oxyai-context-notes]").value.trim();
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
      targetMode: field("[data-oxyai-target-mode]").value || "selected",
      captured: capturedContext || getSelectedContext(field("[data-oxyai-target-mode]").value || "selected"),
      notes: field("[data-oxyai-context-notes]").value.trim(),
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
      status("Convert first before copying JSON.", true);
      return;
    }
    await navigator.clipboard.writeText(lastJson);
    status(strings.copied || "Copied to clipboard.");
  }

  function open() {
    buildModal();
    field(".oxyai-builder-overlay").hidden = false;
    setMode("paste");
    refreshHandoff();
    field("[data-oxyai-html]").focus();
  }

  function close() {
    if (!modal) return;
    field(".oxyai-builder-overlay").hidden = true;
  }

  function buildButton() {
    if (parentDoc.getElementById("oxyai-builder-launch")) return;
    const button = parentDoc.createElement("button");
    button.id = "oxyai-builder-launch";
    button.type = "button";
    button.textContent = "OxyAI";
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
    const target = field("[data-oxyai-target-summary]");
    if (!target) return;
    if (!context) {
      target.textContent = "No element captured yet.";
      return;
    }
    const parts = [
      context.mode || "selected",
      context.selectedNodeId ? "node " + context.selectedNodeId : "",
      context.domSelection?.activeTag ? String(context.domSelection.activeTag).toLowerCase() : "",
      context.domSelection?.selectedText ? "text selected" : "",
    ].filter(Boolean);
    target.textContent = parts.length ? parts.join(" / ") : "Context captured.";
  }

  function selectedNodeIdForApply() {
    if ((field("[data-oxyai-apply-operation]").value || "append") !== "replace_node") {
      return null;
    }

    const context = capturedContext || getSelectedContext("selected");
    const nodeId = parseInt(context?.selectedNodeId || "", 10);
    return Number.isFinite(nodeId) && nodeId > 0 ? nodeId : null;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function escapeAttr(value) {
    return String(value).replace(/[^a-z0-9_-]/gi, "");
  }

  function init() {
    if (initialized) {
      return;
    }
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
      if (event.key === "Escape" && modal && !field(".oxyai-builder-overlay").hidden) {
        close();
      }
  }

  function getListeningTargets() {
    const targets = [window, currentDoc];

    if (parentWindow !== window) {
      targets.push(parentWindow);
    }

    if (parentDoc !== currentDoc) {
      targets.push(parentDoc);
    }

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
