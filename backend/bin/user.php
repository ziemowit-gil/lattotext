<?php
// Konta z wiersza poleceń:
//   php user.php add adres@example.com [admin] [Imię]   — dodaje konto (bez wysyłania maila)
//   php user.php invite adres@example.com               — wysyła link logowania
//   php user.php link adres@example.com                 — wypisuje jednorazowy link logowania
//   php user.php list
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../php/lib.php';
[$cmd, $email, $role, $name] = array_pad(array_slice($argv, 1), 4, '');
switch ($cmd) {
    case 'add':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Podaj poprawny adres e-mail.\n");
        if (find_user_by_email($email)) exit("Konto $email już istnieje.\n");
        db()->prepare('INSERT INTO users(email, name, role, created_at) VALUES(?, ?, ?, ?)')->execute([$email, $name, $role === 'admin' ? 'admin' : 'user', now()]);
        echo "Dodano $email (" . ($role === 'admin' ? 'administrator' : 'użytkownik') . "). Zaloguj się na " . LX_URL . "/konto/\n";
        break;
    case 'invite':
        $u = find_user_by_email($email) ?? exit("Nie ma konta $email.\n");
        echo send_login_link($u, true) ? "Wysłano zaproszenie do $email.\n" : "Za dużo linków w ostatniej godzinie.\n";
        break;
    case 'link':
        // Jednorazowy link logowania wypisany w terminalu (gdy poczta jeszcze nie działa)
        $u = find_user_by_email($email) ?? exit("Nie ma konta $email.\n");
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO login_tokens(token_hash, user_id, expires_at, created_at) VALUES(?, ?, ?, ?)')->execute([hash('sha256', $token), $u['id'], time() + 1200, time()]);
        echo LX_URL . "/konto/?t=$token\n(działa 20 minut, jeden raz)\n";
        break;
    case 'list':
        foreach (db()->query('SELECT email, role, blocked FROM users ORDER BY email') as $u) echo $u['email'], "\t", $u['role'], $u['blocked'] ? "\tzablokowane" : '', "\n";
        break;
    default:
        echo "Użycie: php user.php add|invite|list adres@example.com [admin] [Imię]\n";
}
