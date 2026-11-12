#!/usr/bin/env node
// convert-hljs-to-json.mjs
// Usage:
//   eg: node convert-hljs-to-json.mjs input.js output.json [--id mirc] [--name "mIRC"] [--aliases mrc,mircs]

import fs from 'fs';
import vm from 'vm';
import path from 'path';

const argv = process.argv.slice(2);
if (argv.length < 2) {
  console.error('Usage: node convert-hljs-to-json.mjs input.js output.json [--id id] [--name "Name"] [--aliases a,b,c]');
  process.exit(1);
}
const inFile  = argv[0];
const outFile = argv[1];

function readOpt(flag) {
  const i = argv.indexOf(flag);
  return (i !== -1 && argv[i+1]) ? argv[i+1] : null;
}
const optId       = readOpt('--id');
const optName     = readOpt('--name');
const optAliasesS = readOpt('--aliases');
const optAliases  = optAliasesS ? optAliasesS.split(',').map(s => s.trim()).filter(Boolean) : null;

// hljs stub
function makeHLJS() {
  const BACKSLASH_ESCAPE = { begin: /\\[\s\S]/ };

  const APOS_STRING_MODE = {
    className: 'string',
    begin: /'/, end: /'/, illegal: /\n/,
    contains: [BACKSLASH_ESCAPE]
  };

  const QUOTE_STRING_MODE = {
    className: 'string',
    begin: /"/, end: /"/, illegal: /\n/,
    contains: [BACKSLASH_ESCAPE]
  };

  const NUMBER_MODE = {
    className: 'number',
    begin: /\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/,
    relevance: 0
  };

  function inherit(obj, props={}) { return Object.assign({}, obj, props); }

  let captured = null;
  function registerLanguage(name, defOrFn) {
    let def = defOrFn;
    if (typeof defOrFn === 'function') def = defOrFn(hljs);
    captured = { name, def };
  }

  const hljs = {
    BACKSLASH_ESCAPE,
    APOS_STRING_MODE,
    QUOTE_STRING_MODE,
    NUMBER_MODE,
    inherit,
    registerLanguage
  };
  Object.defineProperty(hljs, '__captured', { get() { return captured; } });
  return hljs;
}

// Load/execute the language module in a sandbox
async function loadLanguageDef(modulePath) {
  let src = await fs.promises.readFile(modulePath, 'utf8');

  // ESM export shims
  if (/\bexport\s+default\s+function\b/.test(src)) {
    src = src.replace(/\bexport\s+default\s+function\b/, 'module.exports = function');
  } else if (/\bexport\s+default\b/.test(src)) {
    src = src.replace(/\bexport\s+default\b/, 'module.exports =');
  }

  const hljs = makeHLJS();
  const sandbox = {
    module: { exports: {} },
    exports: {},
    hljs,
    require: () => { throw new Error('require() disabled in sandbox'); },
    __dirname: path.dirname(modulePath),
    __filename: path.resolve(modulePath),
    console
  };

  vm.createContext(sandbox, { name: 'hljs-lang-sandbox' });
  try {
    vm.runInContext(src, sandbox, {
      filename: modulePath,
      displayErrors: true,
      timeout: 5000
    });
  } catch (e) {
    throw new Error(`Execution error in ${modulePath}: ${e.message}`);
  }

  // Get a definition
  let def = null;
  let nameHint = null;

  if (typeof sandbox.module.exports === 'function') {
    const maybe = sandbox.module.exports(sandbox.hljs);
    if (maybe && typeof maybe === 'object') def = maybe;
  } else if (sandbox.module.exports && typeof sandbox.module.exports === 'object') {
    def = sandbox.module.exports;
  }
  if (!def && sandbox.hljs.__captured) {
    def = sandbox.hljs.__captured.def;
    nameHint = sandbox.hljs.__captured.name || null;
  }
  if (!def || typeof def !== 'object') {
    throw new Error('Could not obtain a language definition object from the module.');
  }
  return { def, nameHint };
}

// RegExp detection across VM realms
const toString = Object.prototype.toString;
function isRegExp(x) { return toString.call(x) === '[object RegExp]'; }

// Deep convert RegExp to strings
function toPlain(obj) {
  if (obj == null) return obj;
  if (isRegExp(obj)) return obj.source;
  if (Array.isArray(obj)) return obj.map(toPlain);
  if (typeof obj !== 'object') return obj;

  const out = {};
  for (const [k, v] of Object.entries(obj)) {
    out[k] = toPlain(v);
  }
  return out;
}

// Normalize classes & structure
function normalizeClassNames(node) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) { node.forEach(normalizeClassNames); return; }

  const map = { code: 'meta' };
  if (node.className && map[node.className]) node.className = map[node.className];

  if (!node.className && node.scope) { node.className = node.scope; delete node.scope; }

  for (const key of ['contains','variants','starts']) {
    if (node[key]) normalizeClassNames(node[key]);
  }
}

function normalizeKeywords(kw) {
  if (!kw) return kw;
  if (typeof kw === 'string') return kw;
  if (Array.isArray(kw)) return kw.join(' ');
  if (typeof kw === 'object') {
    // Keep keyword groups (keyword, built_in, literal, meta...) so each gets its own class
    const out = {};
    for (const [group, words] of Object.entries(kw)) {
      if (group === '$pattern') continue;
      out[group] = (Array.isArray(words) ? words.join(' ') : String(words)).trim();
    }
    return out;
  }
  return String(kw);
}

function finalize(def, idOpt, nameOpt, nameHint, aliasesOpt) {
  const plain = toPlain(def);
  normalizeClassNames(plain);
  if (plain.keywords && typeof plain.keywords === 'object' && plain.keywords.$pattern && !plain.lexemes) {
    plain.lexemes = plain.keywords.$pattern;
  }
  if (plain.keywords) plain.keywords = normalizeKeywords(plain.keywords);

  const out = {};
  if (nameOpt) out.name = nameOpt;
  else if (plain.name) out.name = plain.name;
  else if (nameHint) out.name = nameHint;

  if (idOpt) out.id = idOpt;
  if (aliasesOpt) out.aliases = aliasesOpt;

  // Copy common top-level fields
  const copyKeys = new Set([
    'aliases','case_insensitive','keywords','contains','illegal','variants',
    'begin','end','className','relevance','lexemes','match','starts'
  ]);
  for (const [k, v] of Object.entries(plain)) {
    if (k in out) continue;
    if (copyKeys.has(k)) out[k] = v;
  }

  if (out.aliases && !Array.isArray(out.aliases)) {
    out.aliases = String(out.aliases).split(',').map(s => s.trim()).filter(Boolean);
  }
  return out;
}

(async () => {
  try {
    const { def, nameHint } = await loadLanguageDef(inFile);
    const result = finalize(def, optId, optName, nameHint, optAliases);
    await fs.promises.writeFile(outFile, JSON.stringify(result, null, 2), 'utf8');
    console.log(`Wrote ${path.basename(outFile)}${result.id ? ` (id: ${result.id}` : ''}${result.name ? `${result.id ? ', ' : ' ('}name: ${result.name}` : ''}${(result.id || result.name) ? ')' : ''}`);
  } catch (e) {
    console.error(e.message);
    process.exit(2);
  }
})();
