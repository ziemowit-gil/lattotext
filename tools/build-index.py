#!/usr/bin/env python3
"""Składa stronę główną: pages/page.html + backend/converter.js → pages/index.html."""
from pathlib import Path
root = Path(__file__).resolve().parent.parent
page = (root / "pages/page.html").read_text(encoding="utf-8")
conv = (root / "backend/converter.js").read_text(encoding="utf-8")
(root / "pages/index.html").write_text(page.replace("<!--CONVERTER-->", conv), encoding="utf-8")
print("Zapisano pages/index.html")
