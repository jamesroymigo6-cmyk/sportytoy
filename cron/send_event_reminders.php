<?php
require dirname(__DIR__) . '/config/db.php';
require dirname(__DIR__) . '/config/sms.php';

// Designed for an hourly cron job. CLI is preferred. A secret token allows hosts that only support URL cron.
// Vercel Cron is trusted automatically via its x-vercel-cron request header.
if (PHP_SAPI !== 'cli') {
    $isVercelCron = ($_SERVER['HTTP_X_VERCEL_CRON'] ?? '') === '1';
    $token = (string)($_GET['token'] ?? '');
    $expected = (string)sportsync_env('CRON_TOKEN', '');
    if (!$isVercelCron && ($expected === '' || !hash_equals($expected, $token))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!sportsync_sms_enabled()) {
    fwrite(STDERR, "Sporty Ni Migo SMS is not configured.\n");
    exit(2);
}

// Events starting between 23 and 25 hours from now. Hourly execution gives a stable 24-hour reminder window.
$q = $pdo->query("SELECT e.id,e.title,e.start_at,v.name venue_name,u.id organizer_id,u.full_name organizer_name,u.phone organizer_phone
                  FROM events e
                  LEFT JOIN venues v ON v.id=e.venue_id
                  LEFT JOIN users u ON u.id=e.organizer_id
                  WHERE e.status='scheduled'
                    AND e.start_at BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
                  ORDER BY e.start_at");
$events = $q->fetchAll(PDO::FETCH_ASSOC);
$log = $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)');

$sent=0; $failed=0; $skipped=0;
foreach ($events as $event) {
    $recipients = [];
    if (!empty($event['organizer_id'])) {
        $recipients[(int)$event['organizer_id']] = ['id'=>(int)$event['organizer_id'],'name'=>$event['organizer_name'],'phone'=>$event['organizer_phone']];
    }
    // Approved registrants live directly in event_registrations (consolidated schema).
    $rp = $pdo->prepare('SELECT r.user_id id,r.full_name name,u.phone FROM event_registrations r
                         LEFT JOIN users u ON u.id=r.user_id
                         WHERE r.event_id=? AND r.status="approved" AND u.status="active"');
    $rp->execute([$event['id']]);
    foreach ($rp->fetchAll(PDO::FETCH_ASSOC) as $row) $recipients[(int)$row['id']]=$row;

    $message = 'Sporty Ni Migo reminder: "'.$event['title'].'" starts '.date('M d, Y g:i A', strtotime($event['start_at'])).' at '.($event['venue_name'] ?: 'the assigned venue').'. Please check Sporty Ni Migo for updates.';
    foreach ($recipients as $recipient) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action="sms_event_reminder_24h" AND details LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)');
        $exists->execute([$recipient['id'], 'event #'.$event['id'].'|%']);
        if ($exists->fetchColumn()) { $skipped++; continue; }

        $phone = sportsync_normalize_phone((string)($recipient['phone'] ?? ''));
        if (!$phone) { $failed++; $log->execute([$recipient['id'],'sms_event_reminder_24h','event #'.$event['id'].' phone='.($recipient['phone'] ?? '') .' invalid phone']); continue; }
        $result = sportsync_send_sms($phone,$message);
        $log->execute([$recipient['id'],'sms_event_reminder_24h','event #'.$event['id'].' status='.$result['status'].' to '.$phone]);
        if ($result['ok']) $sent++; else $failed++;
    }
}

echo "Sporty Ni Migo 24h reminders: {$sent} sent, {$failed} failed, {$skipped} already handled.\n";
