<?php
// Panel admina: użytkownicy i limity, historia odczytów, zużycie Claude API, ustawienia skrzynki.
declare(strict_types=1);
require_once dirname(__DIR__) . '/sciezka.php';
require_once LX_DIR . '/php/layout.php';
require_once LX_DIR . '/php/mail.php';

$me = current_user();
if (!$me) { flash('Zaloguj się, żeby otworzyć panel admina.', 'err'); redirect('../konto/'); }
if ($me['role'] !== 'admin') { http_response_code(403); page_start('Brak dostępu', $me, 'admin'); echo '<h1>Brak dostępu</h1><p>Panel jest tylko dla administratorów.</p>'; page_end(); exit; }

$tabs = ['users' => 'Użytkownicy', 'history' => 'Historia odczytów', 'usage' => 'Zużycie i koszty', 'settings' => 'Ustawienia skrzynki'];
$tab = $_GET['tab'] ?? 'users';
if (!isset($tabs[$tab])) $tab = 'users';
$db = db();

// ---------- akcje ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Formularz wygasł. Spróbuj jeszcze raz.', 'err'); redirect("./?tab=$tab"); }
    $a = $_POST['action'] ?? '';
    $limitIn = trim((string)($_POST['daily_limit'] ?? ''));
    $limitVal = $limitIn === '' ? null : max(0, (int)$limitIn);

    if ($a === 'user-add') {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Adres e-mail jest niepoprawny. Popraw go i dodaj ponownie.', 'err'); redirect('./?tab=users'); }
        if (find_user_by_email($email)) { flash("Konto $email już istnieje.", 'err'); redirect('./?tab=users'); }
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $db->prepare('INSERT INTO users(email, name, role, daily_limit, created_at) VALUES(?, ?, ?, ?, ?)')
            ->execute([$email, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80), $role, $limitVal, now()]);
        $u = find_user_by_email($email);
        if (!empty($_POST['invite'])) { send_login_link($u, true); flash("Dodano konto $email i wysłano zaproszenie z linkiem logowania."); }
        else flash("Dodano konto $email. Użytkownik zaloguje się, wpisując adres na stronie Moje konto.");
        redirect('./?tab=users');
    }
    $id = (int)($_POST['id'] ?? 0);
    $target = $id ? find_user($id) : null;
    if (in_array($a, ['user-save', 'user-delete', 'user-invite'], true) && !$target) { flash('Nie ma takiego konta.', 'err'); redirect('./?tab=users'); }
    if ($a === 'user-save') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $blocked = !empty($_POST['blocked']) ? 1 : 0;
        if ($target['id'] === $me['id'] && ($role !== 'admin' || $blocked)) { flash('Nie możesz odebrać sobie roli administratora ani zablokować własnego konta.', 'err'); redirect('./?tab=users'); }
        $db->prepare('UPDATE users SET name = ?, role = ?, daily_limit = ?, blocked = ?, api_enabled = ? WHERE id = ?')
            ->execute([mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80), $role, $limitVal, $blocked, !empty($_POST['api_enabled']) ? 1 : 0, $id]);
        flash('Zapisano zmiany konta ' . $target['email'] . '.');
        redirect('./?tab=users');
    }
    if ($a === 'user-invite') {
        flash(send_login_link($target, true) ? 'Wysłano ponownie zaproszenie do ' . $target['email'] . '.' : 'Za dużo linków w ostatniej godzinie. Spróbuj później.', 'ok');
        redirect('./?tab=users');
    }
    if ($a === 'user-delete') {
        if ($target['id'] === $me['id']) { flash('Nie możesz usunąć własnego konta.', 'err'); redirect('./?tab=users'); }
        if (($_POST['confirm'] ?? '') !== $target['email']) { flash('Konto nie zostało usunięte: wpisany adres nie zgadza się z adresem konta.', 'err'); redirect('./?tab=users&delete=' . $id); }
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('Usunięto konto ' . $target['email'] . '. Jego odczyty zostały w historii jako anonimowe.');
        redirect('./?tab=users');
    }
    if ($a === 'settings') {
        foreach (['mail_address', 'sender_name', 'imap_host', 'imap_port', 'imap_user', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_user',
                  'reply_subject', 'reply_intro', 'user_daily_limit', 'anon_daily_limit', 'global_daily_limit', 'keep_files_days'] as $k) {
            if (isset($_POST[$k])) set_setting($k, trim((string)$_POST[$k]));
        }
        foreach (['imap_security' => ['ssl', 'starttls', 'none'], 'smtp_security' => ['ssl', 'starttls', 'none'], 'smtp_mode' => ['smtp', 'mail']] as $k => $allowed) {
            if (in_array($_POST[$k] ?? '', $allowed, true)) set_setting($k, $_POST[$k]);
        }
        set_setting('mail_enabled', !empty($_POST['mail_enabled']) ? '1' : '0');
        set_setting('private_mode', !empty($_POST['private_mode']) ? '1' : '0');
        set_setting('mail_from_anyone', !empty($_POST['mail_from_anyone']) ? '1' : '0');
        if (isset($_POST['mail_anon_limit'])) set_setting('mail_anon_limit', (string)max(0, (int)$_POST['mail_anon_limit']));
        set_setting('anon_enabled', !empty($_POST['anon_enabled']) ? '1' : '0');
        set_setting('smtp_same_pass', !empty($_POST['smtp_same_pass']) ? '1' : '0');
        // Puste pole hasła = bez zmian
        if (($_POST['imap_password'] ?? '') !== '') set_secret('imap_password', (string)$_POST['imap_password']);
        if (($_POST['smtp_password'] ?? '') !== '') set_secret('smtp_password', (string)$_POST['smtp_password']);
        flash('Zapisano ustawienia.');
        redirect('./?tab=settings');
    }
    if ($a === 'smtp-test') {
        $ok = lx_mail($me['email'], 'Test wysyłki — Równania i LaTeX', "To jest wiadomość testowa z panelu admina.\nJeśli ją widzisz, wysyłka poczty działa.\n");
        flash($ok ? 'Wysłano testowy mail na ' . $me['email'] . '. Sprawdź skrzynkę (także spam).' : 'Nie udało się wysłać maila. ' . setting('mail_last_send'), $ok ? 'ok' : 'err');
        redirect('./?tab=settings');
    }
    if ($a === 'imap-test') {
        [$mbox, $msg] = imap_connect();
        if ($mbox) { $n = imap_num_msg($mbox); imap_close($mbox); flash("$msg W folderze jest $n wiadomości."); }
        else flash($msg, 'err');
        redirect('./?tab=settings');
    }
    if ($a === 'api-key-save') {
        $key = trim((string)($_POST['anthropic_key'] ?? ''));
        if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $key)) { flash('To nie wygląda na klucz Claude API. Klucz zaczyna się od sk-ant- — skopiuj go w całości z konsoli Anthropic.', 'err'); redirect('./?tab=settings#key-h'); }
        set_secret('anthropic_api_key', $key);
        $res = node_job(['action' => 'check-key']);
        flash('Zapisano klucz Claude API. ' . ($res['message'] ?? 'Test: ' . ($res['error'] ?? 'brak odpowiedzi')), ($res['status'] ?? 500) === 200 ? 'ok' : 'err');
        redirect('./?tab=settings#key-h');
    }
    if ($a === 'api-key-test') {
        $res = node_job(['action' => 'check-key']);
        flash($res['message'] ?? ($res['error'] ?? 'Brak odpowiedzi.'), ($res['status'] ?? 500) === 200 ? 'ok' : 'err');
        redirect('./?tab=settings#key-h');
    }
    if ($a === 'api-key-delete') {
        if (($_POST['confirm'] ?? '') !== '1') { flash('Klucz nie został usunięty — zaznacz potwierdzenie.', 'err'); redirect('./?tab=settings#key-h'); }
        db()->prepare('DELETE FROM settings WHERE key = ?')->execute(['anthropic_api_key']);
        flash('Usunięto klucz Claude API z panelu. Odczyt z kartki działa dalej tylko, jeśli klucz jest w pliku .env.');
        redirect('./?tab=settings#key-h');
    }
    if ($a === 'da-mailbox') {
        // Zakłada skrzynkę przez API DirectAdmin. Dane logowania do DirectAdmin nie są nigdzie zapisywane.
        $daUrl = rtrim(trim((string)($_POST['da_url'] ?? '')), '/');
        $daUser = trim((string)($_POST['da_user'] ?? ''));
        $daPass = (string)($_POST['da_pass'] ?? '');
        $box = strtolower(trim((string)($_POST['box_user'] ?? '')));
        $domain = strtolower(trim((string)($_POST['box_domain'] ?? '')));
        $quota = max(0, (int)($_POST['box_quota'] ?? 500));
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $daUrl) || $daUser === '' || $daPass === '') { flash('Uzupełnij adres panelu, login i hasło DirectAdmin.', 'err'); redirect('./?tab=settings#da-h'); }
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $box) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) { flash('Nazwa skrzynki może mieć tylko małe litery, cyfry, kropki, myślniki i podkreślenia, a domena musi być poprawna.', 'err'); redirect('./?tab=settings#da-h'); }
        $boxPass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), '=') . '7!';
        $ch = curl_init($daUrl . '/CMD_API_POP');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $daUser . ':' . $daPass,
            CURLOPT_POSTFIELDS => http_build_query(['action' => 'create', 'domain' => $domain, 'user' => $box, 'passwd' => $boxPass, 'passwd2' => $boxPass, 'quota' => $quota, 'limit' => 0]),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        unset($daPass);
        if ($resp === false) { flash('Nie udało się połączyć z DirectAdmin: ' . $cerr, 'err'); redirect('./?tab=settings#da-h'); }
        if ($code === 401) { flash('DirectAdmin odrzucił login albo hasło. Sprawdź dane i spróbuj ponownie.', 'err'); redirect('./?tab=settings#da-h'); }
        parse_str((string)$resp, $out);
        if (($out['error'] ?? '1') !== '0') {
            $why = trim(strip_tags(urldecode(($out['text'] ?? '') . ' ' . ($out['details'] ?? '')))) ?: 'nieznany błąd (kod ' . $code . ')';
            flash('DirectAdmin nie założył skrzynki: ' . $why, 'err');
            redirect('./?tab=settings#da-h');
        }
        $address = "$box@$domain";
        set_setting('mail_address', $address);
        set_setting('imap_user', '');
        set_setting('smtp_user', '');
        set_setting('smtp_same_pass', '1');
        set_secret('imap_password', $boxPass);
        [$mbox, $msg] = imap_connect();
        if ($mbox) imap_close($mbox);
        flash("Założono skrzynkę $address i zapisano jej hasło w ustawieniach. " . ($mbox ? 'Połączenie IMAP działa.' : 'Test IMAP: ' . $msg . ' (nowa skrzynka może potrzebować chwili — sprawdź ponownie za minutę).'), $mbox ? 'ok' : 'err');
        redirect('./?tab=settings');
    }
    if ($a === 'test-scan') { test_scan($me); redirect('./?tab=settings#test-h'); }
    if ($a === 'imap-poll') { flash(poll_mailbox(true)); redirect('./?tab=settings'); }
}

