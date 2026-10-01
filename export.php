<?php
require 'config/db.php'; require 'config/auth.php'; require_login(); require_role(['Administrator','Event Organizer']);
$type=$_GET['type']??'events';$format=$_GET['format']??'csv';
$queries=[
'events'=>['SELECT e.id,e.title,e.event_type,e.start_at,e.end_at,v.name venue,e.status FROM events e LEFT JOIN venues v ON v.id=e.venue_id',['id','title','event_type','start_at','end_at','venue','status']],
'participants'=>['SELECT r.full_name,r.email,r.phone,r.team,r.category,e.title event,r.status FROM event_registrations r JOIN events e ON e.id=r.event_id',['full_name','email','phone','team','category','event','status']],
'inventory'=>['SELECT name,category,quantity,min_stock,max_stock,unit,location FROM items WHERE kind="supply"',['name','category','quantity','min_stock','max_stock','unit','location']],
'equipment'=>['SELECT name,category,quantity,condition_status,current_location,status FROM equipment',['name','category','quantity','condition_status','current_location','status']],
'sales'=>['SELECT o.id order_id,u.full_name customer,o.total_amount amount,o.payment_method,o.payment_status,o.created_at paid_at FROM orders o JOIN users u ON u.id=o.user_id',['order_id','customer','amount','payment_method','payment_status','paid_at']],
'bookings'=>['SELECT e.title event,v.name venue,e.start_at,e.end_at,e.status FROM events e LEFT JOIN venues v ON v.id=e.venue_id',['event','venue','start_at','end_at','status']]];
if(!isset($queries[$type]))exit('Unknown report');
[$sql,$cols]=$queries[$type];
$rows=$pdo->query($sql)->fetchAll();
if($format==='print'){echo '<html><head><title>Report</title><style>body{font-family:Arial;padding:30px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#eee}</style></head><body><h2>'.htmlspecialchars(ucfirst($type)).' Report</h2><button onclick="print()">Print / Save as PDF</button><table><tr>';foreach($cols as $c)echo '<th>'.htmlspecialchars($c).'</th>';echo '</tr>';foreach($rows as $r){echo '<tr>';foreach($cols as $c)echo '<td>'.htmlspecialchars((string)$r[$c]).'</td>';echo '</tr>';}echo '</table></body></html>';exit;}
if($format==='xls'){header('Content-Type: application/vnd.ms-excel');header('Content-Disposition: attachment; filename='.$type.'_report.xls');echo '<table border="1"><tr>';foreach($cols as $c)echo '<th>'.htmlspecialchars($c).'</th>';echo '</tr>';foreach($rows as $r){echo '<tr>';foreach($cols as $c)echo '<td>'.htmlspecialchars((string)$r[$c]).'</td>';echo '</tr>';}echo '</table>';exit;}
header('Content-Type: text/csv');header('Content-Disposition: attachment; filename='.$type.'_report.csv');$f=fopen('php://output','w');if($cols)fputcsv($f,$cols);foreach($rows as $r)fputcsv($f,$r);fclose($f);
