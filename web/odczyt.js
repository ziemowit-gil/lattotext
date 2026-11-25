/*
 * odczyt.js — wspólne okno przed odczytem pliku (strona główna i klient HTTP).
 * Pyta o tryb (tylko wzory / cały dokument), a przy PDF-ie pokazuje podgląd stron i pyta, które odczytać.
 * Użycie: const choice = await LatexRead.ask(file); // null = anulowano, inaczej { mode: 'math'|'doc', pages: '' | '1-3, 5' }
 */
(() => {
  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
  const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  const MAX_THUMBS = 40;

  const pl = n => (n === 1 ? 'strona' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 'strony' : 'stron');

  const CSS = `
.lxr { border: 2px solid var(--ink, #16191f); border-radius: 6px; padding: 1.25rem 1.5rem; width: min(46rem, calc(100vw - 2rem)); max-height: calc(100vh - 2rem); background: var(--bg, #fff); color: var(--ink, #16191f); }
.lxr::backdrop { background: rgb(0 0 0 / .45); }
.lxr h2 { margin: 0 0 .25rem; font-size: 1.25rem; }
.lxr h3 { margin: 1rem 0 .25rem; font-size: 1.05rem; }
.lxr .hint { color: var(--muted, #4f5666); font-size: .95rem; margin: 0; }
.lxr fieldset { border: 0; padding: 0; margin: .75rem 0 0; display: flex; flex-direction: column; gap: .4rem; }
.lxr legend { font-weight: 600; padding: 0; margin-bottom: .25rem; }
.lxr .opt { display: flex; gap: .6rem; align-items: flex-start; min-height: 2.75rem; cursor: pointer; }
.lxr .opt input { width: 1.25rem; height: 1.25rem; margin: .2rem 0 0; flex: none; accent-color: var(--accent, #1a3db0); }
.lxr .range { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: .5rem; }
.lxr .range label { font-weight: 600; }
.lxr input[type=text] { font: inherit; padding: .4rem .6rem; min-height: 2.75rem; border: 2px solid var(--line, #767d8c); border-radius: 4px; background: var(--bg, #fff); color: var(--ink, #16191f); width: 12rem; }
.lxr input[aria-invalid=true] { border-color: var(--danger, #a0101c); }
.lxr button { font: inherit; min-height: 2.75rem; padding: .4rem .9rem; border-radius: 4px; border: 2px solid var(--ink, #16191f); background: var(--bg, #fff); color: var(--ink, #16191f); cursor: pointer; }
.lxr button.go { background: var(--ink, #16191f); color: var(--bg, #fff); }
.lxr button:focus-visible, .lxr input:focus-visible { outline: 3px solid var(--accent, #1a3db0); outline-offset: 2px; }
.lxr .thumbs { list-style: none; margin: .75rem 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .75rem; max-height: 46vh; overflow-y: auto; }
.lxr .thumbs label { display: flex; flex-direction: column; gap: .3rem; align-items: center; padding: .4rem; border: 2px solid var(--soft, #e3e6ec); border-radius: 4px; cursor: pointer; }
.lxr .thumbs label:has(input:checked) { border-color: var(--accent, #1a3db0); }
.lxr .thumbs label:has(input:focus-visible) { outline: 3px solid var(--accent, #1a3db0); outline-offset: 2px; }
.lxr .thumbs .top { display: flex; gap: .4rem; align-items: center; font-weight: 600; font-size: .95rem; }
.lxr .thumbs input { width: 1.2rem; height: 1.2rem; margin: 0; accent-color: var(--accent, #1a3db0); }
.lxr .thumbs canvas { width: 100%; height: auto; background: #fff; box-shadow: 0 0 0 1px var(--soft, #e3e6ec); }
.lxr .err { color: var(--danger, #a0101c); font-weight: 600; margin: .5rem 0 0; }
.lxr .err:empty { display: none; }
.lxr .actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
@media (prefers-color-scheme: dark) { .lxr .thumbs canvas { box-shadow: none; } }
`;

  const HTML = `
<form method="dialog" novalidate>
  <h2 id="lxr-h">Jak odczytać plik?</h2>
  <p class="hint" id="lxr-file"></p>
  <fieldset>
    <legend>Tryb odczytu</legend>
    <label class="opt"><input type="radio" name="lxr-mode" value="math"> <span><strong>Tylko wzory</strong><br><span class="hint">pomija tytuły, opisy, numery stron i inny tekst</span></span></label>
    <label class="opt"><input type="radio" name="lxr-mode" value="doc"> <span><strong>Cały dokument</strong><br><span class="hint">tekst jako akapity, wzory w LaTeX-u, do pobrania jako .tex</span></span></label>
  </fieldset>
  <section id="lxr-pdf" aria-labelledby="lxr-ph" hidden>
    <h3 id="lxr-ph">Które strony odczytać?</h3>
    <p class="hint" id="lxr-info" aria-live="polite">Wczytuję podgląd…</p>
    <div class="range">
      <label for="lxr-range">Strony</label>
      <input type="text" id="lxr-range" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="np. 1-3, 5" aria-describedby="lxr-range-h">
      <button type="button" id="lxr-all">Wszystkie</button>
      <button type="button" id="lxr-none">Żadna</button>
    </div>
    <p class="hint" id="lxr-range-h">Zaznacz miniatury albo wpisz numery stron. Odczytujesz i płacisz tylko za wybrane strony.</p>
    <ul class="thumbs" id="lxr-thumbs" aria-label="Strony dokumentu"></ul>
  </section>
  <p class="err" id="lxr-err" role="alert"></p>
  <div class="actions">
    <button type="submit" value="ok" class="go" id="lxr-ok">Odczytaj</button>
    <button type="submit" value="cancel" id="lxr-cancel">Anuluj</button>
  </div>
</form>`;

  let dlg, pdfjsReady, pageCount = 0;
  const $ = id => document.getElementById(id);

  function loadScript(src) {
    return new Promise((ok, fail) => {
      if (document.querySelector(`script[src="${src}"]`)) return ok();
      const s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = () => fail(new Error('Nie udało się wczytać podglądu PDF.'));
      document.head.appendChild(s);
    });
  }
  async function pdfjs() {
    if (!pdfjsReady) pdfjsReady = loadScript(PDFJS).then(() => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; return window.pdfjsLib; });
    return pdfjsReady;
  }

  function build() {
    if (dlg) return;
    const style = document.createElement('style');
    style.textContent = CSS;
    document.head.appendChild(style);
    dlg = document.createElement('dialog');
    dlg.className = 'lxr';
    dlg.setAttribute('aria-labelledby', 'lxr-h');
    dlg.setAttribute('aria-describedby', 'lxr-file');
    dlg.innerHTML = HTML;
    document.body.appendChild(dlg);
    $('lxr-all').addEventListener('click', () => { setChecked(() => true); $('lxr-range').focus(); });
    $('lxr-none').addEventListener('click', () => { setChecked(() => false); $('lxr-range').focus(); });
    $('lxr-range').addEventListener('input', () => {
      const r = parse($('lxr-range').value);
      $('lxr-range').setAttribute('aria-invalid', r.error ? 'true' : 'false');
      $('lxr-err').textContent = '';
      if (!r.error) setChecked(n => r.pages.has(n), false);
      else showInfo(r.error);
    });
    $('lxr-thumbs').addEventListener('change', () => { syncRange(); $('lxr-err').textContent = ''; });
  }

  function boxes() { return [...dlg.querySelectorAll('#lxr-thumbs input[type=checkbox]')]; }
  function selected() { return pageCount ? Array.from({ length: pageCount }, (_, i) => i + 1).filter(n => isChecked(n)) : []; }
  const chosen = new Set();
  function isChecked(n) { const b = dlg.querySelector(`#lxr-thumbs input[value="${n}"]`); return b ? b.checked : chosen.has(n); }
  function setChecked(fn, updateRange = true) {
    chosen.clear();
    for (let n = 1; n <= pageCount; n++) if (fn(n)) chosen.add(n);
    boxes().forEach(b => { b.checked = chosen.has(+b.value); });
    if (updateRange) syncRange(); else showCount();
  }
  function compress(list) {
    const out = [];
    for (let i = 0; i < list.length; i++) {
      let j = i;
      while (j + 1 < list.length && list[j + 1] === list[j] + 1) j++;
      out.push(i === j ? `${list[i]}` : `${list[i]}-${list[j]}`);
      i = j;
    }
    return out.join(', ');
  }
  function syncRange() {
    // strony z miniaturami bierzemy z pól wyboru, strony powyżej limitu zostają takie, jak były
    for (const n of [...chosen]) if (n <= MAX_THUMBS) chosen.delete(n);
    boxes().forEach(b => b.checked && chosen.add(+b.value));
    $('lxr-range').value = compress(selected());
    $('lxr-range').setAttribute('aria-invalid', 'false');
    showCount();
  }
  function showInfo(t) { $('lxr-info').textContent = t; }
  function showCount() {
    const n = selected().length;
    showInfo(`Dokument ma ${pageCount} ${pl(pageCount)}. Wybrano ${n} ${pl(n)}.` + (pageCount > MAX_THUMBS ? ` Podgląd pokazuje pierwsze ${MAX_THUMBS}.` : ''));
  }
  function parse(spec) {
    const pages = new Set();
    for (const part of spec.split(/[,;\s]+/).filter(Boolean)) {
      const m = /^(\d+)(?:-(\d+))?$/.exec(part);
      if (!m) return { error: `Nie rozumiem „${part}”. Wpisz np. 1-3, 5.` };
      const a = +m[1], b = m[2] ? +m[2] : a;
      if (a < 1 || b < a) return { error: `Zły zakres „${part}”.` };
      if (b > pageCount) return { error: `Dokument ma ${pageCount} ${pl(pageCount)}.` };
      for (let i = a; i <= b; i++) pages.add(i);
    }
    return { pages };
  }

  async function preview(file, token) {
    const lib = await pdfjs();
    const doc = await lib.getDocument({ data: await file.arrayBuffer() }).promise;
    if (token !== current) return;
    pageCount = doc.numPages;
    const ul = $('lxr-thumbs');
    ul.innerHTML = '';
    for (let n = 1; n <= Math.min(pageCount, MAX_THUMBS); n++) {
      const li = document.createElement('li');
      li.innerHTML = `<label><span class="top"><input type="checkbox" value="${n}" checked> Strona ${n}</span><canvas aria-hidden="true"></canvas></label>`;
      ul.appendChild(li);
    }
    setChecked(() => true);
    // Miniatury rysujemy po kolei, żeby nie blokować okna
    for (let n = 1; n <= Math.min(pageCount, MAX_THUMBS); n++) {
      if (token !== current) return;
      const page = await doc.getPage(n);
      const vp1 = page.getViewport({ scale: 1 });
      const vp = page.getViewport({ scale: 220 / vp1.width });
      const cv = ul.querySelector(`input[value="${n}"]`).closest('label').querySelector('canvas');
      cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
      await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
    }
  }

  let current = 0;
  async function ask(file) {
    build();
    const token = ++current;
    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '');
    let last = 'math';
    try { last = localStorage.getItem('eq2latex:mode-read') || 'math'; } catch {}
    dlg.querySelector(`input[name=lxr-mode][value="${last}"]`).checked = true;
    $('lxr-file').textContent = `Plik: ${file.name || 'zdjęcie'}` + (file.size ? ` (${Math.round(file.size / 1024)} KB)` : '');
    $('lxr-err').textContent = '';
    $('lxr-pdf').hidden = !isPdf;
    pageCount = 0;
    if (isPdf) {
      $('lxr-thumbs').innerHTML = '';
      $('lxr-range').value = '';
      showInfo('Wczytuję podgląd…');
      preview(file, token).catch(() => {
        if (token !== current) return;
        showInfo('Nie udało się pokazać podglądu. Możesz odczytać cały plik albo wpisać numery stron.');
      });
    }
    dlg.returnValue = '';
    return new Promise(resolve => {
      const form = dlg.querySelector('form');
      const onSubmit = e => {
        if (e.submitter?.value !== 'ok' || !isPdf) return;
        const spec = $('lxr-range').value.trim();
        if (pageCount) {
          const r = parse(spec);
          if (r.error || !r.pages.size) {
            e.preventDefault();
            $('lxr-err').textContent = r.error || 'Wybierz co najmniej jedną stronę albo kliknij Wszystkie.';
            $('lxr-range').focus();
          }
        }
      };
      form.addEventListener('submit', onSubmit);
      dlg.addEventListener('close', () => {
        form.removeEventListener('submit', onSubmit);
        current++;
        if (dlg.returnValue !== 'ok') return resolve(null);
        const mode = dlg.querySelector('input[name=lxr-mode]:checked').value;
        try { localStorage.setItem('eq2latex:mode-read', mode); } catch {}
        let pages = '';
        if (isPdf) {
          const list = pageCount ? selected() : [];
          pages = pageCount && list.length === pageCount ? '' : (pageCount ? compress(list) : $('lxr-range').value.trim());
        }
        resolve({ mode, pages });
      }, { once: true });
      dlg.showModal();
    });
  }

  window.LatexRead = { ask };
})();
