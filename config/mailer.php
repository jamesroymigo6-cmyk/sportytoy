<?php
require_once __DIR__ . '/app.php';

function sportsync_send_mail(string $to, string $subject, string $html, string $plain=''): bool {
    $from = sportsync_env('MAIL_FROM', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = sportsync_env('MAIL_FROM_NAME', 'Sporty Ni Migo');
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'X-Mailer: Sporty Ni Migo';
    $ok = @mail($to, $subject, $html, implode("\r\n", $headers));
    if (!$ok) sportsync_log('Mail delivery failed for recipient: ' . $to . ' subject: ' . $subject);
    return $ok;
}
