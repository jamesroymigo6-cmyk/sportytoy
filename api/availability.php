<?php
require '../config/db.php';
require '../config/auth.php';
require '../config/schedule.php';
require_api_login();
if (!can_role('plan_event')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Your role manages resources and cannot create personal event reservations.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$start = trim($_GET['start_at'] ?? '');
$end = trim($_GET['end_at'] ?? '');
$people = max(1, (int)($_GET['people'] ?? 1));
if (!$start || !$end) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Choose a valid start and end time.']);
    exit;
}

// Run the shared advanced schedule rules so the planner can tell the user what
// to adjust. We do NOT fail the whole request when a rule is missed: the user
// still needs to see which venues exist for this capacity and time window.
$ruleCheck = sportsync_validate_schedule(null, ['start'=>$start,'end'=>$end]);
$ruleError = $ruleCheck['ok'] ? null : $ruleCheck['error'];

// Capacity + time-conflict filter (the part that genuinely determines suitability).
$q=$pdo->prepare('SELECT v.* FROM venues v WHERE v.status="available" AND v.capacity>=? AND NOT EXISTS (SELECT 1 FROM events e WHERE e.venue_id=v.id AND e.status IN("scheduled","ongoing") AND e.start_at < ? AND e.end_at > ?) ORDER BY v.capacity ASC,v.name');
$q->execute([$people,$end,$start]);
$venues=$q->fetchAll(PDO::FETCH_ASSOC);

// Equipment availability is independent of the venue rules above.
$eq=$pdo->prepare('SELECT e.*, GREATEST(0,e.quantity-COALESCE((SELECT SUM(r.quantity) FROM event_equipment_reservations r WHERE r.equipment_id=e.id AND r.status="reserved" AND r.start_at < ? AND r.end_at > ?),0)) available_quantity FROM equipment e WHERE e.status<>"maintenance" ORDER BY e.category,e.name');
$eq->execute([$end,$start]);
$equipment=$eq->fetchAll(PDO::FETCH_ASSOC);

$response=['ok'=>true,'venues'=>$venues,'equipment'=>$equipment];
if($ruleError){
    $response['rules']=['blocked'=>true,'error'=>$ruleError];
    if(!$venues){
        $response['ok']=false;
        $response['error']='No venue matches this schedule and capacity. '.$ruleError;
    }
}
echo json_encode($response, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
