<?php
// Wspólna biblioteka: baza, ustawienia, logowanie linkiem, limity, odczyt skanów, poczta.
declare(strict_types=1);

define('LX_DIR', dirname(__DIR__)); // katalog backendu (np. ~/latex-api)
const LX_URL  = 'https://ziemowitgil.pl/latex';
const LX_PRICE_IN  = 4.0;   // USD za 1M tokenów wejścia (claude-opus-5-5)
const LX_PRICE_OUT = 20.0;  // USD za 1M tokenów wyjścia
const LX_MEDIA = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'];
const LX_MAX_FILE = 5 * 1024 * 1024;

const LX_DEFAULTS = [
    'private_mode'       => '1',
    'mail_enabled'       => '1',
    'mail_from_anyone'   => '1',
    'mail_anon_limit'    => '10',
    'mail_address'       => 'latex@dpoczta.pl',
    'sender_name'        => 'Równania i LaTeX',
    'imap_host'          => 'imap.dpoczta.pl',
    'imap_port'          => '993',
    'imap_security'      => 'ssl',
    'imap_user'          => '',
    'imap_folder'        => 'INBOX',
    'smtp_mode'          => 'smtp',
    'smtp_host'          => 'smtp.dpoczta.pl',
    'smtp_port'          => '587',
    'smtp_security'      => 'starttls',
    'smtp_user'          => '',
    'smtp_same_pass'     => '1',
    'reply_subject'      => 'Odczytany wzór: {plik}',
    'reply_intro'        => "Dzień dobry,\n\nponiżej wzory odczytane z załączników Twojej wiadomości. Sprawdź niewyraźne miejsca — pismo odręczne bywa niejednoznaczne.",
    'user_daily_limit'   => '50',
    'anon_enabled'       => '1',
    'anon_daily_limit'   => '30',
    'global_daily_limit' => '300',
    'keep_files_days'    => '30',
];

date_default_timezone_set('Europe/Warsaw');
// Błędy PHP idą do logu serwera, nie na stronę (nie zdradzamy ścieżek)
if (PHP_SAPI !== 'cli') { ini_set('display_errors', '0'); ini_set('log_errors', '1'); }

function lx_env(string $key, ?string $default = null): ?string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        foreach (@file(LX_DIR . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m)) $env[$m[1]] = trim($m[2], "'\"");
        }
    }
    return ($env[$key] ?? '') !== '' ? $env[$key] : $default;
}

function db(): PDO
{
    static $db = null;
    if ($db) return $db;
    if (!is_dir(LX_DIR . '/data')) mkdir(LX_DIR . '/data', 0700, true);
    $db = new PDO('sqlite:' . LX_DIR . '/data/app.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $db->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL COLLATE NOCASE, name TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'user', daily_limit INTEGER, blocked INTEGER NOT NULL DEFAULT 0,
            api_token_hash TEXT, created_at TEXT NOT NULL, last_login_at TEXT);
        CREATE TABLE IF NOT EXISTS login_tokens(
            token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS scans(
            id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, source TEXT NOT NULL,
            ip_hash TEXT, created_at TEXT NOT NULL, media_type TEXT, file_name TEXT, file_path TEXT,
            status TEXT NOT NULL, error TEXT, latex TEXT, plain TEXT, speech TEXT, model TEXT,
            input_tokens INTEGER NOT NULL DEFAULT 0, output_tokens INTEGER NOT NULL DEFAULT 0, cost_usd REAL NOT NULL DEFAULT 0);
        CREATE INDEX IF NOT EXISTS scans_created ON scans(created_at);
        CREATE INDEX IF NOT EXISTS scans_user ON scans(user_id, created_at);
        CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
    ");
    $cols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('api_enabled', $cols, true)) $db->exec('ALTER TABLE users ADD COLUMN api_enabled INTEGER NOT NULL DEFAULT 1');
    if (!in_array('api_calls', $cols, true)) $db->exec('ALTER TABLE users ADD COLUMN api_calls INTEGER NOT NULL DEFAULT 0');
    return $db;
}

function setting(string $key): string
{
    $s = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? (LX_DEFAULTS[$key] ?? '') : (string)$v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

// ---------- hasła w ustawieniach (szyfrowane kluczem z pliku data/secret.key) ----------

function secret_key(): string
{
    $f = LX_DIR . '/data/secret.key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); chmod($f, 0600); }
    return hex2bin(trim((string)file_get_contents($f)));
}

function set_secret(string $key, string $plain): void
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    set_setting($key, base64_encode($iv . $tag . $ct));
}

