(function () {
  "use strict";

  const config = window.oxyaiOxygen || {};
  const strings = config.strings || {};
  const state = {
    lastJson: "",
    pagesLoaded: false,
    mode: "generate",
    operation: "append",
  };

  function $(id) {
    return document.getElementById(id);
  }

  function $$(selector, scope) {
    return Array.from((scope || document).querySelectorAll(selector));
  }

  // ===== STATUS =====

  function setStatus(message, level) {
    const el = $("oxyai-status");
    if (!el) return;
    el.textContent = message;
    el.classList.remove("is-working", "is-success", "is-error");
    if (level === "working") el.classList.add("is-working");
    else if (level === "success") el.classList.add("is-success");
    else if (level === "error") el.classList.add("is-error");
  }

  function setStatusReady() {
    setStatus(strings.ready || "Ready");
  }

  // ===== FORM DATA =====

  function source() {
    return {
      html: ($("oxyai-html")?.value || "").trim(),
      css: ($("oxyai-css")?.value || "").trim(),
      js: ($("oxyai-js")?.value || "").trim(),
    };
  }

  function options() {
    return {
      safeMode: !!$("oxyai-safe-mode")?.checked,
      inlineStyles: !!$("oxyai-inline-styles")?.checked,
      includeCssElement: !!$("oxyai-include-css")?.checked,
      useSelectors: !!$("oxyai-use-selectors")?.checked,
      wrapInContainer: true,
    };
  }

  function aiInput() {
    return {
      prompt: ($("oxyai-prompt")?.value || "").trim(),
      preset: $("oxyai-preset")?.value || "",
      siteInspiration: $("oxyai-site-inspiration")?.value || "",
    };
  }

  // ===== REQUEST =====

  async function request(path, payload, method) {
    const response = await fetch(config.restUrl + path, {
      method: method || "POST",
      credentials: "same-origin",
      headers: {
        "content-type": "application/json",
        "x-wp-nonce": config.nonce,
      },
      body: payload ? JSON.stringify(payload) : undefined,
    });
    const data = await response.json();
    if (!response.ok || data.success === false) {
      throw new Error(data.message || strings.failed || "Request failed.");
    }
    return data;
  }

  // ===== AUDIT =====

  const AUDIT_GROUPS = [
    { key: "preserved", label: "Preserved", icon: "✓" },
    { key: "transformed", label: "Transformed", icon: "↻" },
    { key: "stripped", label: "Stripped", icon: "⌫" },
    { key: "followUp", label: "Follow-up", icon: "→" },
  ];

  function renderAudit(audit) {
    const target = $("oxyai-audit");
    if (!target) return;
    const normalized = audit || {};
    target.innerHTML = AUDIT_GROUPS.map((group) => {
      const items = Array.isArray(normalized[group.key]) ? normalized[group.key] : [];
      const body = items.length
        ? `<ul class="ox-audit__items">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>`
        : '<p class="ox-audit__empty">Nothing notable.</p>';
      return `
        <div class="ox-audit__row" data-kind="${group.key}">
          <span class="ox-audit__badge" aria-hidden="true">${group.icon}</span>
          <div>
            <span class="ox-audit__title">${group.label}</span>
            ${body}
          </div>
        </div>
      `;
    }).join("");
  }

  function renderOxygen(oxygen) {
    const output = $("oxyai-output");
    const json = oxygen?.rawJson || JSON.stringify({ element: oxygen?.element || null }, null, 2);
    state.lastJson = json;
    if (output) output.textContent = json;
    renderAudit(oxygen?.audit || {});

    const disclosure = $("oxyai-output-disclosure");
    if (disclosure && !disclosure.open) {
      disclosure.open = true;
    }
  }

  // ===== PLAN / TRIPLE SHOT =====

  function renderPlan(plan) {
    const target = $("oxyai-plan");
    if (!target) return;
    const questions = Array.isArray(plan?.questions) ? plan.questions : [];
    target.hidden = false;
    target.innerHTML = `
      <div class="ox-plan__head">
        <strong>${escapeHtml(plan?.status === "ready" ? "Ready to generate" : "Plan questions")}</strong>
        <p>${escapeHtml(plan?.summary || "Review the plan before generating.")}</p>
      </div>
      ${questions.length ? `<div class="ox-plan__questions">${questions.map(renderPlanQuestion).join("")}</div>` : ""}
      <div class="ox-plan__actions">
        <button type="button" class="ox-btn ox-btn--primary" data-oxyai-use-plan>Use planned prompt</button>
      </div>
    `;

    target.querySelector("[data-oxyai-use-plan]")?.addEventListener("click", function () {
      const additions = collectPlanAnswers(target, questions);
      const readyPrompt = (plan?.readyPrompt || aiInput().prompt || "").trim();
      const nextPrompt = [readyPrompt, additions ? "User choices:\n" + additions : ""].filter(Boolean).join("\n\n");
      const prompt = $("oxyai-prompt");
      if (prompt) prompt.value = nextPrompt;
      setStatus("Plan applied — generate when ready.", "success");
    });
  }

  function renderVariants(variants) {
    const target = $("oxyai-variants");
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
            <button type="button" class="ox-btn ox-btn--soft" data-oxyai-use-variant="${index}">Use</button>
          </div>
        `).join("")
      : '<p class="ox-audit__empty">No variants returned.</p>';

    target.querySelectorAll("[data-oxyai-use-variant]").forEach((button) => {
      button.addEventListener("click", function () {
        const variant = normalized[parseInt(button.getAttribute("data-oxyai-use-variant") || "0", 10)] || {};
        const generated = variant.source || {};
        if ($("oxyai-html")) $("oxyai-html").value = generated.html || "";
        if ($("oxyai-css")) $("oxyai-css").value = generated.css || "";
        if ($("oxyai-js")) $("oxyai-js").value = generated.js || "";
        setStatus("Variant loaded — switch to Paste to review.", "success");
        setMode("paste");
      });
    });
  }

  function planQuestionId(question, index) {
    const source = String(question.id || question.label || "question");
    let hash = 0;
    for (let i = 0; i < source.length; i++) {
      hash = ((hash << 5) - hash + source.charCodeAt(i)) | 0;
    }
    return `q-${index}-${Math.abs(hash).toString(36)}`;
  }

  function renderPlanQuestion(question, index) {
    const id = planQuestionId(question, index);
    const originalId = question.id || question.label || "question";
    const type = question.type || "single_choice";
    const choices = Array.isArray(question.options) ? question.options : [];
    const inputs = choices.length
      ? choices.map((option) => {
          const inputType = type === "multi_choice" ? "checkbox" : "radio";
          return `<label class="ox-plan__choice"><input type="${inputType}" name="oxyai-plan-${id}" value="${escapeAttribute(option)}"> ${escapeHtml(option)}</label>`;
        }).join("")
      : `<input type="text" class="ox-input" data-oxyai-plan-text="${id}" placeholder="Type an answer…">`;

    return `
      <div class="ox-plan__q" data-oxyai-question="${id}" data-oxyai-question-id="${escapeAttribute(originalId)}" data-oxyai-question-label="${escapeAttribute(question.label || "")}">
        <strong>${escapeHtml(question.label || "Question")}</strong>
        ${question.why ? `<p>${escapeHtml(question.why)}</p>` : ""}
        <div class="ox-plan__choices">${inputs}</div>
        ${question.allowCustom && choices.length ? `<input type="text" class="ox-input" data-oxyai-plan-custom="${id}" placeholder="Optional custom answer…">` : ""}
      </div>
    `;
  }

  function collectPlanAnswers(container, questions) {
    return questions.map((question, index) => {
      const id = planQuestionId(question, index);
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

  // ===== MODE / TABS =====

  function setMode(mode) {
    state.mode = mode;
    $$("[data-ox-mode]").forEach((tab) => {
      const active = tab.getAttribute("data-ox-mode") === mode;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
      tab.setAttribute("tabindex", active ? "0" : "-1");
    });
    $$("[data-ox-panel]").forEach((panel) => {
      panel.hidden = panel.getAttribute("data-ox-panel") !== mode;
    });

    if (mode === "generate") $("oxyai-prompt")?.focus({ preventScroll: true });
    if (mode === "paste") $("oxyai-html")?.focus({ preventScroll: true });
    if (mode === "apply" && !state.pagesLoaded) {
      loadPages().catch((error) => setStatus(error?.message || "Failed to load pages.", "error"));
    }
  }

  function setSourceTab(tab) {
    $$("[data-oxyai-tab]").forEach((button) => {
      const active = button.getAttribute("data-oxyai-tab") === tab;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
      button.setAttribute("tabindex", active ? "0" : "-1");
    });
    $$("[data-oxyai-panel]").forEach((panel) => {
      panel.hidden = panel.getAttribute("data-oxyai-panel") !== tab;
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
    const currentIndex = tabs.indexOf(document.activeElement);
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

  function setOperation(operation) {
    state.operation = operation;
    $$("[data-ox-operation]").forEach((button) => {
      button.classList.toggle("is-active", button.getAttribute("data-ox-operation") === operation);
    });
    if ($("oxyai-page-operation")) $("oxyai-page-operation").value = operation;
  }

  // ===== PROVIDER =====

  function syncProviderFields() {
    const provider = $("oxyai-provider-select")?.value || "openai";
    $$("[data-oxyai-provider-fields]").forEach((group) => {
      group.hidden = group.getAttribute("data-oxyai-provider-fields") !== provider;
    });
  }

  function randomToken() {
    const bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    return "oxyai_" + Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  }

  function syncCodexUrl() {
    const token = $("oxyai-mcp-token")?.value || "";
    const target = $("oxyai-codex-url");
    if (!target) return;
    const base = target.getAttribute("data-base-url") || "";
    target.textContent = base + (base.includes("?") ? "&" : "?") + "oxyai_token=" + encodeURIComponent(token);
  }

  function regenerateToken() {
    const token = $("oxyai-mcp-token");
    if (!token) return;
    token.value = randomToken();
    syncCodexUrl();
    setStatus("New MCP token generated — save setup to keep it.", "success");
  }

  async function copyCodexUrl() {
    const value = $("oxyai-codex-url")?.textContent || "";
    if (!value) return;
    await navigator.clipboard.writeText(value);
    setStatus("Codex URL copied.", "success");
  }

  // ===== ACTIONS =====

  function renderPreview(data) {
    const audit = data.audit || {};
    const summary = data.summary || {};
    const count = summary.elementCount || 0;
    const types = Object.keys(summary.byType || {}).length;
    setStatus(`Preview · ${count} element${count === 1 ? "" : "s"}, ${types} type${types === 1 ? "" : "s"}`, "success");
    renderAudit(audit);
  }

  async function preview() {
    setStatus(strings.working || "Working…", "working");
    const data = await request("/preview", { ...source(), options: options() });
    renderPreview(data);
  }

  async function convert() {
    setStatus(strings.working || "Working…", "working");
    const data = await request("/convert", { ...source(), options: options() });
    renderOxygen(data.oxygen || {});
    setStatus(strings.converted || "Converted.", "success");
  }

  async function generate() {
    const input = aiInput();
    if (!input.prompt) {
      setStatus("Write a prompt first.", "error");
      return;
    }
    setStatus(strings.working || "Working…", "working");
    const data = await request("/generate", input);
    const generated = data.source || {};
    if ($("oxyai-html")) $("oxyai-html").value = generated.html || "";
    if ($("oxyai-css")) $("oxyai-css").value = generated.css || "";
    if ($("oxyai-js")) $("oxyai-js").value = generated.js || "";
    setStatus(strings.generated || "Source generated — review on the Paste tab.", "success");
    setMode("paste");
  }

  async function plan() {
    const input = aiInput();
    if (!input.prompt) {
      setStatus("Prompt is required for Plan Mode.", "error");
      return;
    }
    setStatus("Planning…", "working");
    const data = await request("/plan", input);
    renderPlan(data.plan || {});
    setStatus(data.plan?.status === "ready" ? "Plan ready." : "Answer the questions to refine.", "success");
  }

  async function tripleShot() {
    const input = aiInput();
    if (!input.prompt) {
      setStatus("Prompt is required for Triple Shot.", "error");
      return;
    }
    setStatus("Generating variants…", "working");
    const data = await request("/triple-shot", input);
    renderVariants(data.variants || []);
    setStatus("Three variants ready.", "success");
  }

  async function copyOutput() {
    if (!state.lastJson) {
      setStatus("Convert first before copying JSON.", "error");
      return;
    }
    await navigator.clipboard.writeText(state.lastJson);
    setStatus(strings.copied || "Copied to clipboard.", "success");
  }

  async function loadPages() {
    setStatus("Loading pages…", "working");
    const data = await request("/codex/pages", null, "GET");
    const select = $("oxyai-target-page");
    if (!select) return;
    const pages = Array.isArray(data.pages) ? data.pages : [];
    select.innerHTML = pages.length
      ? pages.map((page) => `<option value="${escapeAttr(page.id)}">${escapeHtml(page.title || "Untitled")} · #${escapeHtml(page.id)}</option>`).join("")
      : '<option value="">No pages found</option>';
    state.pagesLoaded = true;
    setStatus(pages.length ? "Pages loaded — choose a target." : "No editable pages found.", pages.length ? "success" : "error");
  }

  async function applyToPage(dryRun) {
    if (!state.pagesLoaded) {
      await loadPages();
    }
    const select = $("oxyai-target-page");
    const postId = parseInt(select?.value || "", 10);
    if (!Number.isFinite(postId) || postId < 1) {
      setStatus("Choose a target page first.", "error");
      return;
    }
    const payload = {
      ...source(),
      options: options(),
      operation: state.operation || "append",
      dryRun: !!dryRun,
    };
    setStatus(dryRun ? "Checking write…" : "Applying to page…", "working");
    const data = await request("/codex/page/" + postId + "/apply", payload);
    setStatus(
      dryRun
        ? `Dry run OK · ${data.afterNodeCount || 0} nodes after ${data.operation || "append"}.`
        : `Applied · backup ${data.backupId || "created"}, ${data.afterNodeCount || 0} nodes stored.`,
      "success"
    );
  }

  // ===== MODAL =====

  function openSetup() {
    const modal = $("oxyai-setup-modal");
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = "hidden";
    modal.querySelector("input, select, textarea")?.focus({ preventScroll: true });
  }

  function closeSetup() {
    const modal = $("oxyai-setup-modal");
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = "";
  }

  // ===== HELPERS =====

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

  function bind(id, handler) {
    const el = $(id);
    if (!el) return;
    el.addEventListener("click", async function (event) {
      event.preventDefault();
      try {
        await handler();
      } catch (error) {
        setStatus(error?.message || strings.failed || "Request failed.", "error");
      }
    });
  }

  // ===== INIT =====

  document.addEventListener("DOMContentLoaded", function () {
    bind("oxyai-run-preview", preview);
    bind("oxyai-run-convert", convert);
    bind("oxyai-run-generate", generate);
    bind("oxyai-run-plan", plan);
    bind("oxyai-run-triple-shot", tripleShot);
    bind("oxyai-copy-output", copyOutput);
    bind("oxyai-load-pages", loadPages);
    bind("oxyai-apply-page", function () { return applyToPage(false); });
    bind("oxyai-dry-run-page", function () { return applyToPage(true); });
    bind("oxyai-regenerate-token", regenerateToken);
    bind("oxyai-copy-codex-url", copyCodexUrl);

    $("oxyai-clear-prompt")?.addEventListener("click", function () {
      if ($("oxyai-prompt")) $("oxyai-prompt").value = "";
      $("oxyai-plan").hidden = true;
      $("oxyai-variants").hidden = true;
      setStatusReady();
    });

    // Mode tabs
    $$("[data-ox-mode]").forEach((tab) => {
      tab.addEventListener("click", function () {
        setMode(tab.getAttribute("data-ox-mode") || "generate");
      });
    });

    // Source tabs (paste mode)
    $$("[data-oxyai-tab]").forEach((button) => {
      button.addEventListener("click", function () {
        setSourceTab(button.getAttribute("data-oxyai-tab") || "html");
      });
    });

    // Arrow-key navigation across role="tablist" groups
    $$("[role='tablist']").forEach((tablist) => {
      tablist.addEventListener("keydown", handleTablistKeys);
    });

    // Operation segmented control
    $$("[data-ox-operation]").forEach((button) => {
      button.addEventListener("click", function () {
        setOperation(button.getAttribute("data-ox-operation") || "append");
      });
    });

    // Setup modal
    $("oxyai-provider-select")?.addEventListener("change", syncProviderFields);
    $("oxyai-mcp-token")?.addEventListener("input", syncCodexUrl);
    $$("[data-ox-open-setup]").forEach((button) => {
      button.addEventListener("click", openSetup);
    });
    $$("[data-ox-close-setup]").forEach((button) => {
      button.addEventListener("click", closeSetup);
    });
    // Builder shortcut button
    $$("[data-ox-shortcut]").forEach((button) => {
      button.addEventListener("click", async function () {
        try {
          await navigator.clipboard.writeText("Ctrl/Cmd + Shift + Y");
          setStatus("Builder shortcut copied — use it inside Oxygen.", "success");
        } catch (error) {
          setStatus(error?.message || "Failed to copy shortcut.", "error");
        }
      });
    });

    // Esc closes modal
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        const modal = $("oxyai-setup-modal");
        if (modal && !modal.hidden) {
          closeSetup();
        }
      }
    });

    // Cmd/Ctrl+Enter submits generate in generate mode
    $("oxyai-prompt")?.addEventListener("keydown", function (event) {
      if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
        event.preventDefault();
        $("oxyai-run-generate")?.click();
      }
    });

    setSourceTab("html");
    setMode("generate");
    setOperation("append");
    syncProviderFields();
    syncCodexUrl();
    renderAudit({});
  });
})();
