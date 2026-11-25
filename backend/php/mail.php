<?php
// Odczyt załączników ze skrzynki IMAP i odpowiedzi mailem.
// Przyjmuje maile od użytkowników, a gdy admin pozwoli — od każdego nadawcy (z dziennym limitem na adres).
declare(strict_types=1);

const LX_MAIL_MAX_MESSAGES = 20;     // na jedno uruchomienie
const LX_MAIL_MAX_ATTACH   = 5;      // na jeden mail

function imap_mailbox_string(): string
{
    $flags = ['ssl' => '/imap/ssl', 'starttls' => '/imap/tls', 'none' => '/imap/notls'][setting('imap_security')] ?? '/imap/ssl';
    return '{' . setting('imap_host') . ':' . (int)setting('imap_port') . $flags . '}' . setting('imap_folder');
}

/** @return array{0: IMAP\Connection|false, 1: string} */
function imap_connect()
{
    $pass = imap_password();
    if ($pass === '') return [false, 'Brak hasła skrzynki: wpisz je w panelu admina, w części Ustawienia skrzynki.'];
    $user = imap_login();
    $mbox = @imap_open(imap_mailbox_string(), $user, $pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$mbox) {
        $err = imap_last_error() ?: 'nieznany błąd';
        imap_errors();
        return [false, 'Nie udało się połączyć ze skrzynką ' . $user . ': ' . $err];
    }
    return [$mbox, 'Połączono ze skrzynką ' . $user . '.'];
}

function decode_part(string $data, int $encoding): string
{
    return match ($encoding) {
        3 => base64_decode($data) ?: '',
        4 => quoted_printable_decode($data),
        default => $data,
    };
}

function part_filename(object $p): string
{
    foreach ([$p->dparameters ?? [], $p->parameters ?? []] as $params) {
        foreach ($params as $x) {
            if (in_array(strtolower($x->attribute), ['filename', 'name', 'filename*', 'name*'], true)) {
                $v = $x->value;
                if (str_starts_with(strtolower($x->attribute), 'filename*') || str_ends_with($x->attribute, '*')) {
                    $v = rawurldecode(preg_replace("/^[^']*'[^']*'/", '', $v));
                }
                return iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $v;
            }
        }
    }
    return '';
}

/** Zbiera załączniki-obrazy i PDF-y z drzewa części maila. */
function collect_attachments($mbox, int $uid, object $part, string $section, array &$out): void
{
    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $sub) {
            collect_attachments($mbox, $uid, $sub, $section === '' ? (string)($i + 1) : "$section." . ($i + 1), $out);
        }
        return;
    }
    $isImage = $part->type === 5;
    $isPdf = $part->type === 3 && strtolower($part->subtype ?? '') === 'pdf';
    if (!$isImage && !$isPdf) return;
    if (count($out) >= LX_MAIL_MAX_ATTACH) return;
    $raw = imap_fetchbody($mbox, $uid, $section === '' ? '1' : $section, FT_UID | FT_PEEK);
    $bytes = decode_part((string)$raw, (int)$part->encoding);
    if ($bytes === '') return;
    $out[] = ['name' => part_filename($part) ?: ('zalacznik-' . (count($out) + 1)), 'bytes' => $bytes];
}

function reply_body(array $results, string $name): string
{
    $txt = str_replace('{imie}', $name, setting('reply_intro')) . "\n\n";
    foreach ($results as $i => [$file, $res]) {
        $txt .= str_repeat('-', 40) . "\n" . ($i + 1) . '. ' . $file . "\n\n";
        if (($res['status'] ?? 500) === 200) {
            $txt .= "LaTeX:\n" . $res['latex'] . "\n\n";
            if (!empty($res['text'])) $txt .= "Zwykły zapis:\n" . $res['text'] . "\n\n";
            if (!empty($res['speech'])) $txt .= "Odczyt słowny:\n" . $res['speech'] . "\n\n";
        } else {
            $txt .= 'Nie udało się odczytać: ' . ($res['error'] ?? 'nieznany błąd') . "\n\n";
        }
    }
    return $txt . "--\nRównania i LaTeX · " . LX_URL . "/\nHistoria odczytów: " . LX_URL . "/konto/\n";
}

/**
 * Sprawdza skrzynkę. $force pomija wyłącznik w ustawieniach (przycisk w panelu).
 * Zwraca opis wyniku po polsku.
 */
