<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 * demo: https://paste.boxlabs.uk/
 *
 * https://phpaste.sourceforge.io/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License in LICENCE for more details.
 */

declare(strict_types=1);

$h = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

/* Engine badge -+changes + ignore WS toggle (provided by controller) */
$engine_badge_html = $GLOBALS['diff_engine_badge'] ?? '';
$ws_on             = !empty($GLOBALS['ignore_ws_on']);
$ws_toggle_url     = (string)($GLOBALS['ignore_ws_toggle'] ?? '#');

$no_changes        = !empty($GLOBALS['diff_no_changes']);
$changes_add       = (int)($GLOBALS['diff_changes_add'] ?? 0);
$changes_del       = (int)($GLOBALS['diff_changes_del'] ?? 0);
$changes_total     = (int)($GLOBALS['diff_changes_total'] ?? ($changes_add + $changes_del));

/* Optional server-side "only changes" toggle (set by controller if enabled) */
$only_on           = !empty($GLOBALS['only_on']);
$only_toggle_url   = (string)($GLOBALS['only_toggle_url'] ?? '');

/* Derive single dropdown initial value + label */
$single_id = strtolower($lang_right ?? $lang_left ?? 'autodetect');
if ($single_id === 'autodetect' && isset($lang_left) && strtolower((string)$lang_left) !== 'autodetect') {
    $single_id = strtolower((string)$lang_left);
}
$leftLabel  = $leftLabel  ?? 'Old';
$rightLabel = $rightLabel ?? 'New';

