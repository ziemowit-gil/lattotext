---
name: rownania-latex
description: Zamienia wzory matematyczne między zwykłym zapisem (jak w kalkulatorze), LaTeX-em i polskim odczytem słownym, a także odczytuje odręczne wzory ze zdjęć kartek, tablicy i skanów (JPG, PNG, WEBP, GIF, PDF). Użyj, gdy użytkownik prosi o LaTeX dla wzoru, zamianę LaTeX-a na zwykły zapis, odczyt wzoru dla niewidomych albo przepisanie wzorów z kartki lub zdjęcia.
---

# Równania i LaTeX

API: `https://ziemowitgil.pl/latex/api.php`. Każde zapytanie wymaga tokenu użytkownika w zmiennej środowiskowej `LATEX_TOKEN` (token tworzy się po zalogowaniu na https://ziemowitgil.pl/latex/konto/). Jeśli zmiennej nie ma, poproś użytkownika, żeby ją ustawił: `export LATEX_TOKEN=lx_…`. Strona dla ludzi: https://ziemowitgil.pl/latex/

## Zwykły zapis → LaTeX

```bash
curl -s https://ziemowitgil.pl/latex/api.php -H "Authorization: Bearer $LATEX_TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"to-latex","input":"x = (-b +- sqrt(b^2-4ac))/(2a)"}'
```

Odpowiedź: `latex`, `speech` (polski odczyt), `errors` (lista `{line, msg, col}`).
Opcje: `"options":{"mul":"cdot|times|none","align":true,"comma":false}`.

Składnia wejścia: `a/b` ułamek, `x^2`, `x_1` lub `x1`, `sqrt(x)`, `root(3,x)`, `|x|`, `n!`,
`sum(k=1, n, k^2)`, `int(0, 1, x^2, x)`, `lim(x->0, sin(x)/x)`, `diff(f, x)`, `pdiff(f, y, 2)`,
`binom(n,k)`, `mat(1,2;3,4)`, `vec(v)`, `<=`, `>=`, ` != `, `~=`, `+-`, `=>`, `<=>`, `in RR`,
greckie nazwy (`alpha`, `Delta`), `tg`, `ctg`, `NWD`. Kolejne linie zaczynające się od `=` dają wyrównany ciąg.

## LaTeX → zwykły zapis

Buduj JSON przez `jq`, żeby ukośniki LaTeX-a nie zginęły:

```bash
jq -n --arg s '\frac{a}{b} + \sqrt{x}' '{action:"from-latex", input:$s}' \
  | curl -s https://ziemowitgil.pl/latex/api.php -H "Authorization: Bearer $LATEX_TOKEN" -H 'Content-Type: application/json' -d @-
```

Odpowiedź: `text`, `speech`, `errors`.

## Skrypt w Pythonie

Jeśli wygodniej, pobierz `https://ziemowitgil.pl/latex/latexconverter.py` (tylko biblioteka standardowa) i uruchom `python latexconverter.py nazwa_pliku` — token bierze z `LATEX_TOKEN`. Przy zdjęciach i PDF-ach zawsze podaj tryb: `--tylko-wzory` albo `--caly-dokument`, a przy PDF-ie także `--strony 1-3,5` albo `--strony wszystkie` (bez terminala skrypt nie zapyta sam i odmówi). Inne opcje: `--obrazek png|svg`, `-o plik`, `--json`.

## Wzór → obrazek PNG albo SVG

```bash
curl -s https://ziemowitgil.pl/latex/api.php -H "Authorization: Bearer $LATEX_TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"render","input":"sqrt(x^2+y^2)","from":"text","format":"png","scale":4}' -o wzor.png
```

`from`: `text` (zwykły zapis) albo `latex`. `format`: `png` albo `svg`. Opcje: `scale` (1–8, tylko PNG), `color`, `background` (np. `#ffffff`, brak = przezroczyste).
Odpowiedź to sam obrazek; nagłówki `X-Formula-Latex` i `X-Formula-Speech` (URL-encoded) mają LaTeX i polski odczyt — użyj odczytu jako tekstu alternatywnego.

## Kartka, zdjęcie lub skan → LaTeX (Claude API)

**Zanim cokolwiek wyślesz, zapytaj użytkownika, w jakim trybie odczytać plik:** tylko wzory (`only_math=1`, domyślnie pomija tekst niematematyczny) czy cały dokument (`only_math=0`, tekst i wzory w LaTeX-u). Nie zgaduj trybu. Przy PDF-ie zapytaj też, które strony odczytać, i podaj je w polu `pages` (np. `1-3,5`; brak pola = wszystkie strony). Płaci się tylko za wybrane strony.

```bash
curl -s https://ziemowitgil.pl/latex/api.php -H "Authorization: Bearer $LATEX_TOKEN" -F action=scan -F only_math=1 -F plik=@kartka.jpg
```

Odpowiedź: `latex`, `text`, `speech`, `warnings`. Limit: 5 MB na plik. Odczyty liczą się do dziennego limitu użytkownika i trafiają do jego historii.
Kody błędów: 401 brak lub zły token, 403 API wyłączone dla konta, 413 za duży plik, 415 zły format (HEIC zapisz jako JPG), 422 brak wzoru, 429 limit, 503 odczyt wyłączony.

Po odczycie pokaż użytkownikowi LaTeX i poproś o sprawdzenie niewyraźnych miejsc — pismo odręczne bywa niejednoznaczne.
