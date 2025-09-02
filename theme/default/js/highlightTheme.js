// Theme picker for highlight.php (works with multiple .hljs-theme-select)
(function () {
  "use strict";

  // Prefer server-provided list/initial if present
  const THEMES  = Array.isArray(window.__HL_THEMES) ? window.__HL_THEMES : null;
  const INITIAL_FROM_SERVER = window.__HL_INITIAL || null;

  // ---------- helpers ----------
  function normId(s){
    return (s || "")
      .toLowerCase()
      .replace(/[ _]+/g, "-")
      .replace(/\.min\.css$/,"")
      .replace(/\.css$/,"")
      .replace(/[?#].*$/,"")
      .trim();
  }

  function findHeaderLink() {
    // prefer explicit id if present
    const byId = document.getElementById("hljs-theme-link");
    if (byId && byId.tagName === "LINK") return byId;

    const links = document.querySelectorAll('link[rel="stylesheet"]');
    for (let i = 0; i < links.length; i++) {
      const href = (links[i].getAttribute("href") || "").toLowerCase();
      // path check is case-insensitive
      if (href.includes("/includes/highlight/")) return links[i];
    }
    return null;
  }

  function ensureLink() {
    let el = document.getElementById("hljs-theme-link") || findHeaderLink();
    if (el) { el.id = "hljs-theme-link"; return el; }
    el = document.createElement("link");
    el.id = "hljs-theme-link";
    el.rel = "stylesheet";
    document.head.appendChild(el);
    return el;
  }

  function parseUrlInitial(){
    try { return normId(new URL(location.href).searchParams.get("theme")); }
    catch { return null; }
  }

  // ---------- boot ----------
  document.addEventListener("DOMContentLoaded", function () {
    const headerLink = findHeaderLink();

    // Build theme map: id -> {id, href}
    const themeMap = new Map();

    if (THEMES && THEMES.length) {
      for (const t of THEMES) {
        const id = normId(t.id || t.name || t.href || "");
        if (!id) continue;
        themeMap.set(id, { id, href: t.href || t.url || t.path || "" });
      }
    } else {
      // Fallback: derive from current stylesheet folder + the select options
      const selects = document.querySelectorAll(".hljs-theme-select");
      if (!headerLink && !selects.length) return; // nothing to do

      // base dir and extension preference from current link
      let baseDir = "";
      let ext = ".css";
      if (headerLink) {
        const href = headerLink.getAttribute("href") || "";
        const parts = href.split("?")[0].split("#")[0].split("/");
        parts.pop(); // remove filename
        baseDir = parts.join("/");
        ext = /\.min\.css$/i.test(href) ? ".min.css" : ".css";
      }

      const seen = new Set();
      for (let i = 0; i < selects.length; i++) {
        const opts = selects[i].options || [];
        for (let j = 0; j < opts.length; j++) {
          const id = normId(opts[j].value || opts[j].text);
          if (!id || seen.has(id)) continue;
          seen.add(id);
          const href = baseDir ? (baseDir + "/" + id + ext) : "";
          themeMap.set(id, { id, href });
        }
      }
    }

    if (!themeMap.size) return;

    // Determine defaultId from whatever is already linked
    const defaultId = (function () {
      if (!headerLink) {
        if (themeMap.has("hybrid")) return "hybrid";
        if (themeMap.has("github-dark")) return "github-dark";
        return themeMap.keys().next().value;
      }
      const href = headerLink.getAttribute("href") || "";
      const file = (href.split("?")[0].split("#")[0].split("/").pop()) || "";
      return normId(file);
    })();

    // Prefer URL > localStorage > server-initial > default
    const urlInitial   = parseUrlInitial();
    const serverInitial= INITIAL_FROM_SERVER ? normId(INITIAL_FROM_SERVER) : null;

    function chooseId() {
      // URL param wins if valid
      if (urlInitial && themeMap.has(normId(urlInitial))) return normId(urlInitial);
      // Then whatever user saved last time
      try {
        const ls = localStorage.getItem("hljs_theme");
        if (ls && themeMap.has(normId(ls))) return normId(ls);
      } catch (_) {}
      // Then server-suggested starting theme
      if (serverInitial && themeMap.has(serverInitial)) return serverInitial;
      // Keep current default if present
      if (defaultId && themeMap.has(normId(defaultId))) return normId(defaultId);
      // Else a sensible fallback
      if (themeMap.has("hybrid")) return "hybrid";
      if (themeMap.has("github-dark")) return "github-dark";
      return themeMap.keys().next().value;
    }

    function applyThemeId(id, {forceSaveOnly = false} = {}) {
      const key = normId(id);
      const entry = themeMap.get(key);
      if (!entry) return;

      if (!forceSaveOnly) {
        const link = ensureLink();
        const currentHref = link.getAttribute("href") || "";
        if (entry.href && currentHref !== entry.href) {
          link.setAttribute("href", entry.href);
        }
      }
      // Always persist the chosen id
      try { localStorage.setItem("hljs_theme", entry.id); } catch (_) {}
    }

    function updateThemeQueryParam(currentId, defaultId) {
      const isDefault = normId(currentId) === normId(defaultId);
      try {
        const url = new URL(window.location.href);
        const prev = url.toString();
        if (isDefault) {
          url.searchParams.delete("theme");
          url.searchParams.delete("highlight");
        } else {
          url.searchParams.set("theme", currentId);
          url.searchParams.delete("highlight");
        }
        const next = url.toString();
        if (next !== prev) window.history.replaceState(null, "", url);
      } catch {
        let href = window.location.href
          .replace(/([?&])(theme|highlight)=[^&]*/gi, "$1")
          .replace(/[?&]$/, "");
        if (!isDefault) {
          href += (href.indexOf("?") === -1 ? "?" : "&") + "theme=" + encodeURIComponent(currentId);
        }
        window.history.replaceState(null, "", href);
      }
    }

    function syncSelectsTo(id) {
      const selects = document.querySelectorAll(".hljs-theme-select");
      for (let i = 0; i < selects.length; i++) {
        if (selects[i].value !== id) selects[i].value = id;
      }
    }

    // Init current theme
    let currentId = chooseId();
    if (currentId) {
      const entry = themeMap.get(currentId);
      const link  = ensureLink();
      const currentHref = link.getAttribute("href") || "";

      // Apply stylesheet if it differs
      if (entry && entry.href && currentHref !== entry.href) {
        applyThemeId(currentId);
      } else {
        // Even if href matches (e.g., URL picked the default), ensure LS is updated
        applyThemeId(currentId, { forceSaveOnly: true });
      }

      syncSelectsTo(currentId);
      updateThemeQueryParam(currentId, defaultId);
    }

    // Single delegated handler for all pickers (header + modal)
    function onChange(e) {
      const t = e.target;
      if (!t || !t.classList || !t.classList.contains("hljs-theme-select")) return;
      const nextId = normId(t.value);
      if (!themeMap.has(nextId) || nextId === currentId) return;
      currentId = nextId;
      applyThemeId(currentId);
      updateThemeQueryParam(currentId, defaultId);
      syncSelectsTo(currentId);
    }
    document.addEventListener("change", onChange, true);

    // When fullscreen modal appears and injects a picker, sync its value
    document.addEventListener("shown.bs.modal", function () {
      if (currentId) syncSelectsTo(currentId);
    });

  });
})();