function poll_mailbox(bool $force = false): string
{
    if (!$force && setting('mail_enabled') !== '1') return 'Odczyt z maila jest wyłączony w ustawieniach.';
    $lock = fopen(LX_DIR . '/data/mail.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'Skrzynka jest właśnie sprawdzana przez inne zadanie.';

    [$mbox, $msg] = imap_connect();
    if (!$mbox) { finish_poll($msg); return $msg; }

    $uids = imap_search($mbox, 'UNSEEN', SE_UID) ?: [];
    $stats = ['przetworzone' => 0, 'odrzucone' => 0, 'załączniki' => 0, 'błędy' => 0];
    foreach (array_slice($uids, 0, LX_MAIL_MAX_MESSAGES) as $uid) {
        $uid = (int)$uid;
        try {
            process_message($mbox, $uid, $stats);
        } catch (Throwable $e) {
            $stats['błędy']++;
            error_log('latex-mail uid ' . $uid . ': ' . $e->getMessage());
        }
        // Oznaczamy jako przeczytany dopiero po obsłużeniu (także po błędzie, żeby nie zapętlić się na jednym mailu)
        imap_setflag_full($mbox, (string)$uid, '\\Seen', ST_UID);
    }
    imap_close($mbox);
    imap_errors(); imap_alerts();
    $removed = cleanup_files();
    $summary = sprintf('Sprawdzono skrzynkę: %d nowych maili, odpowiedziano na %d, pominięto %d (automaty, zablokowane konta lub maile bez załącznika), odczytano %d załączników.',
        count($uids), $stats['przetworzone'], $stats['odrzucone'], $stats['załączniki']) . ($stats['błędy'] ? ' Błędy przy ' . $stats['błędy'] . ' mailach — szczegóły w logu serwera.' : '') . ($removed ? " Usunięto $removed starych plików." : '');
    finish_poll($summary);
    flock($lock, LOCK_UN);
    return $summary;
}

/** Obsługuje jedną wiadomość: sprawdza nadawcę, odczytuje załączniki i odpowiada. */
function process_message($mbox, int $uid, array &$stats): void
{
    $msgno = imap_msgno($mbox, $uid);
    $h = imap_headerinfo($mbox, $msgno);
    $rawHeaders = (string)imap_fetchheader($mbox, $uid, FT_UID);

    $from = $h->from[0] ?? null;
    $sender = $from ? strtolower($from->mailbox . '@' . $from->host) : '';
    // Nie odpowiadamy na automaty, zwrotki i własne wiadomości (zapobiega pętlom)
    if (preg_match('/^(Auto-Submitted:\s*auto-|Precedence:\s*(bulk|junk|list))/mi', $rawHeaders) || $sender === strtolower(setting('mail_address'))) {
        $stats['odrzucone']++;
        return;
    }
    $u = $sender ? find_user_by_email($sender) : null;
    if ($u && $u['blocked']) { $stats['odrzucone']++; return; }
    // Bez konta: tylko gdy admin pozwala przyjmować maile od każdego (limit liczony według adresu nadawcy)
    $anon = !$u;
    if ($anon && (private_mode() || setting('mail_from_anyone') !== '1' || !filter_var($sender, FILTER_VALIDATE_EMAIL))) { $stats['odrzucone']++; return; }
    $replyTo = $u['email'] ?? $sender;
    $replyName = $u['name'] ?? '';

    $structure = imap_fetchstructure($mbox, $uid, FT_UID);
    $atts = [];
    if ($structure) collect_attachments($mbox, (int)$uid, $structure, '', $atts);

    $subjectIn = isset($h->subject) ? (iconv_mime_decode($h->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: '') : '';
    $replyHeaders = [];
    if (!empty($h->message_id)) { $replyHeaders['In-Reply-To'] = $h->message_id; $replyHeaders['References'] = $h->message_id; }

    if (!$atts) {
        // Osobom bez konta nie odpisujemy na maile bez załącznika (ochrona przed spamem zwrotnym)
        if ($anon) { $stats['odrzucone']++; return; }
        lx_mail($u['email'], 'Re: ' . ($subjectIn ?: 'wzór'), "Dzień dobry,\n\nw Twojej wiadomości nie znalazłem zdjęcia ani PDF-u. Załącz zdjęcie kartki ze wzorem (JPG, PNG, WEBP, GIF albo PDF, do 5 MB) i wyślij ponownie.\n\n--\nRównania i LaTeX · " . LX_URL . "/\n", $replyHeaders);
        $stats['przetworzone']++;
        return;
    }
    $results = [];
    foreach ($atts as $a) {
        $GLOBALS['lx_anon_key'] = $anon ? 'mail:' . $sender : null;
        $res = run_scan($a['bytes'], $u, 'email', $a['name'], $anon);
        unset($GLOBALS['lx_anon_key']);
        $results[] = [$a['name'], $res];
        $stats['załączniki']++;
    }
    $subject = str_replace('{plik}', count($atts) === 1 ? $atts[0]['name'] : count($atts) . ' załączniki', setting('reply_subject'));
    lx_mail($replyTo, $subject, reply_body($results, $replyName), $replyHeaders);
    $stats['przetworzone']++;
}

function finish_poll(string $summary): void
{
    set_setting('mail_last_run', now());
    set_setting('mail_last_result', $summary);
}
