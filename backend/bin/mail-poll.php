<?php
// Cron: co 5 minut sprawdza skrzynkę i odpowiada na maile z załącznikami.
// */5 * * * * php /sciezka/do/latex-api/bin/mail-poll.php >/dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../php/lib.php';
require __DIR__ . '/../php/mail.php';
echo poll_mailbox(in_array('--force', $argv, true)), "\n";
