'use strict';
// Logo LatToText: typograficzna gra w stylu logotypów TeX/LaTeX, składana MathJaxem do czystych krzywych SVG.
const fs = require('fs');
const path = require('path');
const { Resvg } = require('@resvg/resvg-js');
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

const INK = '#16191f', ACCENT = '#1a3db0', INK_DARK = '#eceef2', ACCENT_DARK = '#a9bcff';

// Zwraca zawartość <svg> MathJaxa (viewBox + elementy) dla danego TeX-a
function tex(src) {
  const svg = adaptor.innerHTML(mj.convert(src, { display: true }));
  const vb = /viewBox="([-\d.]+) ([-\d.]+) ([\d.]+) ([\d.]+)"/.exec(svg).slice(1).map(Number);
  const inner = svg.replace(/^<svg[^>]*>/, '').replace(/<\/svg>$/, '');
  return { vb, inner };
}

// Znak słowny: L, podniesione a, T  ⇄  T, obniżone E, xt  (jak logotypy LaTeX i TeX)
const LAT = String.raw`\mathrm{L\kern-.33em\raise.5em{\scriptstyle A}\kern-.08em T}`;
const TEXT = String.raw`\mathrm{T\kern-.1667em\lower.5ex{E}\kern-.02em xt}`;
const WORD = LAT + String.raw`\;\;{\color{ACC}\rightleftarrows}\;\;` + TEXT;

// Ikona: ułamek „LaT” nad „TExt”
const ICON = String.raw`\dfrac{` + LAT + '}{' + TEXT + '}';

function wordmark(ink, accent, title) {
  const { vb, inner } = tex(WORD.replace('ACC', accent));
  const pad = 120;
  const [x, y, w, h] = [vb[0] - pad, vb[1] - pad, vb[2] + 2 * pad, vb[3] + 2 * pad];
  const body = inner.replace(/currentColor/g, ink);
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${x} ${y} ${w} ${h}" width="${(w / 22).toFixed(0)}" height="${(h / 22).toFixed(0)}" role="img" aria-labelledby="t">
<title id="t">${title}</title>
${body}
</svg>
`;
}

function icon(ink, accent, bg, title) {
  const { vb, inner } = tex(ICON);
  // kwadrat z ramką i niebieską kreską ułamkową (zamiast czarnej)
  const S = Math.max(vb[2], vb[3]) + 700;
  const cx = vb[0] + vb[2] / 2, cy = vb[1] + vb[3] / 2;
  const x0 = cx - S / 2, y0 = cy - S / 2;
  const body = inner.replace(/currentColor/g, ink).replace(/<rect([^>]*)><\/rect>/g, `<rect$1 fill="${accent}"></rect>`).replace(/<rect([^>]*)\/>/g, `<rect$1 fill="${accent}"/>`);
  const r = S * 0.16, sw = S * 0.045;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${x0} ${y0} ${S} ${S}" width="512" height="512" role="img" aria-labelledby="t">
<title id="t">${title}</title>
<rect x="${x0 + sw / 2}" y="${y0 + sw / 2}" width="${S - sw}" height="${S - sw}" rx="${r}" fill="${bg}" stroke="${accent}" stroke-width="${sw}"/>
${body}
</svg>
`;
}

const out = process.argv[2] || 'logo';
fs.mkdirSync(out, { recursive: true });
const files = {
  'lattotext-logo.svg': wordmark(INK, ACCENT, 'LatToText'),
  'lattotext-logo-ciemne.svg': wordmark(INK_DARK, ACCENT_DARK, 'LatToText'),
  'lattotext-ikona.svg': icon(INK, ACCENT, '#ffffff', 'LatToText'),
  'lattotext-ikona-ciemna.svg': icon(INK_DARK, ACCENT_DARK, '#14161b', 'LatToText'),
};
for (const [name, svg] of Object.entries(files)) {
  fs.writeFileSync(path.join(out, name), svg);
  const png = new Resvg(svg, { fitTo: { mode: 'width', value: name.includes('ikona') ? 512 : 1200 }, background: name.includes('ciem') ? '#14161b' : '#ffffff' }).render().asPng();
  fs.writeFileSync(path.join(out, name.replace('.svg', '.png')), png);
}
// favicon 32 px
fs.writeFileSync(path.join(out, 'favicon-32.png'), new Resvg(files['lattotext-ikona.svg'], { fitTo: { mode: 'width', value: 32 } }).render().asPng());
console.log('Zapisano:', Object.keys(files).join(', '), '+ PNG i favicon-32.png');
