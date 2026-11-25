<?php
// Prosty klient HTTP: zapytania do API z tokenem użytkownika, podgląd obrazków PNG/SVG i odpowiedzi JSON.
declare(strict_types=1);
require_once dirname(__DIR__) . '/sciezka.php';
require_once LX_DIR . '/php/layout.php';

$u = current_user();
if (!$u) { flash('Zaloguj się, żeby korzystać z klienta HTTP.', 'err'); redirect('./'); }
page_start('Klient HTTP', $u, 'konto');
?>
<p><a href="./">← Moje konto</a></p>
<h1>Klient HTTP</h1>
<p>Wyślij zapytanie do API swoim tokenem i zobacz odpowiedź. Akcja „Obrazek wzoru” zwraca gotowy PNG albo SVG do wstawienia w dokument lub prezentację.</p>

<form id="client" class="stack" style="max-width:46rem" novalidate>
  <fieldset style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:.5rem">
    <legend style="font-weight:600">Token</legend>
    <div class="row">
      <label class="f" for="token" style="flex:1 1 20rem">Token API (lx_…)
        <input type="password" id="token" autocomplete="off" spellcheck="false" aria-describedby="token-h" style="width:100%">
      </label>
      <button type="button" class="plain" id="show-token" aria-pressed="false" aria-controls="token">Pokaż token</button>
    </div>
    <p class="hint" id="token-h"><?= $u['api_token_hash'] ? 'Masz aktywny token. Wklej go tutaj — ze względów bezpieczeństwa nie pokazujemy go ponownie.' : 'Nie masz jeszcze tokenu.' ?> <a href="./#h-api">Utwórz nowy token na stronie konta</a>. Token zostaje tylko w tej karcie przeglądarki. Bez tokenu klient używa Twojej sesji logowania.</p>
  </fieldset>

  <label class="f" for="action">Akcja
    <select id="action">
      <option value="render">Obrazek wzoru (PNG / SVG)</option>
      <option value="to-latex">Zwykły zapis → LaTeX</option>
      <option value="from-latex">LaTeX → zwykły zapis</option>
      <option value="scan">Odczyt z kartki (zdjęcie lub PDF)</option>
    </select>
  </label>

  <div id="f-input" class="stack" style="max-width:none">
    <label class="f" for="input" id="input-label">Wzór</label>
    <textarea id="input" spellcheck="false" translate="no" rows="4">x = (-b +- sqrt(b^2 - 4ac)) / (2a)</textarea>
  </div>

  <fieldset id="f-render" style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:.5rem">
    <legend style="font-weight:600">Obrazek</legend>
    <div class="row">
      <label class="c" for="from-text"><input type="radio" name="from" id="from-text" value="text" checked> Wejście to zwykły zapis</label>
      <label class="c" for="from-latex"><input type="radio" name="from" id="from-latex" value="latex"> Wejście to LaTeX</label>
    </div>
    <div class="row">
      <label class="c" for="fmt-png"><input type="radio" name="format" id="fmt-png" value="png" checked> PNG</label>
      <label class="c" for="fmt-svg"><input type="radio" name="format" id="fmt-svg" value="svg"> SVG</label>
    </div>
    <div class="row">
      <label class="f" for="scale">Powiększenie PNG (1–8)<input type="number" id="scale" min="1" max="8" step="0.5" value="3" inputmode="decimal" style="width:7rem"></label>
      <label class="f" for="color">Kolor wzoru<input type="color" id="color" value="#000000" style="width:5rem;padding:.2rem"></label>
      <label class="f" for="bg">Tło<input type="color" id="bg" value="#ffffff" style="width:5rem;padding:.2rem"></label>
      <label class="c" for="transparent"><input type="checkbox" id="transparent"> Przezroczyste tło</label>
    </div>
  </fieldset>

  <div id="f-file" hidden>
    <label class="f" for="file">Plik ze wzorem (JPG, PNG, WEBP, GIF, PDF do 5 MB)
      <input type="file" id="file" accept="image/*,application/pdf">
    </label>
    <p class="hint">Przed wysłaniem klient zapyta, czy odczytać tylko wzory, czy cały dokument, a przy PDF-ie pokaże podgląd stron do wybrania.</p>
  </div>

  <button type="submit" id="send">Wyślij zapytanie</button>
