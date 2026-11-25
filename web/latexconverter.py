#!/usr/bin/env python3
"""
latexconverter.py — wysyła plik do LatToText (https://ziemowitgil.pl/latex/) i wypisuje wynik.

Użycie:
    python latexconverter.py nazwa_pliku [opcje]

Co robi z plikiem (rozpoznaje po rozszerzeniu):
    .jpg .jpeg .png .webp .gif .pdf   odczyt wzorów przez AI (zdjęcie kartki, skan, PDF)
    .tex                              LaTeX → zwykły zapis
    .txt i inne tekstowe              zwykły zapis → LaTeX

Token API:
    Utwórz go po zalogowaniu na https://ziemowitgil.pl/latex/konto/ i podaj jednym ze sposobów:
      export LATEX_TOKEN=lx_...        (zalecane)
      python latexconverter.py plik.pdf --token lx_...

Przykłady:
    python latexconverter.py kartka.jpg                 (zapyta o tryb odczytu)
    python latexconverter.py kartka.jpg --tylko-wzory
    python latexconverter.py skrypt.pdf --tylko-wzory --strony 2-4
    python latexconverter.py notatki.pdf --caly-dokument -o notatki.tex
    python latexconverter.py wzory.txt --obrazek png -o wzor.png
    python latexconverter.py wzor.tex --json

Wymaga tylko Pythona 3.8+ (bez dodatkowych pakietów).
"""
import argparse
import json
import mimetypes
import os
import sys
import time
import urllib.error
import urllib.request
import uuid

API = os.environ.get("LATEX_API", "https://ziemowitgil.pl/latex/api.php")
VERSION = "1.2"
IMAGE_EXT = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png", ".webp": "image/webp", ".gif": "image/gif", ".pdf": "application/pdf"}
MAX_FILE = 5 * 1024 * 1024


def fail(msg, code=1):
    print(f"Błąd: {msg}", file=sys.stderr)
    sys.exit(code)


def request(body, headers, token, quiet=False):
    """Wysyła zapytanie POST i zwraca (kod, nagłówki, treść w bajtach)."""
    headers = dict(headers)
    headers["Authorization"] = f"Bearer {token}"
    headers["User-Agent"] = f"latexconverter.py/{VERSION}"
    req = urllib.request.Request(API, data=body, headers=headers, method="POST")
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=180) as r:
            data = r.read()
            code, hdrs = r.status, r.headers
    except urllib.error.HTTPError as e:
        data, code, hdrs = e.read(), e.code, e.headers
    except urllib.error.URLError as e:
        fail(f"nie udało się połączyć z {API} ({e.reason}).")
    if not quiet:
        print(f"Odpowiedź po {time.time() - t0:.1f} s (kod {code}).", file=sys.stderr)
    return code, hdrs, data


def multipart(fields, file_field, filename, content, ctype):
    boundary = "----latexconverter" + uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(
        f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{os.path.basename(filename)}"\r\n'
        f"Content-Type: {ctype}\r\n\r\n".encode() + content + b"\r\n"
    )
    parts.append(f"--{boundary}--\r\n".encode())
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def show_error(code, data):
    try:
        msg = json.loads(data).get("error") or data.decode("utf-8", "replace")
    except ValueError:
        msg = data.decode("utf-8", "replace")[:300]
    hints = {401: " Sprawdź token (LATEX_TOKEN) albo utwórz nowy na stronie Moje konto.", 403: " Administrator wyłączył API dla tego konta.",
             429: " Spróbuj później.", 413: " Plik jest za duży (limit 5 MB)."}
    fail(f"{msg}{hints.get(code, '')}", 2)


def write_out(path, text_or_bytes):
    mode = "wb" if isinstance(text_or_bytes, bytes) else "w"
    with open(path, mode, **({} if mode == "wb" else {"encoding": "utf-8"})) as f:
        f.write(text_or_bytes)
    print(f"Zapisano: {path}", file=sys.stderr)


