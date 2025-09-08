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

/* Engine badge -+changes + ignore WS toggle (provided by controller) */
$engine_badge_html = $GLOBALS['diff_engine_badge'] ?? '';
$ws_on             = !empty($GLOBALS['ignore_ws_on']);
$ws_toggle_url     = (string)($GLOBALS['ignore_ws_toggle'] ?? '#');

$no_changes        = !empty($GLOBALS['diff_no_changes']);
$changes_add       = (int)($GLOBALS['diff_changes_add'] ?? 0);
$changes_del       = (int)($GLOBALS['diff_changes_del'] ?? 0);
$changes_total     = (int)($GLOBALS['diff_changes_total'] ?? ($changes_add + $changes_del));
?>
<div class="container-fluid diff-outer">
  <!-- Top toolbar -->
  <div class="diff-toolbar">
    <div class="grow">
      <span class="lbl">Left:</span><span class="badge bg-secondary-subtle"><?= $h($leftLabel ?? 'Old code') ?></span>
      <span class="lbl ms-2">Right:</span><span class="badge bg-secondary-subtle"><?= $h($rightLabel ?? 'New code') ?></span>
      <span class="lbl ms-2">Languages:</span>
      <span class="badge bg-secondary-subtle"><?= $h($lang_left_label ?? '') ?></span>
      <span class="badge bg-secondary-subtle"><?= $h($lang_right_label ?? '') ?></span>

      <?php if (!empty($engine_badge_html)): ?>
        <span class="ms-2"><?= $engine_badge_html /* safe HTML */ ?></span>
      <?php endif; ?>

      <?php if ($no_changes): ?>
        <span class="badge bg-success ms-2" title="No differences between left and right">No changes</span>
      <?php else: ?>
        <span class="badge bg-success ms-2" title="Added lines">+<?= $changes_add ?></span>
        <span class="badge bg-danger  ms-1" title="Deleted lines">-<?= $changes_del ?></span>
        <span class="badge bg-secondary-subtle ms-1" title="Total changed lines">±<?= $changes_total ?></span>
      <?php endif; ?>
    </div>

    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="optWrap" <?= !empty($wrap) ? 'checked':'' ?>>
      <label class="form-check-label" for="optWrap">Wrap</label>
    </div>
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="optLine" <?= !empty($lineno) ? 'checked':'' ?>>
      <label class="form-check-label" for="optLine">Line #</label>
    </div>

    <a class="btn btn-outline-secondary btn-sm" id="btnWS"
       href="<?= $h($ws_toggle_url) ?>"
       role="button"
       title="Toggle ignoring trailing whitespace">
      <?= $ws_on ? 'Whitespace: Ignored' : 'Whitespace: Shown' ?>
    </a>

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
          <?php
            $printed = [];
            foreach ($popular_langs as $pid):
                $lid = strtolower($pid);
                if (!isset($language_map[$lid])) continue;
                $printed[$lid] = true;
                $sel = ($lid === strtolower($lang_left ?? '')) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($language_map[$lid]) ?></option>
          <?php endforeach; ?>
          <option disabled>──────────</option>
          <?php foreach ($language_map as $lid => $label):
                if (isset($printed[$lid])) continue;
                $sel = ($lid === strtolower($lang_left ?? '')) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="d-flex gap-2 align-items-center">
        <small class="text-muted">Right language</small>
        <select class="form-select form-select-sm lang-select" id="rightLang">
          <option value="autodetect" <?= (strtolower($lang_right ?? '')==='autodetect')?'selected':''; ?>>Autodetect</option>
          <option disabled>──────────</option>
          <?php
            $printed = [];
            foreach ($popular_langs as $pid):
                $lid = strtolower($pid);
                if (!isset($language_map[$lid])) continue;
                $printed[$lid] = true;
                $sel = ($lid === strtolower($lang_right ?? '')) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($language_map[$lid]) ?></option>
          <?php endforeach; ?>
          <option disabled>──────────</option>
          <?php foreach ($language_map as $lid => $label):
                if (isset($printed[$lid])) continue;
                $sel = ($lid === strtolower($lang_right ?? '')) ? ' selected' : '';
          ?>
                <option value="<?= $h($lid) ?>"<?= $sel ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
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
          <textarea class="form-control code-input paste-textarea" rows="10" id="leftText" data-editor="true" spellcheck="false" placeholder="old version"><?= $h($GLOBALS['left'] ?? '') ?></textarea>
        </div>
        <div class="col-lg-6">
          <textarea class="form-control code-input paste-textarea" rows="10" id="rightText" data-editor="true" spellcheck="false" placeholder="new version"><?= $h($GLOBALS['right'] ?? '') ?></textarea>
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
              <?php if ($r['lclass'] === 'ctx'): ?>
                <?= hl_render_line((string)$r['lhtml'], $lang_left ?? 'text') ?>
              <?php else: ?>
                <?php if ($r['lhtml'] !== ''): ?>
                  <span class="marker"><?= $r['lclass']==='del' ? '–' : '' ?></span>
                <?php endif; ?>
                <?= $r['l_intra'] ? $r['lhtml'] : $h($r['lhtml']) ?>
              <?php endif; ?>
            </div></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code right <?= $r['rclass'] ?>"><div class="code-inner">
              <?php if ($r['rclass'] === 'ctx'): ?>
                <?= hl_render_line((string)$r['rhtml'], $lang_right ?? 'text') ?>
              <?php else: ?>
                <?php if ($r['rhtml'] !== ''): ?>
                  <span class="marker"><?= $r['rclass']==='add' ? '+' : '' ?></span>
                <?php endif; ?>
                <?= $r['r_intra'] ? $r['rhtml'] : $h($r['rhtml']) ?>
              <?php endif; ?>
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
              <?php if ($r['class'] === 'ctx'): ?>
                <?= hl_render_line((string)$r['html'], ($lang_right ?: $lang_left) ?? 'text') ?>
              <?php else: ?>
                <?php if ($r['html'] !== ''): ?>
                  <span class="marker"><?= $r['class']==='add' ? '+' : ($r['class']==='del' ? '–' : '') ?></span>
                <?php endif; ?>
                <?= $r['intra'] ? $r['html'] : $h($r['html']) ?>
              <?php endif; ?>
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

  // Swap
  $('#btnSwap')?.addEventListener('click', ()=>{
    const a=$('#leftText'), b=$('#rightText'); const t=a.value; a.value=b.value; b.value=t;
    const la=$('#leftLang'), lb=$('#rightLang'); if (la&&lb){ const tv=la.value; la.value=lb.value; lb.value=tv; }
  });

  // Compare
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

  // Download
  $('#btnDownload')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href); url.searchParams.set('download','1'); f.action=url.pathname+'?'+url.searchParams.toString();
    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',$('#leftLang').value));
    f.appendChild(mk('right_lang',$('#rightLang').value));
    f.appendChild(mk('left_label','<?= $h($leftLabel ?? "Old") ?>'));
    f.appendChild(mk('right_label','<?= $h($rightLabel ?? "New") ?>'));
    f.appendChild(mk('split_pct',String(splitPct)));
    document.body.appendChild(f); f.submit();
  });

  // Layout
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