</form>

<section class="block" aria-labelledby="req-h">
  <h2 id="req-h">Zapytanie jako curl</h2>
  <pre id="curl" translate="no" tabindex="0" aria-labelledby="req-h"></pre>
  <p class="hint">Token w poleceniu jest zastąpiony skrótem <code>$TOKEN</code>. Ustaw go poleceniem <code>export TOKEN=lx_…</code>.</p>
</section>

<section class="block" aria-labelledby="res-h" id="result" aria-busy="false">
  <h2 id="res-h">Odpowiedź</h2>
  <p id="res-status" role="status">Jeszcze nic nie wysłano.</p>
  <dialog class="upload" id="prog-box" aria-labelledby="prog-box-h">
      <h2 id="prog-box-h">Odczyt pliku</h2>
      <div class="prow"><label for="p-up">Wysyłanie pliku</label><span class="pct" id="p-up-pct" aria-hidden="true">0%</span></div>
      <progress id="p-up" max="100" value="0" aria-describedby="p-up-info"></progress>
      <p class="hint" id="p-up-info"></p>
      <div class="prow"><label for="p-ai">Odczyt przez AI <span class="hint">(szacunek)</span></label><span class="pct" id="p-ai-pct" aria-hidden="true">czeka</span></div>
      <progress id="p-ai" max="100" value="0" aria-describedby="p-ai-info"></progress>
      <p class="hint" id="p-ai-info"></p>
      <button type="button" class="abort" id="prog-box-abort">Przerwij</button>
    </dialog>
  <div id="res-image" hidden>
    <figure style="margin:.75rem 0;padding:1rem;border:1px solid var(--soft);border-radius:4px;overflow-x:auto">
      <img id="img" alt="" style="max-width:100%;height:auto">
      <figcaption class="hint" id="img-cap" style="margin-top:.5rem"></figcaption>
    </figure>
    <div class="row">
      <a class="btn" id="download" href="#" download="wzor.png">Pobierz obrazek</a>
      <button type="button" class="plain" id="copy-link">Kopiuj link do obrazka</button>
    </div>
    <p class="hint" style="margin-top:.5rem">Link jest podpisany Twoim kontem i działa bez tokenu, więc możesz go wstawić jako <code>&lt;img src="…"&gt;</code> na stronie albo w dokumencie. Przestanie działać, jeśli administrator wyłączy Ci API.</p>
  </div>
  <pre id="res-body" translate="no" tabindex="0" aria-label="Treść odpowiedzi" hidden></pre>
</section>

