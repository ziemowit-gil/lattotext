<?php
// Moje konto: logowanie jednorazowym linkiem, historia odczytów, token API.
declare(strict_types=1);
require_once dirname(__DIR__) . '/sciezka.php';
require_once LX_DIR . '/php/layout.php';

lx_session();

// Wejście z linku w mailu
if (isset($_GET['t'])) {
    $u = consume_login_token((string)$_GET['t']);
    if ($u) {
        $next = $_SESSION['next'] ?? '';
        unset($_SESSION['next']);
        login_as($u);
        flash('Zalogowano. Witaj' . ($u['name'] !== '' ? ', ' . $u['name'] : '') . '!');
        // Wracamy tam, gdzie ktoś chciał wejść przed zalogowaniem (tylko w obrębie /latex/)
        redirect(is_string($next) && preg_match('#^/latex/[^\s]*$#', $next) && !str_contains($next, '//') ? $next : './');
    }
    flash('Ten link logowania wygasł albo został już użyty. Poproś o nowy poniżej.', 'err');
    redirect('./');
}

$u = current_user();
$newToken = null;
$confirmToken = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Formularz wygasł. Spróbuj jeszcze raz.', 'err'); redirect('./'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Wpisz poprawny adres e-mail, na przykład jan@example.com.', 'err');
            redirect('./?email=' . rawurlencode($email));
        }
        $found = find_user_by_email($email);
        if ($found && !$found['blocked']) send_login_link($found);
        // Ten sam komunikat niezależnie od tego, czy konto istnieje
        flash('Jeśli ten adres ma konto, za chwilę dostaniesz link do logowania. Link działa 20 minut. Sprawdź też folder spam.');
        redirect('./');
    }
    if ($action === 'logout') { logout(); flash('Wylogowano.'); redirect('./'); }
    if (!$u) redirect('./');
    if ($action === 'token') {
        if ($u['api_token_hash'] && ($_POST['confirm'] ?? '') !== '1') {
            $confirmToken = true;
        } else {
            $newToken = new_api_token($u);
            $u = find_user((int)$u['id']);
        }
    }
    if ($action === 'test') {
        test_scan($u);
        redirect('./#h-test');
    }
    if ($action === 'token-delete') {
        db()->prepare('UPDATE users SET api_token_hash = NULL WHERE id = ?')->execute([$u['id']]);
        flash('Token API został usunięty. Zapytania z tym tokenem przestały działać.');
        redirect('./');
    }
    if ($action === 'name') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $u['id']]);
        flash('Zapisano imię.');
        redirect('./');
    }
}

if (!$u) {
    page_start('Logowanie', null, 'konto');
    ?>
    <h1><?= private_mode() ? 'Zaloguj się do LatToText' : 'Moje konto' ?></h1>
    <?php if (private_mode()): ?>
    <div class="msg" role="note" style="border-color:var(--accent);color:var(--ink);font-weight:400">
      <p><strong>Dostęp tylko przez link logowania.</strong> LatToText jest dostępny wyłącznie dla osób z kontem. Nie ma haseł — za każdym razem logujesz się jednorazowym linkiem, który wysyłamy na Twój adres e-mail.</p>
      <p>Nie masz konta? Konta zakłada administrator.</p>
    </div>
    <?php endif; ?>
    <p>Wpisz swój adres e-mail, a wyślemy Ci jednorazowy link do logowania (działa 20 minut, jeden raz).</p>
    <form method="post" class="stack" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label class="f" for="email">Adres e-mail
        <input type="email" id="email" name="email" autocomplete="email" required value="<?= e($_GET['email'] ?? '') ?>">
      </label>
      <button type="submit">Wyślij link do logowania</button>
    </form>
    <?php if (!private_mode()): ?><p class="hint">Bez konta możesz korzystać z konwertera i odczytywać kartki z dziennym limitem.</p><?php endif; ?>
    <?php
    page_end();
    exit;
}

