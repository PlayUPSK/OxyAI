(function () {
  "use strict";

  const config = window.oxyaiOxygen || {};
  const strings = config.strings || {};
  const state = {
    lastJson: "",
    pagesLoaded: false,
  };

  function $(id) {
    return document.getElementById(id);
  }

  function setStatus(message, isError) {
    const el = $("oxyai-status");
    if (!el) return;
    el.textContent = message;
    el.classList.toggle("is-error", !!isError);
  }

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

  function renderAudit(audit) {
    const target = $("oxyai-audit");
    if (!target) return;
    const normalized = audit || {};
    const groups = ["preserved", "transformed", "stripped", "followUp"];
    target.innerHTML = groups
      .map((group) => {
        const items = Array.isArray(normalized[group]) ? normalized[group] : [];
        return `<section><strong>${group}</strong><ul>${
          items.length
            ? items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")
            : "<li>No notable items.</li>"
        }</ul></section>`;
      })
      .join("");
  }

  function renderOxygen(oxygen) {
    const output = $("oxyai-output");
    const json = oxygen?.rawJson || JSON.stringify({ element: oxygen?.element || null }, null, 2);
    state.lastJson = json;
    if (output) output.value = json;
    renderAudit(oxygen?.audit || {});
  }

  function renderPlan(plan) {
    const target = $("oxyai-plan");
    if (!target) return;

    const questions = Array.isArray(plan?.questions) ? plan.questions : [];
    target.hidden = false;
    target.innerHTML = `
      <div class="oxyai-plan-head">
        <strong>${escapeHtml(plan?.status === "ready" ? "Ready to generate" : "Plan Mode questions")}</strong>
        <span>${escapeHtml(plan?.summary || "Review the plan before generating.")}</span>
      </div>
      ${
        questions.length
          ? `<div class="oxyai-plan-questions">${questions.map(renderPlanQuestion).join("")}</div>`
          : ""
      }
      <div class="oxyai-plan-actions">
        <button type="button" class="button button-primary" data-oxyai-use-plan>Use planned prompt</button>
      </div>
    `;

    target.querySelector("[data-oxyai-use-plan]")?.addEventListener("click", function () {
      const additions = collectPlanAnswers(target, questions);
      const readyPrompt = (plan?.readyPrompt || aiInput().prompt || "").trim();
      const nextPrompt = [readyPrompt, additions ? "User choices:\n" + additions : ""].filter(Boolean).join("\n\n");
      if ($("oxyai-prompt")) $("oxyai-prompt").value = nextPrompt;
      setStatus("Plan applied to the prompt. Generate source when ready.");
    });
  }

  function renderVariants(variants) {
    const target = $("oxyai-variants");
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
            <button type="button" class="button" data-oxyai-use-variant="${index}">Use this source</button>
          </section>
        `).join("")
      : "<p>No variants returned.</p>";

    target.querySelectorAll("[data-oxyai-use-variant]").forEach((button) => {
      button.addEventListener("click", function () {
        const variant = normalized[parseInt(button.getAttribute("data-oxyai-use-variant") || "0", 10)] || {};
        const generated = variant.source || {};
        if ($("oxyai-html")) $("oxyai-html").value = generated.html || "";
        if ($("oxyai-css")) $("oxyai-css").value = generated.css || "";
        if ($("oxyai-js")) $("oxyai-js").value = generated.js || "";
        setStatus("Variant loaded into source fields. Preview or convert when ready.");
      });
    });
  }

  function renderPlanQuestion(question) {
    const id = escapeAttr(question.id || question.label || "question");
    const type = question.type || "single_choice";
    const options = Array.isArray(question.options) ? question.options : [];
    const inputs = options.length
      ? options.map((option, index) => {
          const inputType = type === "multi_choice" ? "checkbox" : "radio";
          return `<label><input type="${inputType}" name="oxyai-plan-${id}" value="${escapeAttribute(option)}"${
            index === 0 && inputType === "radio" ? " checked" : ""
          }> ${escapeHtml(option)}</label>`;
        }).join("")
      : `<input type="${type === "color" ? "text" : "text"}" data-oxyai-plan-text="${id}" placeholder="Type an answer...">`;

    return `
      <section data-oxyai-question="${id}" data-oxyai-question-label="${escapeAttr(question.label || "")}">
        <strong>${escapeHtml(question.label || "Question")}</strong>
        ${question.why ? `<p>${escapeHtml(question.why)}</p>` : ""}
        <div>${inputs}</div>
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

  function activateMode(mode) {
    document.querySelectorAll("[data-oxyai-mode]").forEach((card) => {
      card.classList.toggle("is-active", card.getAttribute("data-oxyai-mode") === mode);
    });
  }

  function focusTarget(target) {
    if (target === "prompt") {
      activateMode("generate");
      $("oxyai-prompt")?.focus();
      return;
    }

    if (target === "mcp") {
      activateMode("remote");
      document.getElementById("oxyai-mcp-panel")?.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }

    activateMode("paste");
    $("oxyai-html")?.focus();
  }

  function setSourceTab(tab) {
    document.querySelectorAll("[data-oxyai-tab]").forEach((button) => {
      button.classList.toggle("is-active", button.getAttribute("data-oxyai-tab") === tab);
    });
    document.querySelectorAll("[data-oxyai-panel]").forEach((panel) => {
      panel.hidden = panel.getAttribute("data-oxyai-panel") !== tab;
    });
  }

  function syncProviderFields() {
    const provider = $("oxyai-provider-select")?.value || "openai";
    document.querySelectorAll("[data-oxyai-provider-fields]").forEach((group) => {
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
    setStatus("New MCP token generated. Click Save setup to keep it.");
  }

  async function copyCodexUrl() {
    const value = $("oxyai-codex-url")?.textContent || "";
    if (!value) return;
    await navigator.clipboard.writeText(value);
    setStatus("Codex URL copied.");
  }

  function renderPreview(data) {
    const audit = data.audit || {};
    const summary = data.summary || {};
    setStatus(
      `Preview: ${summary.elementCount || 0} elements, ${Object.keys(summary.byType || {}).length} element types.`,
      false
    );
    renderAudit(audit);
  }

  async function preview() {
    setStatus(strings.working || "Working...");
    const data = await request("/preview", {
      ...source(),
      options: options(),
    });
    renderPreview(data);
  }

  async function convert() {
    setStatus(strings.working || "Working...");
    const data = await request("/convert", {
      ...source(),
      options: options(),
    });
    renderOxygen(data.oxygen || {});
    setStatus(strings.converted || "Converted successfully.");
  }

  async function generate() {
    const input = aiInput();
    const prompt = input.prompt;
    if (!prompt) {
      setStatus("Prompt is required for AI generation.", true);
      return;
    }

    setStatus(strings.working || "Working...");
    const data = await request("/generate", {
      ...input,
    });
    const generated = data.source || {};
    if ($("oxyai-html")) $("oxyai-html").value = generated.html || "";
    if ($("oxyai-css")) $("oxyai-css").value = generated.css || "";
    if ($("oxyai-js")) $("oxyai-js").value = generated.js || "";
    setStatus(strings.generated || "AI source generated.");
  }

  async function plan() {
    const input = aiInput();
    if (!input.prompt) {
      setStatus("Prompt is required for Plan Mode.", true);
      return;
    }

    setStatus("Planning generation...");
    const data = await request("/plan", input);
    renderPlan(data.plan || {});
    setStatus(data.plan?.status === "ready" ? "Plan is ready. Use the planned prompt or generate now." : "Plan questions ready.");
  }

  async function tripleShot() {
    const input = aiInput();
    if (!input.prompt) {
      setStatus("Prompt is required for Triple Shot.", true);
      return;
    }

    setStatus("Generating three variants...");
    const data = await request("/triple-shot", input);
    renderVariants(data.variants || []);
    setStatus("Triple Shot variants ready. Choose one to load into the source fields.");
  }

  async function copyOutput() {
    if (!state.lastJson) {
      setStatus("Convert first before copying JSON.", true);
      return;
    }

    await navigator.clipboard.writeText(state.lastJson);
    setStatus(strings.copied || "Copied to clipboard.");
  }

  async function loadPages() {
    setStatus("Loading pages...");
    const data = await request("/codex/pages", null, "GET");
    const select = $("oxyai-target-page");
    if (!select) return;
    const pages = Array.isArray(data.pages) ? data.pages : [];
    select.innerHTML = pages.length
      ? pages.map((page) => `<option value="${escapeAttr(page.id)}">${escapeHtml(page.title || "Untitled")} (${escapeHtml(page.type || "post")} #${escapeHtml(page.id)})</option>`).join("")
      : '<option value="">No pages found</option>';
    state.pagesLoaded = true;
    setStatus(pages.length ? "Pages loaded. Choose a target and apply when ready." : "No editable pages found.", !pages.length);
  }

  async function applyToPage(dryRun) {
    const select = $("oxyai-target-page");
    if (!state.pagesLoaded) {
      await loadPages();
    }

    const postId = parseInt(select?.value || "", 10);
    if (!Number.isFinite(postId) || postId < 1) {
      setStatus("Choose a target page first.", true);
      return;
    }

    const payload = {
      ...source(),
      options: options(),
      operation: $("oxyai-page-operation")?.value || "append",
      dryRun: !!dryRun,
    };

    setStatus(dryRun ? "Checking page write..." : "Applying Oxygen content to page...");
    const data = await request("/codex/page/" + postId + "/apply", payload);
    setStatus(
      dryRun
        ? `Dry run OK: ${data.afterNodeCount || 0} nodes after ${data.operation || "append"}.`
        : `Applied to page. Backup ${data.backupId || "created"}; ${data.afterNodeCount || 0} nodes now stored.`
    );
  }

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
    el.addEventListener("click", async function () {
      try {
        await handler();
      } catch (error) {
        setStatus(error?.message || strings.failed || "Request failed.", true);
      }
    });
  }

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

    document.querySelectorAll("[data-oxyai-tab]").forEach((button) => {
      button.addEventListener("click", function () {
        setSourceTab(button.getAttribute("data-oxyai-tab") || "html");
      });
    });

    $("oxyai-provider-select")?.addEventListener("change", syncProviderFields);
    $("oxyai-mcp-token")?.addEventListener("input", syncCodexUrl);

    document.querySelectorAll("[data-oxyai-scroll]").forEach((button) => {
      button.addEventListener("click", function () {
        const id = button.getAttribute("data-oxyai-scroll") === "setup" ? "oxyai-setup" : "oxyai-composer";
        document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });

    document.querySelectorAll("[data-oxyai-focus]").forEach((button) => {
      button.addEventListener("click", function () {
        focusTarget(button.getAttribute("data-oxyai-focus") || "html");
      });
    });

    document.querySelectorAll("[data-oxyai-mode]").forEach((card) => {
      card.addEventListener("click", function (event) {
        if (event.target && event.target.tagName === "BUTTON") {
          return;
        }
        activateMode(card.getAttribute("data-oxyai-mode") || "paste");
      });
    });

    const shortcutButton = document.querySelector("[data-oxyai-copy-shortcut]");
    if (shortcutButton) {
      shortcutButton.addEventListener("click", async function () {
        activateMode("edit");
        await navigator.clipboard.writeText("Ctrl/Cmd + Shift + Y");
        setStatus("Builder shortcut copied: Ctrl/Cmd + Shift + Y");
      });
    }

    setSourceTab("html");
    syncProviderFields();
    syncCodexUrl();
  });
})();
