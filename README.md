# LatToText

![LatToText](logo/lattotext-logo.svg)

Konwerter wzorów matematycznych: zwykły zapis ⇄ LaTeX, polski odczyt słowny dla czytników ekranu i odczyt wzorów z kartek, zdjęć i PDF-ów przez Claude API.

Działa pod adresem https://ziemowitgil.pl/latex/ (dostęp po zalogowaniu).

## Co umie

- **Zwykły zapis → LaTeX**: `x = (-b +- sqrt(b^2-4ac))/(2a)` → `x = \frac{-b \pm \sqrt{b^{2} - 4ac}}{2a}`. Ułamki, potęgi, pierwiastki, sumy, całki, granice, pochodne, macierze, przypadki (`cases`), litery greckie, polskie `tg` i `ctg`.
- **LaTeX → zwykły zapis**, odporny na nieznane polecenia: czego konwerter nie zna, przepisuje bez zmian jako wstawkę `` `\polecenie{…}` `` i zostawia uwagę zamiast błędu.
- **Odczyt słowny po polsku** każdego wzoru („x równa się ułamek, licznik: …”), także jako tekst alternatywny w obrazkach i PDF-ach.
- **Odczyt z kartki** (zdjęcie, skan, PDF) przez Claude API: tylko wzory albo cały dokument, z podglądem i wyborem stron PDF-u. Pliki nie są zapisywane na serwerze — znikają zaraz po odczycie.
- **Obrazki wzorów** PNG i SVG, kopiowanie obrazu do Worda, pobieranie SVG i PDF.
- **Konta** z logowaniem jednorazowym linkiem z maila, tryb prywatny (tylko zalogowani), panel admina: użytkownicy i limity, historia odczytów, koszty Claude API, konfiguracja poczty (IMAP/SMTP).
- **Odczyt z maila**: zdjęcie wysłane na skrzynkę wraca odpowiedzią z LaTeX-em, zwykłym zapisem i odczytem.
- **API z kluczem per użytkownik**, klient HTTP w przeglądarce, skrypt `latexconverter.py` i umiejętność dla Claude Code (`SKILL.md`).
- Dostępność: WCAG 2.2 AA (komunikaty status/alert, etykiety, fokus, kontrasty, cele ≥ 44 px).

## Struktura

| Katalog | Zawartość | Gdzie na serwerze |
|---|---|---|
| `web/` | publiczne pliki: `api.php`, bramka logowania `gate.php`, `konto/`, `admin/`, `odczyt.js`, skrypt i opisy | katalog strony, np. `public_html/latex/` |
| `pages/` | strony i pliki za bramką: szablon strony głównej, przykłady, dokumentacja API, przykładowe PDF-y | `latex-api/web/` (poza katalogiem publicznym) |
| `backend/` | `converter.js` (parser w obie strony + odczyt słowny), `cli.cjs` (Node: konwersje, Claude API, render SVG/PNG, wybór stron PDF), `php/` (baza, konta, limity, poczta), `bin/` (cron, konta z CLI) | `latex-api/` |
| `tools/` | budowanie strony głównej, generatory przykładowych PDF-ów, strony przykładów i logo | lokalnie |
| `logo/` | logo i ikona (SVG, wersje jasne i ciemne) | `web/` zawiera kopie do strony |

## Instalacja

Wymagania: PHP 8.3+ (pdo_sqlite, imap, curl, fileinfo, openssl), Node 20+.

```bash
# backend
cp -r backend ~/latex-api && cd ~/latex-api
npm install                      # @anthropic-ai/sdk, mathjax-full, @resvg/resvg-js, pdf-lib
cp .env.example .env             # NODE_BIN, opcjonalnie ANTHROPIC_API_KEY
mkdir -p data web && chmod 700 data

# strony za bramką
python3 tools/build-index.py     # pages/page.html + backend/converter.js → pages/index.html
cp pages/index.html pages/przyklady.html pages/api-docs.html pages/*.pdf ~/latex-api/web/

# katalog publiczny
cp -r web/. ~/public_html/latex/
cp ~/public_html/latex/sciezka.example.php ~/public_html/latex/sciezka.php   # wpisz ścieżkę do latex-api
```

Pierwsze konto administratora:

```bash
php ~/latex-api/bin/user.php add admin@example.com admin Imię
php ~/latex-api/bin/user.php link admin@example.com    # jednorazowy link logowania
```

Odczyt z maila (cron co 5 minut):

```bash
*/5 * * * * php ~/latex-api/bin/mail-poll.php >/dev/null 2>&1
```

Klucz Claude API, hasło skrzynki, limity i tryb prywatny ustawia się w panelu admina (`/latex/admin/`). Hasła są tam zapisywane zaszyfrowane kluczem z `data/secret.key`.

## API

```bash
curl -s https://ziemowitgil.pl/latex/api.php -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"action":"to-latex","input":"sqrt(x)/2"}'
```

Akcje: `to-latex`, `from-latex`, `render` (obrazek PNG/SVG), `sign` (podpisany link do obrazka), `scan` (odczyt z kartki: `only_math`, `pages`). Pełny opis: strona `/latex/api.php` w przeglądarce i `web/llms.txt`.

## Autor

Projekt i wykonanie: Ziemowit Gil.