def ask_mode(path):
    """Przed odczytem pyta o tryb. Bez terminala (skrypty, Claude Code) wymaga jawnej opcji."""
    if not sys.stdin.isatty():
        fail("podaj tryb odczytu: --tylko-wzory albo --caly-dokument (bez terminala skrypt nie może zapytać).")
    print(f"W jakim trybie odczytać plik {os.path.basename(path)}?", file=sys.stderr)
    print("  1 — tylko wzory (pomija tekst niematematyczny)", file=sys.stderr)
    print("  2 — cały dokument (tekst i wzory w LaTeX-u)", file=sys.stderr)
    while True:
        try:
            choice = input("Wybierz 1 lub 2 (q — anuluj): ").strip().lower()
        except EOFError:
            fail("anulowano — nie wybrano trybu.", 130)
        if choice in ("1", "w", "wzory"):
            return False
        if choice in ("2", "d", "dokument", "caly", "cały"):
            return True
        if choice in ("q", "anuluj", ""):
            fail("anulowano — nic nie zostało wysłane.", 130)
        print("Wpisz 1 albo 2.", file=sys.stderr)


def pdf_page_count(content):
    """Przybliżona liczba stron PDF-u (bez dodatkowych bibliotek)."""
    import re
    n = len(re.findall(rb"/Type\s*/Page(?![a-zA-Z])", content))
    return n or None


def ask_pages(path, content):
    """Przy PDF-ie pyta, które strony odczytać. Bez terminala wymaga opcji --strony."""
    if not sys.stdin.isatty():
        fail("podaj strony PDF-u do odczytu: --strony 1-3,5 albo --strony wszystkie (bez terminala skrypt nie może zapytać).")
    n = pdf_page_count(content)
    print(f"Plik {os.path.basename(path)} ma {'ok. ' + str(n) if n else 'nieznaną liczbę'} stron.", file=sys.stderr)
    try:
        answer = input("Które strony odczytać? (Enter = wszystkie, np. 1-3,5; q — anuluj): ").strip().lower()
    except EOFError:
        fail("anulowano — nie wybrano stron.", 130)
    if answer in ("q", "anuluj"):
        fail("anulowano — nic nie zostało wysłane.", 130)
    return "" if answer in ("", "wszystkie", "all", "*") else answer


