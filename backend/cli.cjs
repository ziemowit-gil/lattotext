'use strict';
// Backend konwertera: czyta JSON ze stdin, wypisuje JSON na stdout.
// Wywoływany przez api.php. Akcje: to-latex, from-latex, scan.
const fs = require('fs');
const path = require('path');
const Conv = require('./converter.js');

function loadEnv() {
  try {
    for (const line of fs.readFileSync(path.join(__dirname, '.env'), 'utf8').split('\n')) {
      const m = /^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/.exec(line);
      if (m && m[2] && !process.env[m[1]]) process.env[m[1]] = m[2].replace(/^['"]|['"]$/g, '');
    }
  } catch {}
}

const SYSTEM = `Odczytujesz zapisy matematyczne z kartek, odręcznych notatek, zdjęć tablicy i skanów, także po polsku.
Przepisz każdy wzór i równanie z materiału do LaTeX.

Zasady:
- Zwróć wyłącznie kod LaTeX, bez komentarzy, bez $ i bez bloków \`\`\`.
- Jeden wzór: zwróć sam wzór.
- Kilka wzorów albo ciąg przekształceń: otocz całość \\begin{aligned} ... \\end{aligned}, każdy wzór w osobnej linii zakończonej \\\\, a przed pierwszym znakiem relacji w linii postaw &.
- Używaj standardowych poleceń: \\frac, \\sqrt, \\sum, \\prod, \\int (z \\,dx), \\lim, \\binom, \\vec, \\left( \\right), pmatrix. Polskie tg i ctg zapisuj jako \\operatorname{tg} i \\operatorname{ctg}.
- Słowa wplecione we wzór zapisz jako \\text{...}.
- Pomiń wszystko, co nie jest matematyką: tytuły, nagłówki, akapity, opisy, numery stron, przypisy, nagłówki i stopki stron, podpisy rysunków, tabele z samym tekstem oraz numerację wzorów typu (1), (2.3).
- Każdy wzór przepisz raz, w kolejności występowania.
- Przepisuj to, co jest napisane, nawet jeśli w obliczeniach jest błąd. Niewyraźny znak odczytaj według najbardziej prawdopodobnego znaczenia w kontekście.
- Jeśli w materiale nie ma żadnego wzoru, zwróć dokładnie: BRAK`;

const SYSTEM_FULL = `Przepisujesz dokumenty z matematyką (kartki, notatki, skany, PDF-y), także po polsku, do LaTeX-a.

Zasady:
- Zwróć wyłącznie treść dokumentu w LaTeX-u, bez preambuły, \\documentclass i \\begin{document}, bez komentarzy i bez bloków \`\`\`.
- Tekst przepisz jako zwykłe akapity, zachowując polskie znaki. Nagłówki jako \\section*{...} albo \\subsection*{...}.
- Wzory w tekście zapisz w $...$, a wzory wyświetlane w \\[ ... \\]. Ciągi przekształceń w \\begin{aligned} wewnątrz \\[ ... \\].
- Pomiń numery stron, nagłówki i stopki stron.
- Przepisuj to, co jest napisane. Niewyraźny znak odczytaj według najbardziej prawdopodobnego znaczenia w kontekście.`;

/** "1-3, 5" → [0,1,2,4]; zwraca błąd, gdy zakres wychodzi poza dokument. */
function parsePages(spec, count) {
  const out = new Set();
  for (const part of String(spec).split(/[,;\s]+/).filter(Boolean)) {
    const m = /^(\d+)(?:-(\d+))?$/.exec(part);
    if (!m) return { error: `Nie rozumiem zakresu stron „${part}”. Wpisz np. 1-3, 5.` };
    const a = +m[1], b = m[2] ? +m[2] : a;
    if (a < 1 || b < a) return { error: `Zły zakres stron „${part}”.` };
    if (b > count) return { error: `Dokument ma ${count} ${count === 1 ? 'stronę' : (count % 10 >= 2 && count % 10 <= 4 && (count % 100 < 12 || count % 100 > 14)) ? 'strony' : 'stron'}, a wybrano stronę ${b}.` };
    for (let i = a; i <= b; i++) out.add(i - 1);
  }
  if (!out.size) return { error: 'Nie wybrano żadnej strony.' };
  return { pages: [...out].sort((x, y) => x - y) };
}

async function pickPdfPages(base64, spec) {
  const { PDFDocument } = require('pdf-lib');
  const src = await PDFDocument.load(Buffer.from(base64, 'base64'), { ignoreEncryption: true });
  const count = src.getPageCount();
  const r = parsePages(spec, count);
  if (r.error) return { error: r.error, count };
  if (r.pages.length === count) return { data: base64, count, picked: r.pages };
  const dst = await PDFDocument.create();
  for (const p of await dst.copyPages(src, r.pages)) dst.addPage(p);
  return { data: Buffer.from(await dst.save()).toString('base64'), count, picked: r.pages };
}

async function scan(req) {
  const onlyMath = req.only_math !== false;
  let pageInfo = null;
  if (req.media_type === 'application/pdf' && req.pages && String(req.pages).trim() && !/^(wszystkie|all|\*)$/i.test(String(req.pages).trim())) {
    try {
      const r = await pickPdfPages(req.data, req.pages);
      if (r.error) return { status: 400, error: r.error, page_count: r.count };
      req.data = r.data;
      pageInfo = { page_count: r.count, pages_read: r.picked.map(i => i + 1) };
    } catch (e) {
      return { status: 400, error: 'Nie udało się otworzyć PDF-u, żeby wybrać strony: ' + e.message };
    }
  }
  loadEnv();
  if (!process.env.ANTHROPIC_API_KEY) {
    return { status: 503, error: 'Odczyt z kartki nie jest jeszcze włączony: na serwerze brakuje klucza Claude API.' };
  }
  const { default: Anthropic } = await import('@anthropic-ai/sdk');
  const client = new Anthropic({ timeout: 100_000, maxRetries: 1 });
  const source = { type: 'base64', media_type: req.media_type, data: req.data };
  const block = req.media_type === 'application/pdf' ? { type: 'document', source } : { type: 'image', source };
  let response;
  try {
    response = await client.beta.messages.create({
      model: 'claude-opus-5-5',
      max_tokens: 16000,
      betas: ['server-side-fallback-2026-07-01'],
      fallbacks: 'default',
      output_config: { effort: 'medium' },
      system: onlyMath ? SYSTEM : SYSTEM_FULL,
      messages: [{ role: 'user', content: [block, { type: 'text', text: onlyMath ? 'Przepisz same wzory z tego materiału do LaTeX-a.' : 'Przepisz cały ten dokument do LaTeX-a.' }] }],
    });
  } catch (e) {
    if (e instanceof Anthropic.AuthenticationError) return { status: 503, error: 'Klucz Claude API na serwerze jest nieprawidłowy.' };
    if (e instanceof Anthropic.RateLimitError) return { status: 429, error: 'Claude API jest teraz przeciążone. Spróbuj za minutę.' };
    if (e instanceof Anthropic.BadRequestError) return { status: 400, error: 'Claude API nie przyjęło tego pliku. Spróbuj innego zdjęcia lub formatu.' };
    if (e instanceof Anthropic.APIError) return { status: 502, error: `Błąd połączenia z Claude API${e.status ? ' (' + e.status + ')' : ''}. Spróbuj ponownie.` };
    throw e;
  }
  const usage = { input_tokens: response.usage?.input_tokens ?? 0, output_tokens: response.usage?.output_tokens ?? 0 };
  if (response.stop_reason === 'refusal') return { status: 422, error: 'Model odmówił przetworzenia tego pliku.', usage };
  let latex = response.content.filter(b => b.type === 'text').map(b => b.text).join('\n').trim();
  latex = latex.replace(/^```(?:latex|tex)?\s*/i, '').replace(/```\s*$/, '').replace(/^\$+|\$+$/g, '').trim();
  if (!latex || latex === 'BRAK') return { status: 422, error: 'Nie znalazłem w materiale żadnego wzoru.', usage };
  if (!onlyMath) {
    // Cały dokument: tekst z wzorami nie przechodzi przez zamianę na zwykły zapis
    return { status: 200, mode: 'document', latex, text: null, speech: null, warnings: [],
      truncated: response.stop_reason === 'max_tokens', model: response.model, usage, ...pageInfo };
  }
  const r = Conv.fromLatex(latex);
  return {
    status: 200, mode: 'formulas', latex, text: r.text, speech: r.speech, warnings: r.warnings,
    truncated: response.stop_reason === 'max_tokens', model: response.model,
    usage: { input_tokens: response.usage?.input_tokens ?? 0, output_tokens: response.usage?.output_tokens ?? 0 },
    ...pageInfo,
  };
}

// ---------- obrazki wzorów (MathJax → SVG, resvg → PNG) ----------
let MJ = null;
function mathjaxDoc() {
  if (MJ) return MJ;
  const { mathjax } = require('mathjax-full/js/mathjax.js');
  const { TeX } = require('mathjax-full/js/input/tex.js');
  const { SVG } = require('mathjax-full/js/output/svg.js');
  const { liteAdaptor } = require('mathjax-full/js/adaptors/liteAdaptor.js');
  const { RegisterHTMLHandler } = require('mathjax-full/js/handlers/html.js');
  const { AllPackages } = require('mathjax-full/js/input/tex/AllPackages.js');
  const adaptor = liteAdaptor();
  RegisterHTMLHandler(adaptor);
  const doc = mathjax.document('', {
    InputJax: new TeX({ packages: AllPackages.filter(p => !['bussproofs', 'require', 'autoload'].includes(p)) }),
    OutputJax: new SVG({ fontCache: 'none' }),
  });
  MJ = { adaptor, doc };
  return MJ;
}

const COLOR = /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/;

function render(req) {
  let tex = String(req.input ?? '');
  let speech = '';
  if (req.from === 'text') {
    const r = Conv.convert(tex, { align: true });
    if (r.errors.length) return { status: 422, error: 'Błąd we wzorze: ' + r.errors.map(e => `linia ${e.line}: ${e.msg}`).join('; '), errors: r.errors };
    tex = r.latex; speech = r.speech;
  } else {
    tex = tex.trim().replace(/^\$\$?|\$\$?$/g, '').replace(/^\\\[|\\\]$/g, '');
    const r = Conv.fromLatex(tex);
    speech = r.speech;
  }
  if (!tex.trim()) return { status: 400, error: 'Pole "input" jest puste.' };
  const color = COLOR.test(req.color || '') ? req.color : '#000000';
  const bg = COLOR.test(req.background || '') ? req.background : null;
  const { adaptor, doc } = mathjaxDoc();
  const node = doc.convert(tex, { display: true });
  let svg = adaptor.innerHTML(node);
  const err = /data-mjx-error="([^"]*)"/.exec(svg);
  if (err) return { status: 422, error: 'LaTeX zawiera błąd: ' + err[1] };
  // jednostki ex → piksele (1ex = 8px), margines 4px liczony w skali viewBox, kolor i dostępny opis
  const pad = 4;
  const wEx = parseFloat((/ width="([\d.]+)ex"/.exec(svg) || [])[1] || '0');
  const hEx = parseFloat((/ height="([\d.]+)ex"/.exec(svg) || [])[1] || '0');
  const vb = /viewBox="([-\d.]+) ([-\d.]+) ([\d.]+) ([\d.]+)"/.exec(svg);
  if (vb && wEx) {
    const [x, y, w, h] = vb.slice(1).map(Number);
    const unitsPerPx = w / (wEx * 8);
    const p = pad * unitsPerPx;
    svg = svg
      .replace(vb[0], `viewBox="${x - p} ${y - p} ${w + 2 * p} ${h + 2 * p}"`)
      .replace(/ width="[\d.]+ex"/, ` width="${(wEx * 8 + 2 * pad).toFixed(2)}"`)
      .replace(/ height="[\d.]+ex"/, ` height="${(hEx * 8 + 2 * pad).toFixed(2)}"`);
  }
  svg = svg
    .replace(/currentColor/g, color)
    .replace(/ style="vertical-align:[^"]*"/, '')
    .replace(/ role="img"/, ` role="img" aria-label="${speech.replace(/[&"<>]/g, c => ({ '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' }[c]))}"`);
  if (bg) svg = svg.replace(/(<svg[^>]*>)/, `$1<rect x="-100000" y="-100000" width="200000" height="200000" fill="${bg}"/>`);
  const format = req.format === 'png' ? 'png' : 'svg';
  if (format === 'svg') return { status: 200, format, data: Buffer.from(svg).toString('base64'), latex: tex, speech };
  const scale = Math.min(8, Math.max(1, Number(req.scale) || 3));
  const { Resvg } = require('@resvg/resvg-js');
  const png = new Resvg(svg, { fitTo: { mode: 'zoom', value: scale } }).render().asPng();
  return { status: 200, format, data: Buffer.from(png).toString('base64'), latex: tex, speech };
}

