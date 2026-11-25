'use strict';
// Generuje statyczną podstronę przyklady.html: wzór (SVG), zwykły zapis, LaTeX, odczyt słowny, link do konwertera.
const fs = require('fs');
const Conv = require('../../backend/converter.js');
const { mathjax } = require('mathjax-full/js/mathjax.js');
const { TeX } = require('mathjax-full/js/input/tex.js');
const { SVG } = require('mathjax-full/js/output/svg.js');
const { liteAdaptor } = require('mathjax-full/js/adaptors/liteAdaptor.js');
const { RegisterHTMLHandler } = require('mathjax-full/js/handlers/html.js');
const { AllPackages } = require('mathjax-full/js/input/tex/AllPackages.js');

const adaptor = liteAdaptor();
RegisterHTMLHandler(adaptor);
const mj = mathjax.document('', {
  InputJax: new TeX({ packages: AllPackages.filter(p => !['bussproofs', 'require', 'autoload'].includes(p)) }),
  OutputJax: new SVG({ fontCache: 'none' }),
});
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const slug = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

function svgOf(tex) {
  // Kolor z CSS (currentColor), rozmiar w ex, żeby skalował się z tekstem; dla czytników ukryty — jest odczyt słowny
  return adaptor.innerHTML(mj.convert(tex, { display: true }))
    .replace(/ role="img"/, ' aria-hidden="true"')
    .replace(/ style="vertical-align:[^"]*"/, '');
}

const groups = require('./formulas.json');
let n = 0;
const toc = [];
const sections = groups.map(([title, list]) => {
  const id = slug(title);
  toc.push(`<li><a href="#${id}">${esc(title)}</a> <span class="hint">(${list.length})</span></li>`);
  const items = list.map(src => {
    n++;
    const r = Conv.convert(src, { align: false });
    const open = './?wzor=' + encodeURIComponent(src);
    return `
      <li class="ex" id="wzor-${n}">
        <div class="formula">${svgOf(r.latex)}</div>
        <dl>
          <div><dt>Odczyt</dt><dd>${esc(r.speech)}</dd></div>
          <div><dt>Zwykły zapis</dt><dd><code translate="no">${esc(src)}</code></dd></div>
          <div><dt>LaTeX</dt><dd><code translate="no">${esc(r.latex)}</code></dd></div>
        </dl>
        <p class="links"><a href="${esc(open)}">Otwórz w konwerterze<span class="sr">: wzór ${n}</span></a></p>
      </li>`;
  }).join('');
  return `
  <section aria-labelledby="${id}">
    <h2 id="${id}">${esc(title)}</h2>
    <ol class="examples" start="${n - list.length + 1}">${items}
    </ol>
  </section>`;
}).join('');

