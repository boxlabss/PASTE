<?php
/*
 * File: generatepasswd.php for Paste
 * Just a little Bootstrap 5 tool for the registration page.
 * Fully client side generation.
 * License: GPLv3
 */
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Password Generator</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    /* Keep styles minimal*/
    :root {
      --brand-blue: #399BFF;
      --card-bg: rgba(255,255,255,0.04);
      --text-muted: #9aa4ad;
    }
    @media (prefers-color-scheme: light) {
      :root {
        --card-bg: #ffffff;
        --text-muted: #6c757d;
      }
    }

    body {
      font-family: "Fira Sans", system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background:
        radial-gradient(1200px 600px at 10% -10%, rgba(57,155,255,.20), transparent 60%),
        radial-gradient(1200px 600px at 110% 10%, rgba(57,155,255,.15), transparent 60%);
    }

    .page-wrap { flex: 1 0 auto; }
    .hero {
      text-align: center;
      margin-top: 2rem;
      margin-bottom: 1.25rem;
    }
    .hero h1 {
      font-weight: 700;
      letter-spacing: .2px;
    }
    .hero p {
      color: var(--text-muted);
      margin: .5rem 0 0;
    }

    .generator-card {
      background: var(--card-bg);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 1rem;
      backdrop-filter: saturate(120%) blur(6px);
    }

    .password-box {
      font-family: "Fira Code", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 1.125rem;
      user-select: all;
      letter-spacing: .2px;
    }

    .btn-perky {
      --bs-btn-bg: var(--brand-blue);
      --bs-btn-border-color: var(--brand-blue);
      --bs-btn-hover-bg: #2f8ae5;
      --bs-btn-hover-border-color: #2f8ae5;
      --bs-btn-color: #fff;
    }

    .entropy-badge {
      font-variant-numeric: tabular-nums;
    }

    .footer-note {
      color: var(--text-muted);
      font-size: .9rem;
    }

    .range-output {
      min-width: 2.5ch;
      text-align: right;
      display: inline-block;
    }
    .form-check .form-text {
      margin-left: 1.65rem;
      margin-top: .25rem;
    }
  </style>