<style>
.upload { border: 2px solid var(--ink); border-radius: 6px; padding: 1.25rem 1.5rem; width: min(34rem, calc(100vw - 2rem)); background: var(--bg); color: var(--ink); }
.upload[open] { display: flex; flex-direction: column; gap: .3rem; }
.upload::backdrop { background: rgb(0 0 0 / .45); }
.upload h2 { margin: 0 0 .5rem; font-size: 1.2rem; }
.upload .prow { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; font-weight: 600; }
.upload .pct { font-size: 1.35rem; font-variant-numeric: tabular-nums; }
.upload progress { width: 100%; height: .9rem; accent-color: var(--accent); }
.upload .hint { margin: 0 0 .4rem; }
.upload .abort { align-self: flex-start; margin-top: .5rem; }
</style>
<script src="../odczyt.js"></script>
<script>
(() => {
  const $ = id => document.getElementById(id);
  const API = new URL('../api.php', location.href).href;
  let blobUrl = null;
  let lastRead = 'math', lastPages = '';
  try { lastRead = localStorage.getItem('eq2latex:mode-read') || 'math'; } catch {}
  try { const t = sessionStorage.getItem('lx_token'); if (t) $('token').value = t; } catch {}

  const PRESETS = {
    'to-latex': ['Wzór w zwykłym zapisie', 'x = (-b +- sqrt(b^2 - 4ac)) / (2a)'],
    'from-latex': ['Kod LaTeX', '\\frac{a}{b} + \\sqrt{x}'],
  };

  function params() {
    const action = $('action').value;
    const p = { action };
    if (action === 'render') {
      p.input = $('input').value;
      p.from = document.querySelector('input[name=from]:checked').value;
      p.format = document.querySelector('input[name=format]:checked').value;
      if (p.format === 'png') p.scale = Number($('scale').value) || 3;
      p.color = $('color').value;
      if (!$('transparent').checked) p.background = $('bg').value;
    } else if (action !== 'scan') {
      p.input = $('input').value;
    }
    return p;
  }

  const shq = s => "'" + String(s).replace(/'/g, "'\\''") + "'";
  function curl() {
    const p = params();
    const auth = $('token').value.trim() ? " \\\n  -H 'Authorization: Bearer '\"$TOKEN\"" : '';
    if (p.action === 'scan') {
      const name = $('file').files[0]?.name || 'kartka.jpg';
      return `curl -s ${API}${auth} \\\n  -F action=scan -F only_math=${lastRead === 'doc' ? 0 : 1}${lastPages ? ' -F pages=' + shq(lastPages) : ''} -F plik=@${shq(name)}\n# only_math: 1 = tylko wzory, 0 = cały dokument; pages: np. 1-3,5 (bez = wszystkie)`;
    }
    const out = p.action === 'render' ? ` \\\n  -o wzor.${p.format}` : '';
    return `curl -s ${API}${auth} \\\n  -H 'Content-Type: application/json' \\\n  -d ${shq(JSON.stringify(p))}${out}`;
  }

  function sync() {
    const a = $('action').value;
    $('f-render').hidden = a !== 'render';
    $('f-file').hidden = a !== 'scan';
    $('f-input').hidden = a === 'scan';
    if (a === 'render') {
      $('input-label').textContent = document.querySelector('input[name=from]:checked').value === 'text' ? 'Wzór w zwykłym zapisie' : 'Kod LaTeX';
    } else if (PRESETS[a]) {
      $('input-label').textContent = PRESETS[a][0];
    }
    $('scale').disabled = document.querySelector('input[name=format]:checked').value !== 'png';
    $('bg').disabled = $('transparent').checked;
    $('curl').textContent = curl();
  }

  $('action').addEventListener('change', () => {
    const a = $('action').value;
    if (PRESETS[a]) $('input').value = PRESETS[a][1];
    sync();
  });
  document.querySelectorAll('input[name=from]').forEach(r => r.addEventListener('change', () => {
    $('input').value = r.value === 'text' ? 'x = (-b +- sqrt(b^2 - 4ac)) / (2a)' : 'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}';
    sync();
  }));
  $('client').addEventListener('input', sync);
  $('client').addEventListener('change', sync);
  $('show-token').addEventListener('click', e => {
    const show = $('token').type === 'password';
    $('token').type = show ? 'text' : 'password';
    e.currentTarget.setAttribute('aria-pressed', String(show));
    e.currentTarget.textContent = show ? 'Ukryj token' : 'Pokaż token';
  });
  $('token').addEventListener('change', () => { try { sessionStorage.setItem('lx_token', $('token').value.trim()); } catch {} });

  // ---------- Dwa paski: wysyłanie (prawdziwy procent) i odczyt przez AI (szacunek) ----------
  const P = {
    box: $('prog-box'), up: $('p-up'), upPct: $('p-up-pct'), upInfo: $('p-up-info'),
    ai: $('p-ai'), aiPct: $('p-ai-pct'), aiInfo: $('p-ai-info'), tick: null,
  };
  const mb = b => (b / 1048576).toLocaleString('pl-PL', { maximumFractionDigits: 2 });
  function progStart(file) {
    clearInterval(P.tick);
    if (!P.box.open) P.box.showModal();
    P.up.value = 0; P.upPct.textContent = '0%';
    P.upInfo.textContent = file ? `${file.name} · ${mb(file.size)} MB` : 'Zapytanie';
    P.ai.value = 0; P.aiPct.textContent = 'czeka'; P.aiInfo.textContent = '';
  }
  function progUpload(loaded, total) {
    const pct = Math.round(loaded / total * 100);
    P.up.value = pct; P.upPct.textContent = pct + '%';
    P.upInfo.textContent = `Wysłano ${mb(loaded)} z ${mb(total)} MB`;
  }
  function progAi(file, announce) {
    P.up.value = 100; P.upPct.textContent = '100% ✓';
    P.upInfo.textContent = file ? `Wysłano ${mb(file.size)} MB` : 'Wysłano';
    announce('Plik wysłany w 100%. AI odczytuje wzory.');
    const isPdf = file && file.type === 'application/pdf';
    const size = file ? file.size : 50000;
    const expected = Math.min(150, (isPdf ? 12 : 10) + size / 1024 / (isPdf ? 12 : 60));
    const stages = [[0, 'czyta plik'], [35, 'przepisuje wzory'], [75, 'układa wynik']];
    const t0 = Date.now();
    let last = '';
    const show = () => {
      const sec = (Date.now() - t0) / 1000;
      const pct = Math.min(95, Math.round(95 * (1 - Math.exp(-sec / (expected / 2.2)))));
      P.ai.value = pct; P.aiPct.textContent = pct + '%';
      const stage = stages.filter(([f]) => pct >= f).pop()[1];
      P.aiInfo.textContent = `AI ${stage} · ${Math.round(sec)} s, zwykle ok. ${Math.round(expected)} s`;
      if (stage !== last && last) announce('AI ' + stage + '.');
      last = stage;
    };
    show();
    P.tick = setInterval(show, 500);
  }
  function progDone() {
    clearInterval(P.tick);
    P.up.value = 100; P.upPct.textContent = '100% ✓';
    P.ai.value = 100; P.aiPct.textContent = '100% ✓';
    P.aiInfo.textContent = 'Gotowe';
  }
  function progHide() { clearInterval(P.tick); P.tick = null; if (P.box.open) P.box.close(); P.xhr = null; }
  // Przerwij / Esc: zatrzymuje wysyłanie i odczyt
  const abortUpload = () => { if (P.xhr) P.xhr.abort(); };
  $('prog-box-abort').addEventListener('click', abortUpload);
  P.box.addEventListener('cancel', e => { e.preventDefault(); abortUpload(); });
  const hideProg = progHide;
  function sendWithProgress(headers, body, file) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API);
      Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
      xhr.responseType = 'blob';
      xhr.timeout = 180000;
      P.xhr = xhr;
      progStart(file);
      xhr.upload.onprogress = e => { if (e.lengthComputable) progUpload(e.loaded, e.total); };
      xhr.upload.onload = () => progAi(file, m => { $('res-status').textContent = m; });
      xhr.onload = () => {
        progDone();
        resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, type: xhr.getResponseHeader('Content-Type') || '', header: n => xhr.getResponseHeader(n), blob: xhr.response });
      };
      xhr.onerror = () => reject(new Error('network'));
      xhr.onabort = () => reject(new Error('abort'));
      xhr.ontimeout = () => reject(new Error('timeout'));
      xhr.send(body);
    });
  }


  $('client').addEventListener('submit', async e => {
    e.preventDefault();
    const p = params();
    const headers = {};
    const token = $('token').value.trim();
    if (token) headers.Authorization = 'Bearer ' + token;
    let body;
    if (p.action === 'scan') {
      const f = $('file').files[0];
      if (!f) { $('res-status').textContent = 'Wybierz plik ze wzorem.'; $('file').focus(); return; }
      const choice = await LatexRead.ask(f);
      if (!choice) { $('res-status').textContent = 'Anulowano odczyt. Nic nie zostało wysłane.'; return; }
      lastRead = choice.mode; lastPages = choice.pages; sync();
      body = new FormData(); body.append('action', 'scan'); body.append('plik', f, f.name);
      body.append('only_math', choice.mode === 'math' ? '1' : '0');
      if (choice.pages) body.append('pages', choice.pages);
    } else {
      if (!p.input.trim()) { $('res-status').textContent = 'Wpisz wzór.'; $('input').focus(); return; }
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(p);
    }
    $('result').setAttribute('aria-busy', 'true');
    $('send').disabled = true;
    $('res-status').textContent = p.action === 'scan' ? 'Wysyłam. Odczyt z kartki może potrwać do pół minuty…' : 'Wysyłam zapytanie…';
    const t0 = performance.now();
    try {
      const r = await sendWithProgress(headers, body, p.action === 'scan' ? $('file').files[0] : null);
      const ms = Math.round(performance.now() - t0);
      const type = r.type;
      const head = `Kod ${r.status} ${r.ok ? '(sukces)' : '(błąd)'}, ${ms} ms, typ ${type.split(';')[0] || 'brak'}.`;
      if (r.ok && type.startsWith('image/')) {
        const blob = r.blob;
        if (blobUrl) URL.revokeObjectURL(blobUrl);
        blobUrl = URL.createObjectURL(blob);
        const speech = decodeURIComponent(r.header('X-Formula-Speech') || '');
        const latex = decodeURIComponent(r.header('X-Formula-Latex') || '');
        $('img').src = blobUrl;
        $('img').alt = speech ? 'Wzór: ' + speech : 'Wzór';
        $('img-cap').textContent = 'LaTeX: ' + latex;
        $('download').href = blobUrl;
        $('download').download = 'wzor.' + p.format;
        $('res-image').hidden = false;
        $('res-body').hidden = true;
        $('res-status').textContent = `${head} Obrazek ${p.format.toUpperCase()}, ${Math.round(blob.size / 102.4) / 10} KB. Odczyt: ${speech}`;
      } else {
        const txt = await r.blob.text();
        let pretty = txt; try { pretty = JSON.stringify(JSON.parse(txt), null, 2); } catch {}
        $('res-body').textContent = pretty;
        $('res-body').hidden = false;
        $('res-image').hidden = true;
        $('res-status').textContent = head + ' Treść poniżej.';
      }
    } catch (err) {
      $('res-status').textContent = err?.message === 'abort' ? 'Przerwano zapytanie.' : 'Nie udało się połączyć z API. Sprawdź połączenie i spróbuj ponownie.';
    } finally {
      hideProg();
      $('result').setAttribute('aria-busy', 'false');
      $('send').disabled = false;
    }
  });

  $('copy-link').addEventListener('click', async () => {
    // Link podpisany Twoim kontem: działa bez tokenu, a admin może go unieważnić, wyłączając Ci API
    let url;
    try {
      const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ ...params(), action: 'sign' }) });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'błąd ' + r.status);
      url = d.url;
    } catch (e) { $('res-status').textContent = 'Nie udało się przygotować linku: ' + e.message; return; }
    try { await navigator.clipboard.writeText(url); $('res-status').textContent = 'Skopiowano podpisany link do obrazka.'; }
    catch { $('res-body').textContent = url; $('res-body').hidden = false; $('res-status').textContent = 'Nie udało się skopiować. Link jest poniżej.'; }
  });

  sync();
})();
</script>
<?php
page_end();
