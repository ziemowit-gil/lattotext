'use strict';
// Dokument PDF: wzory złożone jak w LaTeX-u, pod każdym kod LaTeX i odczyt słowny (tagged PDF).
const fs = require('fs');
const PDFDocument = require('pdfkit');
const { Resvg } = require('@resvg/resvg-js');
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

function png(tex) {
  let svg = adaptor.innerHTML(mj.convert(tex, { display: true }));
  const w = parseFloat(/ width="([\d.]+)ex"/.exec(svg)[1]) * 8;
  const h = parseFloat(/ height="([\d.]+)ex"/.exec(svg)[1]) * 8;
  svg = svg.replace(/currentColor/g, '#000').replace(/ width="[\d.]+ex"/, ` width="${w}"`).replace(/ height="[\d.]+ex"/, ` height="${h}"`);
  return { png: new Resvg(svg, { fitTo: { mode: 'zoom', value: 4 } }).render().asPng(), w, h };
}

const DOC = [
  ['Funkcja kwadratowa', [
    'f(x) = ax^2 + bx + c',
    'W = (-b/(2a), -Delta/(4a))',
    'f(x) = a(x - x_1)(x - x_2)',
  ]],
  ['Pochodne i całki', [
    'diff(sin x, x) = cos x',
    'diff(e^x, x) = e^x',
    'diff(ln x, x) = 1/x',
    'int(1/x, x) = ln|x| + C',
    'int(0, pi, sin x, x) = 2',
    'f(x) ~= f(a) + f\'(a)(x - a)',
  ]],
  ['Szeregi', [
    'sum(n=1, inf, 1/n^2) = pi^2/6',
    'sum(n=0, inf, q^n) = 1/(1 - q), |q| < 1',
    'e = sum(n=0, inf, 1/n!)',
  ]],
  ['Geometria', [
    'P = pi r^2',
    'P = 1/2 a h',
    'a^2 + b^2 = c^2',
    'd = sqrt((x_2 - x_1)^2 + (y_2 - y_1)^2)',
    'V = 4/3 pi r^3',
  ]],
  ['Macierze i układy równań', [
    'mat(a, b; c, d) mat(x; y) = mat(e; f)',
    'det mat(a, b; c, d) = ad - bc',
    'x = det(A_x)/det(A)',
  ]],
  ['Fizyka', [
    'F = m a',
    'E_p = m g h',
    'p = m v',
    'P = W/t',
    'U = R I',
  ]],
];

const out = process.argv[2];
const doc = new PDFDocument({
  size: 'A4', margins: { top: 56, bottom: 56, left: 64, right: 64 },
  tagged: true, lang: 'pl-PL', displayTitle: true, pdfVersion: '1.7',
  info: { Title: 'Wzory z kodem LaTeX', Author: 'LatToText — Ziemowit Gil', Subject: 'Wzory matematyczne i fizyczne z kodem LaTeX i odczytem słownym' },
});
doc.registerFont('body', '/System/Library/Fonts/Supplemental/Arial.ttf');
doc.registerFont('bold', '/System/Library/Fonts/Supplemental/Arial Bold.ttf');
doc.registerFont('mono', '/System/Library/Fonts/Supplemental/Courier New.ttf');
doc.pipe(fs.createWriteStream(out));
const root = doc.struct('Document');
doc.addStructure(root);
const L = 64, W = doc.page.width - 128, BOTTOM = doc.page.height - 64;

root.add(doc.struct('H1', () => doc.font('bold').fontSize(20).fillColor('#000').text('Wzory z kodem LaTeX', L, doc.y)));
root.add(doc.struct('P', () => doc.moveDown(0.3).font('body').fontSize(10.5).fillColor('#333')
  .text('Każdy wzór ma pod spodem kod LaTeX gotowy do skopiowania oraz polski odczyt słowny. Wygenerowane w LatToText — ziemowitgil.pl/latex.', { width: W })));
doc.moveDown(0.6);

let n = 0;
for (const [section, list] of DOC) {
  if (doc.y > BOTTOM - 120) doc.addPage();
  root.add(doc.struct('H2', () => doc.font('bold').fontSize(13).fillColor('#000').text(section, L, doc.y + 8)));
  doc.moveDown(0.3);
  for (const src of list) {
    n++;
    const r = Conv.convert(src, { align: false });
    if (r.errors.length) throw new Error(src + ': ' + r.errors[0].msg);
    const img = png(r.latex);
    const scale = Math.min(1.2, (W - 50) / img.w);
    const iw = img.w * scale, ih = img.h * scale;
    doc.font('mono').fontSize(9);
    const codeH = doc.heightOfString(r.latex, { width: W - 44 });
    doc.font('body').fontSize(8.5);
    const spH = doc.heightOfString(r.speech, { width: W - 44 });
    if (doc.y + ih + codeH + spH + 26 > BOTTOM) doc.addPage();
    const y = doc.y + 4;
    root.add(doc.struct('Span', () => doc.font('body').fontSize(10).fillColor('#555').text(`(${n})`, L, y + ih / 2 - 5, { width: 40 })));
    root.add(doc.struct('Figure', { alt: `Wzór ${n}: ${r.speech}` }, () => doc.image(img.png, L + 44, y, { width: iw, height: ih })));
    const cy = y + ih + 4;
    doc.rect(L + 40, cy - 2, W - 40, codeH + 6).fill('#f2f4f7');
    root.add(doc.struct('Code', () => doc.font('mono').fontSize(9).fillColor('#16191f').text(r.latex, L + 44, cy + 1, { width: W - 48 })));
    root.add(doc.struct('P', () => doc.font('body').fontSize(8.5).fillColor('#4f5666').text('Odczyt: ' + r.speech, L + 44, cy + codeH + 8, { width: W - 48 })));
    doc.x = L;
    doc.y = cy + codeH + spH + 16;
  }
}
root.add(doc.struct('P', () => doc.font('body').fontSize(9).fillColor('#555')
  .text('LatToText · https://ziemowitgil.pl/latex/ · Projekt i wykonanie: Ziemowit Gil', L, doc.y + 12, { width: W })));
root.end();
doc.end();
console.log('wzorów:', n);
