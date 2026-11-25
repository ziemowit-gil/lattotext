<?php
// Bramka: w trybie prywatnym strony i pliki LatToText widzą tylko zalogowani.
// Pliki leżą poza katalogiem publicznym (~/latex-api/web/), adresy zostają te same dzięki .htaccess.
declare(strict_types=1);
require_once __DIR__ . '/sciezka.php';

const GATED = [
    'index.html'           => 'text/html; charset=utf-8',
    'przyklady.html'       => 'text/html; charset=utf-8',
    'api-docs.html'        => 'text/html; charset=utf-8',
    'przyklad-krotki.pdf'  => 'application/pdf',
    'przyklady-wzorow.pdf' => 'application/pdf',
];

$f = (string)($GATE_FILE ?? ($_GET['f'] ?? 'index.html'));
if (!isset(GATED[$f])) { http_response_code(404); exit('Nie ma takiej strony.'); }

if (!gate_allowed()) {
    if (str_starts_with(GATED[$f], 'text/html')) {
        lx_session();
        $_SESSION['next'] = strtok($_SERVER['REQUEST_URI'] ?? '/latex/', '#');
        redirect(LX_URL . '/konto/');
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("LatToText jest dostępny tylko dla osób z kontem. Zaloguj się na " . LX_URL . "/konto/\n");
}

header('Content-Type: ' . GATED[$f]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . (setting('private_mode') === '1' ? 'private, no-cache' : 'public, max-age=300'));
if (GATED[$f] === 'application/pdf') header('Content-Disposition: inline; filename="' . $f . '"');
readfile(LX_DIR . '/web/' . $f);
