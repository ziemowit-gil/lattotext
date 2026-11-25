'use strict';
// Generuje przykładowe PDF-y ze wzorami: przyklady-wzorow.pdf (pełny) i przyklad-krotki.pdf (1 strona).
// Wzory są obrazkami jak w zeskanowanym dokumencie; odczyt słowny trafia do tekstu alternatywnego (tagged PDF).
const fs = require('fs');
const path = require('path');
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

const FONT = '/System/Library/Fonts/Supplemental/Arial.ttf';
const FONT_B = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
const EX = 8; // px na 1ex

function formulaPng(tex) {
  let svg = adaptor.innerHTML(mj.convert(tex, { display: true }));
  const w = parseFloat(/ width="([\d.]+)ex"/.exec(svg)[1]);
  const h = parseFloat(/ height="([\d.]+)ex"/.exec(svg)[1]);
  svg = svg.replace(/currentColor/g, '#000').replace(/ width="[\d.]+ex"/, ` width="${w * EX}"`).replace(/ height="[\d.]+ex"/, ` height="${h * EX}"`);
  const png = new Resvg(svg, { fitTo: { mode: 'zoom', value: 4 } }).render().asPng();
  return { png, w: w * EX, h: h * EX };
}

function build(file, title, intro, groups) {
  const doc = new PDFDocument({
    size: 'A4', margins: { top: 56, bottom: 56, left: 64, right: 64 },
    tagged: true, lang: 'pl-PL', displayTitle: true, pdfVersion: '1.7',
    info: { Title: title, Author: 'LatToText — Ziemowit Gil', Subject: 'Przykładowe wzory do odczytu', Keywords: 'LaTeX, wzory, matematyka' },
  });
  doc.registerFont('body', FONT);
  doc.registerFont('bold', FONT_B);
  doc.pipe(fs.createWriteStream(file));
  const root = doc.struct('Document');
  doc.addStructure(root);
  const W = doc.page.width - 128;

  root.add(doc.struct('H1', () => doc.font('bold').fontSize(20).fillColor('#000').text(title)));
  root.add(doc.struct('P', () => doc.moveDown(0.4).font('body').fontSize(10.5).fillColor('#333').text(intro, { width: W })));
  doc.moveDown(0.8);

  let n = 0;
  for (const [section, list] of groups) {
    if (doc.y > doc.page.height - 160) doc.addPage();
    root.add(doc.struct('H2', () => doc.font('bold').fontSize(13).fillColor('#000').text(section, 64, doc.y + 6)));
    doc.moveDown(0.4);
    for (const src of list) {
      n++;
      const r = Conv.convert(src, { align: false });
      const img = formulaPng(r.latex);
      const scale = Math.min(1.25, (W - 60) / img.w);
      const iw = img.w * scale, ih = img.h * scale;
      if (doc.y + ih + 18 > doc.page.height - 56) doc.addPage();
      const y = doc.y + 4;
      root.add(doc.struct('Span', () => doc.font('body').fontSize(10).fillColor('#555').text(`(${n})`, 64, y + ih / 2 - 5, { width: 40 })));
      root.add(doc.struct('Figure', { alt: `Wzór ${n}: ${r.speech}` }, () => doc.image(img.png, 64 + 44, y, { width: iw, height: ih })));
      doc.x = 64;
      doc.y = y + ih + 10;
    }
    doc.moveDown(0.4);
  }
  root.add(doc.struct('P', () => doc.font('body').fontSize(9).fillColor('#555')
    .text('LatToText · https://ziemowitgil.pl/latex/ · Projekt i wykonanie: Ziemowit Gil', 64, doc.page.height - 48, { width: W, lineBreak: false })));
  root.end();
  doc.end();
  return n;
}

const groups = require('./formulas.json');
const out = process.argv[2] || '.';
const full = build(path.join(out, 'przyklady-wzorow.pdf'), 'Przykładowe wzory — LatToText',
  'Zestaw wzorów z liceum i pierwszych lat studiów. Wyślij ten plik do odczytu na stronie ziemowitgil.pl/latex (przycisk „Odczytaj z pliku”) albo mailem, a dostaniesz kod LaTeX, zwykły zapis i odczyt słowny każdego wzoru.',
  groups);
const short = build(path.join(out, 'przyklad-krotki.pdf'), 'Krótki przykład — LatToText',
  'Pięć wzorów do szybkiej próby odczytu.',
  [['Przykład', ['x = (-b +- sqrt(Delta)) / (2a)', 'sin^2 alpha + cos^2 alpha = 1', 'lim(n->inf, (1 + 1/n)^n) = e', 'int(0, 1, x^2, x) = 1/3', 'binom(n, k) = n!/(k!(n - k)!)']]]);
console.log(`pełny: ${full} wzorów, krótki: ${short}`);
