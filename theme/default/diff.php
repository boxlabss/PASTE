<?php
/*
 * Paste $v3.3 https://github.com/boxlabss/PASTE
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
		  <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Jump">
			<button class="btn btn-outline-secondary" id="btnPrevChange" type="button" title="Previous change">
			  <i class="bi bi-chevron-up" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Prev</span>
			</button>
			<button class="btn btn-outline-secondary" id="btnNextChange" type="button" title="Next change">
			  <i class="bi bi-chevron-down" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Next</span>
			</button>
		  </div>
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

		<button class="btn btn-primary btn-sm" id="btnDownload" type="button">
		  <i class="bi bi-download" aria-hidden="true"></i><span class="ms-1">Download .diff</span>
		</button>
		<button class="btn btn-outline-primary btn-sm" id="btnPatch" type="button">
		  <i class="bi bi-git" aria-hidden="true"></i><span class="ms-1">Download .patch</span>
		</button>
	  </div>
	</div>

	<!-- Language selector + actions -->
	<div class="diff-toolbar btn-toolbar mt-2 gap-2 flex-wrap align-items-center">
	  <div class="input-group input-group-sm w-auto" style="min-width: 14rem;">
		<span class="input-group-text">Language</span>
		<select class="form-select form-select-sm" id="langAll" aria-label="Diff language">
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
		<button class="btn btn-outline-secondary" id="btnSwap" type="button" title="Swap panes">
		  <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Swap</span>
		</button>
		<button class="btn btn-success" id="btnCompare" type="button" title="Recompute diff">
		  <i class="bi bi-play" aria-hidden="true"></i><span class="ms-1">Compare</span>
		</button>
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
        <?php $lang_unified = ($lang_right ?: $lang_left) ?? 'text'; ?>
        <?php foreach ($uniRows as $r): ?>
          <tr>
            <td class="no"><?= $h($r['lno']) ?></td>
            <td class="no"><?= $h($r['rno']) ?></td>
            <td class="code <?= $r['class'] ?>"><div class="code-inner">
              <?php if ($r['class'] === 'ctx'): ?>
                <?= hl_render_line((string)$r['html'], $lang_unified) ?>
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

  // Swap (swap textareas only; one language applies to both)
  $('#btnSwap')?.addEventListener('click', ()=>{
    const a=$('#leftText'), b=$('#rightText'); const t=a.value; a.value=b.value; b.value=t;
  });

  // Compare
  $('#btnCompare')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href); url.searchParams.delete('download'); f.action=url.pathname+url.search;
    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    const lang = $('#langAll')?.value ?? 'autodetect';
    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',lang));
    f.appendChild(mk('right_lang',lang));
    f.appendChild(mk('split_pct',String(splitPct)));
    document.body.appendChild(f); f.submit();
  });

  // Download .diff
  $('#btnDownload')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href); url.searchParams.set('download','1'); f.action=url.pathname+'?'+url.searchParams.toString();
    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    const lang = $('#langAll')?.value ?? 'autodetect';
    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',lang));
    f.appendChild(mk('right_lang',lang));
    f.appendChild(mk('left_label','<?= $h($leftLabel) ?>'));
    f.appendChild(mk('right_label','<?= $h($rightLabel) ?>'));
    f.appendChild(mk('split_pct',String(splitPct)));
    document.body.appendChild(f); f.submit();
  });

  // Download .patch
  $('#btnPatch')?.addEventListener('click', ()=>{
    const f=document.createElement('form'); f.method='POST';
    const url=new URL(location.href);
    url.searchParams.set('download','patch');       // only a tiny GET flag
    f.action = url.pathname+'?'+url.searchParams.toString();

    const mk=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; return i; };
    const lang = $('#langAll')?.value ?? 'autodetect';

    f.appendChild(mk('left_text',$('#leftText').value));
    f.appendChild(mk('right_text',$('#rightText').value));
    f.appendChild(mk('left_lang',lang));
    f.appendChild(mk('right_lang',lang));
    f.appendChild(mk('left_label','<?= $h($leftLabel) ?>'));
    f.appendChild(mk('right_label','<?= $h($rightLabel) ?>'));
    f.appendChild(mk('split_pct',String(splitPct)));

    document.body.appendChild(f); f.submit();
  });

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
    function changeRows() {
      const side = qs('#tblSide tbody tr').filter(tr => {
        const l = tr.querySelector('.code.left');
        const r = tr.querySelector('.code.right');
        return l?.classList.contains('del') || r?.classList.contains('add');
      });
      const uni  = qs('#tblUni  tbody tr').filter(tr => {
        const c = tr.querySelector('.code');
        return c?.classList.contains('add') || c?.classList.contains('del');
      });
      // whichever table is visible
      const tblSide = document.getElementById('tblSide');
      return (tblSide && tblSide.style.display !== 'none') ? side : uni;
    }

    function scrollToRow(row) {
      if (!row) return;
      row.classList.add('jump-flash');
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(()=> row.classList.remove('jump-flash'), 600);
    }

    let idx = -1;
    function jump(dir) {
      const rows = changeRows();
      if (!rows.length) return;
      idx = (idx + dir + rows.length) % rows.length;
      scrollToRow(rows[idx]);
    }

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