const html = `<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Przykłady wzorów – LatToText</title>
<meta name="description" content="${n} przykładowych wzorów: zwykły zapis, kod LaTeX, podgląd i polski odczyt słowny. Pliki PDF do wypróbowania odczytu z kartki.">
<link rel="icon" href="/latex/lattotext-ikona.svg" type="image/svg+xml">
<link rel="icon" href="/latex/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/latex/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
:root { --bg:#fff; --ink:#16191f; --muted:#4f5666; --line:#767d8c; --soft:#e3e6ec; --accent:#1a3db0; --code-bg:#f2f4f7;
  --f-body:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif; --f-mono:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace; }
@media (prefers-color-scheme: dark) { :root { --bg:#14161b; --ink:#eceef2; --muted:#b1b7c4; --line:#8a92a3; --soft:#2c313b; --accent:#a9bcff; --code-bg:#1d2129; color-scheme:dark; } }
* { box-sizing:border-box; }
html { background:var(--bg); }
body { margin:0; background:var(--bg); color:var(--ink); font-family:var(--f-body); font-size:1.0625rem; line-height:1.6;
  padding:2rem max(1rem, env(safe-area-inset-right,0px)) 4rem max(1rem, env(safe-area-inset-left,0px)); }
main { max-width:52rem; margin:0 auto; display:flex; flex-direction:column; gap:2rem; }
h1 { font-size:clamp(1.75rem,5vw,2.25rem); line-height:1.2; margin:0; }
h2 { font-size:1.35rem; margin:0 0 .75rem; padding-top:1.25rem; border-top:1px solid var(--soft); }
p { margin:0; }
a { color:var(--accent); text-underline-offset:3px; }
:focus-visible { outline:3px solid var(--accent); outline-offset:2px; }
.skip { position:absolute; left:-9999px; top:1rem; background:var(--bg); color:var(--accent); padding:.5rem .75rem; }
.skip:focus { left:1rem; }
.sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); clip-path:inset(50%); white-space:nowrap; }
.hint { color:var(--muted); font-size:.95rem; }
.lead { color:var(--muted); margin-top:.5rem; max-width:62ch; }
.brandbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem 1.25rem; margin-bottom: .75rem; }
.brand { display: inline-flex; align-items: center; min-height: 2.75rem; border-radius: 4px; }
.brand img { height: 3.4rem; width: auto; display: block; }
.topnav { display:flex; flex-wrap:wrap; gap:.25rem 1.25rem; }
.topnav a { display:inline-flex; align-items:center; min-height:2.75rem; }
.topnav a[aria-current="page"] { color:var(--ink); font-weight:600; text-decoration:none; }
.buttons { display:flex; flex-wrap:wrap; gap:.75rem; margin-top:.75rem; }
.btn { display:inline-flex; align-items:center; min-height:2.75rem; padding:.5rem 1rem; border:2px solid var(--ink); border-radius:4px; color:var(--ink); text-decoration:none; }
.btn.primary { background:var(--ink); color:var(--bg); }
.btn:hover { border-color:var(--accent); }
.toc { columns:2 14rem; margin:.5rem 0 0; padding-left:1.25rem; }
.toc li { min-height:2rem; }
.examples { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:1rem; counter-reset:none; }
.ex { border:1px solid var(--soft); border-radius:6px; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.5rem; min-width:0; }
.ex .formula { overflow-x:auto; font-size:1.2rem; padding:.25rem 0; }
.ex .formula svg { max-width:none; }
dl { margin:0; display:grid; gap:.35rem; }
dl > div { display:grid; grid-template-columns:8.5rem minmax(0,1fr); gap:.75rem; }
@media (max-width:560px) { dl > div { grid-template-columns:minmax(0,1fr); gap:0; } }
dt { color:var(--muted); font-size:.95rem; }
dd { margin:0; min-width:0; }
code { font-family:var(--f-mono); font-size:.9em; background:var(--code-bg); padding:.05rem .3rem; border-radius:3px; word-break:break-word; }
.links a { display:inline-flex; align-items:center; min-height:2.75rem; }
footer { color:var(--muted); font-size:.95rem; border-top:1px solid var(--soft); padding-top:1rem; }
</style>
</head>
<body>
<a class="skip" href="#lista">Przejdź do przykładów</a>
<main>
  <header>
    <div class="brandbar">
      <a class="brand" href="/latex/"><picture><source srcset="/latex/lattotext-logo-ciemne.svg" media="(prefers-color-scheme: dark)"><img src="/latex/lattotext-logo.svg" alt="LatToText — strona główna" width="160" height="40"></picture></a>
      <nav aria-label="Główna" class="topnav"><a href="./">Konwerter</a><a href="przyklady.html" aria-current="page">Przykłady</a><a href="api.php">API</a><a href="konto/">Moje konto</a></nav>
    </div>
    <h1>Przykłady wzorów</h1>
    <p class="lead">${n} wzorów z liceum i pierwszych lat studiów. Przy każdym jest zwykły zapis do wpisania w konwerterze, kod LaTeX i odczyt słowny. Kliknij „Otwórz w konwerterze”, żeby zmieniać wzór i kopiować wynik.</p>
  </header>

  <section aria-labelledby="pdf-h">
    <h2 id="pdf-h" style="border-top:0;padding-top:0">Pliki do wypróbowania odczytu z kartki</h2>
    <p>Te same wzory złożone w PDF-ie jak w dokumencie LaTeX. Wyślij plik do odczytu w konwerterze (przycisk „Odczytaj z pliku”) albo mailem, a AI przepisze wzory z powrotem do LaTeX-a.</p>
    <div class="buttons">
      <a class="btn primary" href="./?przyklad=1">Odczytaj krótki przykład w konwerterze</a>
      <a class="btn" href="przyklad-krotki.pdf" download>Pobierz krótki przykład<span class="sr"> (PDF, 1 strona, 5 wzorów)</span></a>
      <a class="btn" href="przyklady-wzorow.pdf" download>Pobierz pełny zestaw<span class="sr"> (PDF, 5 stron, ${n} wzorów)</span></a>
    </div>
    <p class="hint" style="margin-top:.5rem">Krótki przykład: 1 strona, 5 wzorów. Pełny zestaw: 5 stron, ${n} wzorów. Każdy wzór w plikach ma odczyt słowny dla czytników ekranu.</p>
  </section>

  <nav aria-labelledby="toc-h">
    <h2 id="toc-h">Działy</h2>
    <ul class="toc">${toc.join('')}</ul>
  </nav>

  <div id="lista">${sections}
  </div>

  <footer>
    <p><strong>LatToText</strong> · <a href="https://ziemowitgil.pl/latex/">strona projektu</a> · Projekt i wykonanie: <a href="https://ziemowitgil.pl/">Ziemowit Gil</a></p>
  </footer>
</main>
</body>
</html>
`;
fs.writeFileSync(process.argv[2] || 'przyklady.html', html);
console.log('przykładów:', n, 'rozmiar:', Math.round(html.length / 1024), 'KB');