/* For the header: if both labels same, show one Language badge; else show two */
$sameLangs = ($lang_left_label ?? '') === ($lang_right_label ?? '');
?>
<div class="container-fluid diff-outer">
    <!-- Top toolbar -->
    <div class="diff-toolbar btn-toolbar justify-content-between flex-wrap gap-2 align-items-center">
      <!-- Left: context + stats -->
      <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="d-flex align-items-center flex-wrap gap-2 small text-body-secondary">
          <span>Left:</span><span class="badge bg-secondary-subtle text-wrap"><?= $h($leftLabel) ?></span>
          <span class="ms-2">Right:</span><span class="badge bg-secondary-subtle text-wrap"><?= $h($rightLabel) ?></span>

          <?php if ($sameLangs): ?>
            <span class="ms-2">Language:</span>
            <span class="badge bg-secondary-subtle"><?= $h($lang_left_label ?? $lang_right_label ?? 'Autodetect') ?></span>
          <?php else: ?>
            <span class="ms-2">Languages:</span>
            <span class="badge bg-secondary-subtle"><?= $h($lang_left_label ?? 'Autodetect') ?></span>
            <span class="badge bg-secondary-subtle"><?= $h($lang_right_label ?? 'Autodetect') ?></span>
          <?php endif; ?>

          <?php if (!empty($engine_badge_html)): ?>
            <span class="ms-2"><?= $engine_badge_html /* safe HTML */ ?></span>
          <?php endif; ?>
        </div>

        <?php if ($no_changes): ?>
          <span class="badge bg-success-subtle border border-success-subtle text-success-emphasis ms-2">No changes</span>
        <?php else: ?>
          <div class="d-flex align-items-center gap-1 ms-2">
            <span class="badge bg-success-subtle border border-success-subtle text-success-emphasis" title="Added lines">+<?= $changes_add ?></span>
            <span class="badge bg-danger-subtle  border border-danger-subtle  text-danger-emphasis"  title="Deleted lines">-<?= $changes_del ?></span>
            <span class="badge bg-secondary-subtle" title="Total changed lines">±<?= $changes_total ?></span>
          </div>
          <!-- Prev/Next moved to floating overlay inside diff area -->
        <?php endif; ?>
      </div>

      <!-- Right: controls -->
      <div class="btn-toolbar flex-wrap gap-2 ms-lg-auto">
        <!-- Wrap / Line / Only changes -->
        <div class="btn-group btn-group-sm" role="group" aria-label="View toggles">
          <input class="btn-check" type="checkbox" id="optWrap" <?= !empty($wrap) ? 'checked':'' ?>>
          <label class="btn btn-outline-secondary" for="optWrap">Wrap</label>

          <input class="btn-check" type="checkbox" id="optLine" <?= !empty($lineno) ? 'checked':'' ?>>
          <label class="btn btn-outline-secondary" for="optLine">Line #</label>

          <!-- Client-side filter (kept for instant toggle) -->
          <input class="btn-check" type="checkbox" id="btnOnlyChangesCheck" autocomplete="off">
          <label class="btn btn-outline-secondary" id="btnOnlyChanges" for="btnOnlyChangesCheck" aria-pressed="false">
            Only changes
          </label>
        </div>

        <a class="btn btn-outline-secondary btn-sm" id="btnWS"
           href="<?= $h($ws_toggle_url) ?>"
           role="button"
           title="Toggle ignoring trailing whitespace">
          <i class="bi bi-slash-circle" aria-hidden="true"></i>
          <span class="ms-1"><?= $ws_on ? 'Whitespace: Ignored' : 'Whitespace: Shown' ?></span>
        </a>

        <?php if ($only_toggle_url !== ''): ?>
        <a class="btn btn-outline-secondary btn-sm" id="btnOnlyServer"
           href="<?= $h($only_toggle_url) ?>"
           role="button"
           title="Server-side filter: show only changed lines">
          <i class="bi bi-filter-square" aria-hidden="true"></i>
          <span class="ms-1"><?= $only_on ? 'Only: On' : 'Only: Off' ?></span>
        </a>
        <?php endif; ?>

        <div class="btn-group btn-group-sm" role="group" aria-label="View mode">
          <button type="button" class="btn btn-outline-secondary <?= ($view_mode ?? '')==='side'?'active':'' ?>" id="btnSide">
            <i class="bi bi-layout-three-columns" aria-hidden="true"></i>
            <span class="d-none d-sm-inline ms-1">Side-by-side</span>
          </button>
          <button type="button" class="btn btn-outline-secondary <?= ($view_mode ?? '')==='unified'?'active':'' ?>" id="btnUni">
            <i class="bi bi-menu-button-wide" aria-hidden="true"></i>
            <span class="d-none d-sm-inline ms-1">Unified</span>
          </button>
        </div>

        <button class="btn btn-primary btn-sm" type="submit" form="diffForm"
                formaction="<?= $h($GLOBALS['form_action_dl_diff'] ?? 'diff.php?download=diff') ?>"
                title="Download unified .diff">
          <i class="bi bi-download" aria-hidden="true"></i><span class="ms-1">Download .diff</span>
        </button>
        <button class="btn btn-outline-primary btn-sm" type="submit" form="diffForm"
                formaction="<?= $h($GLOBALS['form_action_dl_patch'] ?? 'diff.php?download=patch') ?>"
                title="Download git .patch">
          <i class="bi bi-git" aria-hidden="true"></i><span class="ms-1">Download .patch</span>
        </button>
      </div>
    </div>

    <!-- Language selector + actions + editors (single server-side form) -->
    <form id="diffForm" method="post" action="<?= $h($GLOBALS['form_action_compare'] ?? 'diff.php') ?>" accept-charset="UTF-8">
    <input type="hidden" name="left_label"  value="<?= $h($leftLabel) ?>">
    <input type="hidden" name="right_label" value="<?= $h($rightLabel) ?>">
    <div class="diff-toolbar btn-toolbar mt-2 gap-2 flex-wrap align-items-center">
      <div class="input-group input-group-sm w-auto" style="min-width: 14rem;">
        <span class="input-group-text">Language</span>
        <select class="form-select form-select-sm" id="langAll" name="lang" aria-label="Diff language">
          <option value="autodetect" <?= ($single_id==='autodetect')?'selected':''; ?>>Autodetect</option>
          <option disabled>──────────</option>
          <?php
            $printed = [];
            foreach ($popular_langs as $pid):
                $lid = strtolower($pid);
                if (!isset($language_map[$lid])) continue;
                $printed[$lid] = true;
                $sel = ($lid === $single_id) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($language_map[$lid]) ?></option>
          <?php endforeach; ?>
          <option disabled>──────────</option>
          <?php foreach ($language_map as $lid => $label):
                if (isset($printed[$lid])) continue;
                $sel = ($lid === $single_id) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Actions">
        <?php if (!empty($GLOBALS['can_undo'])): ?>
        <button class="btn btn-outline-warning" name="action" value="merge_undo" type="submit" title="Undo last merge">
          <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Undo merge</span>
        </button>
        <?php endif; ?>
        <button class="btn btn-outline-secondary" name="action" value="swap" type="submit" title="Swap panes">
          <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Swap</span>
        </button>
        <button class="btn btn-success" name="action" value="compare" type="submit" title="Recompute diff">
          <i class="bi bi-play" aria-hidden="true"></i><span class="ms-1">Compare</span>
        </button>
      </div>
    </div>

  <!-- Editors -->
  <div class="diff-toolbar mt-2">
    <div class="w-100">
      <div class="row g-3">
        <div class="col-lg-6">
          <textarea class="form-control code-input paste-textarea" rows="10" id="leftText" name="left_text" data-editor="true" spellcheck="false" placeholder="old version"><?= $h($GLOBALS['left'] ?? '') ?></textarea>
        </div>
        <div class="col-lg-6">
          <textarea class="form-control code-input paste-textarea" rows="10" id="rightText" name="right_text" data-editor="true" spellcheck="false" placeholder="new version"><?= $h($GLOBALS['right'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>
  </form>

  <!-- Server-side merge target: the per-hunk buttons below submit to this -->
  <form id="mergeForm" method="post" action="<?= $h($GLOBALS['merge_action'] ?? 'diff.php') ?>" accept-charset="UTF-8" class="d-none"></form>

  <!-- Diff viewer -->
  <div class="diff-area mt-2">
    <?php if (!$no_changes): ?>
    <div class="diff-float-nav" id="diffFloatNav" role="group" aria-label="Navigate changes">
      <button class="dfn-btn" id="btnPrevChange" type="button" title="Previous change (P)">
        <i class="bi bi-chevron-up" aria-hidden="true"></i>
      </button>
      <span class="dfn-counter" id="dfnCounter">– / –</span>
      <button class="dfn-btn" id="btnNextChange" type="button" title="Next change (N)">
        <i class="bi bi-chevron-down" aria-hidden="true"></i>
      </button>
    </div>
    <?php endif; ?>
    <div class="diff-scroll" id="diffScroll" data-init-split="<?= $h((string)($split_pct ?? 50)) ?>">
      <div class="split-overlay" id="splitOverlay">
        <div class="splitter" id="splitter" role="separator" aria-orientation="vertical" aria-label="Resize"></div>
      </div>

      <!-- side-by-side -->
      <table class="diff-table <?= !empty($wrap) ? 'wrap-on':'wrap-off' ?> <?= !empty($lineno) ? '':'lineoff' ?>" id="tblSide" <?= ($view_mode ?? '')==='unified'?'style="display:none"':'' ?>>
        <colgroup id="sideCols">
          <col class="col-lno-l" />
          <col class="col-code-l" />
          <col class="col-lno-r" />
          <col class="col-code-r" />
        </colgroup>
        <tbody>
        <?php foreach ($sideRows as $r): ?>
          <tr>
            <td class="no"><?= $h($r['lno']) ?></td>
            <td class="code left <?= $r['lclass'] ?>"><div class="code-inner"><?php
              if ($r['lclass'] === 'ctx') {
                echo hl_render_line((string)$r['lhtml'], $lang_left ?? 'text');
              } else {
                if ($r['lhtml'] !== '') echo '<span class="marker">'.($r['lclass']==='del' ? '–' : '').'</span>';
                echo $r['l_intra'] ? $r['lhtml'] : $h($r['lhtml']);
              }
            ?></div></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code right <?= $r['rclass'] ?>"><div class="code-inner"><?php
              if ($r['rclass'] === 'ctx') {
                echo hl_render_line((string)$r['rhtml'], $lang_right ?? 'text');
              } else {
                if ($r['rhtml'] !== '') echo '<span class="marker">'.($r['rclass']==='add' ? '+' : '').'</span>';
                echo $r['r_intra'] ? $r['rhtml'] : $h($r['rhtml']);
              }
            ?></div></td>
          </tr>
          <?php if (!empty($r['hunk_end'])): $hid = (int)($r['hunk'] ?? 0); ?>
          <tr class="diff-merge-row">
            <td colspan="2" class="merge-cell merge-cell-l">
              <button class="btn-merge btn-merge-r" type="submit" form="mergeForm"
                      name="merge" value="<?= $hid ?>:right"
                      title="Copy left (old) into the right side">Merge to right &#8594;</button>
            </td>
            <td colspan="2" class="merge-cell merge-cell-r">
              <button class="btn-merge btn-merge-l" type="submit" form="mergeForm"
                      name="merge" value="<?= $hid ?>:left"
                      title="Copy right (new) into the left side">&#8592; Merge to left</button>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- unified -->
      <table class="diff-table unified <?= !empty($wrap) ? 'wrap-on':'wrap-off' ?> <?= !empty($lineno) ? '':'lineoff' ?>" id="tblUni" <?= ($view_mode ?? '')==='side'?'style="display:none"':'' ?>>
        <tbody>
        <?php $lang_unified = ($lang_right ?: $lang_left) ?? 'text'; ?>
        <?php foreach ($uniRows as $r): ?>
          <tr>
            <td class="no"><?= $h($r['lno']) ?></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code <?= $r['class'] ?>"><div class="code-inner"><?php
              if ($r['class'] === 'ctx') {
                echo hl_render_line((string)$r['html'], $lang_unified);
              } else {
                if ($r['html'] !== '') echo '<span class="marker">'.($r['class']==='add' ? '+' : ($r['class']==='del' ? '–' : '')).'</span>';
                echo $r['intra'] ? $r['html'] : $h($r['html']);
              }
            ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  const $ = (s,r=document)=>r.querySelector(s);
  const root    = $('#diffScroll');
  const overlay = $('#splitOverlay');
  const tblSide = $('#tblSide');
  const tblUni  = $('#tblUni');
  const cg      = $('#sideCols');
  const bar     = $('#splitter');

  const clamp = (v,min,max)=>Math.max(min,Math.min(max,v));
  function readCookie(name){ return document.cookie.split('; ').find(x=>x.startsWith(name+'='))?.split('=')[1]; }
  function writeCookie(name,val){ document.cookie = name+'='+val+'; path=/; max-age='+(60*60*24*30)+'; samesite=Lax'; }

  let splitPct = clamp(parseFloat(readCookie('diffSplitPct') ?? root?.dataset.initSplit ?? '50') || 50, 20, 80);

  const numberWidth = () =>
    tblSide.classList.contains('lineoff') ? 0 :
      (parseFloat(getComputedStyle(root).getPropertyValue('--lno')) || 56);

  // Layout helpers
  function placeBar(px){
    bar.style.marginLeft = '0';
    bar.style.transform  = 'none';
    bar.style.left       = Math.round(px) + 'px';
  }

  function layoutSideTable(){
    if (!root || !tblSide || !cg || !bar || tblSide.style.display==='none') return;

    const cols = cg.querySelectorAll('col');
    const lno  = numberWidth();

    const total   = Math.max(320, root.clientWidth);
    const minCode = 80;
    const avail   = Math.max(160, total - (lno*2));

    let leftCode  = Math.round(avail * (splitPct/100));
    leftCode      = clamp(leftCode, minCode, avail - minCode);
    const rightCode= avail - leftCode;

    if (cols.length === 4){
      cols[0].style.width = lno+'px';
      cols[1].style.width = leftCode+'px';
      cols[2].style.width = lno+'px';
      cols[3].style.width = rightCode+'px';
    }

    const barW = bar.offsetWidth || 12;
    const x = lno + leftCode - (barW/2);
    placeBar(x);
  }

  // View toggles
  $('#btnSide')?.addEventListener('click', ()=>{
    tblSide.style.display=''; tblUni.style.display='none';
    overlay.style.display='';
    requestAnimationFrame(layoutSideTable);
  });
  $('#btnUni')?.addEventListener('click', ()=>{
    tblUni.style.display='';   tblSide.style.display='none';
    overlay.style.display='none';
  });

  // Wrap / Line toggles
  $('#optWrap')?.addEventListener('change', (e)=>{
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('wrap-on', e.target.checked));
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('wrap-off', !e.target.checked));
    requestAnimationFrame(layoutSideTable);
  });
  $('#optLine')?.addEventListener('change', (e)=>{
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('lineoff', !e.target.checked));
    requestAnimationFrame(layoutSideTable);
  });

    // Only-changes toggle (client-side quick filter)
    (function(){
      const btn = document.getElementById('btnOnlyChanges');
      if (!btn) return;

      let only = false;
      const toggle = () => {
        only = !only;
        btn.setAttribute('aria-pressed', only ? 'true' : 'false');
        btn.classList.toggle('active', only);

        // side-by-side: hide rows where both sides are context
        document.querySelectorAll('#tblSide tbody tr').forEach(tr => {
          const l = tr.querySelector('.code.left');
          const r = tr.querySelector('.code.right');
          const hide = l?.classList.contains('ctx') && r?.classList.contains('ctx');
          tr.style.display = (only && hide) ? 'none' : '';
        });

        // unified: hide rows with class ctx
        document.querySelectorAll('#tblUni tbody tr').forEach(tr => {
          const cell = tr.querySelector('.code');
          const hide = cell?.classList.contains('ctx');
          tr.style.display = (only && hide) ? 'none' : '';
        });
      };

      btn.addEventListener('click', toggle);
    })();

  (function(){
    const qs = s => Array.from(document.querySelectorAll(s));
    const scrollPort = document.getElementById('diffScroll');
    const counter    = document.getElementById('dfnCounter');

    function changeRows() {
      const side = qs('#tblSide tbody tr:not(.diff-merge-row)').filter(tr => {
        const l = tr.querySelector('.code.left');
        const r = tr.querySelector('.code.right');
        return l?.classList.contains('del') || r?.classList.contains('add');
      });
      const uni  = qs('#tblUni  tbody tr').filter(tr => {
        const c = tr.querySelector('.code');
        return c?.classList.contains('add') || c?.classList.contains('del');
      });
      const tblSide = document.getElementById('tblSide');
      return (tblSide && tblSide.style.display !== 'none') ? side : uni;
    }

    function scrollToRow(row) {
      if (!row || !scrollPort) return;
      // Scroll within the diff container, not the whole page
      const portRect = scrollPort.getBoundingClientRect();
      const rowRect  = row.getBoundingClientRect();
      const offset   = rowRect.top - portRect.top + scrollPort.scrollTop
                       - scrollPort.clientHeight / 2 + row.offsetHeight / 2;
      scrollPort.scrollTo({ top: Math.max(0, offset), behavior: 'smooth' });
      row.classList.remove('jump-flash');
      void row.offsetWidth;
      row.classList.add('jump-flash');
      setTimeout(()=> row.classList.remove('jump-flash'), 900);
    }

    let idx = -1;
    function updateCounter() {
      if (!counter) return;
      const total = changeRows().length;
      counter.textContent = total ? `${idx + 1} / ${total}` : '– / –';
    }
    function jump(dir) {
      const rows = changeRows();
      if (!rows.length) return;
      idx = (idx + dir + rows.length) % rows.length;
      scrollToRow(rows[idx]);
      updateCounter();
    }

    // Init counter when diff loads
    setTimeout(updateCounter, 400);

    document.getElementById('btnNextChange')?.addEventListener('click', ()=>jump(+1));
    document.getElementById('btnPrevChange')?.addEventListener('click', ()=>jump(-1));

    // keyboard: n / p (and ] / [ as alternates)
    document.addEventListener('keydown', e => {
      if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
      if (e.key === 'n' || e.key === ']') { e.preventDefault(); jump(+1); }
      if (e.key === 'p' || e.key === '[') { e.preventDefault(); jump(-1); }
    });
  })();

  // Tab / Shift+Tab indentation in editors
  (function(){
    const INDENT = '    '; // 4 spaces; switch to '\t' to insert real tabs
    const reOutdent = /^(?: {1,4}|\t)/;

    document.querySelectorAll('textarea[data-editor="true"]').forEach(ta => {
      ta.addEventListener('keydown', e => {
        if (e.key !== 'Tab') return;
        e.preventDefault();

        const s = ta.selectionStart;
        const ed = ta.selectionEnd;
        const val = ta.value;

        const lineStart = val.lastIndexOf('\n', s - 1) + 1;
        const nextNL = val.indexOf('\n', ed);
        const lineEnd = nextNL === -1 ? val.length : nextNL;

        const block = val.slice(lineStart, lineEnd);

        if (s !== ed) {
          if (e.shiftKey) {
            const out = block.replace(/^/gm, m => '');
            const out2 = block.replace(reOutdent, '');
            const replaced = block.split('\n').map(l => l.replace(reOutdent, '')).join('\n');
            ta.setRangeText(replaced, lineStart, lineEnd, 'preserve');
            const delta = block.length - replaced.length;
            ta.selectionStart = s - Math.min(4, s - lineStart);
            ta.selectionEnd   = ed - delta;
          } else {
            const indented = block.replace(/^/gm, INDENT);
            ta.setRangeText(indented, lineStart, lineEnd, 'preserve');
            const lines = (block.match(/\n/g) || []).length + 1;
            ta.selectionStart = s + INDENT.length;
            ta.selectionEnd   = ed + INDENT.length * lines;
          }
        } else {
          if (e.shiftKey) {
            const before = val.slice(lineStart, s);
            const m = reOutdent.exec(before);
            if (m) {
              ta.setRangeText('', lineStart, lineStart + m[0].length, 'end');
              ta.selectionStart = ta.selectionEnd = s - m[0].length;
            }
          } else {
            ta.setRangeText(INDENT, s, s, 'end');
          }
        }
      });
    });
  })();

  // Dragging
  (function(){
    if (!root || !bar) return;

    function setFromClientX(clientX){
      const rect  = root.getBoundingClientRect();
      const total = Math.max(320, root.clientWidth);
      const lno   = numberWidth();
      const barW  = bar.offsetWidth || 12;

      let px = clientX - rect.left;
      const minBoundary = lno + 80 + (barW/2);
      const maxBoundary = total - lno - 80 - (barW/2);
      px = Math.max(minBoundary, Math.min(maxBoundary, px));

      const codeAvail = Math.max(160, total - (lno*2));
      const leftCode  = px - (barW/2) - lno;
      splitPct = clamp((leftCode / codeAvail) * 100, 20, 80);
      writeCookie('diffSplitPct', splitPct.toFixed(1));

      placeBar(px - (barW/2));
      requestAnimationFrame(layoutSideTable);
    }

    let dragging=false;
    bar.addEventListener('mousedown', e=>{ dragging=true; bar.classList.add('dragging'); e.preventDefault(); });
    window.addEventListener('mousemove', e=>{ if (dragging) setFromClientX(e.clientX); }, {passive:false});
    window.addEventListener('mouseup',   ()=>{ dragging=false; bar.classList.remove('dragging'); });

    bar.addEventListener('touchstart', ()=>{ dragging=true; bar.classList.add('dragging'); }, {passive:true});
    window.addEventListener('touchmove', e=>{
      if (!dragging) return; const t=e.touches[0]; if (t) setFromClientX(t.clientX);
    }, {passive:false});
    window.addEventListener('touchend',  ()=>{ dragging=false; bar.classList.remove('dragging'); });

    window.addEventListener('resize', ()=> requestAnimationFrame(layoutSideTable), {passive:true});

    document.addEventListener('DOMContentLoaded', ()=>{
      bar.style.marginLeft='0'; bar.style.transform='none';
      requestAnimationFrame(layoutSideTable);
    });
  })();
})();
</script>