function get_secret(string $key): string
{
    $raw = base64_decode(setting($key), true);
    if (!$raw || strlen($raw) < 29) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function anthropic_key(): string { return get_secret('anthropic_api_key') ?: (string)lx_env('ANTHROPIC_API_KEY', ''); }

function imap_login(): string { return setting('imap_user') ?: setting('mail_address'); }
function imap_password(): string { return get_secret('imap_password') ?: (string)lx_env('IMAP_PASSWORD', ''); }
function smtp_login(): string { return setting('smtp_user') ?: imap_login(); }
function smtp_password(): string { return setting('smtp_same_pass') === '1' ? imap_password() : get_secret('smtp_password'); }

function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
// Dla maili od osób bez konta limit liczy się według adresu nadawcy zamiast IP
function ip_hash(): string { return hash('sha256', ($GLOBALS['lx_anon_key'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli')) . '|latex'); }
function usd(float $v): string { return '$' . number_format($v, $v < 1 ? 4 : 2, ',', ' '); }

// ---------- sesja, logowanie ----------

function lx_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('latex_sess');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/latex/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function find_user(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function find_user_by_email(string $email): ?array
{
    $s = db()->prepare('SELECT * FROM users WHERE email = ?');
    $s->execute([trim($email)]);
    return $s->fetch() ?: null;
}

function current_user(): ?array
{
    lx_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    $u = find_user((int)$id);
    if (!$u || $u['blocked']) { unset($_SESSION['uid']); return null; }
    return $u;
}

function user_by_bearer(): ?array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(lx_[A-Za-z0-9]+)$/', trim($h), $m)) return null;
    $s = db()->prepare('SELECT * FROM users WHERE api_token_hash = ? AND blocked = 0');
    $s->execute([hash('sha256', $m[1])]);
    return $s->fetch() ?: null;
}

/** Podpisany link do obrazka wzoru (GET bez tokenu, przypisany do użytkownika). */
function sign_params(array $params, int $userId): string
{
    unset($params['sig'], $params['u']);
    ksort($params);
    $params['u'] = (string)$userId;
    $base = http_build_query($params);
    return $base . '&sig=' . substr(hash_hmac('sha256', $base, secret_key()), 0, 32);
}

function verify_signed(array $get): ?array
{
    $sig = (string)($get['sig'] ?? '');
    $uid = (int)($get['u'] ?? 0);
    if ($sig === '' || !$uid) return null;
    $expected = sign_params(array_map('strval', $get), $uid);
    if (!hash_equals(substr($expected, strrpos($expected, 'sig=') + 4), $sig)) return null;
    $u = find_user($uid);
    return ($u && !$u['blocked'] && $u['api_enabled']) ? $u : null;
}

function count_api_call(array $u): void
{
    db()->prepare('UPDATE users SET api_calls = api_calls + 1 WHERE id = ?')->execute([$u['id']]);
}

function login_as(array $u): void
{
    lx_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now(), $u['id']]);
}

function logout(): void
{
    lx_session();
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    lx_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }

function csrf_ok(): bool
{
    lx_session();
    return isset($_POST['_csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_csrf']);
}

function flash(string $msg, string $kind = 'ok'): void { lx_session(); $_SESSION['flash'][] = [$msg, $kind]; }

function take_flash(): array { lx_session(); $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function redirect(string $url): never { header('Location: ' . $url, true, 303); exit; }

/** Wysyła link logowania. Zwraca false, gdy przekroczono limit linków. */
function send_login_link(array $u, bool $invite = false): bool
{
    $s = db()->prepare('SELECT COUNT(*) FROM login_tokens WHERE user_id = ? AND created_at > ?');
    $s->execute([$u['id'], time() - 3600]);
    if ((int)$s->fetchColumn() >= 5) return false;
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO login_tokens(token_hash, user_id, expires_at, created_at) VALUES(?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $u['id'], time() + ($invite ? 7 * 86400 : 20 * 60), time()]);
    $link = LX_URL . '/konto/?t=' . $token;
    $name = $u['name'] !== '' ? ' ' . $u['name'] : '';
    if ($invite) {
        $subject = 'Zaproszenie do konwertera Równania i LaTeX';
        $body = "Dzień dobry$name,\n\nmasz konto w konwerterze wzorów Równania i LaTeX (" . LX_URL . "/).\n\n"
            . "Zaloguj się tym linkiem (działa 7 dni, jeden raz):\n$link\n\n"
            . "Dostęp do LatToText jest tylko przez link logowania — nie ma haseł. Kiedy zechcesz wejść ponownie, wpisz swój adres na " . LX_URL . "/konto/, a wyślemy Ci nowy link.\n\n"
            . "Zdjęcia kartek ze wzorami możesz też wysyłać na adres " . setting('mail_address') . " — odpiszemy z odczytanym wzorem.\n";
    } else {
        $subject = 'Link do logowania — Równania i LaTeX';
        $body = "Dzień dobry$name,\n\nTwój link do logowania (działa 20 minut, jeden raz):\n$link\n\nDostęp do LatToText jest tylko przez taki link — nie ma haseł.\nJeśli nie prosiłeś o ten link, zignoruj tę wiadomość.\n";
    }
    lx_mail($u['email'], $subject, $body);
    return true;
}

function consume_login_token(string $token): ?array
{
    $s = db()->prepare('SELECT * FROM login_tokens WHERE token_hash = ?');
    $s->execute([hash('sha256', $token)]);
    $t = $s->fetch();
    if (!$t || $t['used'] || $t['expires_at'] < time()) return null;
    db()->prepare('UPDATE login_tokens SET used = 1 WHERE token_hash = ?')->execute([$t['token_hash']]);
    $u = find_user((int)$t['user_id']);
    return ($u && !$u['blocked']) ? $u : null;
}

function new_api_token(array $u): string
{
    $token = 'lx_' . bin2hex(random_bytes(20));
    db()->prepare('UPDATE users SET api_token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $u['id']]);
    return $token;
}

// ---------- poczta ----------

function lx_mail(string $to, string $subject, string $body, array $extraHeaders = []): bool
{
    $from = setting('mail_address');
    $fromHeader = mb_encode_mimeheader(setting('sender_name') ?: 'Równania i LaTeX', 'UTF-8') . " <$from>";
    $headers = array_merge([
        'From' => $fromHeader,
        'Reply-To' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'Auto-Submitted' => 'auto-generated',
    ], $extraHeaders);
    $encSubject = mb_encode_mimeheader($subject, 'UTF-8');
    $encBody = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    // Bez hasła SMTP (np. przed konfiguracją skrzynki) wysyłamy przez mail() serwera WWW
    if (setting('smtp_mode') === 'smtp' && setting('smtp_host') !== '' && smtp_password() !== '') {
        $all = array_merge(['Date' => date(DATE_RFC2822), 'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>',
            'To' => $to, 'Subject' => $encSubject], $headers);
        $data = '';
        foreach ($all as $k => $v) $data .= "$k: $v\r\n";
        $err = smtp_send($to, $from, $data . "\r\n" . $encBody);
        set_setting('mail_last_send', now() . ($err ? ' — błąd: ' . $err : ' — wysłano do ' . $to));
        return $err === null;
    }
    $ok = mail($to, $encSubject, $encBody, $headers, '-f ' . $from);
    set_setting('mail_last_send', now() . ($ok ? ' — przekazano do funkcji mail() serwera' : ' — funkcja mail() zwróciła błąd'));
    return $ok;
}

/** Minimalny klient SMTP (SSL, STARTTLS albo bez szyfrowania; AUTH LOGIN). Zwraca opis błędu albo null. */
function smtp_send(string $to, string $from, string $message): ?string
{
    $sec = setting('smtp_security');
    $host = setting('smtp_host');
    $port = (int)setting('smtp_port');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "nie udało się połączyć z $host:$port ($errstr)";
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) { $resp .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $resp;
    };
    $cmd = function (?string $c, array $ok) use ($fp, $read): string {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim($r) ?: 'brak odpowiedzi serwera');
        return $r;
    };
    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($sec === 'starttls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('nie udało się włączyć szyfrowania STARTTLS');
            $cmd($ehlo, [250]);
        }
        $pass = smtp_password();
        if ($pass !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(smtp_login()), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd(preg_replace('/^\./m', '..', $message) . "\r\n.", [250]);
        try { $cmd('QUIT', [221]); } catch (RuntimeException) {}
        fclose($fp);
        return null;
    } catch (RuntimeException $e) {
        fclose($fp);
        return 'serwer poczty odpowiedział: ' . $e->getMessage();
    }
}

// ---------- limity i odczyt ----------

function count_scans(string $where, array $args): int
{
    $s = db()->prepare("SELECT COUNT(*) FROM scans WHERE created_at >= ? AND status != 'rejected' AND $where");
    $s->execute(array_merge([today() . ' 00:00:00'], $args));
    return (int)$s->fetchColumn();
}

function user_limit(array $u): int { return $u['daily_limit'] !== null ? (int)$u['daily_limit'] : (int)setting('user_daily_limit'); }

/** Tryb prywatny: strony, API i poczta tylko dla osób z kontem. */
function private_mode(): bool { return setting('private_mode') === '1'; }

function gate_allowed(): bool { return !private_mode() || current_user() !== null; }

/** Zwraca komunikat błędu, jeśli limit został wyczerpany, albo null. */
function limit_error(?array $u, bool $mailAnon = false): ?string
{
    if (count_scans('1 = 1', []) >= (int)setting('global_daily_limit')) return 'Wykorzystano dzienny limit odczytów dla całej strony. Spróbuj jutro.';
    if ($u) {
        if (count_scans('user_id = ?', [$u['id']]) >= user_limit($u)) return 'Wykorzystano Twój dzienny limit odczytów (' . user_limit($u) . '). Spróbuj jutro albo napisz do administratora.';
        return null;
    }
    if ($mailAnon) {
        if (count_scans('user_id IS NULL AND ip_hash = ?', [ip_hash()]) >= (int)setting('mail_anon_limit')) return 'Wykorzystano dzienny limit odczytów z tego adresu e-mail (' . setting('mail_anon_limit') . '). Spróbuj jutro albo poproś o konto.';
        return null;
    }
    if (setting('anon_enabled') !== '1') return 'Odczyt z kartki wymaga konta. Zaloguj się na stronie Moje konto.';
    if (count_scans('user_id IS NULL AND ip_hash = ?', [ip_hash()]) >= (int)setting('anon_daily_limit')) return 'Wykorzystano dzienny limit odczytów bez konta. Zaloguj się albo spróbuj jutro.';
    return null;
}

function node_job(array $job): array
{
    $env = ['HOME' => getenv('HOME') ?: dirname(LX_DIR), 'PATH' => '/usr/bin:/bin'];
    // Klucz z panelu admina ma pierwszeństwo przed plikiem .env
    if (($key = get_secret('anthropic_api_key')) !== '') $env['ANTHROPIC_API_KEY'] = $key;
    // Ścieżka do Node: NODE_BIN w .env (na hostingu z nvm PATH serwera WWW zwykle jej nie zna)
    $proc = proc_open([lx_env('NODE_BIN', 'node'), LX_DIR . '/cli.cjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LX_DIR, $env);
    if (!is_resource($proc)) return ['status' => 500, 'error' => 'Nie udało się uruchomić konwertera.'];
    fwrite($pipes[0], json_encode($job));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $res = json_decode($out ?: '', true);
    if (!is_array($res)) { error_log('latex-api: ' . substr($err, 0, 500)); return ['status' => 500, 'error' => 'Konwerter nie odpowiedział poprawnie.']; }
    return $res;
}

/**
 * Odczytuje wzory z obrazu lub PDF-u przez Claude API i zapisuje wynik w historii.
 * Zwraca odpowiedź w formacie API (z polem status = kod HTTP).
 */
function run_scan(string $bytes, ?array $u, string $source, string $fileName = '', bool $mailAnon = false, bool $onlyMath = true, string $pages = ''): array
{
    if ($bytes === '') return ['status' => 400, 'error' => 'Plik jest pusty.'];
    if (strlen($bytes) > LX_MAX_FILE) return ['status' => 413, 'error' => 'Plik jest za duży. Limit to 5 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
    if (!in_array($mime, LX_MEDIA, true)) return ['status' => 415, 'error' => 'Obsługiwane pliki: JPG, PNG, WEBP, GIF i PDF. Zdjęcia HEIC zapisz najpierw jako JPG.'];
    if ($err = limit_error($u, $mailAnon)) return ['status' => 429, 'error' => $err];

    $db = db();
    $db->prepare('INSERT INTO scans(user_id, source, ip_hash, created_at, media_type, file_name, status) VALUES(?, ?, ?, ?, ?, ?, ?)')
        ->execute([$u['id'] ?? null, $source, ip_hash(), now(), $mime, mb_substr($fileName, 0, 200), 'pending']);
    $id = (int)$db->lastInsertId();

    // Pliku nie zapisujemy na dysku: idzie w pamięci do odczytu i znika po zapytaniu.
    // W historii zostaje tylko wynik (LaTeX, zwykły zapis, odczyt), nazwa pliku i koszt.
    $rel = null;

    $res = node_job(['action' => 'scan', 'media_type' => $mime, 'data' => base64_encode($bytes), 'only_math' => $onlyMath, 'pages' => $pages]);
    $code = (int)($res['status'] ?? 500);
    $in = (int)($res['usage']['input_tokens'] ?? 0);
    $outT = (int)($res['usage']['output_tokens'] ?? 0);
    $cost = $in / 1e6 * LX_PRICE_IN + $outT / 1e6 * LX_PRICE_OUT;
    $db->prepare('UPDATE scans SET file_path = ?, status = ?, error = ?, latex = ?, plain = ?, speech = ?, model = ?, input_tokens = ?, output_tokens = ?, cost_usd = ? WHERE id = ?')
        ->execute([$rel, $code === 200 ? 'ok' : ($code === 503 ? 'rejected' : 'error'), $res['error'] ?? null, $res['latex'] ?? null, $res['text'] ?? null,
            $res['speech'] ?? null, $res['model'] ?? null, $in, $outT, $cost, $id]);
    unset($res['usage']);
    $res['id'] = $id;
    return $res;
}

define('LX_SAMPLE', LX_DIR . '/web/przyklad-krotki.pdf');

/** Test całego odczytu na przykładowym PDF-ie. Zapisuje wynik w sesji, żeby pokazać go po przekierowaniu. */
function test_scan(array $u): void
{
    lx_session();
    set_time_limit(130);
    $t0 = microtime(true);
    $res = run_scan((string)@file_get_contents(LX_SAMPLE), $u, 'web', 'przyklad-krotki.pdf (test)');
    $res['seconds'] = round(microtime(true) - $t0, 1);
    if (!empty($res['id'])) {
        $s = db()->prepare('SELECT input_tokens, output_tokens, cost_usd FROM scans WHERE id = ?');
        $s->execute([$res['id']]);
        $res['cost'] = $s->fetch() ?: null;
    }
    $_SESSION['test_result'] = $res;
}

/** HTML z wynikiem testu (raz, po przekierowaniu). */
function test_result_html(bool $details): string
{
    lx_session();
    $r = $_SESSION['test_result'] ?? null;
    unset($_SESSION['test_result']);
    if (!$r) return '';
    $ok = ($r['status'] ?? 500) === 200;
    $h = '<div class="' . ($ok ? 'msg' : 'msg err') . '" role="' . ($ok ? 'status' : 'alert') . '" tabindex="-1" id="test-result">'
        . ($ok ? 'Test udany: przykładowy PDF został odczytany w ' . e((string)$r['seconds']) . ' s.' : 'Test nieudany: ' . e($r['error'] ?? 'nieznany błąd')) . '</div>';
    if ($ok) {
        $h .= '<h3>Odczytany LaTeX</h3><pre translate="no" tabindex="0">' . e($r['latex']) . '</pre>';
        if (!empty($r['text'])) $h .= '<h3>Zwykły zapis</h3><pre translate="no" tabindex="0">' . e($r['text']) . '</pre>';
        if (!empty($r['speech'])) $h .= '<h3>Odczyt słowny</h3><p>' . e($r['speech']) . '</p>';
    }
    if ($details && !empty($r['cost'])) {
        $c = $r['cost'];
        $h .= '<p class="hint">Tokeny: ' . (int)$c['input_tokens'] . ' wejścia, ' . (int)$c['output_tokens'] . ' wyjścia · koszt ok. ' . e(usd((float)$c['cost_usd'])) . ' · numer odczytu w historii: ' . (int)$r['id'] . '</p>';
    }
    return $h;
}

/** Usuwa pliki starsze niż ustawienie keep_files_days (wynik odczytu zostaje w historii). */
function cleanup_files(): int
{
    $days = max(1, (int)setting('keep_files_days'));
    $s = db()->prepare('SELECT id, file_path FROM scans WHERE file_path IS NOT NULL AND created_at < ?');
    $s->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
    $n = 0;
    foreach ($s->fetchAll() as $r) {
        @unlink(LX_DIR . '/data/' . $r['file_path']);
        db()->prepare('UPDATE scans SET file_path = NULL WHERE id = ?')->execute([$r['id']]);
        $n++;
    }
    return $n;
}
