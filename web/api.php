<?php
// API konwertera równań: https://ziemowitgil.pl/latex/api.php
// Konwersje tekstu robi ~/latex-api/cli.cjs, odczyt z kartki run_scan() z ~/latex-api/php/lib.php (Claude API).
declare(strict_types=1);
require_once __DIR__ . '/sciezka.php';

const MAX_TEXT = 20000;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Konwerter');
header('Access-Control-Expose-Headers: X-Formula-Latex, X-Formula-Speech');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
/** Zwraca obrazek wzoru (SVG albo PNG) zamiast JSON. */
function render_image(array $req): never
{
    $input = (string)($req['input'] ?? '');
    if (trim($input) === '') reply(400, ['error' => 'Pole "input" jest puste.']);
    if (mb_strlen($input) > 5000) reply(413, ['error' => 'Za długi wzór do obrazka. Limit to 5000 znaków.']);
    $res = node_job([
        'action' => 'render', 'input' => $input,
        'from' => ($req['from'] ?? 'latex') === 'text' ? 'text' : 'latex',
        'format' => ($req['format'] ?? 'svg') === 'png' ? 'png' : 'svg',
        'scale' => (float)($req['scale'] ?? 3),
        'color' => (string)($req['color'] ?? ''),
        'background' => (string)($req['background'] ?? ''),
    ]);
    if (($res['status'] ?? 500) !== 200) { $c = (int)($res['status'] ?? 500); unset($res['status']); reply($c, $res); }
    header('Content-Type: ' . ($res['format'] === 'png' ? 'image/png' : 'image/svg+xml; charset=utf-8'));
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Expose-Headers: X-Formula-Latex, X-Formula-Speech');
    header('X-Formula-Latex: ' . rawurlencode($res['latex']));
    header('X-Formula-Speech: ' . rawurlencode($res['speech']));
    header('Content-Disposition: inline; filename="wzor.' . $res['format'] . '"');
    echo base64_decode($res['data']);
    exit;
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'render') {
    $signer = verify_signed($_GET);
    if (!$signer) reply(401, ['error' => 'Link do obrazka jest nieprawidłowy albo dostęp do API został wyłączony. Wygeneruj link ponownie w kliencie HTTP na stronie Moje konto.']);
    count_api_call($signer);
    render_image($_GET);
}
if ($method === 'GET') {
    // Przeglądarka dostaje stronę z dokumentacją, programy — JSON
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') && ($_GET['format'] ?? '') !== 'json') {
        $GATE_FILE = 'api-docs.html';
        require __DIR__ . '/gate.php';
        exit;
    }
    reply(200, [
        'name' => 'Równania i LaTeX — API',
        'docs' => LX_URL . '/api.php (w przeglądarce) i ' . LX_URL . '/llms.txt',
        'actions' => [
            'to-latex'   => 'POST JSON {"action":"to-latex","input":"x = (-b +- sqrt(b^2-4ac))/(2a)","options":{"mul":"cdot|times|none","align":true,"comma":false}}',
            'from-latex' => 'POST JSON {"action":"from-latex","input":"\\\\frac{a}{b}"}',
            'scan'       => 'POST multipart: action=scan, plik=@kartka.jpg  (albo JSON {"action":"scan","media_type":"image/jpeg","data":"<base64>"})',
            'render'     => 'GET/POST {"action":"render","input":"\\\\frac{a}{b}","from":"latex|text","format":"svg|png","scale":3,"color":"#000000","background":"#ffffff"} → obrazek wzoru',
        ],
        'auth' => 'Wymagany nagłówek Authorization: Bearer lx_… — token tworzy zalogowany użytkownik na ' . LX_URL . '/konto/. Linki GET do obrazków podpisuje akcja sign.',
        'limits' => ['file_mb' => 5, 'text_chars' => MAX_TEXT, 'scans_without_account_per_day' => (int)setting('anon_daily_limit')],
    ]);
}
if ($method !== 'POST') reply(405, ['error' => 'Użyj POST.']);

$bytes = null;
$fileName = '';
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_starts_with($ctype, 'multipart/form-data')) {
    $req = ['action' => (string)($_POST['action'] ?? 'scan')];
    if (isset($_POST['input'])) $req['input'] = (string)$_POST['input'];
    $f = $_FILES['plik'] ?? $_FILES['file'] ?? null;
    if ($f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) reply(400, ['error' => 'Nie udało się przesłać pliku (za duży albo przerwany).']);
        if ($f['size'] > LX_MAX_FILE) reply(413, ['error' => 'Plik jest za duży. Limit to 5 MB.']);
        $bytes = (string)file_get_contents($f['tmp_name']);
        $fileName = (string)($f['name'] ?? '');
    }
} else {
    $raw = file_get_contents('php://input', false, null, 0, LX_MAX_FILE * 2);
    $req = json_decode($raw ?: '', true);
    if (!is_array($req)) reply(400, ['error' => 'Wyślij JSON z polem "action" albo formularz multipart.']);
    if (isset($req['data'])) {
        $bytes = base64_decode((string)$req['data'], true);
        if ($bytes === false) reply(400, ['error' => 'Pole "data" nie jest poprawnym base64.']);
        $fileName = (string)($req['file_name'] ?? '');
    }
}

