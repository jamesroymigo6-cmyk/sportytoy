<?php
require '../config/db.php';
require '../config/auth.php';
require_api_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$eventId=(int)($_GET['event_id']??0);
$q=$pdo->prepare('SELECT e.id,e.title,e.start_at,e.is_outdoor,v.name venue_name,v.latitude,v.longitude FROM events e LEFT JOIN venues v ON v.id=e.venue_id WHERE e.id=?');
$q->execute([$eventId]);
$event=$q->fetch(PDO::FETCH_ASSOC);
if(!$event){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Event not found.']);exit;}
echo json_encode(['ok'=>true,'event'=>$event], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
