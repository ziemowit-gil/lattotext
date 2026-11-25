<?php
// Wspólny wygląd stron konta i panelu (WCAG 2.2 AA: kontrasty, focus, landmarki, komunikaty status/alert).
declare(strict_types=1);

function page_start(string $title, ?array $u, string $active = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    $links = [['../', 'Konwerter', 'home'], ['../przyklady.html', 'Przykłady', 'ex'], ['../api.php', 'API', 'api'], ['../konto/', 'Moje konto', 'konto']];
    if ($u && $u['role'] === 'admin') $links[] = ['../admin/', 'Panel admina', 'admin'];
    // W trybie prywatnym osoba niezalogowana widzi tylko logowanie
    if (!$u && function_exists('private_mode') && private_mode()) $links = [['../konto/', 'Logowanie', 'konto']];
    ?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> – Równania i LaTeX</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
:root { --bg:#fff; --ink:#16191f; --muted:#4f5666; --line:#767d8c; --soft:#e3e6ec; --accent:#1a3db0; --code-bg:#f2f4f7; --danger:#a0101c; --ok:#1c6b34;
  --f-body:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif; --f-mono:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace; }
@media (prefers-color-scheme: dark) { :root { --bg:#14161b; --ink:#eceef2; --muted:#b1b7c4; --line:#8a92a3; --soft:#2c313b; --accent:#a9bcff; --code-bg:#1d2129; --danger:#ffa4ab; --ok:#8fdca6; color-scheme:dark; } }
* { box-sizing:border-box; }
html { background:var(--bg); }
body { margin:0; background:var(--bg); color:var(--ink); font-family:var(--f-body); font-size:1.0625rem; line-height:1.6;
  padding:1.25rem max(1rem, env(safe-area-inset-right,0px)) 4rem max(1rem, env(safe-area-inset-left,0px)); }
.wrap { max-width:68rem; margin:0 auto; display:flex; flex-direction:column; gap:1.75rem; }
nav ul { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; gap:.25rem 1.25rem; align-items:center; }
nav a { display:inline-flex; align-items:center; min-height:2.75rem; color:var(--accent); text-underline-offset:3px; }
nav a[aria-current="page"] { color:var(--ink); font-weight:600; text-decoration:none; }
nav form { margin:0; }
h1 { font-size:clamp(1.6rem,4.5vw,2.1rem); line-height:1.2; margin:0; }
h2 { font-size:1.25rem; margin:0 0 .75rem; }
h3 { font-size:1.0625rem; margin:1rem 0 .5rem; }
p { margin:0; } p + p { margin-top:.6rem; }
a { color:var(--accent); text-underline-offset:3px; }
:focus-visible { outline:3px solid var(--accent); outline-offset:2px; }
.skip { position:absolute; left:-9999px; top:1rem; background:var(--bg); color:var(--accent); padding:.5rem .75rem; z-index:9; }
.skip:focus { left:1rem; }
.sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); clip-path:inset(50%); white-space:nowrap; }
.muted, .hint { color:var(--muted); }
.hint { font-size:.95rem; }
section.block { border-top:1px solid var(--soft); padding-top:1.25rem; }
.msg { padding:.6rem .9rem; border-left:4px solid var(--ok); color:var(--ok); font-weight:600; }
.msg.err { border-color:var(--danger); color:var(--danger); }
form.stack { display:flex; flex-direction:column; gap:.75rem; max-width:32rem; }
.row { display:flex; flex-wrap:wrap; gap:.75rem 1rem; align-items:flex-end; }
label.f { display:flex; flex-direction:column; gap:.25rem; font-weight:600; }
label.c { display:inline-flex; align-items:center; gap:.5rem; min-height:2.75rem; }
label.c input { width:1.25rem; height:1.25rem; margin:0; accent-color:var(--accent); }
input[type=text], input[type=email], input[type=number], input[type=date], select, textarea {
  font:inherit; font-weight:400; color:var(--ink); background:var(--bg); border:2px solid var(--line); border-radius:4px; padding:.45rem .6rem; min-height:2.75rem; max-width:100%; }
textarea { min-height:6rem; width:100%; }
input:focus-visible, select:focus-visible, textarea:focus-visible { border-color:var(--accent); outline-offset:0; }
input[aria-invalid="true"] { border-color:var(--danger); }
button, .btn { font:inherit; min-height:2.75rem; padding:.45rem 1rem; border-radius:4px; border:2px solid var(--ink); background:var(--ink); color:var(--bg); cursor:pointer; display:inline-flex; align-items:center; text-decoration:none; }
button.plain, .btn.plain { background:var(--bg); color:var(--ink); }
button.danger { background:var(--danger); border-color:var(--danger); color:var(--bg); }
button:hover, .btn:hover { border-color:var(--accent); }
button.link { background:none; border:0; color:var(--accent); text-decoration:underline; text-underline-offset:3px; padding:0 .25rem; min-height:2.75rem; }
.scroll { overflow-x:auto; }
table { border-collapse:collapse; width:100%; font-size:.95rem; }
caption { text-align:left; font-weight:600; padding:.25rem 0 .5rem; }
th, td { text-align:left; padding:.45rem .75rem .45rem 0; border-bottom:1px solid var(--soft); vertical-align:top; }
th { border-bottom:2px solid var(--line); font-weight:600; white-space:nowrap; }
td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
code, pre { font-family:var(--f-mono); }
code { font-size:.9em; background:var(--code-bg); padding:.05rem .3rem; border-radius:3px; word-break:break-word; }
pre { background:var(--code-bg); padding:.75rem 1rem; border-radius:4px; white-space:pre-wrap; word-break:break-word; margin:.5rem 0 0; }
.tag { display:inline-block; padding:0 .45rem; border:1px solid var(--line); border-radius:3px; font-size:.85rem; white-space:nowrap; }
.tag.ok { border-color:var(--ok); color:var(--ok); } .tag.err { border-color:var(--danger); color:var(--danger); }
.stats { display:flex; flex-wrap:wrap; gap:1rem 2.5rem; }
.stats div { display:flex; flex-direction:column; }
.stats b { font-size:1.5rem; font-variant-numeric:tabular-nums; }
.confirm { border:2px solid var(--danger); padding:1rem; border-radius:4px; display:flex; flex-direction:column; gap:.75rem; }
.tabs ul { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; gap:.5rem; border-bottom:2px solid var(--soft); }
.tabs a { display:inline-flex; align-items:center; min-height:2.75rem; padding:0 .9rem; text-decoration:none; border:2px solid transparent; border-bottom:0; border-radius:4px 4px 0 0; }
.tabs a[aria-current="page"] { border-color:var(--soft); background:var(--code-bg); color:var(--ink); font-weight:600; }
.pager { display:flex; gap:1rem; align-items:center; margin-top:.75rem; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<div class="wrap">
<header>
  <nav aria-label="Główna">
    <ul>
      <?php foreach ($links as [$href, $label, $key]): ?>
        <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
      <?php endforeach; ?>
      <?php if ($u): ?>
        <li><form method="post" action="../konto/"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="link" type="submit">Wyloguj (<?= e($u['email']) ?>)</button></form></li>
      <?php endif; ?>
    </ul>
  </nav>
</header>
<main id="tresc" tabindex="-1">
<div class="wrap" style="gap:1.75rem">
<?php
    foreach (take_flash() as [$msg, $kind]) {
        $role = $kind === 'err' ? 'alert' : 'status';
        echo '<p class="msg' . ($kind === 'err' ? ' err' : '') . '" role="' . $role . '">' . e($msg) . '</p>';
    }
}

function page_end(): void
{
    echo "</div>\n</main>\n<footer class=\"hint\" style=\"border-top:1px solid var(--soft);padding-top:1rem\"><p><strong>LatToText</strong> · <a href=\"https://ziemowitgil.pl/latex/\">strona projektu</a> · Projekt i wykonanie: <a href=\"https://ziemowitgil.pl/\">Ziemowit Gil</a></p></footer>\n</div>\n</body>\n</html>\n";
}

function source_label(string $s): string
{
    return ['web' => 'strona', 'api' => 'API', 'email' => 'e-mail'][$s] ?? $s;
}

function status_tag(string $s): string
{
    return match ($s) {
        'ok' => '<span class="tag ok">odczytano</span>',
        'pending' => '<span class="tag">w toku</span>',
        'rejected' => '<span class="tag">wyłączone</span>',
        default => '<span class="tag err">błąd</span>',
    };
}