page_start('Panel admina', $me, 'admin');
?>
<h1>Panel admina</h1>
<nav class="tabs" aria-label="Części panelu">
  <ul>
    <?php foreach ($tabs as $k => $label): ?>
      <li><a href="?tab=<?= $k ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<h2 class="sr"><?= e($tabs[$tab]) ?></h2>
<?php

// ---------- Użytkownicy ----------
if ($tab === 'users') {
    $delId = (int)($_GET['delete'] ?? 0);
    $editId = (int)($_GET['edit'] ?? 0);
    if ($delId && ($t = find_user($delId))) { ?>
      <div class="confirm" role="alert" aria-labelledby="del-h">
        <h3 id="del-h" style="margin:0">Usunąć konto <?= e($t['email']) ?>?</h3>
        <p>Tego nie da się cofnąć. Użytkownik straci dostęp i token API, a jego odczyty zostaną w historii jako anonimowe. Jeśli chcesz tylko wstrzymać dostęp, zablokuj konto zamiast je usuwać.</p>
        <form method="post" class="stack">
          <?= csrf_field() ?><input type="hidden" name="action" value="user-delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <label class="f" for="confirm-email">Wpisz adres konta, aby potwierdzić
            <input type="email" id="confirm-email" name="confirm" autocomplete="off" required>
          </label>
          <div class="row"><button class="danger" type="submit">Usuń konto na stałe</button><a class="btn plain" href="./?tab=users">Anuluj</a></div>
        </form>
      </div>
    <?php }
    if ($editId && ($t = find_user($editId))) { ?>
      <section class="block" aria-labelledby="edit-h">
        <h3 id="edit-h">Edycja konta <?= e($t['email']) ?></h3>
        <form method="post" class="stack">
          <?= csrf_field() ?><input type="hidden" name="action" value="user-save"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <label class="f" for="e-name">Imię<input type="text" id="e-name" name="name" maxlength="80" value="<?= e($t['name']) ?>"></label>
          <label class="f" for="e-role">Rola
            <select id="e-role" name="role"><option value="user"<?= $t['role'] === 'user' ? ' selected' : '' ?>>użytkownik</option><option value="admin"<?= $t['role'] === 'admin' ? ' selected' : '' ?>>administrator</option></select>
          </label>
          <label class="f" for="e-limit">Dzienny limit odczytów
            <input type="number" id="e-limit" name="daily_limit" min="0" inputmode="numeric" value="<?= e($t['daily_limit'] === null ? '' : (string)$t['daily_limit']) ?>" aria-describedby="e-limit-h">
          </label>
          <p class="hint" id="e-limit-h">Puste pole oznacza limit domyślny (<?= e(setting('user_daily_limit')) ?> dziennie).</p>
          <label class="c" for="e-api"><input type="checkbox" id="e-api" name="api_enabled" value="1"<?= $t['api_enabled'] ? ' checked' : '' ?>> Dostęp do API (token i linki do obrazków)</label>
          <label class="c" for="e-blocked"><input type="checkbox" id="e-blocked" name="blocked" value="1"<?= $t['blocked'] ? ' checked' : '' ?>> Konto zablokowane (bez logowania, API i odczytu z maila)</label>
          <div class="row"><button type="submit">Zapisz zmiany</button><a class="btn plain" href="./?tab=users">Anuluj</a></div>
        </form>
      </section>
    <?php }
    $rows = $db->query("SELECT u.*,
        (SELECT COUNT(*) FROM scans s WHERE s.user_id = u.id AND s.created_at >= '" . today() . " 00:00:00' AND s.status != 'rejected') AS today,
        (SELECT COUNT(*) FROM scans s WHERE s.user_id = u.id) AS total
        FROM users u ORDER BY u.role = 'admin' DESC, u.email")->fetchAll();
    ?>
    <section aria-labelledby="ul-h">
      <h3 id="ul-h">Konta (<?= count($rows) ?>)</h3>
      <div class="scroll">
        <table>
          <caption class="sr">Lista kont z limitami i aktywnością</caption>
          <thead><tr><th scope="col">E-mail</th><th scope="col">Imię</th><th scope="col">Rola</th><th scope="col">Stan</th><th scope="col">API</th><th scope="col" class="num">Dziś / limit</th><th scope="col" class="num">Razem</th><th scope="col">Ostatnie logowanie</th><th scope="col">Działania</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <th scope="row" style="font-weight:400;white-space:normal"><?= e($r['email']) ?></th>
              <td><?= e($r['name'] ?: '—') ?></td>
              <td><?= $r['role'] === 'admin' ? 'administrator' : 'użytkownik' ?></td>
              <td><?= $r['blocked'] ? '<span class="tag err">zablokowane</span>' : '<span class="tag ok">aktywne</span>' ?></td>
              <td><?= !$r['api_enabled'] ? '<span class="tag">wyłączone</span>' : ($r['api_token_hash'] ? 'token, ' . (int)$r['api_calls'] . ' zapytań' : 'bez tokenu') ?></td>
              <td class="num"><?= (int)$r['today'] ?> / <?= user_limit($r) ?></td>
              <td class="num"><?= (int)$r['total'] ?></td>
              <td><?= e($r['last_login_at'] ? substr($r['last_login_at'], 0, 16) : 'jeszcze nie') ?></td>
              <td>
                <div class="row" style="gap:.25rem .75rem">
                  <a href="?tab=users&amp;edit=<?= $r['id'] ?>" aria-label="Edytuj konto <?= e($r['email']) ?>">Edytuj</a>
                  <a href="?tab=history&amp;user=<?= $r['id'] ?>" aria-label="Historia odczytów <?= e($r['email']) ?>">Historia</a>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user-invite"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="link" type="submit" aria-label="Wyślij ponownie zaproszenie do <?= e($r['email']) ?>">Zaproś ponownie</button></form>
                  <?php if ($r['id'] !== $me['id']): ?><a href="?tab=users&amp;delete=<?= $r['id'] ?>" aria-label="Usuń konto <?= e($r['email']) ?>">Usuń</a><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <section class="block" aria-labelledby="add-h">
      <h3 id="add-h">Dodaj konto</h3>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="user-add">
        <label class="f" for="a-email">Adres e-mail (wymagany)<input type="email" id="a-email" name="email" required autocomplete="off"></label>
        <label class="f" for="a-name">Imię<input type="text" id="a-name" name="name" maxlength="80"></label>
        <label class="f" for="a-role">Rola
          <select id="a-role" name="role"><option value="user">użytkownik</option><option value="admin">administrator</option></select>
        </label>
        <label class="f" for="a-limit">Dzienny limit odczytów<input type="number" id="a-limit" name="daily_limit" min="0" inputmode="numeric" aria-describedby="a-limit-h"></label>
        <p class="hint" id="a-limit-h">Zostaw puste, aby użyć limitu domyślnego (<?= e(setting('user_daily_limit')) ?> dziennie).</p>
        <label class="c" for="a-invite"><input type="checkbox" id="a-invite" name="invite" value="1" checked> Wyślij zaproszenie z linkiem logowania</label>
        <button type="submit">Dodaj konto</button>
      </form>
    </section>
    <?php
}

// ---------- Historia ----------
if ($tab === 'history') {
    $where = ['1 = 1']; $args = [];
    $fUser = $_GET['user'] ?? ''; $fSource = $_GET['source'] ?? ''; $fStatus = $_GET['status'] ?? '';
    $fFrom = $_GET['from'] ?? ''; $fTo = $_GET['to'] ?? '';
    if ($fUser === 'anon') $where[] = 's.user_id IS NULL';
    elseif ($fUser !== '') { $where[] = 's.user_id = ?'; $args[] = (int)$fUser; }
    if (in_array($fSource, ['web', 'api', 'email'], true)) { $where[] = 's.source = ?'; $args[] = $fSource; }
    if (in_array($fStatus, ['ok', 'error', 'pending', 'rejected'], true)) { $where[] = 's.status = ?'; $args[] = $fStatus; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 's.created_at >= ?'; $args[] = "$fFrom 00:00:00"; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) { $where[] = 's.created_at <= ?'; $args[] = "$fTo 23:59:59"; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($_GET['p'] ?? 1)); $per = 50;
    $cnt = $db->prepare("SELECT COUNT(*) FROM scans s WHERE $w"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $q = $db->prepare("SELECT s.*, u.email FROM scans s LEFT JOIN users u ON u.id = s.user_id WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $q->execute($args);
    $rows = $q->fetchAll();
    $users = $db->query('SELECT id, email FROM users ORDER BY email')->fetchAll();
    $qs = fn(array $over) => '?' . http_build_query(array_merge(['tab' => 'history', 'user' => $fUser, 'source' => $fSource, 'status' => $fStatus, 'from' => $fFrom, 'to' => $fTo], $over));
    ?>
    <form method="get" class="row" aria-label="Filtry historii">
      <input type="hidden" name="tab" value="history">
      <label class="f" for="h-user">Użytkownik
        <select id="h-user" name="user">
          <option value="">wszyscy</option>
          <option value="anon"<?= $fUser === 'anon' ? ' selected' : '' ?>>bez konta</option>
          <?php foreach ($users as $x): ?><option value="<?= $x['id'] ?>"<?= (string)$x['id'] === $fUser ? ' selected' : '' ?>><?= e($x['email']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="f" for="h-source">Skąd
        <select id="h-source" name="source"><option value="">wszystko</option><?php foreach (['web' => 'strona', 'api' => 'API', 'email' => 'e-mail'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $fSource ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      </label>
      <label class="f" for="h-status">Wynik
        <select id="h-status" name="status"><option value="">wszystkie</option><?php foreach (['ok' => 'odczytano', 'error' => 'błąd', 'pending' => 'w toku', 'rejected' => 'wyłączone'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $fStatus ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      </label>
      <label class="f" for="h-from">Od<input type="date" id="h-from" name="from" value="<?= e($fFrom) ?>"></label>
      <label class="f" for="h-to">Do<input type="date" id="h-to" name="to" value="<?= e($fTo) ?>"></label>
      <button type="submit">Pokaż</button>
    </form>
    <p role="status">Znaleziono <?= $total ?> odczytów<?= $total > $per ? ', strona ' . $page . ' z ' . ceil($total / $per) : '' ?>.</p>
    <div class="scroll">
      <table>
        <caption class="sr">Historia odczytów, od najnowszego</caption>
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Skąd</th><th scope="col">Plik</th><th scope="col">Wynik</th><th scope="col" class="num">Tokeny</th><th scope="col" class="num">Koszt</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="num"><?= e(substr($r['created_at'], 0, 16)) ?></td>
            <td><?= e($r['email'] ?? 'bez konta') ?></td>
            <td><?= e(source_label($r['source'])) ?></td>
            <td><?php if ($r['file_path']): ?><a href="plik.php?id=<?= $r['id'] ?>" target="_blank" rel="noopener" aria-label="Otwórz plik <?= e($r['file_name'] ?: 'odczytu ' . $r['id']) ?> w nowej karcie"><?= e($r['file_name'] ?: 'plik') ?></a><?php else: ?><?= e($r['file_name'] ?: '—') ?> <span class="hint">(usunięty)</span><?php endif; ?></td>
            <td><?= status_tag($r['status']) ?>
              <?php if ($r['latex']): ?><div><code translate="no"><?= e($r['latex']) ?></code></div><?php endif; ?>
              <?php if ($r['error']): ?><div class="hint"><?= e($r['error']) ?></div><?php endif; ?>
            </td>
            <td class="num"><?= number_format((int)$r['input_tokens'] + (int)$r['output_tokens'], 0, ',', ' ') ?></td>
            <td class="num"><?= usd((float)$r['cost_usd']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <?php if ($page > 1): ?><a href="<?= e($qs(['p' => $page - 1])) ?>">← Nowsze</a><?php endif; ?>
      <?php if ($page * $per < $total): ?><a href="<?= e($qs(['p' => $page + 1])) ?>">Starsze →</a><?php endif; ?>
    </div>
    <?php
}

// ---------- Zużycie ----------
if ($tab === 'usage') {
    $since = date('Y-m-d', time() - 29 * 86400) . ' 00:00:00';
    $month = date('Y-m') . '-01 00:00:00';
    $sum = fn(string $from) => $db->query("SELECT COUNT(*) n, COALESCE(SUM(input_tokens),0) i, COALESCE(SUM(output_tokens),0) o, COALESCE(SUM(cost_usd),0) c FROM scans WHERE created_at >= '$from'")->fetch();
    $t = $sum(today() . ' 00:00:00'); $m = $sum($month); $d30 = $sum($since);
    $days = $db->query("SELECT substr(created_at,1,10) d, COUNT(*) n, SUM(status='ok') ok, SUM(input_tokens) i, SUM(output_tokens) o, SUM(cost_usd) c FROM scans WHERE created_at >= '$since' GROUP BY d ORDER BY d DESC")->fetchAll();
    $per = $db->query("SELECT COALESCE(u.email,'bez konta') who, COUNT(*) n, SUM(s.input_tokens) i, SUM(s.output_tokens) o, SUM(s.cost_usd) c FROM scans s LEFT JOIN users u ON u.id = s.user_id WHERE s.created_at >= '$month' GROUP BY s.user_id ORDER BY c DESC")->fetchAll();
    $src = $db->query("SELECT source, COUNT(*) n, SUM(cost_usd) c FROM scans WHERE created_at >= '$month' GROUP BY source")->fetchAll();
    ?>
    <div class="stats" aria-label="Podsumowanie kosztów">
      <div><span>Dzisiaj</span><b><?= usd((float)$t['c']) ?></b><span class="hint"><?= (int)$t['n'] ?> odczytów</span></div>
      <div><span>Ten miesiąc</span><b><?= usd((float)$m['c']) ?></b><span class="hint"><?= (int)$m['n'] ?> odczytów</span></div>
      <div><span>Ostatnie 30 dni</span><b><?= usd((float)$d30['c']) ?></b><span class="hint"><?= number_format((int)$d30['i'] + (int)$d30['o'], 0, ',', ' ') ?> tokenów</span></div>
    </div>
    <p class="hint">Koszt jest szacowany z liczby tokenów według cennika Claude Opus 5.5 ($<?= LX_PRICE_IN ?> za milion tokenów wejścia, $<?= LX_PRICE_OUT ?> za milion wyjścia). Dokładne rozliczenie jest w konsoli Anthropic.</p>
    <section class="block" aria-labelledby="ud-h">
      <h3 id="ud-h">Dzień po dniu (30 dni)</h3>
      <div class="scroll"><table>
        <caption class="sr">Zużycie Claude API w kolejnych dniach</caption>
        <thead><tr><th scope="col">Dzień</th><th scope="col" class="num">Odczyty</th><th scope="col" class="num">Udane</th><th scope="col" class="num">Tokeny wejścia</th><th scope="col" class="num">Tokeny wyjścia</th><th scope="col" class="num">Koszt</th></tr></thead>
        <tbody><?php foreach ($days as $r): ?><tr><th scope="row" class="num" style="font-weight:400"><?= e($r['d']) ?></th><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= (int)$r['ok'] ?></td><td class="num"><?= number_format((int)$r['i'], 0, ',', ' ') ?></td><td class="num"><?= number_format((int)$r['o'], 0, ',', ' ') ?></td><td class="num"><?= usd((float)$r['c']) ?></td></tr><?php endforeach; ?>
        <?php if (!$days): ?><tr><td colspan="6">Brak odczytów w ostatnich 30 dniach.</td></tr><?php endif; ?></tbody>
      </table></div>
    </section>
    <section class="block" aria-labelledby="uu-h">
      <h3 id="uu-h">Według użytkowników (ten miesiąc)</h3>
      <div class="scroll"><table>
        <caption class="sr">Zużycie w tym miesiącu według użytkowników</caption>
        <thead><tr><th scope="col">Kto</th><th scope="col" class="num">Odczyty</th><th scope="col" class="num">Tokeny</th><th scope="col" class="num">Koszt</th></tr></thead>
        <tbody><?php foreach ($per as $r): ?><tr><th scope="row" style="font-weight:400"><?= e($r['who']) ?></th><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= number_format((int)$r['i'] + (int)$r['o'], 0, ',', ' ') ?></td><td class="num"><?= usd((float)$r['c']) ?></td></tr><?php endforeach; ?>
        <?php if (!$per): ?><tr><td colspan="4">Brak odczytów w tym miesiącu.</td></tr><?php endif; ?></tbody>
      </table></div>
      <h3>Według źródła (ten miesiąc)</h3>
      <ul><?php foreach ($src as $r): ?><li><?= e(source_label($r['source'])) ?>: <?= (int)$r['n'] ?> odczytów, <?= usd((float)$r['c']) ?></li><?php endforeach; ?><?php if (!$src): ?><li>brak</li><?php endif; ?></ul>
    </section>
    <?php
}

// ---------- Ustawienia ----------
if ($tab === 'settings') {
    $hasImapPass = imap_password() !== '';
    $panelKey = get_secret('anthropic_api_key');
    $hasKey = anthropic_key() !== '';
    $sel = fn(string $k, string $v) => setting($k) === $v ? ' selected' : '';
    ?>
    <section aria-labelledby="st-h">
      <h3 id="st-h">Stan</h3>
      <ul>
        <li>Klucz Claude API: <?= $hasKey ? '<span class="tag ok">ustawiony</span>' : '<span class="tag err">brak</span> — wklej go poniżej' ?></li>
        <li>Hasło skrzynki: <?= $hasImapPass ? '<span class="tag ok">zapisane</span>' : '<span class="tag err">brak</span> — wpisz je poniżej' ?></li>
        <li>Dostęp: <?= private_mode() ? '<span class="tag ok">tryb prywatny</span> — tylko zalogowani' : '<span class="tag">publiczny</span>' ?></li>
        <li>Odczyt z maila: <?= setting('mail_enabled') === '1' ? '<span class="tag ok">włączony</span>' : '<span class="tag">wyłączony</span>' ?>, skrzynka sprawdzana co 5 minut</li>
        <li>Ostatnie sprawdzenie skrzynki: <?= e(setting('mail_last_run') ?: 'jeszcze nie') ?><?= setting('mail_last_result') ? ' — ' . e(setting('mail_last_result')) : '' ?></li>
        <li>Ostatnia wysyłka: <?= e(setting('mail_last_send') ?: 'jeszcze nie') ?></li>
      </ul>
      <div class="row" style="margin-top:.75rem">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="imap-test"><button class="plain" type="submit">Sprawdź odbieranie (IMAP)</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="smtp-test"><button class="plain" type="submit">Wyślij testowy mail do mnie</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="imap-poll"><button class="plain" type="submit">Przetwórz nowe maile teraz</button></form>
      </div>
    </section>
    <section class="block" aria-labelledby="test-h">
      <h3 id="test-h">Test odczytu</h3>
      <p>Wysyła krótki przykładowy PDF (5 wzorów) przez cały mechanizm: limity, Claude API, zapis w historii. Pokazuje wynik, czas, tokeny i koszt.</p>
      <form method="post" style="margin-top:.75rem" id="test-form"><?= csrf_field() ?><input type="hidden" name="action" value="test-scan">
        <button type="submit" id="test-btn"<?= $hasKey ? '' : ' aria-describedby="test-nokey"' ?>>Przetestuj odczyt na przykładowym pliku</button>
      </form>
      <?php if (!$hasKey): ?><p class="hint" id="test-nokey">Najpierw zapisz klucz Claude API poniżej — bez niego test zakończy się błędem.</p><?php endif; ?>
      <p class="hint" id="test-wait" role="status"></p>
      <?= test_result_html(true) ?>
      <script>
      document.getElementById('test-form').addEventListener('submit', () => {
        const b = document.getElementById('test-btn'); b.disabled = true; b.textContent = 'Trwa test…';
        document.getElementById('test-wait').textContent = 'Trwa odczyt przykładowego pliku, to może potrwać do pół minuty.';
      });
      document.getElementById('test-result')?.focus();
      </script>
    </section>
    <section class="block" aria-labelledby="key-h">
      <h3 id="key-h">Klucz Claude API</h3>
      <p>Klucz jest potrzebny do odczytu wzorów z kartek, zdjęć i maili. Utworzysz go w konsoli Anthropic (console.anthropic.com → API Keys). Zapisuje się zaszyfrowany i nie jest już potem pokazywany w całości.</p>
      <p><?php if ($panelKey !== ''): ?>Zapisany klucz: <code translate="no">sk-ant-…<?= e(substr($panelKey, -4)) ?></code><?php elseif ($hasKey): ?>Klucz jest w pliku <code>.env</code> na serwerze.<?php else: ?><strong>Klucz nie jest jeszcze zapisany.</strong><?php endif; ?></p>
      <form method="post" class="stack" autocomplete="off" style="margin-top:.75rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="api-key-save">
        <label class="f" for="anthropic-key"><?= $panelKey !== '' ? 'Wklej nowy klucz, aby zastąpić obecny' : 'Wklej klucz Claude API' ?>
          <input type="password" id="anthropic-key" name="anthropic_key" required autocomplete="off" spellcheck="false" placeholder="sk-ant-…" aria-describedby="anthropic-key-h">
        </label>
        <p class="hint" id="anthropic-key-h">Po zapisaniu panel od razu sprawdzi, czy klucz działa.</p>
        <button type="submit">Zapisz klucz</button>
      </form>
      <?php if ($hasKey): ?>
      <div class="row" style="margin-top:.75rem">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="api-key-test"><button class="plain" type="submit">Sprawdź klucz</button></form>
        <?php if ($panelKey !== ''): ?>
        <form method="post" class="row" style="align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="api-key-delete">
          <label class="c" for="key-del-ok"><input type="checkbox" id="key-del-ok" name="confirm" value="1" required> Tak, usuń klucz (odczyt z kartek przestanie działać)</label>
          <button class="danger" type="submit">Usuń klucz</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>
    <details class="block" id="da-h" style="border-top:1px solid var(--soft);padding-top:1rem">
      <summary style="font-weight:600;min-height:2.75rem;display:flex;align-items:center">Załóż skrzynkę na seohost przez DirectAdmin (opcjonalnie)</summary>
      <p>Załóż skrzynkę dla konwertera bez wchodzenia do DirectAdmin. Hasło skrzynki wygeneruje się samo i zapisze zaszyfrowane w ustawieniach poniżej. Login i hasło DirectAdmin służą tylko do tego jednego wywołania i nie są nigdzie zapisywane.</p>
      <form method="post" class="stack" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="da-mailbox">
        <div class="row">
          <label class="f" for="da-box">Nazwa skrzynki<input type="text" id="da-box" name="box_user" value="latex" required spellcheck="false" style="width:10rem"></label>
          <label class="f" for="da-dom">Domena<input type="text" id="da-dom" name="box_domain" value="ziemowitgil.pl" required spellcheck="false"></label>
          <label class="f" for="da-quota">Pojemność (MB, 0 = bez limitu)<input type="number" id="da-quota" name="box_quota" value="500" min="0" inputmode="numeric" style="width:8rem"></label>
        </div>
        <label class="f" for="da-url">Adres panelu DirectAdmin<input type="url" id="da-url" name="da_url" value="" placeholder="https://serwer.hostingu.pl:2222" required spellcheck="false"></label>
        <label class="f" for="da-user">Login DirectAdmin<input type="text" id="da-user" name="da_user" value="" required spellcheck="false" autocomplete="off"></label>
        <label class="f" for="da-pass">Hasło DirectAdmin (albo klucz logowania)<input type="password" id="da-pass" name="da_pass" required autocomplete="off" aria-describedby="da-pass-h"></label>
        <p class="hint" id="da-pass-h">Jeśli masz w DirectAdmin dwuetapowe logowanie, utwórz tam klucz logowania (Login Keys) z uprawnieniem do kont pocztowych i wpisz go zamiast hasła.</p>
        <button type="submit">Załóż skrzynkę</button>
      </form>
    </details>
    <section class="block" aria-labelledby="sf-h">
      <h3 id="sf-h">Konfiguracja poczty</h3>
      <p class="hint">Te same dane co w programie pocztowym. Login to zwykle pełny adres e-mail.</p>
      <div class="row" style="margin:.5rem 0" role="group" aria-label="Gotowe ustawienia serwerów">
        <button type="button" class="plain preset" data-imap="imap.dpoczta.pl" data-iport="993" data-isec="ssl" data-smtp="smtp.dpoczta.pl" data-sport="587" data-ssec="starttls">Wypełnij dla dpoczta.pl</button>
        <button type="button" class="plain preset" data-imap="mail.ziemowitgil.pl" data-iport="993" data-isec="ssl" data-smtp="mail.ziemowitgil.pl" data-sport="465" data-ssec="ssl">Wypełnij dla seohost</button>
      </div>
      <p class="hint" id="preset-status" role="status"></p>
      <script>
      document.querySelectorAll('.preset').forEach(b => b.addEventListener('click', () => {
        const d = b.dataset, set = (id, v) => { document.getElementById(id).value = v; };
        set('s-host', d.imap); set('s-port', d.iport); set('s-isec', d.isec);
        set('s-shost', d.smtp); set('s-sport', d.sport); set('s-ssec', d.ssec); set('s-mode', 'smtp');
        document.getElementById('preset-status').textContent = 'Wypełniono serwery: ' + b.textContent.replace('Wypełnij dla ', '') + '. Uzupełnij adres i hasło, potem kliknij Zapisz ustawienia.';
      }));
      </script>
      <form method="post" class="stack" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <fieldset style="border:0;padding:0;margin:0 0 1rem;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Dostęp</legend>
          <label class="c" for="s-private"><input type="checkbox" id="s-private" name="private_mode" value="1" aria-describedby="s-private-h"<?= private_mode() ? ' checked' : '' ?>> Tryb prywatny: tylko zalogowani (logowanie linkiem z maila)</label>
          <p class="hint" id="s-private-h">Osoby bez konta widzą tylko stronę logowania. API działa wyłącznie z tokenem, a na maile odpowiadamy tylko adresom z kontem. Skrypt, SKILL.md i llms.txt zostają publiczne.</p>
        </fieldset>
        <fieldset style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Konto</legend>
          <label class="c" for="s-mail"><input type="checkbox" id="s-mail" name="mail_enabled" value="1"<?= setting('mail_enabled') === '1' ? ' checked' : '' ?>> Odczytuj załączniki z maili</label>
          <label class="c" for="s-anyone"><input type="checkbox" id="s-anyone" name="mail_from_anyone" value="1"<?= setting('mail_from_anyone') === '1' ? ' checked' : '' ?>> Przyjmuj maile od każdego nadawcy, nie tylko od użytkowników z kontem</label>
          <label class="f" for="s-mlim">Dzienny limit odczytów z jednego adresu bez konta<input type="number" id="s-mlim" name="mail_anon_limit" min="0" inputmode="numeric" value="<?= e(setting('mail_anon_limit')) ?>" style="width:8rem"></label>
          <label class="f" for="s-addr">Adres e-mail skrzynki<input type="email" id="s-addr" name="mail_address" value="<?= e(setting('mail_address')) ?>"></label>
          <label class="f" for="s-name">Nazwa nadawcy w odpowiedziach<input type="text" id="s-name" name="sender_name" value="<?= e(setting('sender_name')) ?>"></label>
        </fieldset>
        <fieldset style="border:0;padding:0;margin:1rem 0 0;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Odbieranie (IMAP)</legend>
          <label class="f" for="s-host">Serwer<input type="text" id="s-host" name="imap_host" value="<?= e(setting('imap_host')) ?>" spellcheck="false"></label>
          <div class="row">
            <label class="f" for="s-port">Port<input type="number" id="s-port" name="imap_port" inputmode="numeric" value="<?= e(setting('imap_port')) ?>" style="width:7rem"></label>
            <label class="f" for="s-isec">Szyfrowanie
              <select id="s-isec" name="imap_security"><option value="ssl"<?= $sel('imap_security', 'ssl') ?>>SSL/TLS</option><option value="starttls"<?= $sel('imap_security', 'starttls') ?>>STARTTLS</option><option value="none"<?= $sel('imap_security', 'none') ?>>bez szyfrowania</option></select>
            </label>
          </div>
          <label class="f" for="s-iuser">Login<input type="text" id="s-iuser" name="imap_user" value="<?= e(setting('imap_user')) ?>" placeholder="<?= e(setting('mail_address')) ?>" aria-describedby="s-iuser-h" spellcheck="false" autocomplete="off"></label>
          <p class="hint" id="s-iuser-h">Puste pole oznacza adres skrzynki.</p>
          <label class="f" for="s-ipass">Hasło<input type="password" id="s-ipass" name="imap_password" autocomplete="new-password" aria-describedby="s-ipass-h"></label>
          <p class="hint" id="s-ipass-h"><?= $hasImapPass ? 'Hasło jest zapisane (zaszyfrowane). Zostaw puste, żeby go nie zmieniać.' : 'Hasło nie jest jeszcze zapisane.' ?></p>
          <label class="f" for="s-folder">Folder<input type="text" id="s-folder" name="imap_folder" value="<?= e(setting('imap_folder')) ?>" spellcheck="false"></label>
        </fieldset>
        <fieldset style="border:0;padding:0;margin:1rem 0 0;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Wysyłanie (SMTP)</legend>
          <label class="f" for="s-mode">Sposób wysyłki
            <select id="s-mode" name="smtp_mode"><option value="smtp"<?= $sel('smtp_mode', 'smtp') ?>>serwer SMTP (zalecane)</option><option value="mail"<?= $sel('smtp_mode', 'mail') ?>>funkcja mail() serwera WWW</option></select>
          </label>
          <label class="f" for="s-shost">Serwer<input type="text" id="s-shost" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" spellcheck="false"></label>
          <div class="row">
            <label class="f" for="s-sport">Port<input type="number" id="s-sport" name="smtp_port" inputmode="numeric" value="<?= e(setting('smtp_port')) ?>" style="width:7rem"></label>
            <label class="f" for="s-ssec">Szyfrowanie
              <select id="s-ssec" name="smtp_security"><option value="ssl"<?= $sel('smtp_security', 'ssl') ?>>SSL/TLS</option><option value="starttls"<?= $sel('smtp_security', 'starttls') ?>>STARTTLS</option><option value="none"<?= $sel('smtp_security', 'none') ?>>bez szyfrowania</option></select>
            </label>
          </div>
          <label class="f" for="s-suser">Login<input type="text" id="s-suser" name="smtp_user" value="<?= e(setting('smtp_user')) ?>" placeholder="taki sam jak do odbierania" spellcheck="false" autocomplete="off"></label>
          <label class="c" for="s-same"><input type="checkbox" id="s-same" name="smtp_same_pass" value="1"<?= setting('smtp_same_pass') === '1' ? ' checked' : '' ?>> Hasło takie samo jak do odbierania</label>
          <label class="f" for="s-spass">Hasło SMTP (gdy inne)<input type="password" id="s-spass" name="smtp_password" autocomplete="new-password" aria-describedby="s-spass-h"></label>
          <p class="hint" id="s-spass-h"><?= get_secret('smtp_password') !== '' ? 'Osobne hasło SMTP jest zapisane. Zostaw puste, żeby go nie zmieniać.' : 'Osobne hasło SMTP nie jest zapisane.' ?></p>
        </fieldset>
        <fieldset style="border:0;padding:0;margin:1rem 0 0;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Odpowiedzi</legend>
          <label class="f" for="s-subj">Temat odpowiedzi<input type="text" id="s-subj" name="reply_subject" value="<?= e(setting('reply_subject')) ?>" aria-describedby="s-subj-h"></label>
          <p class="hint" id="s-subj-h">{plik} zostanie zastąpione nazwą załącznika.</p>
          <label class="f" for="s-intro">Początek odpowiedzi<textarea id="s-intro" name="reply_intro" rows="4" aria-describedby="s-intro-h"><?= e(setting('reply_intro')) ?></textarea></label>
          <p class="hint" id="s-intro-h">{imie} zostanie zastąpione imieniem użytkownika. Pod spodem system dopisze wyniki odczytu.</p>
        </fieldset>
        <fieldset style="border:0;padding:0;margin:1rem 0 0;display:flex;flex-direction:column;gap:.75rem">
          <legend style="font-weight:600">Limity i przechowywanie</legend>
          <label class="f" for="s-ulim">Domyślny dzienny limit na użytkownika<input type="number" id="s-ulim" name="user_daily_limit" min="0" inputmode="numeric" value="<?= e(setting('user_daily_limit')) ?>"></label>
          <label class="c" for="s-anon"><input type="checkbox" id="s-anon" name="anon_enabled" value="1"<?= setting('anon_enabled') === '1' ? ' checked' : '' ?>> Pozwól odczytywać kartki bez konta</label>
          <label class="f" for="s-alim">Dzienny limit bez konta (na adres IP)<input type="number" id="s-alim" name="anon_daily_limit" min="0" inputmode="numeric" value="<?= e(setting('anon_daily_limit')) ?>"></label>
          <label class="f" for="s-glim">Dzienny limit dla całej strony<input type="number" id="s-glim" name="global_daily_limit" min="0" inputmode="numeric" value="<?= e(setting('global_daily_limit')) ?>"></label>
          <p class="hint">Przesłane zdjęcia i PDF-y nie są zapisywane na serwerze — znikają zaraz po odczycie. W historii zostaje tylko wynik.</p>
        </fieldset>
        <button type="submit">Zapisz ustawienia</button>
      </form>
    </section>
    <?php
}
page_end();
