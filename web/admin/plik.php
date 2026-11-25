<?php
// Podgląd przesłanego pliku — tylko dla administratorów.
declare(strict_types=1);
require_once dirname(__DIR__) . '/sciezka.php';
$me = current_user();
if (!$me || $me['role'] !== 'admin') { http_response_code(403); exit('Brak dostępu.'); }
$s = db()->prepare('SELECT file_path, media_type FROM scans WHERE id = ?');
$s->execute([(int)($_GET['id'] ?? 0)]);
$r = $s->fetch();
$path = $r && $r['file_path'] ? LX_DIR . '/data/' . $r['file_path'] : '';
if (!$path || !is_file($path) || !in_array($r['media_type'], LX_MEDIA, true)) { http_response_code(404); exit('Plik został już usunięty.'); }
header('Content-Type: ' . $r['media_type']);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Cache-Control: private, no-store');
readfile($path);