def main():
    ap = argparse.ArgumentParser(
        prog="latexconverter.py",
        description="Wysyła plik do LatToText i wypisuje LaTeX, zwykły zapis i odczyt słowny.",
        epilog="Token: export LATEX_TOKEN=lx_... (utworzysz go na https://ziemowitgil.pl/latex/konto/)",
    )
    ap.add_argument("plik", help="zdjęcie, skan lub PDF (odczyt przez AI), plik .tex (LaTeX) albo .txt (zwykły zapis)")
    ap.add_argument("--token", help="token API (domyślnie ze zmiennej LATEX_TOKEN)")
    tryb = ap.add_mutually_exclusive_group()
    tryb.add_argument("--tylko-wzory", action="store_true", help="odczyt PDF/zdjęcia: tylko wzory, bez tekstu niematematycznego")
    tryb.add_argument("--caly-dokument", action="store_true", help="odczyt PDF/zdjęcia: cały dokument (tekst i wzory)")
    ap.add_argument("--strony", help="odczyt PDF: które strony, np. 1-3,5 albo 'wszystkie'")
    ap.add_argument("--obrazek", choices=["png", "svg"], help="dla .tex/.txt: zamiast tekstu pobierz obrazek wzoru")
    ap.add_argument("--skala", type=float, default=3, help="powiększenie obrazka PNG (1–8, domyślnie 3)")
    ap.add_argument("-o", "--wyjscie", help="zapisz wynik do pliku (domyślnie: wypisz na ekran)")
    ap.add_argument("--json", action="store_true", help="wypisz pełną odpowiedź API w JSON")
    ap.add_argument("-q", "--cicho", action="store_true", help="nie wypisuj komunikatów o postępie")
    ap.add_argument("--version", action="version", version=f"latexconverter.py {VERSION}")
    a = ap.parse_args()

    token = a.token or os.environ.get("LATEX_TOKEN")
    if not token:
        fail("brak tokenu API. Ustaw go poleceniem: export LATEX_TOKEN=lx_...  (token utworzysz na https://ziemowitgil.pl/latex/konto/)")
    if not os.path.isfile(a.plik):
        fail(f"nie ma pliku {a.plik}")

    ext = os.path.splitext(a.plik)[1].lower()
    log = (lambda *x: None) if a.cicho else (lambda *x: print(*x, file=sys.stderr))

    # ---- zdjęcie / skan / PDF → odczyt przez AI ----
    if ext in IMAGE_EXT:
        if not (a.tylko_wzory or a.caly_dokument):
            a.caly_dokument = ask_mode(a.plik)
        size = os.path.getsize(a.plik)
        if size > MAX_FILE:
            fail(f"plik ma {size / 1048576:.1f} MB, a limit to 5 MB.")
        with open(a.plik, "rb") as f:
            content = f.read()
        fields = {"action": "scan", "only_math": "0" if a.caly_dokument else "1"}
        if ext == ".pdf":
            pages = a.strony if a.strony is not None else ask_pages(a.plik, content)
            if pages and pages.lower() not in ("wszystkie", "all", "*"):
                fields["pages"] = pages
        body, ctype = multipart(fields, "plik", a.plik, content, IMAGE_EXT[ext])
        log(f"Wysyłam {os.path.basename(a.plik)} ({size / 1024:.0f} KB) do odczytu przez AI"
            + (" — cały dokument" if a.caly_dokument else " — tylko wzory")
            + (f", strony {fields['pages']}." if fields.get("pages") else ".") + " To może potrwać do pół minuty…")
        code, _, data = request(body, {"Content-Type": ctype}, token, a.cicho)
        if code != 200:
            show_error(code, data)
        res = json.loads(data)
        if res.get("pages_read"):
            log(f"Odczytano strony {', '.join(map(str, res['pages_read']))} z {res.get('page_count')}.")
        if a.json:
            out = json.dumps(res, ensure_ascii=False, indent=2)
        elif res.get("mode") == "document":
            out = res["latex"]
        else:
            out = res["latex"]
            if not a.wyjscie:
                out = f"LaTeX:\n{res['latex']}\n"
                if res.get("text"):
                    out += f"\nZwykły zapis:\n{res['text']}\n"
                if res.get("speech"):
                    out += f"\nOdczyt słowny:\n{res['speech']}\n"
        if res.get("truncated"):
            log("Uwaga: odpowiedź została ucięta — dokument jest bardzo długi.")
    # ---- plik tekstowy → konwersja albo obrazek ----
    else:
        with open(a.plik, encoding="utf-8") as f:
            text = f.read().strip()
        if not text:
            fail("plik jest pusty.")
        from_latex = ext in (".tex", ".latex")
        if a.obrazek:
            payload = {"action": "render", "input": text, "from": "latex" if from_latex else "text", "format": a.obrazek, "scale": a.skala}
            code, hdrs, data = request(json.dumps(payload).encode(), {"Content-Type": "application/json"}, token, a.cicho)
            if code != 200:
                show_error(code, data)
            path = a.wyjscie or os.path.splitext(a.plik)[0] + "." + a.obrazek
            write_out(path, data)
            speech = urllib.request.unquote(hdrs.get("X-Formula-Speech", ""))
            if speech:
                log(f"Odczyt słowny: {speech}")
            return
        payload = {"action": "from-latex" if from_latex else "to-latex", "input": text}
        code, _, data = request(json.dumps(payload).encode(), {"Content-Type": "application/json"}, token, a.cicho)
        res = json.loads(data) if data else {}
        if code != 200 and not res.get("errors"):
            show_error(code, data)
        for e in res.get("errors", []):
            log(f"Błąd we wzorze, linia {e.get('line')}: {e.get('msg')}")
        for w in res.get("warnings", []) or []:
            log(f"Uwaga, linia {w.get('line')}: {w.get('msg')}")
        if a.json:
            out = json.dumps(res, ensure_ascii=False, indent=2)
        else:
            main_out = res.get("text") if from_latex else res.get("latex")
            out = main_out or ""
            if not a.wyjscie and res.get("speech"):
                out = f"{main_out}\n\nOdczyt słowny:\n{res['speech']}\n"

    if a.wyjscie:
        write_out(a.wyjscie, out if out.endswith("\n") else out + "\n")
    else:
        print(out)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        fail("przerwano.", 130)