$limit = user_limit($u);
$used = count_scans('user_id = ?', [$u['id']]);
$agg = function (string $from) use ($u): array {
    $q = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(input_tokens + output_tokens), 0) t, COALESCE(SUM(cost_usd), 0) c FROM scans WHERE user_id = ? AND created_at >= ?");
    $q->execute([$u['id'], $from]);
    return $q->fetch();
};
$useToday = $agg(today() . ' 00:00:00');
$useMonth = $agg(date('Y-m') . '-01 00:00:00');
$useAll = $agg('0000-00-00');
$q = db()->prepare("SELECT substr(created_at, 1, 10) d, COUNT(*) n, SUM(status = 'ok') ok, SUM(input_tokens + output_tokens) t, SUM(cost_usd) c FROM scans WHERE user_id = ? AND created_at >= ? GROUP BY d ORDER BY d DESC");
$q->execute([$u['id'], date('Y-m-d', time() - 29 * 86400) . ' 00:00:00']);
$days = $q->fetchAll();
$s = db()->prepare('SELECT * FROM scans WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$s->execute([$u['id']]);
$scans = $s->fetchAll();

page_start('Moje konto', $u, 'konto');
?>
<h1>Moje konto</h1>

<section class="block" aria-labelledby="h-today">
  <h2 id="h-today">Zużycie</h2>
  <div class="stats">
    <div><span>Odczyty dzisiaj</span><b><?= $used ?> z <?= $limit ?></b><span class="hint">zostało <?= max(0, $limit - $used) ?></span></div>
    <div><span>Ten miesiąc</span><b><?= (int)$useMonth['n'] ?></b><span class="hint"><?= number_format((int)$useMonth['t'], 0, ',', ' ') ?> tokenów · <?= usd((float)$useMonth['c']) ?></span></div>
    <div><span>Od początku</span><b><?= (int)$useAll['n'] ?></b><span class="hint"><?= number_format((int)$useAll['t'], 0, ',', ' ') ?> tokenów · <?= usd((float)$useAll['c']) ?></span></div>
  </div>
  <p class="hint" style="margin-top:.75rem">Koszt to szacunek zużycia Claude API przez Twoje odczyty z kartek (cennik Claude Opus 5.5). Zamiana wzorów i obrazki nie zużywają Claude API.</p>
  <?php if ($days): ?>
  <details style="margin-top:.75rem">
    <summary>Zużycie dzień po dniu (30 dni)</summary>
    <div class="scroll"><table>
      <caption class="sr">Twoje zużycie w kolejnych dniach</caption>
      <thead><tr><th scope="col">Dzień</th><th scope="col" class="num">Odczyty</th><th scope="col" class="num">Udane</th><th scope="col" class="num">Tokeny</th><th scope="col" class="num">Koszt</th></tr></thead>
      <tbody><?php foreach ($days as $r): ?><tr><th scope="row" class="num" style="font-weight:400"><?= e($r['d']) ?></th><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= (int)$r['ok'] ?></td><td class="num"><?= number_format((int)$r['t'], 0, ',', ' ') ?></td><td class="num"><?= usd((float)$r['c']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </details>
  <?php endif; ?>
</section>

<section class="block" aria-labelledby="h-test">
  <h2 id="h-test">Przetestuj odczyt</h2>
  <p>Sprawdź, jak działa odczyt z kartki, na przykładowym pliku PDF z pięcioma wzorami (<a href="../przyklad-krotki.pdf">zobacz plik</a>). Test zajmuje do pół minuty i liczy się jako jeden odczyt z Twojego dziennego limitu.</p>
  <form method="post" style="margin-top:.75rem" id="test-form"><?= csrf_field() ?><input type="hidden" name="action" value="test">
    <button type="submit" id="test-btn">Przetestuj odczyt na przykładowym pliku</button>
  </form>
  <p class="hint" id="test-wait" role="status"></p>
  <?= test_result_html(false) ?>
</section>

<section class="block" aria-labelledby="h-mail">
  <h2 id="h-mail">Odczyt z maila</h2>
  <p>Wyślij zdjęcie kartki albo PDF na adres <strong><?= e(setting('mail_address')) ?></strong>. Maile z Twojego adresu <strong><?= e($u['email']) ?></strong> liczą się do Twojego limitu i trafiają do Twojej historii. W ciągu kilku minut dostaniesz odpowiedź z LaTeX-em, zwykłym zapisem i odczytem słownym. Jeden mail może mieć do 5 załączników.</p>
  <?php if (setting('mail_enabled') !== '1'): ?>
    <p class="hint">Odczyt z maila jest teraz wyłączony przez administratora.</p>
  <?php endif; ?>
</section>

<section class="block" aria-labelledby="h-api">
  <h2 id="h-api">Token API</h2>
  <?php if ($newToken): ?>
    <div role="status">
      <p class="msg">Nowy token API. Skopiuj go teraz — później nie będzie widoczny.</p>
      <pre translate="no" tabindex="0" aria-label="Token API"><?= e($newToken) ?></pre>
    </div>
    <p style="margin-top:.75rem"><a class="btn" href="klient.php">Otwórz klienta HTTP z tym tokenem</a></p>
    <p style="margin-top:.75rem">Z wiersza poleceń: <a href="../latexconverter.py" download>pobierz latexconverter.py</a>, potem <code>export LATEX_TOKEN=…</code> i <code>python latexconverter.py kartka.jpg</code>.</p>
    <script>try { sessionStorage.setItem('lx_token', <?= json_encode($newToken) ?>); } catch (e) {}</script>
    <p style="margin-top:.75rem">Użycie w zapytaniu:</p>
    <pre translate="no">curl -s <?= e(LX_URL) ?>/api.php -H 'Authorization: Bearer <?= e($newToken) ?>' -F action=scan -F plik=@kartka.jpg</pre>
  <?php elseif ($confirmToken): ?>
    <div class="confirm" role="alert">
      <p><strong>Na pewno utworzyć nowy token?</strong> Obecny token przestanie działać od razu, także w Claude Code i skryptach.</p>
      <div class="row">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="token"><input type="hidden" name="confirm" value="1"><button class="danger" type="submit">Tak, utwórz nowy token</button></form>
        <a class="btn plain" href="./">Anuluj</a>
      </div>
    </div>
  <?php else: ?>
    <p>Token pozwala korzystać z API z Twoim limitem i zapisywać odczyty w Twojej historii, na przykład z Claude Code. Wysyłaj go w nagłówku <code>Authorization: Bearer …</code>. Zapytania wypróbujesz w <a href="klient.php">kliencie HTTP</a> albo skryptem <a href="../latexconverter.py" download>latexconverter.py</a> (<code>python latexconverter.py nazwa_pliku</code>).</p>
    <?php if (!$u['api_enabled']): ?>
      <p class="msg err" role="status">Administrator wyłączył Ci dostęp do API. Konwerter na stronie działa normalnie.</p>
    <?php else: ?>
    <p><?= $u['api_token_hash'] ? 'Masz aktywny token. Wykonane zapytania API: ' . (int)$u['api_calls'] . '.' : 'Nie masz jeszcze tokenu. API działa tylko z tokenem.' ?></p>
    <?php endif; ?>
    <div class="row" style="margin-top:.75rem">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="token"><button type="submit"><?= $u['api_token_hash'] ? 'Utwórz nowy token' : 'Utwórz token' ?></button></form>
      <?php if ($u['api_token_hash']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="token-delete"><button class="plain" type="submit">Usuń token</button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="block" aria-labelledby="h-hist">
  <h2 id="h-hist">Ostatnie odczyty</h2>
  <?php if (!$scans): ?>
    <p>Nie masz jeszcze żadnych odczytów. Wyślij zdjęcie kartki na stronie konwertera albo mailem.</p>
  <?php else: ?>
  <div class="scroll">
    <table>
      <caption class="sr">50 ostatnich odczytów, od najnowszego</caption>
      <thead><tr><th scope="col">Kiedy</th><th scope="col">Skąd</th><th scope="col">Plik</th><th scope="col">Wynik</th></tr></thead>
      <tbody>
      <?php foreach ($scans as $r): ?>
        <tr>
          <td class="num"><?= e(substr($r['created_at'], 0, 16)) ?></td>
          <td><?= e(source_label($r['source'])) ?></td>
          <td><?= e($r['file_name'] ?: '—') ?></td>
          <td>
            <?= status_tag($r['status']) ?>
            <?php if ($r['status'] === 'ok'): ?>
              <div><code translate="no"><?= e($r['latex']) ?></code></div>
              <?php if ($r['speech']): ?><div class="hint">Odczyt: <?= e($r['speech']) ?></div><?php endif; ?>
            <?php elseif ($r['error']): ?>
              <div class="hint"><?= e($r['error']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="block" aria-labelledby="h-name">
  <h2 id="h-name">Dane konta</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="name">
    <label class="f" for="name">Imię (używane w mailach)
      <input type="text" id="name" name="name" autocomplete="given-name" maxlength="80" value="<?= e($u['name']) ?>">
    </label>
    <button type="submit" class="plain">Zapisz imię</button>
  </form>
</section>
<?php
?>
<script>
document.getElementById('test-form')?.addEventListener('submit', () => {
  const b = document.getElementById('test-btn');
  b.disabled = true; b.textContent = 'Trwa test…';
  document.getElementById('test-wait').textContent = 'Trwa odczyt przykładowego pliku, to może potrwać do pół minuty.';
});
document.getElementById('test-result')?.focus();
</script>
<?php
page_end();