// ---------- klucz API per użytkownik ----------
$bearer = user_by_bearer();
if (!$bearer && isset($_SERVER['HTTP_AUTHORIZATION'])) reply(401, ['error' => 'Token API jest nieprawidłowy albo konto jest zablokowane. Utwórz nowy token na stronie Moje konto.']);
if ($bearer && !$bearer['api_enabled']) reply(403, ['error' => 'Administrator wyłączył dostęp do API dla tego konta.']);
$sessionUser = $bearer ? null : current_user();
$apiUser = $bearer ?? $sessionUser;
$fromPage = !$bearer && isset($_SERVER['HTTP_X_KONWERTER']);

$action = (string)($req['action'] ?? '');
$action = ['latex' => 'to-latex', 'text' => 'from-latex', 'ocr' => 'scan', 'image' => 'render'][$action] ?? $action;
// Tryb prywatny: nic bez konta. Poza nim bez konta wolno tylko odczytać kartkę ze strony konwertera.
if (!$apiUser && private_mode()) {
    reply(401, ['error' => 'LatToText jest dostępny tylko dla osób z kontem. Zaloguj się na ' . LX_URL . '/konto/ albo użyj tokenu API.']);
}
if (!$apiUser && !($fromPage && in_array($action, ['scan', 'ocr'], true))) {
    reply(401, ['error' => 'API wymaga klucza. Zaloguj się na ' . LX_URL . '/konto/, utwórz token i wysyłaj go w nagłówku Authorization: Bearer lx_…']);
}
if ($apiUser && !$fromPage) count_api_call($apiUser);
if ($action === 'sign') {
    if (!$apiUser) reply(401, ['error' => 'Podpisywanie linków wymaga konta.']);
    $p = array_intersect_key($req, array_flip(['input', 'from', 'format', 'scale', 'color', 'background']));
    $p['action'] = 'render';
    reply(200, ['url' => LX_URL . '/api.php?' . sign_params(array_map('strval', $p), (int)$apiUser['id'])]);
}
if ($action === 'render') render_image($req);

if ($action === 'to-latex' || $action === 'from-latex') {
    $input = (string)($req['input'] ?? '');
    if (trim($input) === '') reply(400, ['error' => 'Pole "input" jest puste.']);
    if (mb_strlen($input) > MAX_TEXT) reply(413, ['error' => 'Za długi tekst. Limit to ' . MAX_TEXT . ' znaków.']);
    $job = ['action' => $action, 'input' => $input];
    if (isset($req['options']) && is_array($req['options'])) {
        $o = $req['options'];
        $job['options'] = [
            'mul'   => in_array($o['mul'] ?? 'cdot', ['cdot', 'times', 'none'], true) ? ($o['mul'] ?? 'cdot') : 'cdot',
            'align' => (bool)($o['align'] ?? true),
            'comma' => (bool)($o['comma'] ?? false),
        ];
    }
    $res = node_job($job);
} elseif ($action === 'scan') {
    if ($bytes === null || $bytes === '') reply(400, ['error' => 'Dołącz plik w polu "plik" albo base64 w polu "data".']);
    $u = $apiUser;
    $source = $fromPage ? 'web' : 'api';
    set_time_limit(130);
    // only_math: domyślnie tak — pomijaj tekst niematematyczny; 0/false = przepisz cały dokument
    $om = $req['only_math'] ?? ($_POST['only_math'] ?? '1');
    $onlyMath = !in_array(strtolower((string)(is_bool($om) ? ($om ? '1' : '0') : $om)), ['0', 'false', 'nie', 'no'], true);
    // pages: które strony PDF-u odczytać, np. "1-3,5" (puste = wszystkie)
    $pages = mb_substr(trim((string)($req['pages'] ?? ($_POST['pages'] ?? ''))), 0, 200);
    $res = run_scan($bytes, $u, $source, $fileName, false, $onlyMath, $pages);
} else {
    reply(400, ['error' => 'Nieznana akcja. Dostępne: to-latex, from-latex, scan, render, sign.']);
}

$code = (int)($res['status'] ?? 200);
unset($res['status'], $res['usage']);
reply($code, $res);