</head>
<body>
  <main class="page-wrap">
    <div class="container">
      <div class="hero">
        <h1>Generate a Secure Password</h1>
        <p>Client-side generator &mdash; No data leaves your browser</p>
      </div>

      <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
          <div class="generator-card shadow-sm p-3 p-md-4">
            <!-- Output -->
            <div class="mb-3">
              <label class="form-label fw-semibold">Password</label>
              <div class="input-group">
                <input id="out" type="text" class="form-control password-box" readonly aria-describedby="copyBtn">
                <button id="regenBtn" class="btn btn-outline-secondary" type="button" title="Regenerate">
                  <i class="bi bi-arrow-repeat"></i>
                </button>
                <button id="copyBtn" class="btn btn-perky fw-semibold" type="button" title="Copy to clipboard">
                  <i class="bi bi-clipboard-check"></i> Copy
                </button>
              </div>
              <div class="d-flex align-items-center gap-3 mt-2">
                <span class="badge text-bg-secondary entropy-badge" id="entropyBadge" title="Estimated entropy in bits">— bits</span>
                <span id="strengthLabel" class="small"></span>
              </div>
            </div>

            <hr class="my-4">

            <!-- Controls -->
            <form class="row gy-3">
              <div class="col-12 col-md-6">
                <label for="len" class="form-label fw-semibold">Length:
                  <span class="range-output" id="lenOut">16</span>
                </label>
                <input id="len" type="range" class="form-range" min="8" max="64" step="1" value="16" aria-describedby="lenHelp">
                <div id="lenHelp" class="form-text">Longer is stronger; 16–24 is a great default.</div>
              </div>

              <div class="col-12 col-md-6">
                <div class="row">
                  <div class="col-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="lower" checked>
                      <label class="form-check-label" for="lower">Lowercase (a–z)</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="upper" checked>
                      <label class="form-check-label" for="upper">Uppercase (A–Z)</label>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="digits" checked>
                      <label class="form-check-label" for="digits">Digits (0–9)</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="symbols" checked>
                      <label class="form-check-label" for="symbols">Symbols (!@#$…)</label>
                    </div>
                  </div>
                </div>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="noAmbig" checked>
                  <label class="form-check-label" for="noAmbig">Avoid ambiguous characters</label>
                  <div class="form-text">Skips look-alikes like <code>O</code>/<code>0</code>, <code>l</code>/<code>1</code>, <code>{}</code>/<code>[]</code>.</div>
                </div>
              </div>

              <div class="col-12">
                <div class="d-flex gap-2">
                  <button type="button" id="generateBtn" class="btn btn-perky fw-semibold">
                    <i class="bi bi-magic"></i> Generate
                  </button>
                  <button type="button" id="copyBtn2" class="btn btn-outline-secondary">
                    <i class="bi bi-clipboard"></i> Copy
                  </button>
                </div>
              </div>
            </form>

            <div class="mt-4 footer-note">
              Tip: Use a unique password for every site. Consider a password manager.
            </div>
          </div>

          <div class="text-center mt-3">
            <a class="small text-decoration-underline" href="/" aria-label="Back to Paste">← Back to Paste</a>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    (function () {
      "use strict";

      const $ = (id) => document.getElementById(id);

      const lowerChars  = "abcdefghijklmnopqrstuvwxyz";
      const upperChars  = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
      const digitChars  = "0123456789";
      const symbolChars = "!@#$%^&*()-_=+[]{};:,.<>/?~";
      const ambiguous   = "O0oIl1|[]{}()<>`'\".,;:~";

      const len     = $("len");
      const lenOut  = $("lenOut");
      const lower   = $("lower");
      const upper   = $("upper");
      const digits  = $("digits");
      const symbols = $("symbols");
      const noAmbig = $("noAmbig");

      const out          = $("out");
      const generateBtn  = $("generateBtn");
      const regenBtn     = $("regenBtn");
      const copyBtn      = $("copyBtn");
      const copyBtn2     = $("copyBtn2");
      const entropyBadge = $("entropyBadge");
      const strengthLabel= $("strengthLabel");

      function updateLenOutput() {
        lenOut.textContent = String(len.value);
      }

      function buildCharset() {
        let set = "";
        if (lower.checked)   set += lowerChars;
        if (upper.checked)   set += upperChars;
        if (digits.checked)  set += digitChars;
        if (symbols.checked) set += symbolChars;
        if (noAmbig.checked) {
          set = [...set].filter(ch => !ambiguous.includes(ch)).join("");
        }
        return Array.from(new Set(set)).join(""); // dedupe
      }

      function getRandomValues(length) {
        const arr = new Uint32Array(length);
        if (window.crypto && window.crypto.getRandomValues) {
          window.crypto.getRandomValues(arr);
        } else {
          // Fallback (should almost never happen): use Math.random
          for (let i = 0; i < length; i++) arr[i] = Math.floor(Math.random() * 0xFFFFFFFF);
        }
        return arr;
      }

      function generatePassword() {
        const L = parseInt(len.value, 10);
        let charset = buildCharset();
        if (!charset) {
          alert("Please select at least one character set.");
          return;
        }
        // Ensure we sample uniformly from charset using rejection sampling
        const chars = Array.from(charset);
        const n = chars.length;
        const outChars = [];
        const rand = getRandomValues(L * 2); // buffer
        let i = 0;

        const max = Math.floor(0x100000000 / n) * n; // highest multiple of n below 2^32

        for (let produced = 0; produced < L; ) {
          if (i >= rand.length) {
            // refill
            const refill = getRandomValues(L);
            for (let k = 0; k < refill.length; k++) rand[k] = refill[k];
            i = 0;
          }
          const r = rand[i++];
          if (r < max) {
            outChars.push(chars[r % n]);
            produced++;
          }
        }

        const pwd = outChars.join("");
        out.value = pwd;
        updateStrength(pwd, n);
      }

      function log2(x) { return Math.log(x) / Math.log(2); }

      function updateStrength(pwd, charsetSize) {
        const L = pwd.length || 0;
        // Shannon-style estimate (assuming uniform random from charset)
        const bits = Math.round(L * log2(Math.max(2, charsetSize)));
        entropyBadge.textContent = bits + " bits";

        let label = "";
        let cls   = "text-secondary";
        if (bits < 45)        { label = "Weak";      cls = "text-danger"; }
        else if (bits < 64)   { label = "Fair";      cls = "text-warning"; }
        else if (bits < 90)   { label = "Strong";    cls = "text-success"; }
        else                  { label = "Excellent"; cls = "text-success"; }

        strengthLabel.className = "small " + cls;
        strengthLabel.textContent = label + " (charset " + charsetSize + ", length " + L + ")";
      }

      function copyToClipboard() {
        out.select();
        out.setSelectionRange(0, 99999);
        navigator.clipboard?.writeText(out.value).then(() => {
          flashCopied(copyBtn);
          flashCopied(copyBtn2);
        }).catch(() => {
          // Fallback
          document.execCommand("copy");
          flashCopied(copyBtn);
          flashCopied(copyBtn2);
        });
      }

      function flashCopied(btn) {
        if (!btn) return;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> Copied';
        btn.disabled = true;
        setTimeout(() => {
          btn.innerHTML = original;
          btn.disabled = false;
        }, 1200);
      }

      // Events
      len.addEventListener("input", updateLenOutput);
      generateBtn.addEventListener("click", generatePassword);
      regenBtn.addEventListener("click", generatePassword);
      copyBtn.addEventListener("click", copyToClipboard);
      copyBtn2.addEventListener("click", copyToClipboard);
      [lower, upper, digits, symbols, noAmbig].forEach(el => {
        el.addEventListener("change", generatePassword);
      });

      // Init
      updateLenOutput();
      generatePassword();
    })();
  </script>
</body>
</html>
