<?php
/*
 * Paste $v3.2 2025/09/04 https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 */
 
declare(strict_types=1);
$h = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
?>

<style>
/* =========================
   Basic layout & toolbar
   =======================*/
.diff-outer { padding-top: 12px; }

.diff-toolbar{
  display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;
  padding:.5rem .75rem; border:1px solid var(--bs-border-color,#3333);
  border-radius:.5rem; background: var(--bs-body-bg, transparent);
}
.diff-toolbar .grow{ flex:1; }
.diff-toolbar .lbl{ opacity:.8; font-size:.9rem; margin-right:.25rem; }
.langbar{ display:grid; grid-template-columns: 1fr 1fr; gap:.75rem; width:100%; }
.lang-select{ min-width:160px; }

/* =========================
   Scroll viewport & splitter
   =======================*/
.diff-area{ margin-top:.75rem; position:relative; }
.diff-scroll{
  --lno: 56px;                                    /* default per-side line number width */
  height: clamp(60vh, calc(100vh - 240px), 88vh); /* fixed scrollport height */
  overflow: auto;
  border:1px solid var(--bs-border-color,#3333);
  border-radius:.5rem;
  position: relative;
}

.split-overlay{
  position: absolute; inset: 0;
  z-index: 20;
  pointer-events: none;
}

/* Splitter handle */
.splitter{
  position: absolute; top: 0; bottom: 0; left: 0;
  width: 12px; cursor: col-resize; z-index: 21; background: transparent;
  margin-left: 0 !important; transform: none !important;
  pointer-events: auto;
}
/* Visible center line + subtle hover/drag wash */
.splitter::before{
  content:""; position:absolute; top:0; bottom:0; left:50%;
  width:2px; margin-left:-1px; border-radius:1px;
  background: var(--bs-border-color, #6c757d66);
}
.splitter::after{
  content:""; position:absolute; inset:0;
  background:#6c757d1a; opacity:0; transition: opacity .12s ease-in-out;
  pointer-events:none;
}
.splitter:hover::before{ background: var(--bs-border-color, #6c757db3); }
.splitter:hover::after, .splitter.dragging::after{ opacity:.35; }

/* =========================
   Diff tables
   =======================*/
.diff-table{
  width:100%;
  border-collapse: separate;
  border-spacing:0;
  table-layout:fixed;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size:13px; line-height:1.35;
}
.diff-table td{ vertical-align: top; }

/* line numbers live inside each pane */
.diff-table td.no{
  width: var(--lno); min-width: var(--lno);
  color: var(--bs-secondary-color,#8892a6);
  user-select:none; text-align:right;
  padding: 2px 6px; border-right:1px solid var(--bs-border-color,#0001);
}

/* code cells */
.diff-table td.code{ padding:0; }
.code-inner{ display:block; width:100%; padding:2px 8px; overflow:auto; }
/* neutralize highlight block padding */
.code-inner .hljs{ display:inline; padding:0 !important; background:transparent; line-height:inherit; }

/* zebra */
.diff-table tr:nth-child(even) td { background: rgba(0,0,0,.02); }
@media (prefers-color-scheme: dark){
  .diff-table tr:nth-child(even) td { background: rgba(255,255,255,.03); }
}

/* change surfaces */
.diff-table td.ctx  { background: transparent; }
.diff-table td.add  { background: rgba(25,135,84,.16); }
.diff-table td.del  { background: rgba(220,53,69,.18); }
.diff-table td.empty{ background: rgba(108,117,125,.10); }

.marker{ font-weight:600; margin-right:.25rem; opacity:.9; }

/* wrapping + line numbers toggles */
.wrap-off .code-inner{ white-space: pre; }
.wrap-on  .code-inner{ white-space: pre-wrap; word-break: break-word; }

/* Keep cells but collapse when line numbers are off (prevents layout jump) */
.lineoff td.no{
  width:0 !important; min-width:0 !important;
  padding:0 !important; border:0 !important;
  color:transparent !important; overflow:hidden !important;
}
.lineoff col.col-lno-l,
.lineoff col.col-lno-r{ width:0 !important; min-width:0 !important; }

/* unified specifics */
.diff-table.unified td.no{ width:2.75rem; min-width:2.75rem; }
.diff-table.unified.lineoff td.no{ width:0 !important; min-width:0 !important; padding:0 !important; border:0 !important; }
.diff-table.unified td.code{ width:auto; }

/* responsive */
@media (max-width:1000px){ .langbar{ grid-template-columns: 1fr; } }
</style>

<div class="container-fluid diff-outer">
  <!-- Top toolbar -->
  <div class="diff-toolbar">
    <div class="grow">
      <span class="lbl">Left:</span><span class="badge bg-secondary-subtle"><?= $h($leftLabel ?? 'Old code') ?></span>
      <span class="lbl ms-2">Right:</span><span class="badge bg-secondary-subtle"><?= $h($rightLabel ?? 'New code') ?></span>
      <span class="lbl ms-2">Languages:</span>
      <span class="badge bg-secondary-subtle"><?= $h($lang_left_label ?? '') ?></span>
      <span class="badge bg-secondary-subtle"><?= $h($lang_right_label ?? '') ?></span>
    </div>

    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="optWrap" <?= !empty($wrap) ? 'checked':'' ?>>
      <label class="form-check-label" for="optWrap">Wrap</label>
    </div>
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="optLine" <?= !empty($lineno) ? 'checked':'' ?>>
      <label class="form-check-label" for="optLine">Line #</label>
    </div>

    <div class="btn-group" role="group">
      <button type="button" class="btn btn-outline-secondary btn-sm <?= ($view_mode ?? '')==='side'?'active':'' ?>" id="btnSide">Side-by-side</button>
      <button type="button" class="btn btn-outline-secondary btn-sm <?= ($view_mode ?? '')==='unified'?'active':'' ?>" id="btnUni">Unified</button>
    </div>

    <button class="btn btn-primary btn-sm" id="btnDownload" type="button">Download .diff</button>
  </div>

  <!-- Language selectors -->
  <div class="diff-toolbar mt-2">
    <div class="langbar">
      <div class="d-flex gap-2 align-items-center">
        <small class="text-muted">Left language</small>
        <select class="form-select form-select-sm lang-select" id="leftLang">
          <option value="autodetect" <?= (strtolower($lang_left ?? '')==='autodetect')?'selected':''; ?>>Autodetect</option>
          <option disabled>──────────</option>
          <?php $printed=[]; foreach ($popular_langs as $pid) {
            $lid=strtolower($pid); if (!isset($language_map[$lid])) continue; $printed[$lid]=true;
            $sel = ($lid === strtolower($lang_left ?? '')) ? ' selected' : '';
            echo '<option value="'.$h($lid).'"'.$sel.'>'.$h($language_map[$lid]).'</option>';
          }
          echo '<option disabled>──────────</option>';
          foreach ($language_map as $lid=>$label) {
            if (isset($printed[$lid])) continue;
            $sel = ($lid === strtolower($lang_left ?? '')) ? ' selected' : '';
            echo '<option value="'.$h($lid).'"'.$sel.'>'.$h($label).'</option>';
          } ?>
        </select>
      </div>

      <div class="d-flex gap-2 align-items-center">
        <small class="text-muted">Right language</small>
        <select class="form-select form-select-sm lang-select" id="rightLang">
          <option value="autodetect" <?= (strtolower($lang_right ?? '')==='autodetect')?'selected':''; ?>>Autodetect</option>
          <option disabled>──────────</option>
          <?php $printed=[]; foreach ($popular_langs as $pid) {
            $lid=strtolower($pid); if (!isset($language_map[$lid])) continue; $printed[$lid]=true;
            $sel = ($lid === strtolower($lang_right ?? '')) ? ' selected' : '';
            echo '<option value="'.$h($lid).'"'.$sel.'>'.$h($language_map[$lid]).'</option>';
          }
          echo '<option disabled>──────────</option>';
          foreach ($language_map as $lid=>$label) {
            if (isset($printed[$lid])) continue;
            $sel = ($lid === strtolower($lang_right ?? '')) ? ' selected' : '';
            echo '<option value="'.$h($lid).'"'.$sel.'>'.$h($label).'</option>';
          } ?>
        </select>
      </div>
    </div>

    <div class="d-flex flex-column gap-2 ms-2">
      <button class="btn btn-outline-secondary btn-sm" id="btnSwap" type="button">Swap</button>
      <button class="btn btn-success btn-sm" id="btnCompare" type="button">Compare</button>
    </div>
  </div>

  <!-- Editors -->
  <div class="diff-toolbar mt-2">
    <div class="w-100">
      <div class="row g-3">
        <div class="col-lg-6">
          <textarea class="form-control code-input paste-textarea" rows="10" id="leftText" data-editor="true" spellcheck="false"><?= $h($GLOBALS['left'] ?? '') ?></textarea>
        </div>
        <div class="col-lg-6">
          <textarea class="form-control code-input paste-textarea" rows="10" id="rightText" data-editor="true" spellcheck="false"><?= $h($GLOBALS['right'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Diff viewer -->
  <div class="diff-area mt-2">
    <div class="diff-scroll" id="diffScroll" data-init-split="<?= $h((string)($split_pct ?? 50)) ?>">
      <div class="split-overlay" id="splitOverlay">
        <div class="splitter" id="splitter" role="separator" aria-orientation="vertical" aria-label="Resize"></div>
      </div>

      <!-- side-by-side: [l#][lcode][r#][rcode] -->
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
            <td class="code left <?= $r['lclass'] ?>"><div class="code-inner">
              <?php
                if ($r['lclass'] === 'ctx') {
                  echo hl_render_line((string)$r['lhtml'], $lang_left ?? 'text');
                } else {
                  if ($r['lhtml'] !== '') echo '<span class="marker">'.($r['lclass']==='del'?'–':'').'</span>';
                  echo $r['l_intra'] ? $r['lhtml'] : $h($r['lhtml']);
                }
              ?>
            </div></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code right <?= $r['rclass'] ?>"><div class="code-inner">
              <?php
                if ($r['rclass'] === 'ctx') {
                  echo hl_render_line((string)$r['rhtml'], $lang_right ?? 'text');
                } else {
                  if ($r['rhtml'] !== '') echo '<span class="marker">'.($r['rclass']==='add'?'+':'').'</span>';
                  echo $r['r_intra'] ? $r['rhtml'] : $h($r['rhtml']);
                }
              ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- unified -->
      <table class="diff-table unified <?= !empty($wrap) ? 'wrap-on':'wrap-off' ?> <?= !empty($lineno) ? '':'lineoff' ?>" id="tblUni" <?= ($view_mode ?? '')==='side'?'style="display:none"':'' ?>>
        <tbody>
        <?php foreach ($uniRows as $r): ?>
          <tr>
            <td class="no"><?= $h($r['lno']) ?></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code <?= $r['class'] ?>"><div class="code-inner">
              <?php
                if ($r['class'] === 'ctx') {
                  echo hl_render_line((string)$r['html'], ($lang_right ?: $lang_left) ?? 'text');
                } else {
                  echo $r['html'] !== '' ? '<span class="marker">'.($r['class']==='add'?'+':($r['class']==='del'?'–':'')).'</span>' : '';
                  echo $r['intra'] ? $r['html'] : $h($r['html']);
                }
              ?>
            </div></td>
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

  // ---- Swap
  $('#btnSwap')?.addEventListener('click', ()=>{
    const a=$('#leftText'), b=$('#rightText'); const t=a.value; a.value=b.value; b.value=t;
    const la=$('#leftLang'), lb=$('#rightLang'); if (la&&lb){ const tv=la.value; la.value=lb.value; lb.value=tv; }
  });

  // ---- Compare
  $('#btnCompare')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href); url.searchParams.delete('download'); f.action=url.pathname+url.search;
    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',$('#leftLang').value));
    f.appendChild(mk('right_lang',$('#rightLang').value));
    f.appendChild(mk('split_pct',String(splitPct)));
    document.body.appendChild(f); f.submit();
  });

  // ---- Download
  $('#btnDownload')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href); url.searchParams.set('download','1'); f.action=url.pathname+'?'+url.searchParams.toString();
    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',$('#leftLang').value));
    f.appendChild(mk('right_lang',$('#rightLang').value));
    f.appendChild(mk('left_label','<?= $h($leftLabel ?? "Left") ?>'));
    f.appendChild(mk('right_label','<?= $h($rightLabel ?? "Right") ?>'));
    f.appendChild(mk('split_pct',String(splitPct)));
    document.body.appendChild(f); f.submit();
  });

  // ---- Layout
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
      cols[0].style.width = lno+'px';        // l#
      cols[1].style.width = leftCode+'px';   // lcode
      cols[2].style.width = lno+'px';        // r#
      cols[3].style.width = rightCode+'px';  // rcode
    }

    const barW = bar.offsetWidth || 12;
    const x = lno + leftCode - (barW/2);
    placeBar(x);
  }

  // ---- View toggles
  $('#btnSide')?.addEventListener('click', ()=>{
    tblSide.style.display=''; tblUni.style.display='none';
    overlay.style.display='';     // show splitter overlay
    requestAnimationFrame(layoutSideTable);
  });
  $('#btnUni')?.addEventListener('click', ()=>{
    tblUni.style.display='';   tblSide.style.display='none';
    overlay.style.display='none'; // hide splitter in unified view
  });

  // ---- Wrap / Line toggles
  $('#optWrap')?.addEventListener('change', (e)=>{
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('wrap-on', e.target.checked));
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('wrap-off', !e.target.checked));
    requestAnimationFrame(layoutSideTable);
  });
  $('#optLine')?.addEventListener('change', (e)=>{
    [tblSide, tblUni].forEach(t=> t && t.classList.toggle('lineoff', !e.target.checked));
    requestAnimationFrame(layoutSideTable);
  });

  // ---- Dragging
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

      placeBar(px - (barW/2));   // update position immediately
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