async function checkKey() {
  loadEnv();
  if (!process.env.ANTHROPIC_API_KEY) return { status: 503, error: 'Brak klucza Claude API.' };
  const { default: Anthropic } = await import('@anthropic-ai/sdk');
  const client = new Anthropic({ timeout: 20_000, maxRetries: 0 });
  try {
    await client.models.retrieve('claude-opus-5-5');
    return { status: 200, message: 'Klucz działa i ma dostęp do modelu Claude Opus 5.5.' };
  } catch (e) {
    if (e instanceof Anthropic.AuthenticationError) return { status: 401, error: 'Klucz jest nieprawidłowy albo został wyłączony.' };
    if (e instanceof Anthropic.PermissionDeniedError) return { status: 403, error: 'Klucz nie ma dostępu do modelu Claude Opus 5.5.' };
    if (e instanceof Anthropic.NotFoundError) return { status: 404, error: 'Klucz działa, ale model Claude Opus 5.5 jest niedostępny dla tego konta.' };
    if (e instanceof Anthropic.APIError) return { status: 502, error: `Nie udało się sprawdzić klucza${e.status ? ' (' + e.status + ')' : ''}.` };
    throw e;
  }
}

async function main() {
  const chunks = [];
  for await (const c of process.stdin) chunks.push(c);
  let req;
  try { req = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { return { status: 400, error: 'Niepoprawny JSON.' }; }
  switch (req.action) {
    case 'to-latex': {
      const r = Conv.convert(String(req.input ?? ''), req.options || {});
      return { status: r.errors.length && !r.latex ? 422 : 200, latex: r.latex, speech: r.speech, errors: r.errors };
    }
    case 'from-latex': {
      const r = Conv.fromLatex(String(req.input ?? ''));
      return { status: r.text ? 200 : 422, text: r.text, speech: r.speech, errors: r.errors, warnings: r.warnings };
    }
    case 'scan': return scan(req);
    case 'render': return render(req);
    case 'check-key': return checkKey();
    default: return { status: 400, error: 'Nieznana akcja. Dostępne: to-latex, from-latex, scan, render.' };
  }
}

main()
  .then(out => process.stdout.write(JSON.stringify(out)))
  .catch(e => { process.stdout.write(JSON.stringify({ status: 500, error: 'Błąd serwera: ' + (e && e.message || e) })); });