<!-- ================================================================
     DIFF MINIMAP  +  MERGE BUTTONS
     ================================================================ -->
<script>
/* ================================================================
   MINIMAP
   Renders a narrow strip beside the scrollport with coloured marks
   for every changed row.  Positions are computed via
   getBoundingClientRect() so they are always accurate.
   ================================================================ */
(function () {
  'use strict';

  var scroll = document.getElementById('diffScroll');
  if (!scroll) return;

  /* ── DOM setup ─────────────────────────────────────────────── */
  var wrap = document.createElement('div');
  wrap.className = 'diff-map-wrap';
  scroll.parentNode.insertBefore(wrap, scroll);
  wrap.appendChild(scroll);

  var map = document.createElement('div');
  map.id  = 'diffMap';
  map.className = 'diff-map';
  wrap.appendChild(map);

  /* Leave the floating nav in .diff-area (position:relative) —
     it will be pinned to the bottom-right corner outside the scroll pane */
  var floatNav = document.getElementById('diffFloatNav');
  // no reparenting needed

  var thumb = document.createElement('div');
  thumb.className = 'diff-map-thumb';
  map.appendChild(thumb);

  /* expose rebuild so merge code can call it */
  window.diffMinimapRebuild = rebuild;

  /* ── Helpers ───────────────────────────────────────────────── */
  function activeTable() {
    var s = document.getElementById('tblSide');
    return (s && s.style.display !== 'none') ? s
         : document.getElementById('tblUni');
  }

  function flashRow(tr) {
    tr.classList.remove('jump-flash');
    void tr.offsetWidth;               /* restart animation */
    tr.classList.add('jump-flash');
    setTimeout(function () { tr.classList.remove('jump-flash'); }, 900);
  }

  /* Convert a tr's position to a Y coordinate inside the map */
  function rowY(tr) {
    var scrollTop  = scroll.getBoundingClientRect().top;
    var trTop      = tr.getBoundingClientRect().top;
    /* absolute offset within the scrollable content */
    return trTop - scrollTop + scroll.scrollTop;
  }

  /* ── Rebuild marks ─────────────────────────────────────────── */
  function rebuild() {
    map.querySelectorAll('.diff-map-mark').forEach(function (m) { m.remove(); });
    var tbl = activeTable();
    if (!tbl) return;

    var totalH = scroll.scrollHeight;
    var mapH   = map.clientHeight;
    if (!totalH || !mapH) return;

    tbl.querySelectorAll('tbody tr:not(.diff-merge-row)').forEach(function (tr) {
      var lc = tr.querySelector('.code.left');
      var rc = tr.querySelector('.code.right');
      var uc = tr.querySelector('.code:not(.left):not(.right)');

      var isDel = !!(lc && lc.classList.contains('del'))
               || !!(uc && uc.classList.contains('del'));
      var isAdd = !!(rc && rc.classList.contains('add'))
               || !!(uc && uc.classList.contains('add'));
      if (!isDel && !isAdd) return;

      var type = (isDel && isAdd) ? 'mod' : (isAdd ? 'add' : 'del');

      var absY = rowY(tr);
      var y = (absY   / totalH) * mapH;
      var h = Math.max(2, (tr.offsetHeight / totalH) * mapH);

      var mark = document.createElement('div');
      mark.className    = 'diff-map-mark ' + type;
      mark.style.top    = y.toFixed(1) + 'px';
      mark.style.height = h.toFixed(1) + 'px';
      mark.title        = { mod:'Modified', add:'Added', del:'Deleted' }[type] + ' line';

      (function (row) {
        mark.addEventListener('click', function (e) {
          e.stopPropagation();
          var center = rowY(row) - scroll.clientHeight / 2 + row.offsetHeight / 2;
          scroll.scrollTo({ top: Math.max(0, center), behavior: 'smooth' });
          flashRow(row);
        });
      })(tr);

      map.appendChild(mark);
    });

    syncThumb();
  }

  /* ── Viewport thumb ────────────────────────────────────────── */
  function syncThumb() {
    var mapH   = map.clientHeight;
    var totalH = scroll.scrollHeight;
    var viewH  = scroll.clientHeight;
    if (!mapH || !totalH) return;
    var h   = Math.max(16, (viewH  / totalH) * mapH);
    var top = Math.min(mapH - h,   (scroll.scrollTop / totalH) * mapH);
    thumb.style.height = h.toFixed(1)   + 'px';
    thumb.style.top    = top.toFixed(1) + 'px';
  }

  /* Drag thumb to scroll */
  var dragging = false, dragStartY = 0, dragStartScroll = 0;
  thumb.addEventListener('mousedown', function (e) {
    dragging = true;
    dragStartY      = e.clientY;
    dragStartScroll = scroll.scrollTop;
    e.preventDefault();
  });
  document.addEventListener('mousemove', function (e) {
    if (!dragging) return;
    var mapH   = map.clientHeight;
    var totalH = scroll.scrollHeight;
    var delta  = (e.clientY - dragStartY) / mapH * totalH;
    scroll.scrollTop = Math.max(0, Math.min(totalH, dragStartScroll + delta));
  });
  document.addEventListener('mouseup', function () { dragging = false; });

  /* Click map background to jump */
  map.addEventListener('click', function (e) {
    if (e.target === thumb) return;
    var rect = map.getBoundingClientRect();
    var pct  = Math.max(0, Math.min(1, (e.clientY - rect.top) / map.clientHeight));
    scroll.scrollTo({ top: pct * scroll.scrollHeight, behavior: 'smooth' });
  });

  scroll.addEventListener('scroll',  syncThumb, { passive: true });
  window.addEventListener('resize',  function () { rebuild(); }, { passive: true });

  /* Initial build — wait for layout to settle */
  setTimeout(rebuild, 350);

  /* Rebuild when switching view mode */
  ['btnSide', 'btnUni'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (btn) btn.addEventListener('click', function () { setTimeout(rebuild, 150); });
  });
})();

</script>
