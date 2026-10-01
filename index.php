<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/sms.php';
require 'config/schedule.php';
require 'config/gcash.php';
require_once 'config/clerk.php';
require_login();
// Validate BEFORE the CSRF check: a profile save refreshes the session user and
// regenerates the session id; validating first keeps the same session alive so
// the token the browser sent is still the token that verifies.
validate_session_user($pdo);
verify_csrf();

$u=current_user();
$page=$_GET['page']??'dashboard';
$ok=$_GET['ok']??'';
$err='';
$isAdmin=has_role(['Administrator']);
$isStaff=has_role(['Staff/Coordinator']);
$isOrganizer=has_role(['Event Organizer']);
$isManager=can_role('manage_events');
$canPlanEvent=can_role('plan_event');
$canBorrowEquipment=can_role('borrow_equipment');
$canOrderMerchandise=can_role('order_merchandise');
$canRegisterEvent=can_role('register_event');

function redirect_page($page,$ok='Saved'){header('Location:index.php?page='.urlencode($page).'&ok='.urlencode($ok));exit;}
function log_action(PDO $pdo,int $uid,string $action,string $details=''){ $pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')->execute([$uid,$action,$details]); }
function save_image_upload($field,$folder){
    if(empty($_FILES[$field]['name'])) return null;
    if($_FILES[$field]['error']!==UPLOAD_ERR_OK) throw new Exception('Image upload failed.');
    if($_FILES[$field]['size']>4*1024*1024) throw new Exception('Image must be 4 MB or smaller.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($exts[$mime])) throw new Exception('Only JPG, PNG, and WebP images are allowed.');
    $publicFolder=trim(str_replace('\\','/',$folder),'/');
    $absoluteFolder=SPORTSYNC_ROOT.'/'.$publicFolder;
    if(!is_dir($absoluteFolder) && !mkdir($absoluteFolder,0775,true) && !is_dir($absoluteFolder)) throw new Exception('Upload folder is unavailable.');
    $fileName=bin2hex(random_bytes(16)).'.'.$exts[$mime];
    $absolutePath=$absoluteFolder.'/'.$fileName;
    if(!move_uploaded_file($_FILES[$field]['tmp_name'],$absolutePath)) throw new Exception('Unable to save image.');
    @chmod($absolutePath,0644);
    return $publicFolder.'/'.$fileName;
}
function event_owned_or_manager(PDO $pdo,int $eventId,int $userId,bool $isManager){if($isManager)return true;$q=$pdo->prepare('SELECT COUNT(*) FROM events WHERE id=? AND organizer_id=?');$q->execute([$eventId,$userId]);return (bool)$q->fetchColumn();}
function status_badge($status){$s=strtolower((string)$status);$map=['scheduled'=>'success','ongoing'=>'primary','completed'=>'secondary','cancelled'=>'danger','draft'=>'warning','available'=>'success','maintenance'=>'warning','active'=>'success','pending'=>'warning','approved'=>'success','rejected'=>'danger','confirmed'=>'primary','ready'=>'info','verifying'=>'warning'];$c=$map[$s]??'info';return '<span class="status-pill status-'.$c.'">'.e(ucwords(str_replace('_',' ',$status))).'</span>';}
function product_visual(array $p){$path=trim((string)($p['image_path']??''));if($path!=='' && is_file(SPORTSYNC_ROOT.'/'.ltrim($path,'/'))){return '<img class="product-image" src="'.e($path).'" alt="'.e($p['name']??'Product').'" loading="lazy">';}$initial=strtoupper(substr((string)($p['name']??'S'),0,1));return '<div class="product-placeholder product-fallback"><span>'.$initial.'</span><i class="fa-solid fa-dumbbell"></i></div>'; }
function best_effort_notification(PDO $pdo,int $userId,string $title,string $message): void {
    try{$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,subject,message) VALUES(NULL,?,"text",?,?)')->execute([$userId,$title,$message]);}catch(Throwable $e){sportsync_log($e);}
}
/* Session-based cart: $_SESSION['cart'] = [item_id => quantity]. Survives database
   re-imports and never leaves orphaned rows (replaces the legacy carts tables). */
function session_cart(): array { $c=$_SESSION['cart']??[]; return is_array($c)?$c:[]; }
function cart_summary(PDO $pdo,int $userId): array {
    $cart=session_cart();if(!$cart)return ['items'=>[],'count'=>0,'total'=>0.0];
    $ids=array_values(array_filter(array_map('intval',array_keys($cart))));if(!$ids)return ['items'=>[],'count'=>0,'total'=>0.0];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare('SELECT id,name,description,price,quantity,image_path FROM items WHERE kind="product" AND status="active" AND id IN ('.$ph.')');$q->execute($ids);$rows=[];foreach($q->fetchAll() as $r)$rows[(int)$r['id']]=$r;
    $items=[];$count=0;$total=0.0;
    foreach($cart as $id=>$qty){$id=(int)$id;if(!isset($rows[$id])){unset($_SESSION['cart'][$id]);continue;}$qty=min(max(1,(int)$qty),max(0,(int)$rows[$id]['quantity']));if($qty<=0)continue;
        $rows[$id]['cart_quantity']=$qty;$items[]=$rows[$id];$count+=$qty;$total+=(float)$rows[$id]['price']*$qty;}
    return ['items'=>$items,'count'=>$count,'total'=>$total];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=$_POST['action']??'';

  if($action==='create_event_plan'){
    if(!$canPlanEvent) throw new Exception('Your role cannot create personal event plans. Administrators and staff manage event operations instead.');
    $title=trim($_POST['title']??'');$type=trim($_POST['event_type']??'');$start=$_POST['start_at']??'';$end=$_POST['end_at']??'';$venue=(int)($_POST['venue_id']??0);$people=max(1,(int)($_POST['expected_people']??1));$description=trim($_POST['description']??'');$outdoor=isset($_POST['is_outdoor'])?1:0;
    // Automatic venue matching: when the planner submits without an explicit
    // venue (the "Match venue for me" flow), the system picks the smallest
    // conflict-free venue that fits the group and schedule — the full rule set
    // including the turnaround buffer runs on every candidate, so the matched
    // venue can never collide with an existing booking.
    $autoMatched=null;
    if(!$venue){
        $match=sportsync_match_venue($pdo,['start'=>$start,'end'=>$end,'people'=>$people]);
        if(!$match['ok'])throw new Exception($match['error']);
        $venue=(int)$match['venue']['id'];$autoMatched=(string)$match['venue']['name'];
    }
    if($title===''||!$start||!$end||strtotime($end)<=strtotime($start)||!$venue)throw new Exception('Complete the event details, date, and time. The venue is matched automatically if you leave it to us.');
    $people=max(1,(int)($_POST['expected_people']??1));
    // Advanced schedule prevention: hours, duration, advance window, venue
    // status/capacity, buffer between bookings, organizer double-booking, day cap.
    $check=sportsync_validate_schedule($pdo,['start'=>$start,'end'=>$end,'venue_id'=>$venue,'people'=>$people,'organizer_id'=>$u['id'],'check_day_cap'=>true]);
    if(!$check['ok'])throw new Exception($check['error']);
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT * FROM venues WHERE id=? AND status="available" AND capacity>=? FOR UPDATE');$q->execute([$venue,$people]);$v=$q->fetch();if(!$v)throw new Exception('Selected venue is unavailable or too small.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM events WHERE venue_id=? AND status IN("scheduled","ongoing") AND start_at < ? AND end_at > ?');$q->execute([$venue,$end,$start]);if($q->fetchColumn())throw new Exception('That venue was just reserved by another event. Please choose another venue.');
    $selectedEquipment=[];
    foreach($_POST['equipment_qty']??[] as $equipmentId=>$qtyRaw){
      $qty=max(0,(int)$qtyRaw);if(!$qty)continue;$equipmentId=(int)$equipmentId;
      $eq=$pdo->prepare('SELECT * FROM equipment WHERE id=? AND status<>"maintenance" FOR UPDATE');$eq->execute([$equipmentId]);$eqRow=$eq->fetch();
      if(!$eqRow)throw new Exception('One selected equipment item is no longer available.');
      $used=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM event_equipment_reservations WHERE equipment_id=? AND status="reserved" AND start_at < ? AND end_at > ?');$used->execute([$equipmentId,$end,$start]);
      $available=(int)$eqRow['quantity']-(int)$used->fetchColumn();
      if($available<$qty)throw new Exception($eqRow['name'].' no longer has enough available quantity for this schedule.');
      $selectedEquipment[]=[(int)$equipmentId,$qty];
    }
    $pdo->prepare('INSERT INTO events(title,description,event_type,start_at,end_at,venue_id,organizer_id,status,is_outdoor) VALUES(?,?,?,?,?,?,?,"scheduled",?)')->execute([$title,$description,$type,$start,$end,$venue,$u['id'],$outdoor]);
    $eventId=(int)$pdo->lastInsertId();
    $reserve=$pdo->prepare('INSERT INTO event_equipment_reservations(event_id,equipment_id,user_id,quantity,start_at,end_at,status) VALUES(?,?,?,?,?,? ,"reserved")');
    foreach($selectedEquipment as [$equipmentId,$qty])$reserve->execute([$eventId,$equipmentId,$u['id'],$qty,$start,$end]);
    best_effort_notification($pdo,$u['id'],'Event scheduled','Your event "'.$title.'" is reserved at '.$v['name'].'.');
    $pdo->commit();log_action($pdo,$u['id'],'event_plan_created','Event #'.$eventId.' with venue/equipment reservations'.($autoMatched?' (venue auto-matched)':''));redirect_page('plan',($autoMatched?'We matched "'.$autoMatched.'" to your event. ':'').'Event scheduled successfully. Venue and selected equipment are reserved.');
  }

  if($action==='register_event'){
    if(!$canRegisterEvent) throw new Exception('Your role cannot register as a participant.');
    $eventId=(int)($_POST['event_id']??0);$q=$pdo->prepare('SELECT id FROM events WHERE id=? AND status="scheduled" AND start_at>NOW()');$q->execute([$eventId]);if(!$q->fetchColumn())throw new Exception('This event is no longer open for registration.');
    // Schedule prevention for participants: no double-registration across
    // overlapping events, and at most 2 events on the same calendar day.
    $evQ=$pdo->prepare('SELECT title,start_at,end_at FROM events WHERE id=?');$evQ->execute([$eventId]);$ev=$evQ->fetch(PDO::FETCH_ASSOC);
    if($ev){
      $clashQ=$pdo->prepare('SELECT e.title,e.start_at FROM event_registrations r JOIN events e ON e.id=r.event_id WHERE r.user_id=? AND r.status<>"rejected" AND r.event_id<>? AND e.status IN("scheduled","ongoing") AND e.start_at < ? AND e.end_at > ?');
      $clashQ->execute([$u['id'],$eventId,$ev['end_at'],$ev['start_at']]);
      $clash=$clashQ->fetch(PDO::FETCH_ASSOC);
      if($clash)throw new Exception('You are already registered for "'.$clash['title'].'" which overlaps this event ('.date('M d, g:i A',strtotime($clash['start_at'])).'). Finish or withdraw from that event first.');
      $dayQ=$pdo->prepare('SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id=r.event_id WHERE r.user_id=? AND r.status<>"rejected" AND r.event_id<>? AND DATE(e.start_at)=DATE(?)');
      $dayQ->execute([$u['id'],$eventId,$ev['start_at']]);
      if((int)$dayQ->fetchColumn()>=2)throw new Exception('You already have 2 events on '.date('M d, Y',strtotime($ev['start_at'])).'. Spread your registrations across days.');
    }
    $pdo->prepare('INSERT IGNORE INTO event_registrations(user_id,event_id,full_name,email,phone,status) VALUES(?,?,?,?,?,"pending")')->execute([$u['id'],$eventId,$u['name'],$u['email'],$u['phone']??'']);redirect_page('discover','Registration submitted.');
  }

  if($action==='registration_status' && can_role('manage_participants')){$status=in_array($_POST['status']??'', ['pending','approved','rejected'], true)?$_POST['status']:'pending';$pdo->prepare('UPDATE event_registrations SET status=? WHERE id=?')->execute([$status,(int)$_POST['registration_id']]);redirect_page('participants','Registration updated.');}

  if($action==='save_venue' && can_role('manage_venues')){
    $status=in_array($_POST['status']??'', ['available','maintenance','inactive'], true)?$_POST['status']:'available';
    $image=save_image_upload('image','uploads/venues');
    $lat=(trim($_POST['latitude']??'')!==''?$_POST['latitude']:null);
    $lng=(trim($_POST['longitude']??'')!==''?$_POST['longitude']:null);
    if(($lat===null xor $lng===null) && ($lat!==null || $lng!==null)) throw new Exception('Both latitude and longitude are required when you place a map pin.');
    if($lat!==null){ if(!is_numeric($lat)||!is_numeric($lng)) throw new Exception('Map pin coordinates are invalid.'); $lat=(float)$lat; $lng=(float)$lng; if($lat<-90||$lat>90||$lng<-180||$lng>180) throw new Exception('Map pin coordinates are out of range.'); }
    $pdo->prepare('INSERT INTO venues(name,address,latitude,longitude,capacity,facilities,layout_notes,image_path,status) VALUES(?,?,?,?,?,?,?,?,?)')->execute([trim($_POST['name']),trim($_POST['address']),$_POST['latitude']?:null,$_POST['longitude']?:null,max(0,(int)$_POST['capacity']),trim($_POST['facilities']),trim($_POST['layout_notes']),$image,$status]);
    if($image) log_action($pdo,$u['id'],'venue_image_added','Venue #'.$pdo->lastInsertId().' image uploaded');
    redirect_page('venues','Venue added.');
  }
  if($action==='save_equipment' && can_role('manage_equipment')){$status=in_array($_POST['status']??'', ['available','checked_out','maintenance'], true)?$_POST['status']:'available';$pdo->prepare('INSERT INTO equipment(name,category,quantity,condition_status,current_location,status) VALUES(?,?,?,?,?,?)')->execute([trim($_POST['name']),trim($_POST['category']),max(0,(int)$_POST['quantity']),$_POST['condition_status'],trim($_POST['current_location']),$status]);redirect_page('equipment','Equipment added.');}
  if($action==='save_inventory' && can_role('manage_inventory')){$pdo->prepare('INSERT INTO items(kind,name,category,quantity,min_stock,max_stock,unit,location) VALUES("supply",?,?,?,?,?,?,?)')->execute([trim($_POST['item_name']),trim($_POST['category']),max(0,(int)$_POST['quantity']),max(0,(int)$_POST['min_stock']),max(0,(int)$_POST['max_stock']),trim($_POST['unit']),trim($_POST['location'])]);redirect_page('inventory','Inventory item added.');}
  if($action==='stock_tx' && can_role('manage_inventory')){$id=(int)$_POST['inventory_id'];$qty=max(1,(int)$_POST['quantity']);$type=in_array($_POST['transaction_type']??'', ['in','out'], true)?$_POST['transaction_type']:'in';$delta=$type==='out'?-$qty:$qty;$pdo->prepare('UPDATE items SET quantity=GREATEST(0,quantity+?) WHERE id=? AND kind="supply"')->execute([$delta,$id]);log_action($pdo,$u['id'],'stock_'.$type,'Item #'.$id.' qty '.$qty.(($_POST['notes']??'')!==''?' — '.trim($_POST['notes']):''));redirect_page('inventory','Stock updated.');}
  if($action==='save_merch' && can_role('manage_orders')){$image=save_image_upload('image','uploads/merchandise');$pdo->prepare('INSERT INTO items(kind,name,category,description,price,quantity,image_path,status) VALUES("product",?,?,?,?,?,?,"active")')->execute([trim($_POST['name']),trim($_POST['category']?:'Merchandise'),trim($_POST['description']),max(0,(float)$_POST['price']),max(0,(int)$_POST['stock']),$image]);redirect_page('shop','Product added.');}
  if($action==='order_status' && can_role('manage_orders')){$status=$_POST['status']??'';if(!in_array($status,['pending','confirmed','ready','completed','cancelled'],true))throw new Exception('Invalid order status.');$pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,(int)$_POST['order_id']]);redirect_page('shop','Order status updated.');}

  if($action==='gcash_verify_payment' && can_role('manage_orders')){
    $payoutId=(int)($_POST['payout_id']??0);$decision=($_POST['decision']??'')==='fail'?'fail':'paid';
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT gp.*,o.payment_status order_payment_status,o.user_id buyer_id FROM gcash_payouts gp JOIN orders o ON o.id=gp.order_id WHERE gp.id=? FOR UPDATE');$q->execute([$payoutId]);$payout=$q->fetch();
    if(!$payout||$payout['status']!=='verifying')throw new Exception('This GCash payment has already been processed.');
    if($decision==='paid'){
      $pdo->prepare('UPDATE gcash_payouts SET status="paid",verified_by=?,verified_at=NOW() WHERE id=?')->execute([$u['id'],$payoutId]);
      $pdo->prepare('UPDATE orders SET payment_status="paid",payment_verified_at=NOW() WHERE id=?')->execute([$payout['order_id']]);
      best_effort_notification($pdo,(int)$payout['buyer_id'],'GCash payment confirmed','Your GCash payment for order #'.$payout['order_id'].' was confirmed. The shop will prepare your items.');
      $pdo->commit();log_action($pdo,$u['id'],'gcash_payment_confirmed','Order #'.$payout['order_id'].' payout #'.$payoutId);redirect_page('shop','GCash payment confirmed for order #'.$payout['order_id'].'.');
    }
    $pdo->prepare('UPDATE gcash_payouts SET status="failed",verified_by=?,verified_at=NOW() WHERE id=?')->execute([$u['id'],$payoutId]);
    $pdo->prepare('UPDATE orders SET payment_status="failed" WHERE id=?')->execute([$payout['order_id']]);
    best_effort_notification($pdo,(int)$payout['buyer_id'],'GCash payment rejected','The GCash reference for order #'.$payout['order_id'].' could not be verified. Please contact the shop or place the order again.');
    $pdo->commit();log_action($pdo,$u['id'],'gcash_payment_rejected','Order #'.$payout['order_id'].' payout #'.$payoutId);redirect_page('shop','GCash payment marked as failed for order #'.$payout['order_id'].'.');
  }

  if($action==='gcash_poll'){
    if(!sportsync_gcash_paymongo_enabled()) throw new Exception('GCash online payments are not active.');
    $oid=(int)($_POST['order_id']??0);
    $q=$pdo->prepare('SELECT o.*,gp.id payout_id,gp.checkout_session_id session_id,gp.status payout_status FROM orders o LEFT JOIN gcash_payouts gp ON gp.order_id=o.id AND gp.checkout_session_id IS NOT NULL WHERE o.id=? AND o.user_id=? ORDER BY gp.id DESC LIMIT 1');$q->execute([$oid,$u['id']]);$order=$q->fetch();
    if(!$order||$order['payment_method']!=='GCash')throw new Exception('Order not found.');
    if($order['payment_status']==='paid'){header('Content-Type: application/json');echo json_encode(['ok'=>true,'status'=>'paid']);exit;}
    if(!$order['session_id'])throw new Exception('No GCash checkout session is attached to this order.');
    if($order['payout_status']==='paid'){$pdo->prepare('UPDATE orders SET payment_status="paid",payment_verified_at=NOW() WHERE id=?')->execute([$oid]);header('Content-Type: application/json');echo json_encode(['ok'=>true,'status'=>'paid']);exit;}
    try{[$paid,$resumeUrl]=sportsync_gcash_session_status((string)$order['session_id']);}catch(Throwable $gx){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'Could not reach the GCash gateway. It may be busy — try again in a moment.','paid'=>false]);exit;}
    if($paid){$pdo->prepare('UPDATE gcash_payouts SET status="paid",verified_at=NOW() WHERE id=?')->execute([$order['payout_id']]);$pdo->prepare('UPDATE orders SET payment_status="paid",payment_verified_at=NOW() WHERE id=?')->execute([$oid]);header('Content-Type: application/json');echo json_encode(['ok'=>true,'status'=>'paid']);exit;}
    header('Content-Type: application/json');echo json_encode(['ok'=>true,'status'=>'verifying','resume_url'=>$resumeUrl]);exit;
  }
  if($action==='save_tournament' && can_role('manage_events')){$status=in_array($_POST['status']??'', ['upcoming','ongoing','completed'], true)?$_POST['status']:'upcoming';$pdo->prepare('INSERT INTO tournaments(name,event_id,format,status) VALUES(?,?,?,?)')->execute([trim($_POST['name']),(int)($_POST['event_id']??0)?:null,trim($_POST['format']?:'Single Elimination'),$status]);redirect_page('tournament','Tournament created.');}

  // --- Administrator / manager CRUD -------------------------------------------------
  if($action==='update_venue' && can_role('manage_venues')){
    $id=(int)($_POST['id']??0); if(!$id) throw new Exception('Venue not found.');
    $pdo->prepare('SELECT id FROM venues WHERE id=?')->execute([$id]); if(!$pdo->fetchColumn()) throw new Exception('Venue not found.');
    $image=save_image_upload('image','uploads/venues');
    $lat=(trim($_POST['latitude']??'')!==''?$_POST['latitude']:null);
    $lng=(trim($_POST['longitude']??'')!==''?$_POST['longitude']:null);
    if(($lat===null xor $lng===null) && ($lat!==null || $lng!==null)) throw new Exception('Both latitude and longitude are required when you place a map pin.');
    if($lat!==null){ if(!is_numeric($lat)||!is_numeric($lng)) throw new Exception('Map pin coordinates are invalid.'); $lat=(float)$lat; $lng=(float)$lng; if($lat<-90||$lat>90||$lng<-180||$lng>180) throw new Exception('Map pin coordinates are out of range.'); }
    $existingImage=$pdo->query('SELECT image_path FROM venues WHERE id='.$id)->fetchColumn();
    $newImagePath=null;
    if($image!==null){ $newImagePath=$image; }
    elseif(($image===null) && (($_POST['keep_image']??'')!=='')){ $newImagePath=$existingImage; }
    else { $newImagePath=null; }
    $pdo->prepare('UPDATE venues SET name=?,address=?,latitude=?,longitude=?,capacity=?,facilities=?,layout_notes=?,image_path=?,status=? WHERE id=?')->execute([trim($_POST['name']??''),trim($_POST['address']??''),$_POST['latitude']?:null,$_POST['longitude']?:null,max(0,(int)($_POST['capacity']??0)),trim($_POST['facilities']??''),trim($_POST['layout_notes']??''),$newImagePath,$_POST['status']??'available',$id]); redirect_page('venues','Venue updated.');
  }
  if($action==='delete_venue' && can_role('manage_venues')){
    $id=(int)($_POST['id']??0); if(!$id) throw new Exception('Venue not found.');
    // Permanently remove the venue. Past events keep their title and schedule:
    // events.venue_id is ON DELETE SET NULL, so history is preserved, only the
    // venue link is released.
    $pdo->prepare('DELETE FROM venues WHERE id=?')->execute([$id]);
    log_action($pdo,$u['id'],'venue_deleted','Venue #'.$id);
    redirect_page('venues','Venue permanently deleted. Past events keep their records without a venue link.');
  }
  if($action==='update_equipment' && can_role('manage_equipment')){
    $id=(int)($_POST['id']??0);if(!$id)throw new Exception('Equipment not found.');
    $pdo->prepare('UPDATE equipment SET name=?,category=?,quantity=?,condition_status=?,current_location=?,status=? WHERE id=?')->execute([trim($_POST['name']??''),trim($_POST['category']??''),max(0,(int)($_POST['quantity']??0)),$_POST['condition_status']??'good',trim($_POST['current_location']??''),$_POST['status']??'available',$id]);redirect_page('equipment','Equipment updated.');
  }
  if($action==='delete_equipment' && can_role('manage_equipment')){
    $id=(int)($_POST['id']??0);if(!$id)throw new Exception('Equipment not found.');
    // Permanently remove the equipment row. Reservation history that references
    // it is removed with it (event_equipment_reservations is ON DELETE CASCADE).
    $pdo->prepare('DELETE FROM equipment WHERE id=?')->execute([$id]);
    log_action($pdo,$u['id'],'equipment_deleted','Equipment #'.$id);
    redirect_page('equipment','Equipment permanently deleted.');
  }
  if($action==='update_merch' && can_role('manage_orders')){
    $id=(int)($_POST['id']??0); if(!$id) throw new Exception('Product not found.');
    $image=save_image_upload('image','uploads/merchandise');
    $params=[trim($_POST['name']??''),trim($_POST['description']??''),max(0,(float)($_POST['price']??0)),max(0,(int)($_POST['stock']??0)),$_POST['status']??'active'];
    if($image){$pdo->prepare('UPDATE items SET name=?,description=?,price=?,quantity=?,status=?,image_path=? WHERE id=? AND kind="product"')->execute([...$params,$image,$id]);}
    else{$pdo->prepare('UPDATE items SET name=?,description=?,price=?,quantity=?,status=? WHERE id=? AND kind="product"')->execute([...$params,$id]);}
    redirect_page('shop','Product updated.');
  }
  if($action==='delete_merch' && can_role('manage_orders')){
    $id=(int)($_POST['id']??0);if(!$id)throw new Exception('Product not found.');
    // Permanently remove the product. Past orders are unaffected: each order
    // stores its own items_json snapshot, so purchase history stays intact.
    $pdo->prepare('DELETE FROM items WHERE id=? AND kind="product"')->execute([$id]);
    log_action($pdo,$u['id'],'product_deleted','Product #'.$id);
    redirect_page('shop','Product permanently deleted. Past orders keep their item snapshots.');
  }
  if($action==='update_user' && $isAdmin){
    $uid=(int)($_POST['user_id']??0); if(!$uid) throw new Exception('User not found.');
    if(!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address.');
    $pdo->prepare('UPDATE users SET role_id=?,full_name=?,email=?,phone=?,address=?,status=? WHERE id=?')->execute([(int)$_POST['role_id'],trim($_POST['full_name']),strtolower(trim($_POST['email'])),trim($_POST['phone']??''),trim(mb_substr((string)($_POST['address']??''),0,255))?:null,$_POST['status']??'active',$uid]);
    if($uid===$u['id']) refresh_session_user($pdo); redirect_page('users','User updated.');
  }
  if($action==='delete_user' && $isAdmin){
    $uid=(int)($_POST['user_id']??0);if($uid===$u['id'])throw new Exception('You cannot delete your own account.');
    // Permanently remove the account. The database keeps audit data coherent:
    // activity_logs.created_by and messages.sender_id become NULL (SET NULL),
    // while events.organizer_id keeps history (SET NULL). Owned sessions,
    // registrations and orders are removed with the account (CASCADE).
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    log_action($pdo,$u['id'],'user_deleted','User #'.$uid.' permanently deleted');
    redirect_page('users','User permanently deleted.');
  }
  if($action==='update_event' && $isManager){
    $id=(int)($_POST['event_id']??0);if(!$id)throw new Exception('Event not found.');
    $title=trim($_POST['title']??'');$type=trim($_POST['event_type']??'');$desc=trim($_POST['description']??'');$status=$_POST['status']??'scheduled';if($title==='')throw new Exception('Event title is required.');
    $pdo->prepare('UPDATE events SET title=?,event_type=?,description=?,status=? WHERE id=?')->execute([$title,$type,$desc,$status,$id]);redirect_page('events','Event details updated.');
  }
  if($action==='delete_event' && $isAdmin){
    $id=(int)($_POST['event_id']??0);if(!$id)throw new Exception('Event not found.');
    // Permanently remove the event. Registrations and equipment reservations are
    // removed with it (CASCADE); announcements and tournaments keep their history
    // with the event link released (SET NULL).
    $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
    log_action($pdo,$u['id'],'event_deleted','Event #'.$id.' permanently deleted');
    redirect_page('events','Event permanently deleted, including its registrations and reservations.');
  }

  // --- SMS administration ------------------------------------------------------------
  if($action==='sms_bulk_preview' && $isAdmin){
    // Small JSON endpoint: live recipient estimate for the broadcast composer.
    header('Content-Type: application/json');
    try {
      $count=count(sportsync_sms_bulk_recipients($pdo,[
        'audience'           => (string)($_POST['bulk_audience']??'all'),
        'event_id'           => (int)($_POST['bulk_event_id']??0),
        'registration_status'=> (string)($_POST['bulk_reg_status']??''),
        'custom_only'        => isset($_POST['bulk_custom_only']),
        'search'             => (string)($_POST['bulk_search']??''),
      ]));
      echo json_encode(['ok'=>true,'count'=>$count]);
    } catch (Throwable $e) {
      echo json_encode(['ok'=>false,'error'=>'Estimate failed']);
    }
    exit;
  }
  if($action==='save_sms_settings' && $isAdmin){
    $file=SPORTSYNC_ROOT.'/.env';
    $lines=is_file($file)?file($file,FILE_IGNORE_NEW_LINES):['<?php exit; # */', 'APP_ENV=production'];
    // The leading PHP-exit guard keeps a misconfigured server from ever serving
    // the .env as plain text; it is recreated if a hand-edited file lost it.
    if(empty($lines) || strpos($lines[0],'<?php')!==0) array_unshift($lines,'<?php exit; # */');
    $values=[
      'SMS_PROVIDER'   => ($_POST['sms_provider']??'')==='smsgate'?'smsgate':'disabled',
      'SMSGATE_API_URL'=> rtrim(trim($_POST['smsgate_api_url']??''),'/'),
      'SMSGATE_USERNAME'=> trim($_POST['smsgate_username']??''),
      'SMSGATE_PASSWORD'=> trim($_POST['smsgate_password']??''),
      'SMS_SENDER'     => trim($_POST['sms_sender']??'Sporty Ni Migo'),
    ];
    // Leaving the password field blank means "keep the stored password" — never wipe it.
    if($values['SMSGATE_PASSWORD']===''){
      foreach($lines as $line){ if(preg_match('/^SMSGATE_PASSWORD=(.+)$/',$line,$m)){ $values['SMSGATE_PASSWORD']=$m[1]; break; } }
    }
    $updated=[];
    foreach($lines as $line){
      if($line===''||str_starts_with($line,'#')||!str_contains($line,'=')){$updated[]=$line;continue;}
      [$k]=$kv=explode('=',$line,2)+['',''];$k=trim($k);
      if(array_key_exists($k,$values)){
        $v=$values[$k];
        // Keep an existing key placeholder untouched unless a new value arrived.
        if(str_starts_with($v,'CHANGE_THIS')&&preg_match('/^'.preg_quote($k,'/').'=(.+)$/m',implode("\n",$lines),$m)&&str_contains($m[1],'CHANGE_THIS')===false){$v=$m[1];}
        $updated[]=$k.'='.$v;unset($values[$k]);
      }else{$updated[]=$line;}
    }
    foreach($values as $k=>$v){if($v!=='')$updated[]=$k.'='.$v;}
    if(@file_put_contents($file,implode("\n",$updated)."\n",LOCK_EX)===false)throw new Exception('Could not write the .env file. Check folder permissions.');
    log_action($pdo,$u['id'],'sms_settings_updated','Provider='.(($_POST['sms_provider']??'')==='smsgate'?'smsgate':'disabled'));
    // Immediately verify what was just saved so problems surface right here.
    if(sportsync_sms_enabled()){
      $chk=sportsync_smsgate_devices();
      if($chk['ok']){
        $on=0; foreach($chk['devices'] as $d){ if($d['online'])$on++; }
        $total=count($chk['devices']);
        redirect_page('sms', $total===0
          ? 'Settings saved and SMSGate accepted your credentials — but no Android device is registered to this account yet. Install the SMSGate app on a phone and sign in with this account to start dispatching.'
          : 'Settings saved. SMSGate connected: '.$on.' of '.$total.' device'.($total===1?'':'s').' online and ready to send.');
      }
      redirect_page('sms','Settings saved, but the gateway check failed: '.$chk['error']);
    }
    redirect_page('sms','Settings saved, but the service is still inactive. '.(($_POST['sms_provider']??'')==='smsgate'?'Some credentials are missing — see the status card for exactly what.':'Enable the SMSGate provider to activate SMS.'));
  }
  if($action==='sms_connection_check' && $isAdmin){
    $check=sportsync_smsgate_devices();
    if(!$check['ok']){ throw new Exception('SMSGate check failed: '.$check['error']); }
    $online=0; foreach($check['devices'] as $d){ if($d['online'])$online++; }
    $total=count($check['devices']);
    redirect_page('sms', $total===0
      ? 'SMSGate credentials accepted, but no Android devices are registered yet. Install the SMSGate app on a phone and sign in with this account.'
      : 'SMSGate connected. '.$online.' of '.$total.' registered device'.($total===1?'':'s').' online and ready to send.');
  }
  if($action==='sms_test_send' && $isAdmin){
    if(!sportsync_sms_enabled()){
      $missing=[];
      foreach(['SMSGATE_API_URL'=>'API URL','SMSGATE_USERNAME'=>'account username','SMSGATE_PASSWORD'=>'account password','SMS_SENDER'=>'sender label'] as $k=>$lbl){ if(trim((string)sportsync_env($k,''))==='')$missing[]=$lbl; }
      throw new Exception('Cannot send: SMS is not fully configured. Missing: '.e(implode(', ',$missing)).'. Fill the provider form above, press Save settings, then try again.');
    }
    $phone=trim($_POST['test_phone']??'');
    if(!sportsync_normalize_phone($phone))throw new Exception('Enter a valid mobile number, e.g. 09171234567.');
    $result=sportsync_send_sms($phone,'Sporty Ni Migo test message: your SMS configuration is working. Sent '.date('M d, Y g:i A').'.');
    if($result['ok']){log_action($pdo,$u['id'],'sms_test','OK to '.$phone);redirect_page('sms','Test message accepted by SMSGate for '.$phone.'. Status: '.$result['status'].'.');}
    throw new Exception('SMSGate rejected the test message ('.$result['status'].'). Check the API URL, account credentials and sender ID. Details are in storage/logs/app.log.');
  }
  if($action==='sms_bulk_send' && $isAdmin){
    if(!sportsync_sms_enabled()){
      $missing=[];
      foreach(['SMSGATE_API_URL'=>'API URL','SMSGATE_USERNAME'=>'account username','SMSGATE_PASSWORD'=>'account password','SMS_SENDER'=>'sender label'] as $k=>$lbl){ if(trim((string)sportsync_env($k,''))==='')$missing[]=$lbl; }
      throw new Exception('Cannot send: SMS is not fully configured. Missing: '.e(implode(', ',$missing)).'.');
    }
    $message=trim($_POST['bulk_message']??'');
    if($message==='')throw new Exception('Write the message you want to send.');
    if(mb_strlen($message)>400)throw new Exception('Keep the message under 400 characters (SMS-friendly).');
    $recipients=sportsync_sms_bulk_recipients($pdo,[
      'audience'           => (string)($_POST['bulk_audience']??'all'),
      'event_id'           => (int)($_POST['bulk_event_id']??0),
      'registration_status'=> (string)($_POST['bulk_reg_status']??''),
      'custom_only'        => isset($_POST['bulk_custom_only']),
      'search'             => (string)($_POST['bulk_search']??''),
    ]);
    if(!$recipients)throw new Exception('No active user with a mobile number matches these filters. Loosen the filters and try again.');
    if(count($recipients)>200)throw new Exception('This broadcast would text '.count($recipients).' recipients. Narrow the filters (or use the announcement SMS) for very large batches.');
    $confirm=(string)($_POST['bulk_confirm']??'');
    if($confirm!=='SEND')throw new Exception('Type SEND in the confirmation box to dispatch this broadcast to '.count($recipients).' recipient(s).');
    $summary=sportsync_send_bulk_sms($pdo,$recipients,$message);
    log_action($pdo,$u['id'],'sms_bulk','sent='.$summary['sent'].' failed='.$summary['failed'].' skipped='.$summary['skipped'].' filters='.(($_POST['bulk_audience']??'all')).' event='.(int)($_POST['bulk_event_id']??0));
    redirect_page('sms','Broadcast dispatched: '.$summary['sent'].' accepted, '.$summary['failed'].' rejected, '.$summary['skipped'].' skipped (no valid mobile). Check the delivery log below.');
  }
  if($action==='delete_message'){
    $id=(int)($_POST['message_id']??0);$q=$pdo->prepare('SELECT sender_id FROM messages WHERE id=?');$q->execute([$id]);$sender=$q->fetchColumn();if($sender===false)throw new Exception('Message not found.');if($sender!==null&&(int)$sender!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot delete this message.');$pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$id]);redirect_page('communication','Message deleted.');
  }
  if($action==='delete_voice_message'){
    $id=(int)($_POST['voice_id']??0);$q=$pdo->prepare('SELECT sender_id,file_path FROM messages WHERE id=? AND message_type="voice"');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new Exception('Voice message not found.');if((int)$row['sender_id']!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot delete this voice message.');$pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$id]);if(!empty($row['file_path']))@unlink(SPORTSYNC_ROOT.'/'.ltrim($row['file_path'],'/'));redirect_page('communication','Voice message deleted.');
  }
  if($action==='update_post'){
    $id=(int)($_POST['post_id']??0);$content=trim($_POST['content']??'');if($content==='')throw new Exception('Post cannot be empty.');$q=$pdo->prepare('SELECT user_id FROM community_posts WHERE id=?');$q->execute([$id]);$owner=$q->fetchColumn();if(!$owner)throw new Exception('Post not found.');if((int)$owner!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot edit this post.');$pdo->prepare('UPDATE community_posts SET content=? WHERE id=?')->execute([$content,$id]);redirect_page('community','Post updated.');
  }
  if($action==='update_comment'){
    $id=(int)($_POST['comment_id']??0);$content=trim($_POST['content']??'');if($content==='')throw new Exception('Comment cannot be empty.');$q=$pdo->prepare('SELECT user_id FROM post_interactions WHERE id=? AND type="comment"');$q->execute([$id]);$owner=$q->fetchColumn();if(!$owner)throw new Exception('Comment not found.');if((int)$owner!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot edit this comment.');$pdo->prepare('UPDATE post_interactions SET content=? WHERE id=?')->execute([$content,$id]);redirect_page('community','Comment updated.');
  }
  if($action==='delete_post'){
    $id=(int)($_POST['post_id']??0);$q=$pdo->prepare('SELECT user_id,image_path FROM community_posts WHERE id=?');$q->execute([$id]);$post=$q->fetch();if(!$post)throw new Exception('Post not found.');
    if((int)$post['user_id']!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot delete this post.');
    $pdo->prepare('DELETE FROM community_posts WHERE id=?')->execute([$id]);if(!empty($post['image_path'])){@unlink(SPORTSYNC_ROOT.'/'.ltrim($post['image_path'],'/'));}redirect_page('community','Post deleted.');
  }
  if($action==='delete_comment'){
    $id=(int)($_POST['comment_id']??0);$q=$pdo->prepare('SELECT user_id FROM post_interactions WHERE id=? AND type="comment"');$q->execute([$id]);$owner=$q->fetchColumn();if(!$owner)throw new Exception('Comment not found.');if((int)$owner!==(int)$u['id']&&!$isAdmin)throw new Exception('You cannot delete this comment.');$pdo->prepare('DELETE FROM post_interactions WHERE id=?')->execute([$id]);redirect_page('community','Comment deleted.');
  }
  if($action==='update_announcement' && can_role('manage_announcements')){
    $id=(int)($_POST['announcement_id']??0);$pdo->prepare('UPDATE announcements SET title=?,body=?,type=?,audience=?,expires_at=? WHERE id=?')->execute([trim($_POST['title']??''),trim($_POST['body']??''),$_POST['type']??'general',$_POST['audience']??'all',($_POST['expires_at']??'')?:null,$id]);redirect_page('announcements','Announcement updated.');
  }
  if($action==='delete_announcement' && can_role('manage_announcements')){
    $id=(int)($_POST['announcement_id']??0);$pdo->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);redirect_page('announcements','Announcement deleted.');
  }


  if($action==='update_inventory' && can_role('manage_inventory')){
    $id=(int)($_POST['id']??0);if(!$id)throw new Exception('Inventory item not found.');
    $pdo->prepare('UPDATE items SET name=?,category=?,quantity=?,min_stock=?,max_stock=?,unit=?,location=? WHERE id=? AND kind="supply"')->execute([trim($_POST['item_name']??''),trim($_POST['category']??''),max(0,(int)($_POST['quantity']??0)),max(0,(int)($_POST['min_stock']??0)),max(0,(int)($_POST['max_stock']??0)),trim($_POST['unit']??'pcs'),trim($_POST['location']??''),$id]);redirect_page('inventory','Inventory item updated.');
  }
  if($action==='delete_inventory' && can_role('manage_inventory')){
    $id=(int)($_POST['id']??0);if(!$id)throw new Exception('Inventory item not found.');
    $pdo->prepare('DELETE FROM items WHERE id=? AND kind="supply"')->execute([$id]);
    log_action($pdo,$u['id'],'inventory_deleted','Supply item #'.$id);
    redirect_page('inventory','Inventory item permanently deleted.');
  }
  if($action==='save_user' && $isAdmin){if(!filter_var($_POST['email'],FILTER_VALIDATE_EMAIL))throw new Exception('Enter a valid email.');if(!password_is_strong($_POST['password']))throw new Exception('Password must be 10+ characters with upper/lowercase and a number.');$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,phone,address,status,email_verified_at) VALUES(?,?,?,?,?,?,?,NOW())')->execute([(int)$_POST['role_id'],trim($_POST['full_name']),strtolower(trim($_POST['email'])),password_hash($_POST['password'],PASSWORD_DEFAULT),trim($_POST['phone']),trim(mb_substr((string)($_POST['address']??''),0,255))?:null,$_POST['status']]);redirect_page('users','User created.');}
  if($action==='toggle_user_status' && $isAdmin){$uid=(int)$_POST['user_id'];if($uid===$u['id'])throw new Exception('You cannot deactivate your own account.');$pdo->prepare('UPDATE users SET status=IF(status="active","inactive","active") WHERE id=?')->execute([$uid]);redirect_page('users','User status updated.');}

  if($action==='reschedule_event'){
    $eventId=(int)($_POST['event_id']??0);if(!event_owned_or_manager($pdo,$eventId,$u['id'],$isManager))throw new Exception('You cannot reschedule this event.');$start=$_POST['start_at']??'';$end=$_POST['end_at']??'';$venue=(int)($_POST['venue_id']??0);if(!$start||!$end||strtotime($end)<=strtotime($start)||!$venue)throw new Exception('Choose a valid schedule and venue.');
    $orgQ=$pdo->prepare('SELECT organizer_id FROM events WHERE id=?');$orgQ->execute([$eventId]);$evRow=$orgQ->fetch(PDO::FETCH_ASSOC);
    // Advanced schedule prevention on the new slot (excluding this event).
    // Capacity is validated against the new venue's own limit via expected_people
    // posted by the reschedule form when present.
    $check=sportsync_validate_schedule($pdo,['start'=>$start,'end'=>$end,'venue_id'=>$venue,'people'=>max(1,(int)($_POST['expected_people']??1)),'ignore_event'=>$eventId,'organizer_id'=>(int)($evRow['organizer_id']??0)]);
    if(!$check['ok'])throw new Exception($check['error']);
    $pdo->beginTransaction();
    $reserved=$pdo->prepare('SELECT r.equipment_id,r.quantity,e.name,e.quantity total_quantity FROM event_equipment_reservations r JOIN equipment e ON e.id=r.equipment_id WHERE r.event_id=? AND r.status="reserved" FOR UPDATE');$reserved->execute([$eventId]);
    foreach($reserved->fetchAll() as $r){
      $used=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM event_equipment_reservations WHERE equipment_id=? AND event_id<>? AND status="reserved" AND start_at < ? AND end_at > ?');$used->execute([$r['equipment_id'],$eventId,$end,$start]);
      if(((int)$r['total_quantity']-(int)$used->fetchColumn())<(int)$r['quantity'])throw new Exception($r['name'].' is not available in the required quantity at the new time.');
    }
    $pdo->prepare('UPDATE events SET start_at=?,end_at=?,venue_id=? WHERE id=?')->execute([$start,$end,$venue,$eventId]);$pdo->prepare('UPDATE event_equipment_reservations SET start_at=?,end_at=? WHERE event_id=? AND status="reserved"')->execute([$start,$end,$eventId]);$pdo->commit();log_action($pdo,$u['id'],'event_rescheduled','Event #'.$eventId);redirect_page('events','Event rescheduled successfully.');
  }

  if($action==='cancel_event'){$eventId=(int)($_POST['event_id']??0);if(!event_owned_or_manager($pdo,$eventId,$u['id'],$isManager))throw new Exception('You cannot cancel this event.');$pdo->beginTransaction();$pdo->prepare('UPDATE events SET status="cancelled" WHERE id=?')->execute([$eventId]);$pdo->prepare('UPDATE event_equipment_reservations SET status="cancelled" WHERE event_id=?')->execute([$eventId]);$pdo->commit();redirect_page('events','Event cancelled and reservations released.');}

  if($action==='order_merch' || $action==='add_to_cart') {
    if(!$canOrderMerchandise) throw new Exception('Your role manages shop operations and cannot place personal orders.');
    $mid=(int)($_POST['merchandise_id']??0);$qty=max(1,(int)($_POST['quantity']??1));
    $q=$pdo->prepare('SELECT id,name,quantity,status FROM items WHERE id=? AND kind="product"');$q->execute([$mid]);$product=$q->fetch();
    if(!$product || $product['status']!=='active') throw new Exception('This product is no longer available.');
    if((int)$product['quantity']<$qty) throw new Exception('Only '.(int)$product['quantity'].' unit(s) are currently available.');
    $cart=session_cart();$newQty=$qty+(int)($cart[$mid]??0);if($newQty>(int)$product['quantity'])throw new Exception('Your cart quantity exceeds the available stock for '.$product['name'].'.');
    $_SESSION['cart'][$mid]=$newQty;
    redirect_page('shop',$product['name'].' added to cart.');
  }

  if($action==='update_cart_item') {
    if(!$canOrderMerchandise) throw new Exception('Your role cannot use the customer cart.');
    $itemId=(int)($_POST['cart_item_id']??0);$qty=max(1,(int)($_POST['quantity']??1));
    $q=$pdo->prepare('SELECT id,name,quantity FROM items WHERE id=? AND kind="product"');$q->execute([$itemId]);$row=$q->fetch();if(!$row||!isset($_SESSION['cart'][$itemId]))throw new Exception('Cart item not found.');
    if($qty>(int)$row['quantity'])throw new Exception('Only '.(int)$row['quantity'].' unit(s) of '.$row['name'].' are available.');
    $_SESSION['cart'][$itemId]=$qty;redirect_page('shop','Cart updated.');
  }

  if($action==='remove_cart_item') {
    if(!$canOrderMerchandise) throw new Exception('Your role cannot use the customer cart.');
    $itemId=(int)($_POST['cart_item_id']??0);unset($_SESSION['cart'][$itemId]);redirect_page('shop','Item removed from cart.');
  }

  if($action==='checkout_cart') {
    if(!$canOrderMerchandise) throw new Exception('Your role cannot place customer orders.');
    $payment=$_POST['payment_method']??'Cash';if(!in_array($payment,['Cash','GCash','Card'],true))throw new Exception('Choose a valid payment method.');
    $eventId=(int)($_POST['event_id']??0)?:null;$reference=trim($_POST['payment_reference']??'');
    if($payment==='GCash' && !sportsync_gcash_enabled())throw new Exception('GCash payments are not available yet. Please choose another payment method.');
    if($payment==='GCash' && sportsync_gcash_mode()==='manual' && $reference==='')throw new Exception('Enter the GCash reference number from your payment receipt.');
    if($payment==='Card' && $reference==='')throw new Exception('Enter the payment/reference number for Card.');
    $cart=cart_summary($pdo,$u['id']);if(!$cart['items'])throw new Exception('Your cart is empty.');
    $pdo->beginTransaction();$total=0.0;$snapshot=[];$ins=$pdo->prepare('INSERT INTO orders(user_id,event_id,items_json,total_amount,payment_method,payment_status,payment_reference,payment_verified_at) VALUES(?,?,?,?,?,?,?,?)');
    foreach($cart['items'] as $item){$q=$pdo->prepare('SELECT id,name,price,quantity,status FROM items WHERE id=? AND kind="product" FOR UPDATE');$q->execute([(int)$item['id']]);$p=$q->fetch();if(!$p||$p['status']!=='active')throw new Exception($item['name'].' is no longer available.');if((int)$p['quantity']<(int)$item['cart_quantity'])throw new Exception('Not enough stock for '.$p['name'].'. Please update your cart.');$total+=(float)$p['price']*(int)$item['cart_quantity'];}
    foreach($cart['items'] as $item){$snapshot[]=['id'=>(int)$item['id'],'name'=>$item['name'],'qty'=>(int)$item['cart_quantity'],'price'=>(float)$item['price']];$pdo->prepare('UPDATE items SET quantity=GREATEST(0,quantity-?) WHERE id=?')->execute([(int)$item['cart_quantity'],(int)$item['id']]);}
    $paymentStatus=$payment==='Cash'?'pending':($payment==='GCash'?'verifying':'paid');
    // GCash online (auto) mode: the checkout session is created BEFORE the order
    // so a gateway outage never leaves a half-written order behind.
    $sessionId='';$checkoutUrl='';
    if($payment==='GCash' && sportsync_gcash_paymongo_enabled()){
      try{
        [$sessionId,$checkoutUrl]=sportsync_gcash_create_checkout($total,'Sporty Ni Migo order',array_map(fn($i)=>['name'=>$i['name'],'price'=>(float)$i['price'],'qty'=>(int)$i['cart_quantity']],$cart['items']));
      }catch(Throwable $gx){throw new Exception($gx->getMessage());}
    }
    $ins->execute([$u['id'],$eventId,json_encode($snapshot,JSON_UNESCAPED_UNICODE),$total,$payment,$paymentStatus,$reference?:null,$paymentStatus==='paid'?date('Y-m-d H:i:s'):null]);$oid=(int)$pdo->lastInsertId();
    if($payment==='GCash')$pdo->prepare('INSERT INTO gcash_payouts(order_id,checkout_session_id,reference_number,amount,status) VALUES(?,?,?,?,"verifying")')->execute([$oid,$sessionId!==''?$sessionId:null,$reference!==''?$reference:null,$total]);
    $_SESSION['cart']=[];$pdo->commit();
    best_effort_notification($pdo,$u['id'],'Order received','Order #'.$oid.' has been placed using '.$payment.'.');
    if($checkoutUrl!==''){header('Location:'.$checkoutUrl);exit;}
    redirect_page('shop','Checkout complete. Order #'.$oid.' was placed successfully.');
  }

  if($action==='message'){ $receiver=(int)($_POST['receiver_id']??0);$message=trim($_POST['message']??'');if(!$receiver||$message==='')throw new Exception('Choose a recipient and write a message.');if($receiver===(int)$u['id'])throw new Exception('Choose another user as the recipient.');$q=$pdo->prepare('SELECT id FROM users WHERE id=? AND status="active"');$q->execute([$receiver]);if(!$q->fetchColumn())throw new Exception('That recipient is no longer available. Refresh the conversation list.');$stmt=$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,subject,message) VALUES(?,?,?,?)');$stmt->execute([$u['id'],$receiver,trim($_POST['subject']??''),$message]);if($stmt->rowCount()!==1)throw new Exception('The message could not be saved.');best_effort_notification($pdo,$receiver,'New message',$u['name'].' sent you a message.');log_action($pdo,$u['id'],'message_sent','Message sent to user #'.$receiver);header('Location:index.php?page=communication&contact='.$receiver.'&ok='.urlencode('Message sent.'));exit;}

  if($action==='post'){$content=trim($_POST['content']??'');if($content==='')throw new Exception('Write something before posting.');$image=save_image_upload('image','uploads/community');$postType=$_POST['post_type']??'community';if(!in_array($postType,['community','event','tournament'],true))$postType='community';$eventId=(int)($_POST['event_id']??0)?:null;$tournamentId=(int)($_POST['tournament_id']??0)?:null;$pdo->prepare('INSERT INTO community_posts(user_id,post_type,event_id,tournament_id,content,image_path) VALUES(?,?,?,?,?,?)')->execute([$u['id'],$postType,$eventId,$tournamentId,$content,$image]);redirect_page('community','Post shared with the community.');}
  if($action==='like'){$pid=(int)$_POST['post_id'];$q=$pdo->prepare('SELECT id FROM post_interactions WHERE post_id=? AND user_id=? AND type="like"');$q->execute([$pid,$u['id']]);if($id=$q->fetchColumn())$pdo->prepare('DELETE FROM post_interactions WHERE id=?')->execute([$id]);else $pdo->prepare('INSERT INTO post_interactions(post_id,user_id,type) VALUES(?,?,"like")')->execute([$pid,$u['id']]);redirect_page('community','Reaction updated.');}
  if($action==='comment'){$content=trim($_POST['content']??'');$postId=(int)($_POST['post_id']??0);if($content==='')throw new Exception('Comment cannot be empty.');$q=$pdo->prepare('SELECT id FROM community_posts WHERE id=?');$q->execute([$postId]);if(!$q->fetchColumn())throw new Exception('This post no longer exists. Refresh the community wall.');$pdo->prepare('INSERT INTO post_interactions(post_id,user_id,type,content) VALUES(?,?,"comment",?)')->execute([$postId,$u['id'],$content]);redirect_page('community','Comment added.');}

  if($action==='release_equipment_reservation' && can_role('manage_equipment')){
    $reservationId=(int)($_POST['reservation_id']??0);
    $pdo->prepare('UPDATE event_equipment_reservations SET status="released" WHERE id=? AND status="reserved"')->execute([$reservationId]);
    log_action($pdo,$u['id'],'equipment_reservation_released','Reservation #'.$reservationId);
    redirect_page('equipment','Equipment reservation released.');
  }

  if($action==='announcement' && can_role('manage_announcements')){
    $title=trim($_POST['title']??'');$body=trim($_POST['body']??'');$type=$_POST['type']??'general';$audience=$_POST['audience']??'all';
    if(!$title||!$body)throw new Exception('Title and message are required.');
    if(!in_array($type,['general','urgent','emergency'],true))$type='general';
    if(!in_array($audience,['all','participants','staff','organizers','management'],true))$audience='all';
    $expires=$_POST['expires_at']??'';
    $sendSms=isset($_POST['send_sms']) && in_array($type,['urgent','emergency'],true);
    if($sendSms && !sportsync_sms_enabled()) throw new Exception('SMS is not configured yet. Add your SMSGate settings in .env before sending urgent SMS reminders.');
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO announcements(title,body,type,audience,event_id,created_by,expires_at,sms_total,sms_sent,sms_failed) VALUES(?,?,?,?,?,?,?,0,0,0)')->execute([$title,$body,$type,$audience,(int)($_POST['event_id']??0)?:null,$u['id'],$expires ?: null]);
    $announcementId=(int)$pdo->lastInsertId();
    $roleMap=['participants'=>['Participant/Athlete','Spectator/Community Member'],'staff'=>['Staff/Coordinator'],'organizers'=>['Event Organizer'],'management'=>['Administrator','Staff/Coordinator']];
    if(isset($roleMap[$audience])){$roles=$roleMap[$audience];$ph=implode(',',array_fill(0,count($roles),'?'));$q=$pdo->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="active" AND r.name IN ('.$ph.')');$q->execute($roles);}else{$q=$pdo->query('SELECT id FROM users WHERE status="active"');}
    $ins=$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,subject,message) VALUES(NULL,?,"text",?,?)');foreach($q->fetchAll() as $to)$ins->execute([$to['id'],$title,$body]);
    $pdo->commit();
    $message='Announcement published.';
    if($sendSms){
      $sms=sportsync_send_announcement_sms($pdo,$announcementId,$audience,$title,$body);
      // Persist delivery counts so the announcement card can show real results.
      $pdo->prepare('UPDATE announcements SET sms_total=?,sms_sent=?,sms_failed=? WHERE id=?')
          ->execute([(int)$sms['total'],(int)$sms['sent'],(int)$sms['failed'],$announcementId]);
      $message.=' SMS: '.$sms['sent'].' sent';if($sms['failed'])$message.=', '.$sms['failed'].' failed';if($sms['skipped'])$message.=', '.$sms['skipped'].' skipped (no mobile number)';$message.='.';
    }
    log_action($pdo,$u['id'],'announcement_published','Announcement #'.$announcementId.($sendSms?' with SMS':' without SMS'));
    redirect_page('announcements',$message);
  }

  if($action==='save_profile'){$name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');$addr=trim(mb_substr((string)($_POST['address']??''),0,255));if(strlen($name)<3)throw new Exception('Enter your complete name.');if($phone!==''&&!sportsync_normalize_phone($phone))throw new Exception('Enter a valid mobile number for urgent SMS notifications.');$phone=$phone!==''?sportsync_normalize_phone($phone):'';$pdo->prepare('UPDATE users SET full_name=?,phone=?,address=? WHERE id=?')->execute([$name,$phone,$addr!==''?$addr:null,$u['id']]);$pdo->prepare('UPDATE event_registrations SET full_name=?,phone=? WHERE user_id=?')->execute([$name,$phone,$u['id']]);refresh_session_user($pdo);redirect_page('profile','Profile updated.');}
  if($action==='mark_notifications_read'){$pdo->prepare('UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id IS NULL AND message_type="text"')->execute([$u['id']]);redirect_page($page,'Notifications marked as read.');}

 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$err=sportsync_public_error($ex);}
}

$menu=[
 'dashboard'=>['Dashboard','fa-grid-2','Overview'],
 'events'=>[$isManager?'Event Management':'My Events','fa-calendar-days','Events'],
 'discover'=>['Browse Events','fa-compass','Events'],
 'venues'=>['Tupi Venues & Map','fa-location-dot','Events'],
 'shop'=>[$isManager?'Shop & Orders':'Equipment Shop','fa-bag-shopping','Resources'],
 'communication'=>['Communication','fa-comments','Community'],
 'announcements'=>['Announcements','fa-bullhorn','Community'],
 'community'=>['Community Wall','fa-people-group','Community'],
 'tournament'=>['Tournaments','fa-trophy','Community'],
 'profile'=>['My Profile','fa-user','Account'],
];
if($canPlanEvent){
    $menu=['dashboard'=>$menu['dashboard'],'plan'=>['Plan an Event','fa-calendar-plus','Events']] + array_diff_key($menu,['dashboard'=>1]);
}else{
    $menu['equipment']=['Equipment Management','fa-dumbbell','Manage'];
}
if($isManager){
    $menu['participants']=['Participants','fa-users','Manage'];
    $menu['inventory']=['Inventory','fa-boxes-stacked','Manage'];
    $menu['reports']=['Reports','fa-chart-column','Manage'];
}
if($isAdmin){$menu['users']=['Users','fa-user-shield','Manage'];$menu['sms']=['SMS Notifications','fa-mobile-screen-button','Admin'];$menu['manage']=['Data Management','fa-database','Admin'];}
if(!isset($menu[$page]))$page='dashboard';

$nq=$pdo->prepare('SELECT * FROM messages WHERE receiver_id=? AND sender_id IS NULL AND message_type="text" ORDER BY created_at DESC LIMIT 8');$nq->execute([$u['id']]);$notifications=$nq->fetchAll();$ncount=0;foreach($notifications as $n)if(!$n['is_read'])$ncount++;
$messagesUnread=$pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0');$messagesUnread->execute([$u['id']]);$messagesUnread=(int)$messagesUnread->fetchColumn();

?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0f1f3d"><link rel="icon" type="image/png" href="assets/img/logo-icon.png"><title><?=e($menu[$page][0]??'Sporty Ni Migo')?> · Sporty Ni Migo</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><link rel="stylesheet" href="assets/css/app.css?v=20261002a"><link rel="stylesheet" href="assets/css/ui-polish.css?v=20261002a"></head>
<body class="app-body">
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="mobile-backdrop" id="mobileBackdrop"></div>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
 <div class="brand"><div class="brand-logo"><img src="assets/img/logo-icon.png" alt="Sporty Ni Migo logo"></div><div><strong>Sporty Ni Migo</strong><small>Event Operations</small></div></div>
 <div class="sidebar-user"><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div><div class="min-w-0"><strong><?=e($u['name'])?></strong><small><?=e($u['role_name'])?></small></div></div>
 <nav class="sidebar-nav">
 <?php $lastGroup='';foreach($menu as $key=>$m): if($lastGroup!==$m[2]):$lastGroup=$m[2];?><div class="nav-section"><?=e($m[2])?></div><?php endif;?><a class="nav-link <?=$page===$key?'active':''?>" href="?page=<?=$key?>"><i class="fa-solid <?=$m[1]?>"></i><span><?=e($m[0])?></span><?php if($key==='communication'&&$messagesUnread):?><b><?=$messagesUnread?></b><?php endif;?></a><?php endforeach;?>
 </nav>
 <div class="sidebar-bottom"><button class="nav-link logout-nav-btn" type="button" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"><i class="fa-solid fa-right-from-bracket"></i><span>Sign out</span></button></div>
</aside>
<div class="content-wrap">
<header class="topbar"><div class="topbar-left"><button id="sidebarToggle" class="icon-btn d-lg-none" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><i class="fa-solid fa-bars"></i></button><div><span class="eyebrow">SPORTSYNC WORKSPACE</span><h1><?=e($menu[$page][0]??'Dashboard')?></h1></div></div><div class="topbar-right"><div class="mini-weather" id="globalWeather" aria-live="polite"><div class="mini-weather-icon"><i class="fa-solid fa-cloud-sun-rain"></i></div><div><small>Tupi weather</small><strong id="globalWeatherTemp">--°C</strong><span id="globalWeatherText">Loading...</span></div><div class="mini-weather-meta"><b id="globalWeatherRain">--%</b><small>rain</small></div></div><button id="themeToggle" class="icon-btn" title="Toggle theme"><i class="fa-regular fa-moon"></i></button><div class="dropdown"><button class="icon-btn" data-bs-toggle="dropdown"><i class="fa-regular fa-bell"></i><?php if($ncount):?><span class="notification-dot"></span><?php endif;?></button><div class="dropdown-menu dropdown-menu-end notification-menu"><div class="notification-head"><strong>Notifications</strong><span><?=$ncount?> unread</span></div><?php if(!$notifications):?><div class="empty-mini">No notifications yet.</div><?php else:foreach($notifications as $n):?><div class="notification-item <?=$n['is_read']?'':'unread'?>"><strong><?=e($n['subject']??'Notification')?></strong><p><?=e($n['message'])?></p><small><?=e(date('M d, g:i A',strtotime($n['created_at'])))?></small></div><?php endforeach;?><form method="post" class="p-2"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="mark_notifications_read"><button class="btn btn-light btn-sm w-100" data-no-loading="1">Mark all as read</button></form><?php endif;?></div></div><a class="user-chip" href="?page=profile"><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div><div class="d-none d-md-block"><strong><?=e($u['name'])?></strong><small><?=e($u['role_name'])?></small></div></a></div></header>
<main class="main-content" id="mainContent" tabindex="-1">
<?php if($ok):?><div class="alert app-alert success auto-hide"><i class="fa-solid fa-circle-check"></i><span><?=e($ok)?></span></div><?php endif;?><?php if($err):?><div class="alert app-alert danger"><i class="fa-solid fa-circle-exclamation"></i><span><?=e($err)?></span></div><?php endif;?>

<?php if($page==='dashboard'):
if($isManager){
 $myEvents=(int)$pdo->query('SELECT COUNT(*) FROM events WHERE status<>"cancelled"')->fetchColumn();
 $upcoming=(int)$pdo->query('SELECT COUNT(*) FROM events WHERE start_at>NOW() AND status="scheduled"')->fetchColumn();
 $reserved=(int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM event_equipment_reservations WHERE status="reserved"')->fetchColumn();
 $orderCount=(int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status IN("pending","confirmed","ready")')->fetchColumn();
 $userCount=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE status="active"')->fetchColumn();
 $nextEvents=$pdo->query('SELECT e.*,v.name venue_name FROM events e LEFT JOIN venues v ON v.id=e.venue_id WHERE e.start_at>=NOW() AND e.status="scheduled" ORDER BY e.start_at LIMIT 4')->fetchAll();
}else{
 $q=$pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id=? AND status<>"cancelled"');$q->execute([$u['id']]);$myEvents=(int)$q->fetchColumn();
 $q=$pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id=? AND start_at>NOW() AND status="scheduled"');$q->execute([$u['id']]);$upcoming=(int)$q->fetchColumn();
 $q=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM event_equipment_reservations WHERE user_id=? AND status="reserved"');$q->execute([$u['id']]);$reserved=(int)$q->fetchColumn();
 $q=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id=? AND status<>"cancelled"');$q->execute([$u['id']]);$orderCount=(int)$q->fetchColumn();
 $userCount=0;
 $q=$pdo->prepare('SELECT e.*,v.name venue_name FROM events e LEFT JOIN venues v ON v.id=e.venue_id WHERE e.organizer_id=? AND e.start_at>=NOW() AND e.status="scheduled" ORDER BY e.start_at LIMIT 4');$q->execute([$u['id']]);$nextEvents=$q->fetchAll();
}
$recentNotices=$pdo->query('SELECT a.*,u.full_name FROM announcements a LEFT JOIN users u ON u.id=a.created_by WHERE expires_at IS NULL OR expires_at>NOW() ORDER BY created_at DESC LIMIT 3')->fetchAll();
$community=$pdo->query('SELECT p.*,u.full_name FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC LIMIT 3')->fetchAll();
?>
<div class="dashboard-welcome">
 <div><span class="eyebrow">WELCOME BACK</span><h2>Hello, <?=e(explode(' ',$u['name'])[0])?> 👋</h2><p><?=$isManager?'Monitor events, resources, users, orders, and urgent communications from one workspace.':'Your next event, venue, equipment, updates, and conversations are all within reach.'?></p><div class="hero-actions"><?php if($canPlanEvent):?><a class="btn btn-primary" href="?page=plan"><i class="fa-solid fa-calendar-plus"></i>Plan an event</a><?php else:?><a class="btn btn-primary" href="?page=events"><i class="fa-solid fa-calendar-check"></i>Manage events</a><?php endif;?><a class="btn btn-soft" href="?page=communication"><i class="fa-solid fa-comments"></i>Open messages</a></div></div>
 <div class="weather-hero" id="dashboardWeather"><i class="fa-solid fa-cloud-showers-heavy"></i><div><small>Current weather · Tupi</small><strong id="dashWeatherTemp">--°C</strong><span id="dashWeatherText">Checking live conditions...</span></div><div class="weather-metrics"><span><b id="dashRain">--%</b> rain</span><span><b id="dashWind">--</b> km/h</span></div></div>
</div>
<div class="stats-grid dashboard-stats"><div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-calendar-check"></i></div><div><small><?=$isManager?'Total events':'My events'?></small><strong><?=$myEvents?></strong></div></div><div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-clock"></i></div><div><small>Upcoming</small><strong><?=$upcoming?></strong></div></div><div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-dumbbell"></i></div><div><small>Reserved equipment</small><strong><?=$reserved?></strong></div></div><div class="stat-card"><div class="stat-icon purple"><i class="fa-solid fa-bag-shopping"></i></div><div><small>Orders</small><strong><?=$orderCount?></strong></div></div><?php if($isManager):?><div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-location-dot"></i></div><div><small>Active venues</small><strong><?=$pdo->query('SELECT COUNT(*) FROM venues WHERE status="available"')->fetchColumn()?></strong></div></div><?php endif;?><?php if($isAdmin):?><div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-user-shield"></i></div><div><small>Active users</small><strong><?=$userCount?></strong></div></div><?php endif;?></div>
<div class="dashboard-grid">
 <section class="panel"><div class="panel-head"><div><span class="eyebrow">UPCOMING</span><h3><?=$isManager?'Upcoming events':'Your next events'?></h3></div><a href="?page=events">View all</a></div><?php if(!$nextEvents):?><div class="empty-state compact"><i class="fa-regular fa-calendar-plus"></i><h4>No upcoming events yet</h4><p><?=$canPlanEvent?'Choose a date on the planner to create your first event.':'No scheduled event is waiting for management.'?></p><?php if($canPlanEvent):?><a class="btn btn-primary btn-sm" href="?page=plan">Open planner</a><?php endif;?></div><?php else:?><div class="event-list"><?php foreach($nextEvents as $e):?><article class="event-row"><div class="event-date"><strong><?=date('d',strtotime($e['start_at']))?></strong><span><?=strtoupper(date('M',strtotime($e['start_at'])))?></span></div><div class="event-info"><h4><?=e($e['title'])?></h4><p><i class="fa-regular fa-clock"></i><?=e(date('g:i A',strtotime($e['start_at'])))?> · <i class="fa-solid fa-location-dot"></i><?=e($e['venue_name']??'TBA')?></p></div><a class="icon-btn" href="?page=events"><i class="fa-solid fa-arrow-right"></i></a></article><?php endforeach;?></div><?php endif;?></section>
 <section class="panel"><div class="panel-head"><div><span class="eyebrow">ANNOUNCEMENTS</span><h3>Latest notices</h3></div><a href="?page=announcements">View all</a></div><?php if(!$recentNotices):?><div class="empty-state compact"><i class="fa-regular fa-bell"></i><h4>No announcements</h4><p>Important reminders will appear here.</p></div><?php else:foreach($recentNotices as $n):?><article class="dashboard-notice <?=e($n['type'])?>"><i class="fa-solid <?=$n['type']==='emergency'?'fa-triangle-exclamation':'fa-bullhorn'?>"></i><div><strong><?=e($n['title'])?></strong><p><?=e(mb_strimwidth(strip_tags($n['body']),0,105,'…'))?></p><small><?=e(date('M d · g:i A',strtotime($n['created_at'])))?></small></div></article><?php endforeach;endif;?></section>
 <section class="panel quick-panel"><div class="panel-head"><div><span class="eyebrow">QUICK ACCESS</span><h3>What do you want to do?</h3></div></div><div class="quick-grid"><?php if($canPlanEvent):?><a href="?page=plan"><i class="fa-solid fa-calendar-plus"></i><span>Plan event</span></a><?php endif;?><a href="?page=venues"><i class="fa-solid fa-map-location-dot"></i><span>Find venue</span></a><a href="?page=shop"><i class="fa-solid fa-bag-shopping"></i><span>Equipment shop</span></a><a href="?page=communication"><i class="fa-solid fa-comments"></i><span>Messages</span></a><a href="?page=community"><i class="fa-solid fa-people-group"></i><span>Community</span></a><a href="?page=announcements"><i class="fa-solid fa-bullhorn"></i><span>Notices</span></a></div></section>
 <section class="panel"><div class="panel-head"><div><span class="eyebrow">COMMUNITY</span><h3>Latest activity</h3></div><a href="?page=community">Open wall</a></div><?php if(!$community):?><div class="empty-state compact"><i class="fa-solid fa-people-group"></i><h4>No community posts yet</h4><p>Event and tournament updates will appear here.</p></div><?php else:foreach($community as $p):?><div class="feed-mini"><div class="avatar"><?=e(strtoupper(substr($p['full_name'],0,1)))?></div><div><strong><?=e($p['full_name'])?></strong><p><?=e(mb_strimwidth($p['content'],0,110,'…'))?></p><small><?=e(date('M d, g:i A',strtotime($p['created_at'])))?></small></div></div><?php endforeach;endif;?></section>
</div>

<?php elseif($page==='plan'):
$equipment=$pdo->query('SELECT * FROM equipment WHERE status<>"maintenance" ORDER BY category,name')->fetchAll();
?>
<div class="page-intro"><div><span class="eyebrow">CALENDAR PLANNER</span><h2>Plan your event</h2><p>Choose a date, pick a time, then Sporty Ni Migo checks venue and equipment availability for the same schedule.</p></div></div>
<form method="post" id="eventPlanner" class="calendar-planner"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="create_event_plan"><input type="hidden" name="venue_id" id="selectedVenueId"><input type="hidden" name="start_at" id="plannerStart"><input type="hidden" name="end_at" id="plannerEnd">
 <section class="panel calendar-panel"><div class="calendar-toolbar"><button type="button" class="icon-btn" id="calendarPrev"><i class="fa-solid fa-chevron-left"></i></button><div><span class="eyebrow">SELECT DATE</span><h3 id="calendarTitle">Calendar</h3></div><button type="button" class="icon-btn" id="calendarNext"><i class="fa-solid fa-chevron-right"></i></button></div><div class="calendar-week"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="event-calendar" id="eventCalendar"></div></section>
 <section class="panel planner-form-card"><div class="step-line"><span>1</span><div><h3>Event & time</h3><p id="selectedDateText">Choose a date on the calendar.</p></div></div><div class="row g-3"><div class="col-lg-7"><label class="form-label">Event title</label><input class="form-control" name="title" required placeholder="e.g. Community Basketball Tournament"></div><div class="col-lg-5"><label class="form-label">Event type</label><input class="form-control" name="event_type" placeholder="Tournament, training, fun run..."></div><div class="col-md-4"><label class="form-label">Selected date</label><input class="form-control" id="plannerDate" type="date" required></div><div class="col-md-4"><label class="form-label">Start time</label><input class="form-control" id="plannerStartTime" type="time" required></div><div class="col-md-4"><label class="form-label">End time</label><input class="form-control" id="plannerEndTime" type="time" required></div><div class="col-md-4"><label class="form-label">Expected participants</label><input class="form-control" type="number" min="1" value="20" name="expected_people" id="plannerPeople"></div><div class="col-md-8 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_outdoor" id="isOutdoor"><label class="form-check-label" for="isOutdoor">Outdoor event — weather conditions matter</label></div></div><div class="col-12"><label class="form-label">Event details</label><textarea class="form-control" name="description" rows="3" placeholder="Purpose, audience, setup requests..."></textarea></div><div class="col-12 planner-actions-row"><button type="button" class="btn btn-primary" id="findAvailability"><i class="fa-solid fa-magnifying-glass"></i>Check availability</button><button type="button" class="btn btn-soft" id="autoMatchBtn" title="Automatically pick the best conflict-free venue for this schedule"><i class="fa-solid fa-wand-magic-sparkles"></i>Match venue for me</button><input type="hidden" name="auto_match" id="autoMatchField" value="0"><span id="autoMatchStatus" class="auto-match-status"></span></div></div></section>
 <section class="panel planner-selection"><div class="step-line"><span>2</span><div><h3>Available venues in Tupi</h3><p>Only venues with enough capacity and no schedule conflict are shown.</p></div></div><div class="venue-equipment-grid"><div><div id="venueResults" class="venue-results"><div class="empty-inline"><i class="fa-solid fa-calendar-check"></i><span>Select your date and time, then check availability.</span></div></div></div><div id="plannerMap" class="planner-map tupi-map"></div></div></section>
 <section class="panel planner-selection"><div class="step-line"><span>3</span><div><h3>Borrow equipment for this event</h3><p>Equipment borrowing is integrated into the event plan so the same schedule is used.</p></div></div><div id="equipmentResults" class="equipment-results equipment-cards"><?php foreach($equipment as $eq):?><article class="equipment-choice" data-equipment-id="<?=$eq['id']?>"><div class="equipment-choice-icon"><i class="fa-solid fa-dumbbell"></i></div><div class="equipment-choice-info"><strong><?=e($eq['name'])?></strong><small><?=e($eq['category'])?> · <?=e($eq['current_location'])?></small><span class="available-label">Check schedule availability</span></div><div class="qty-control"><label>Qty</label><input type="number" min="0" value="0" name="equipment_qty[<?=$eq['id']?>]" class="form-control"></div></article><?php endforeach;?></div><div class="buy-instead"><div><i class="fa-solid fa-bag-shopping"></i><span><strong>Need your own equipment?</strong><small>Purchase from the Sporty Ni Migo shop instead of borrowing.</small></span></div><a href="?page=shop" class="btn btn-soft">Open shop</a></div></section>
 <section class="planner-review panel"><div class="step-line"><span>4</span><div><h3>Review & confirm</h3><p>Your venue and equipment are checked again at submission to prevent double booking.</p></div></div><div class="review-grid"><div><small>Date & time</small><strong id="summarySchedule">Not selected</strong></div><div><small>Venue</small><strong id="summaryVenue">Not selected</strong></div><div><small>Equipment</small><strong id="summaryEquipment">None</strong></div><div><small>Tupi weather</small><strong id="plannerWeatherSummary">Live widget above</strong></div></div><div class="travel-inline"><i class="fa-solid fa-route"></i><div><strong>Preparation reminder</strong><span id="travelHint">Plan travel and setup time before your scheduled start.<?php if(trim((string)($u['address']??''))===''):?> Add your home address on My Profile for an automatic estimate.<?php endif;?></span><small id="travelSource" class="d-block text-muted"></small></div><label>Travel <input id="travelMins" type="number" min="0" value="30"> min</label><label>Setup <input id="prepMins" type="number" min="0" value="45"> min</label><div id="travelResult"></div></div><button class="btn btn-primary btn-lg" id="submitPlan" disabled><i class="fa-solid fa-check"></i>Confirm event plan</button></section>
</form>

<?php elseif($page==='discover'):
$events=$pdo->query('SELECT e.*,v.name venue_name,v.address FROM events e LEFT JOIN venues v ON v.id=e.venue_id WHERE e.status="scheduled" AND e.start_at>NOW() ORDER BY e.start_at')->fetchAll();$reg=$pdo->prepare('SELECT event_id,status FROM event_registrations WHERE user_id=?');$reg->execute([$u['id']]);$joined=[];foreach($reg->fetchAll() as $x)$joined[(int)$x['event_id']]=$x['status'];
?>
<div class="page-intro"><div><span class="eyebrow">DISCOVER</span><h2>Browse & join events</h2><p>Find upcoming community events and submit your participant registration online.</p></div></div><div class="event-discovery-grid"><?php foreach($events as $e):?><article class="panel discover-card"><div class="discover-date"><strong><?=date('d',strtotime($e['start_at']))?></strong><span><?=strtoupper(date('M',strtotime($e['start_at'])))?></span></div><div class="discover-body"><span class="eyebrow"><?=e($e['event_type']?:'SPORTS EVENT')?></span><h3><?=e($e['title'])?></h3><p><?=e(mb_strimwidth($e['description'],0,130,'…'))?></p><div class="discover-meta"><span><i class="fa-regular fa-clock"></i><?=e(date('M d, Y · g:i A',strtotime($e['start_at'])))?></span><span><i class="fa-solid fa-location-dot"></i><?=e($e['venue_name']??'TBA')?></span></div><?php if(!$canRegisterEvent):?><div class="mt-3"><span class="text-muted small">Management accounts view events but do not register as participants.</span></div><?php elseif(isset($joined[$e['id']])):?><div class="mt-3"><?=status_badge($joined[$e['id']])?></div><?php else:?><form method="post" class="mt-3"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="register_event"><input type="hidden" name="event_id" value="<?=$e['id']?>"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-user-plus"></i>Register for event</button></form><?php endif;?></div></article><?php endforeach;?></div>

<?php elseif($page==='events'):
$sql='SELECT e.*,v.name venue_name,v.address,v.latitude venue_latitude,v.longitude venue_longitude FROM events e LEFT JOIN venues v ON v.id=e.venue_id '.($isManager?'':'WHERE e.organizer_id=? ').'ORDER BY e.start_at DESC';$q=$pdo->prepare($sql);$q->execute($isManager?[]:[$u['id']]);$events=$q->fetchAll();$venues=$pdo->query('SELECT * FROM venues WHERE status="available" ORDER BY name')->fetchAll();
?>
<div class="page-intro"><div><span class="eyebrow">SCHEDULE</span><h2><?=$isManager?'Event calendar':'My events'?></h2><p>Review reservations, respond to weather conditions, and reschedule when needed.</p></div><?php if($canPlanEvent):?><a href="?page=plan" class="btn btn-primary"><i class="fa-solid fa-plus"></i>Plan event</a><?php endif;?></div>
<div class="panel"><div class="table-toolbar"><input class="form-control search-input" data-table-search="#eventsTable" placeholder="Search events..."></div><div class="table-responsive"><table class="table modern-table" id="eventsTable"><thead><tr><th>Event</th><th>Schedule</th><th>Venue</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($events as $e):?><tr><td><strong><?=e($e['title'])?></strong><small><?=e($e['event_type'])?></small></td><td><?=e(date('M d, Y',strtotime($e['start_at'])))?><small><?=e(date('g:i A',strtotime($e['start_at'])))?> – <?=e(date('g:i A',strtotime($e['end_at'])))?></small></td><td><?=e($e['venue_name']??'TBA')?><small><?=e($e['address']??'')?> <?php if($e['venue_latitude'] && $e['venue_longitude']):?><a class="text-primary" href="https://www.google.com/maps/search/?api=1&query=<?=e($e['venue_latitude'])?>,<?=e($e['venue_longitude'])?>" target="_blank" rel="noopener"><i class="fa-solid fa-location-crosshairs"></i> Directions</a><?php endif;?></small></td><td><?=status_badge($e['status'])?></td><td><div class="action-row"><?php if($e['status']!=='cancelled'):?><button class="btn btn-sm btn-soft" data-bs-toggle="modal" data-bs-target="#reschedule<?=$e['id']?>"><i class="fa-solid fa-calendar-day"></i>Reschedule</button><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="cancel_event"><input type="hidden" name="event_id" value="<?=$e['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this event and release its reservations?"><i class="fa-solid fa-xmark"></i></button></form><?php endif;?><?php if($isAdmin):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="<?=$e['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Permanently delete this event? Registrations and reservations are removed with it."><i class="fa-solid fa-trash"></i></button></form><?php endif;?></div></td></tr>
<div class="modal fade" id="reschedule<?=$e['id']?>"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Reschedule <?=e($e['title'])?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="reschedule_event"><input type="hidden" name="event_id" value="<?=$e['id']?>"><label class="form-label">New start</label><input type="datetime-local" class="form-control mb-3" name="start_at" value="<?=date('Y-m-d\TH:i',strtotime($e['start_at']))?>" required><label class="form-label">New end</label><input type="datetime-local" class="form-control mb-3" name="end_at" value="<?=date('Y-m-d\TH:i',strtotime($e['end_at']))?>" required><label class="form-label">Venue</label><select name="venue_id" class="form-select"><?php foreach($venues as $v):?><option value="<?=$v['id']?>" <?=$v['id']==$e['venue_id']?'selected':''?>><?=e($v['name'])?> · Capacity <?=$v['capacity']?></option><?php endforeach;?></select><label class="form-label mt-3">Expected participants</label><input type="number" min="1" class="form-control" name="expected_people" value="20"></div><div class="modal-footer"><button class="btn btn-primary">Save new schedule</button></div></form></div></div>
<?php endforeach;?></tbody></table></div></div>

<?php elseif($page==='venues'):
$venues=$pdo->query('SELECT * FROM venues ORDER BY name')->fetchAll();
$venuesAvailable=$pdo->query('SELECT * FROM venues WHERE status="available" ORDER BY name')->fetchAll();
?>
<div class="page-intro"><div><span class="eyebrow">TUPI, SOUTH COTABATO</span><h2>Venues & map</h2><p>Browse every venue, drop a pin to set its location, and attach a photo so the team can recognize it at a glance.</p></div><?php if(can_role('manage_venues')):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#venuePinModal"><i class="fa-solid fa-map-pin"></i>Add venue</button><?php endif;?></div>
<div class="venue-map-layout"><section class="venue-directory"><?php if(!$venues):?><div class="panel empty-state"><i class="fa-solid fa-location-dot"></i><h4>No venues yet</h4><p>Add your first venue and pin its location on the map.</p></div><?php else:foreach($venues as $v):?><article class="venue-directory-card" data-lat="<?=e($v['latitude'])?>" data-lng="<?=e($v['longitude'])?>"><div class="venue-media"><?php if($v['image_path']&&is_file(SPORTSYNC_ROOT.'/'.ltrim($v['image_path'],'/'))):?><img src="<?=e($v['image_path'])?>" alt="<?=e($v['name'])?>"><?php else:?><div class="venue-media-fallback"><i class="fa-solid fa-building"></i></div><?php endif;?></div><div><h3><?=e($v['name'])?></h3><p><?=e($v['address']?:'Tupi, South Cotabato')?></p><div class="resource-tags"><span><i class="fa-solid fa-users"></i> Capacity <?=$v['capacity']?></span><?php if($v['facilities']):?><span><i class="fa-solid fa-list-check"></i><?=e($v['facilities'])?></span><?php endif;?><span class="status-pill status-<?=$v['status']==='available'?'success':($v['status']==='maintenance'?'warning':'secondary')?>"><?=e(ucwords(str_replace('_',' ',$v['status'])))?></span></div><div class="venue-actions"><button type="button" class="btn btn-soft btn-sm show-venue-map">Show on map</button><?php if(can_role('manage_venues')):?><button type="button" class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editVenue<?=$v['id']?>">Edit</button><?php endif;?><?php if($canPlanEvent && $v['status']==='available'):?><a class="btn btn-primary btn-sm" href="?page=plan">Plan here</a><?php endif;?></div></div></article><?php endforeach;endif;?></section><section class="panel venue-map-panel"><div class="map-caption"><div><span class="eyebrow">LOCAL MAP</span><h3>Tupi, South Cotabato Venue Map</h3></div><span class="status-pill status-success">Interactive</span></div><div id="venueMap" class="venue-map tupi-map"></div><p class="map-help"><i class="fa-solid fa-circle-info"></i>Click a venue card to focus the map. Administrators can open Add venue and drop a pin to set the location.</p></section></div>

<?php if(can_role('manage_venues')):?>
<div class="modal fade" id="venuePinModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Add venue & map pin</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_venue"><div class="row g-3"><div class="col-lg-7"><label class="form-label">Venue name</label><input class="form-control" name="name" required placeholder="e.g. Tupi Municipal Gymnasium"></div><div class="col-lg-5"><label class="form-label">Status</label><select class="form-select" name="status"><option>available</option><option>maintenance</option><option>inactive</option></select></div></div><div class="mb-3"><label class="form-label">Address</label><input class="form-control" name="address" placeholder="Barangay, street, or landmark"></div><div class="mb-3"><label class="form-label">Capacity</label><input type="number" class="form-control" name="capacity" min="0" value="0"></div><div class="mb-3"><label class="form-label">Facilities</label><textarea class="form-control" name="facilities" rows="2" placeholder="Covered court, parking, first-aid area..."></textarea></div><div class="mb-3"><label class="form-label">Layout notes</label><textarea class="form-control" name="layout_notes" rows="2" placeholder="Internal notes about entrances, staging area, etc."></textarea></div><div class="mb-3"><label class="form-label">Venue photo</label><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp"><div class="form-text">Optional. JPG, PNG, or WebP, up to 4 MB.</div></div><div class="mb-3"><label class="form-label">Pin location on map</label><div id="addVenueMap" class="venue-map tupi-map" style="height:320px"></div><p class="map-help">Click the map to drop a pin. The latitude and longitude are saved with the venue.</p><input type="hidden" name="latitude" id="pinLat"><input type="hidden" name="longitude" id="pinLng"><button type="button" class="btn btn-soft btn-sm" id="clearPin">Clear pin</button></div></div><div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-soft" id="useCurrentLocation">Use my location</button><button type="button" class="btn btn-primary" id="saveVenuePin">Save venue</button></div></form></div></div>
<?php endif;?>

<?php elseif($page==='equipment'):
$eq=$pdo->query('SELECT * FROM equipment ORDER BY category,name')->fetchAll();
if($isManager){
 $reservations=$pdo->query('SELECT r.*,e.name equipment_name,ev.title event_title,u.full_name reserved_by FROM event_equipment_reservations r JOIN equipment e ON e.id=r.equipment_id JOIN events ev ON ev.id=r.event_id JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC LIMIT 100')->fetchAll();
}else{
 $myRes=$pdo->prepare('SELECT r.*,e.name equipment_name,ev.title event_title FROM event_equipment_reservations r JOIN equipment e ON e.id=r.equipment_id JOIN events ev ON ev.id=r.event_id WHERE r.user_id=? ORDER BY r.created_at DESC LIMIT 20');$myRes->execute([$u['id']]);$myRes=$myRes->fetchAll();
}
?>
<div class="page-intro"><div><span class="eyebrow"><?=$isManager?'RESOURCE CONTROL':'BORROW & TRACK'?></span><h2><?=$isManager?'Equipment management':'Equipment reservations'?></h2><p><?=$isManager?'Maintain equipment records and monitor or release user reservations. Administrators do not borrow equipment personally.':'Reserve sports gear while planning an event, and keep a clear record of what you borrowed.'?></p></div><div class="d-flex gap-2"><?php if(can_role('manage_equipment')):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#equipmentModal"><i class="fa-solid fa-plus"></i>Add equipment</button><?php elseif($canBorrowEquipment):?><a href="?page=plan" class="btn btn-primary"><i class="fa-solid fa-plus"></i>Reserve with an event</a><?php endif;?></div></div>
<div class="resource-grid"><?php foreach($eq as $x):?><article class="resource-card"><div class="resource-icon"><i class="fa-solid fa-dumbbell"></i></div><div><h3><?=e($x['name'])?></h3><p><?=e($x['category'])?></p><div class="resource-tags"><span><?=$x['quantity']?> total</span><span><?=e($x['condition_status'])?></span><span><?=e($x['current_location'])?></span></div></div><?=status_badge($x['status'])?></article><?php endforeach;?></div>
<section class="panel mt-4"><div class="panel-head"><h3><?=$isManager?'Active & recent reservations':'My reservation history'?></h3></div><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Equipment</th><th>Event</th><?php if($isManager):?><th>Reserved by</th><?php endif;?><th>Quantity</th><th>Schedule</th><th>Status</th><?php if($isManager):?><th>Action</th><?php endif;?></tr></thead><tbody><?php foreach(($isManager?$reservations:$myRes) as $r):?><tr><td><?=e($r['equipment_name'])?></td><td><?=e($r['event_title'])?></td><?php if($isManager):?><td><?=e($r['reserved_by'])?></td><?php endif;?><td><?=$r['quantity']?></td><td><?=e(date('M d, g:i A',strtotime($r['start_at'])))?></td><td><?=status_badge($r['status'])?></td><?php if($isManager):?><td><?php if($r['status']==='reserved'):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="release_equipment_reservation"><input type="hidden" name="reservation_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Release this equipment reservation?">Release</button></form><?php else:?>—<?php endif;?></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></section>
<?php if(can_role('manage_venues')):?>
<div class="modal fade" id="venuePinModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Add venue & map pin</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_venue"><div class="row g-3"><div class="col-lg-7"><label class="form-label">Venue name</label><input class="form-control" name="name" required placeholder="e.g. Tupi Municipal Gymnasium"></div><div class="col-lg-5"><label class="form-label">Status</label><select class="form-select" name="status"><option>available</option><option>maintenance</option><option>inactive</option></select></div></div><div class="mb-3"><label class="form-label">Address</label><input class="form-control" name="address" placeholder="Barangay, street, or landmark"></div><div class="mb-3"><label class="form-label">Capacity</label><input type="number" class="form-control" name="capacity" min="0" value="0"></div><div class="mb-3"><label class="form-label">Facilities</label><textarea class="form-control" name="facilities" rows="2" placeholder="Covered court, parking, first-aid area..."></textarea></div><div class="mb-3"><label class="form-label">Layout notes</label><textarea class="form-control" name="layout_notes" rows="2" placeholder="Internal notes about entrances, staging area, etc."></textarea></div><div class="mb-3"><label class="form-label">Venue photo</label><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp"><div class="form-text">Optional. JPG, PNG, or WebP, up to 4 MB.</div></div><div class="mb-3"><label class="form-label">Pin location on map</label><div id="addVenueMap" class="venue-map tupi-map" style="height:320px"></div><p class="map-help">Click the map to drop a pin. The latitude and longitude are saved with the venue.</p><input type="hidden" name="latitude" id="pinLat"><input type="hidden" name="longitude" id="pinLng"><button type="button" class="btn btn-soft btn-sm" id="clearPin">Clear pin</button></div></div><div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-soft" id="useCurrentLocation">Use my location</button><button type="button" class="btn btn-primary" id="saveVenuePin">Save venue</button></div></form></div></div>
<?php endif;?>

<?php if(can_role('manage_equipment')):?><div class="modal fade" id="equipmentModal"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Add equipment</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_equipment"><label class="form-label">Name</label><input class="form-control mb-3" name="name" required><label class="form-label">Category</label><input class="form-control mb-3" name="category"><div class="row g-2"><div class="col-4"><label class="form-label">Quantity</label><input type="number" min="0" class="form-control" name="quantity" value="1"></div><div class="col-8"><label class="form-label">Condition</label><select class="form-select" name="condition_status"><option>excellent</option><option selected>good</option><option>fair</option><option>repair</option></select></div></div><label class="form-label mt-3">Location</label><input class="form-control mb-3" name="current_location"><label class="form-label">Status</label><select class="form-select" name="status"><option>available</option><option>maintenance</option><option>checked_out</option></select></div><div class="modal-footer"><button class="btn btn-primary">Save equipment</button></div></form></div></div><?php endif;?>

<?php elseif($page==='shop'):
$products=$pdo->query('SELECT * FROM items WHERE kind="product" AND status="active" ORDER BY id DESC')->fetchAll();
$cart=$canOrderMerchandise?cart_summary($pdo,$u['id']):['id'=>null,'items'=>[],'count'=>0,'total'=>0.0];
if($isManager){
  $myEvents=[];
  $orders=$pdo->query('SELECT o.*,u.full_name customer FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 60')->fetchAll();
  $orderItems=[];foreach($orders as $o){$decoded=json_decode((string)$o['items_json'],true);$orderItems[(int)$o['id']]=is_array($decoded)?implode(', ',array_map(fn($i)=>$i['name'].' ×'.$i['qty'],$decoded)):'';}
}else{
  $q=$pdo->prepare('SELECT id,title,start_at FROM events WHERE organizer_id=? AND status="scheduled" ORDER BY start_at DESC');$q->execute([$u['id']]);$myEvents=$q->fetchAll();
  $q=$pdo->prepare('SELECT o.* FROM orders o WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 15');$q->execute([$u['id']]);$orders=$q->fetchAll();$orderItems=[];foreach($orders as $o){$decoded=json_decode((string)$o['items_json'],true);$orderItems[(int)$o['id']]=is_array($decoded)?implode(', ',array_map(fn($i)=>$i['name'].' ×'.$i['qty'],$decoded)):'';}
}
/* GCash payouts: manager verification queue + order→payout links for the list. */
$gcashPending=[];$payoutByOrder=[];$payoutSeen=[];
if(sportsync_gcash_enabled() && $isManager){
  $gcashPending=$pdo->query('SELECT gp.*,u.full_name customer FROM gcash_payouts gp JOIN orders o ON o.id=gp.order_id JOIN users u ON u.id=o.user_id WHERE gp.status="verifying" ORDER BY gp.id DESC LIMIT 50')->fetchAll();
  $ids=array_map(fn($x)=>(int)$x['order_id'],$gcashPending);
  if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$pq=$pdo->prepare('SELECT id,order_id FROM gcash_payouts WHERE order_id IN ('.$ph.')');$pq->execute($ids);foreach($pq->fetchAll() as $r){$payoutByOrder[(int)$r['order_id']]=$r;$payoutSeen[(int)$r['order_id']]=true;}}
}
/* GCash follow-up: in auto (PayMongo) mode, a returning customer's most recent
   verifying order is settled server-side; in manual mode the exact-amount QR
   is prepared for the checkout panel. */
$gcashVerifyOrder=null;$gcashResumeUrl='';$gcashVerifyRef='';
if($page==='shop' && $canOrderMerchandise && sportsync_gcash_enabled()){
  foreach($orders as $o){
    if(($o['payment_method']??'')!=='GCash'||($o['payment_status']??'')!=='verifying')continue;
    $q=$pdo->prepare('SELECT id,checkout_session_id FROM gcash_payouts WHERE order_id=? ORDER BY id DESC LIMIT 1');$q->execute([(int)$o['id']]);$gp=$q->fetch();
    if($gp && $gp['checkout_session_id'] && sportsync_gcash_paymongo_enabled()){
      try{
        [$paid,$resume]=sportsync_gcash_session_status((string)$gp['checkout_session_id']);
        if($paid){
          $pdo->prepare('UPDATE gcash_payouts SET status="paid",verified_at=NOW() WHERE id=? AND status="verifying"')->execute([(int)$gp['id']]);
          $pdo->prepare('UPDATE orders SET payment_status="paid",payment_verified_at=NOW() WHERE id=? AND payment_status="verifying"')->execute([(int)$o['id']]);
          best_effort_notification($pdo,(int)$o['user_id'],'GCash payment confirmed','Your GCash payment for order #'.$o['id'].' was received. The shop will prepare your items.');
          continue;
        }
        if($gcashVerifyOrder===null){$gcashVerifyOrder=$o;$gcashResumeUrl=$resume!==''?$resume:('https://checkout.paymongo.com/'.(string)$gp['checkout_session_id']);$gcashVerifyRef=(string)($gp['reference_number']??'');}
      }catch(Throwable $e){if($gcashVerifyOrder===null){$gcashVerifyOrder=$o;$gcashResumeUrl='https://checkout.paymongo.com/'.(string)$gp['checkout_session_id'];$gcashVerifyRef=(string)($gp['reference_number']??'');}}
    }else if($gcashVerifyOrder===null){$gcashVerifyOrder=$o;$gcashResumeUrl='';$gcashVerifyRef=(string)($gp['reference_number']??'');}
    break;
  }
}
?>
<?php if($gcashVerifyOrder):?><div class="alert app-alert warning gcash-waiting"><i class="fa-solid fa-mobile-screen-button"></i><div><strong>Order #<?= (int)$gcashVerifyOrder['id'] ?> is waiting for your GCash payment of ₱<?=number_format((float)$gcashVerifyOrder['total_amount'],2)?>.</strong><span><?=e($gcashVerifyRef!==''?'Reference '.$gcashVerifyRef.' is being verified — this page updates automatically once management confirms it.':'Complete the payment in the GCash window, this page updates automatically once your payment is confirmed.')?></span><?php if($gcashResumeUrl!==''):?><a class="btn btn-soft btn-sm" href="<?=e($gcashResumeUrl)?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i>Resume GCash payment</a><?php endif;?></div></div><?php endif;?>
<div class="page-intro"><div><span class="eyebrow">SPORTSYNC SHOP</span><h2><?=$isManager?'Products & customer orders':'Equipment & merchandise'?></h2><p><?=$isManager?'Maintain shop products, stock, payments, and customer order status.':'Add equipment and merchandise to your cart, review everything, then choose a payment method at checkout.'?></p></div><div class="d-flex gap-2 align-items-center"><?php if($canOrderMerchandise):?><button class="btn btn-soft position-relative" data-bs-toggle="offcanvas" data-bs-target="#cartCanvas"><i class="fa-solid fa-cart-shopping"></i> Cart <span class="badge text-bg-primary ms-1"><?=$cart['count']?></span></button><?php endif;?><?php if(can_role('manage_orders')):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal"><i class="fa-solid fa-plus"></i>Add product</button><?php endif;?></div></div>
<div class="shop-toolbar panel"><div class="shop-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="shopSearch" class="form-control" placeholder="Search products..."></div><select id="shopSort" class="form-select"><option value="default">Sort products</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="stock-desc">Most stock</option></select></div>
<?php if(!$products):?><div class="panel empty-state"><i class="fa-solid fa-store"></i><h4>No products available</h4><p>Products added by management will appear here.</p></div><?php else:?><div class="product-grid shop-grid" id="shopGrid"><?php foreach($products as $p):?><article class="product-card shop-card" data-name="<?=e(strtolower($p['name'].' '.$p['description']))?>" data-price="<?=$p['price']?>" data-stock="<?=$p['quantity']?>"><div class="product-media"><?=product_visual($p)?><span class="stock-tag <?=$p['quantity']<5?'low':''?>"><?=$p['quantity']?> in stock</span></div><div class="product-body"><h3><?=e($p['name'])?></h3><p><?=e($p['description']?:'Sports equipment and merchandise')?></p><strong class="price">₱<?=number_format($p['price'],2)?></strong><?php if($canOrderMerchandise):?><form method="post" class="shop-form"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="add_to_cart"><input type="hidden" name="merchandise_id" value="<?=$p['id']?>"><label class="shop-event-link">Quantity<input type="number" class="form-control" name="quantity" min="1" max="<?=$p['quantity']?>" value="1" <?=$p['quantity']<=0?'disabled':''?>></label><button class="btn btn-primary w-100" <?=$p['quantity']<=0?'disabled':''?>><i class="fa-solid fa-cart-plus"></i><?=$p['quantity']<=0?'Out of stock':'Add to cart'?></button></form><?php else:?><div class="manager-note"><i class="fa-solid fa-shield-halved"></i>Management account: customer ordering is disabled.</div><?php endif;?></div></article><?php endforeach;?></div><?php endif;?>

<?php if($canOrderMerchandise):?><div class="offcanvas offcanvas-end cart-canvas" tabindex="-1" id="cartCanvas" aria-labelledby="cartCanvasLabel"><div class="offcanvas-header"><div><span class="eyebrow">YOUR CART</span><h5 class="offcanvas-title" id="cartCanvasLabel"><?=$cart['count']?> item<?=$cart['count']==1?'':'s'?></h5></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body d-flex flex-column"><?php if(!$cart['items']):?><div class="empty-state compact flex-grow-1"><i class="fa-solid fa-cart-shopping"></i><h4>Your cart is empty</h4><p>Add products from the shop to continue.</p></div><?php else:?><div class="cart-items flex-grow-1"><?php foreach($cart['items'] as $ci):?><div class="cart-item"><div class="cart-item-visual"><?=product_visual($ci)?></div><div class="cart-item-info"><strong><?=e($ci['name'])?></strong><small>₱<?=number_format($ci['price'],2)?> each</small><form method="post" class="cart-qty-form"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_cart_item"><input type="hidden" name="cart_item_id" value="<?=$ci['id']?>"><input type="number" name="quantity" min="1" max="<?=$ci['quantity']?>" value="<?=$ci['cart_quantity']?>" class="form-control form-control-sm"><button class="btn btn-soft btn-sm">Update</button></form></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="remove_cart_item"><input type="hidden" name="cart_item_id" value="<?=$ci['id']?>"><button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form></div><?php endforeach;?></div><div class="cart-checkout"><div class="cart-total"><span>Total</span><strong>₱<?=number_format($cart['total'],2)?></strong></div><form method="post" id="checkoutForm" data-gcash-mode="<?=e(sportsync_gcash_mode())?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="checkout_cart"><label class="form-label">Payment method</label><div class="payment-methods mb-3"><?php if(sportsync_gcash_enabled()):?><label class="payment-option gcash-option"><input type="radio" name="payment_method" value="GCash" checked><span><i class="fa-solid fa-mobile-screen-button"></i>GCash</span></label><?php endif;?><label class="payment-option"><input type="radio" name="payment_method" value="Cash" <?php if(!sportsync_gcash_enabled()):?>checked<?php endif;?>><span><i class="fa-solid fa-money-bill-wave"></i>Cash</span></label><label class="payment-option"><input type="radio" name="payment_method" value="Card"><span><i class="fa-regular fa-credit-card"></i>Card</span></label></div>
<?php if(sportsync_gcash_enabled()):?>
<div id="gcashPanel" class="gcash-panel mb-3"><div class="gcash-payline"><i class="fa-solid fa-mobile-screen-button"></i><div><strong>Send to <?=e(sportsync_gcash_payline())?></strong><span>Amount: <b>₱<?=number_format($cart['total'],2)?></b></span></div></div>
<?php if(sportsync_gcash_mode()==='manual'):?><ol class="gcash-steps"><li>Open <strong>GCash</strong> and send exactly <strong>₱<?=number_format($cart['total'],2)?></strong> to the number above.</li><li>Copy the <strong>reference number</strong> shown on your GCash receipt.</li><li>Enter it below and check out. Management verifies your payment, then your order is prepared.</li></ol><?php else:?><ol class="gcash-steps"><li>Click <strong>Pay with GCash</strong> below — a secure GCash page opens.</li><li>Approve the payment of <strong>₱<?=number_format($cart['total'],2)?></strong> there.</li><li>You return to Sporty Ni Migo and your order is confirmed automatically.</li></ol><?php endif;?></div>
<?php endif;?>
<div id="paymentReferenceWrap" class="mb-3"><label class="form-label" id="paymentReferenceLabel">GCash reference number</label><input class="form-control" name="payment_reference" id="paymentReference" placeholder="e.g. 9012345678901"><div class="form-text" id="paymentReferenceHint">From your GCash receipt — needed so we can verify your payment.</div></div><label class="form-label">Link order to an event <span class="text-muted">(optional)</span></label><select class="form-select mb-3" name="event_id"><option value="">No event link</option><?php foreach($myEvents as $ev):?><option value="<?=$ev['id']?>"><?=e($ev['title'])?></option><?php endforeach;?></select><button class="btn btn-primary w-100 btn-lg" id="checkoutSubmitBtn"><i class="fa-solid fa-mobile-screen-button" id="checkoutBtnIcon"></i><span id="checkoutBtnLabel"> Pay with GCash · ₱<?=number_format($cart['total'],2)?></span></button></form></div><?php endif;?></div></div><?php endif;?>

<?php if(sportsync_gcash_enabled() && $isManager):?>
<section class="panel mt-4 gcash-verify-panel"><div class="panel-head"><div><span class="eyebrow">GCASH PAYMENTS</span><h3>Payments awaiting verification</h3></div><span class="status-pill status-warning"><?=count($gcashPending)?> pending</span></div><?php if(!$gcashPending):?><div class="empty-state compact"><i class="fa-solid fa-mobile-screen-button"></i><h4>No GCash payments waiting</h4><p>When customers pay via GCash, the reference number appears here for confirmation.</p></div><?php else:foreach($gcashPending as $g):?><div class="order-row gcash-payout-row"><div><strong>Order #<?=$g['order_id']?> · ₱<?=number_format($g['amount'],2)?></strong><small><?=e($g['customer'])?><?php if(trim((string)($g['reference_number']??''))!==''):?> · Ref <?=e($g['reference_number'])?><?php endif;?><?php if(trim((string)($g['sender_mobile']??''))!==''):?> · Sent from <?=e($g['sender_mobile'])?><?php endif;?> · <?=e(date('M d, g:i A',strtotime($g['created_at'])))?></small></div><form method="post" class="d-flex gap-2 flex-wrap"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="gcash_verify_payment"><input type="hidden" name="payout_id" value="<?=$g['id']?>"><button class="btn btn-primary btn-sm" name="decision" value="paid"><i class="fa-solid fa-check"></i>Mark paid</button><button class="btn btn-outline-danger btn-sm" name="decision" value="fail" data-confirm="Reject this GCash payment? The order will be marked as payment failed."><i class="fa-solid fa-xmark"></i>Reject</button></form></div><?php endforeach;endif;?></section>
<?php endif;?>

<section class="panel mt-4"><div class="panel-head"><div><span class="eyebrow">ORDERS</span><h3><?=$isManager?'Recent customer orders':'My recent orders'?></h3></div></div><?php if(!$orders):?><div class="empty-state compact"><i class="fa-solid fa-receipt"></i><h4>No orders yet</h4></div><?php else:foreach($orders as $o):?><?php if(!isset($payoutSeen[(int)$o['id']])):?><div class="order-row"><div><strong><?=e($orderItems[(int)$o['id']]??'Order #'.$o['id'])?></strong><small><?php if($isManager):?><?=e($o['customer'])?> · <?php endif;?><?=e(date('M d, Y · g:i A',strtotime($o['created_at'])))?> · <?=e($o['payment_method']??'Cash')?></small></div><div class="text-end"><strong>₱<?=number_format($o['total_amount'],2)?></strong><div class="small text-muted"><?=e(ucfirst($o['payment_status']??'pending'))?> payment</div><?php if($isManager && $o['payment_method']==='GCash' && $o['payment_status']==='verifying' && isset($payoutByOrder[(int)$o['id']])):?><button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#payoutModal<?=$payoutByOrder[(int)$o['id']]['id']?>"><i class="fa-solid fa-mobile-screen-button"></i>Verify GCash</button><?php endif;?><?php if($isManager):?><form method="post" class="d-flex gap-2 align-items-center mt-1"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="order_status"><input type="hidden" name="order_id" value="<?=$o['id']?>"><select class="form-select form-select-sm" name="status" onchange="this.form.submit()"><option <?=$o['status']==='pending'?'selected':''?>>pending</option><option <?=$o['status']==='confirmed'?'selected':''?>>confirmed</option><option <?=$o['status']==='ready'?'selected':''?>>ready</option><option <?=$o['status']==='completed'?'selected':''?>>completed</option><option <?=$o['status']==='cancelled'?'selected':''?>>cancelled</option></select></form><?php else:?><?=status_badge($o['status'])?><?php endif;?></div></div><?php endif;?><?php endforeach;endif;?></section>
<?php if($isManager):?><div class="modal fade" id="productModal"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><div class="modal-header"><h5 class="modal-title">Add product</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_merch"><label class="form-label">Product name</label><input class="form-control mb-3" name="name" required><label class="form-label">Description</label><textarea class="form-control mb-3" name="description"></textarea><div class="row g-2"><div class="col"><label class="form-label">Price</label><input type="number" min="0" step="0.01" class="form-control" name="price" required></div><div class="col"><label class="form-label">Stock</label><input type="number" min="0" class="form-control" name="stock" required></div></div><label class="form-label mt-3">Product image</label><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp"></div><div class="modal-footer"><button class="btn btn-primary">Save product</button></div></form></div></div></div><?php endif;?>
<?php foreach($gcashPending as $g):if(!trim((string)($g['reference_number']??'')) && !trim((string)($g['sender_mobile']??'')))continue;?><div class="modal fade" id="payoutModal<?=$g['id']?>"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">GCash payment · Order #<?=$g['order_id']?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><dl class="row mb-0"><dt class="col-5">Amount</dt><dd class="col-7"><strong>₱<?=number_format($g['amount'],2)?></strong></dd><dt class="col-5">Customer</dt><dd class="col-7"><?=e($g['customer'])?></dd><dt class="col-5">Reference no.</dt><dd class="col-7"><?=e(trim((string)$g['reference_number'])!==''?$g['reference_number']:'Not provided')?></dd><dt class="col-5">Sender mobile</dt><dd class="col-7"><?=e(trim((string)$g['sender_mobile'])!==''?$g['sender_mobile']:'Not provided')?></dd><dt class="col-5">Submitted</dt><dd class="col-7"><?=e(date('M d, Y · g:i A',strtotime($g['created_at'])))?></dd></dl><p class="form-text mb-0 mt-2">Confirm only after the amount is visible in the shop's GCash account.</p></div><div class="modal-footer"><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="gcash_verify_payment"><input type="hidden" name="payout_id" value="<?=$g['id']?>"><button class="btn btn-outline-danger btn-sm" name="decision" value="fail" data-confirm="Reject this GCash payment?"><i class="fa-solid fa-xmark"></i>Reject</button><button class="btn btn-primary btn-sm" name="decision" value="paid"><i class="fa-solid fa-check"></i>Confirm paid</button></form></div></div></div></div><?php endforeach;?>

<?php elseif($page==='communication'):
$contacts=$pdo->prepare('SELECT u.id,u.full_name,u.email,r.name role_name,(SELECT MAX(created_at) FROM messages m WHERE (m.sender_id=? AND m.receiver_id=u.id) OR (m.sender_id=u.id AND m.receiver_id=?)) last_message FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id<>? AND u.status="active" ORDER BY last_message DESC,u.full_name');$contacts->execute([$u['id'],$u['id'],$u['id']]);$contacts=$contacts->fetchAll();
$contactId=(int)($_GET['contact']??0);$selectedContact=null;foreach($contacts as $c)if((int)$c['id']===$contactId){$selectedContact=$c;break;}
$timeline=[];if($selectedContact){$q=$pdo->prepare('SELECT m.*,s.full_name sender FROM messages m LEFT JOIN users s ON s.id=m.sender_id WHERE ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)) AND m.sender_id IS NOT NULL ORDER BY m.created_at ASC LIMIT 200');$q->execute([$u['id'],$contactId,$contactId,$u['id']]);$timeline=$q->fetchAll();$pdo->prepare('UPDATE messages SET is_read=1,read_at=COALESCE(read_at,NOW()) WHERE receiver_id=? AND sender_id=?')->execute([$u['id'],$contactId]);}
?>
<div class="page-intro"><div><span class="eyebrow">COMMUNICATION PORTAL</span><h2>Messages</h2><p>Chat and send voice messages in one Messenger-style workspace.</p></div></div>
<div class="messenger-shell">
 <aside class="messenger-contacts"><div class="messenger-search"><i class="fa-solid fa-magnifying-glass"></i><input id="contactSearch" type="search" placeholder="Search people"></div><div class="contact-list"><?php if(!$contacts):?><div class="empty-mini">No other active users.</div><?php else:foreach($contacts as $c):?><a href="?page=communication&contact=<?=$c['id']?>" class="contact-item <?=$contactId==(int)$c['id']?'active':''?>"><div class="avatar"><?=e(strtoupper(substr($c['full_name'],0,1)))?></div><div><strong><?=e($c['full_name'])?></strong><span><?=e($c['role_name'])?></span></div><?php if($c['last_message']):?><small><?=e(date('M d',strtotime($c['last_message'])))?></small><?php endif;?></a><?php endforeach;endif;?></div></aside>
 <section class="messenger-chat"><?php if(!$selectedContact):?><div class="messenger-empty"><i class="fa-regular fa-comments"></i><h3>Select a conversation</h3><p>Choose a person from the left to start messaging.</p></div><?php else:?><header class="chat-header"><a href="?page=communication" class="icon-btn d-md-none" aria-label="Back to conversations"><i class="fa-solid fa-arrow-left"></i></a><div class="avatar"><?=e(strtoupper(substr($selectedContact['full_name'],0,1)))?></div><div><strong><?=e($selectedContact['full_name'])?></strong><span><?=e($selectedContact['role_name'])?></span></div><button class="icon-btn" type="button" id="voiceQuickBtn" title="Record voice message"><i class="fa-solid fa-microphone"></i></button></header><div class="chat-thread" id="chatThread"><?php if(!$timeline):?><div class="conversation-start"><i class="fa-regular fa-hand"></i><strong>Start the conversation</strong><span>Send a text or voice message below.</span></div><?php else:foreach($timeline as $r):$mine=(int)$r['sender_id']===(int)$u['id'];?><div class="chat-bubble-wrap <?=$mine?'mine':'theirs'?>"><div class="chat-bubble"><?php if(($r['message_type']??'text')==='text'):?><?php if(!empty($r['subject'])):?><small class="bubble-subject"><?=e($r['subject'])?></small><?php endif;?><p><?=nl2br(e($r['message']))?></p><?php else:?><div class="voice-bubble"><i class="fa-solid fa-microphone-lines"></i><audio controls preload="metadata" src="<?=e($r['file_path'])?>"></audio><span><?=$r['duration_seconds']?>s</span></div><?php endif;?><time><?=e(date('g:i A',strtotime($r['created_at'])))?></time></div></div><?php endforeach;endif;?></div><div class="voice-recorder-inline d-none" id="voiceRecorderInline"><div class="record-status"><span class="record-dot"></span><strong id="voiceTimer">00:00</strong><span id="voiceStatusText">Ready to record</span></div><div class="record-actions"><button type="button" class="btn btn-danger btn-sm" id="voiceRecordBtn"><i class="fa-solid fa-microphone"></i>Record</button><button type="button" class="btn btn-soft btn-sm" id="voiceStopBtn" disabled>Stop</button><button type="button" class="btn btn-primary btn-sm d-none" id="voiceSendBtn">Send voice</button><button type="button" class="btn btn-light btn-sm" id="voiceCancelBtn">Cancel</button></div><audio id="voicePreview" controls class="d-none"></audio><div id="voiceFeedback"></div></div><form method="post" class="chat-composer" id="chatComposer"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="message"><input type="hidden" name="receiver_id" value="<?=$contactId?>"><input type="hidden" name="subject" value=""><button type="button" class="composer-icon" id="composerVoiceBtn" title="Voice message"><i class="fa-solid fa-microphone"></i></button><textarea name="message" rows="1" required placeholder="Type a message..."></textarea><button class="composer-send" title="Send"><i class="fa-solid fa-paper-plane"></i></button></form><?php endif;?></section>
</div>

<?php elseif($page==='community'):
$filter=$_GET['feed']??'all';$where='';$args=[];if(in_array($filter,['event','tournament','community'],true)){$where=' WHERE p.post_type=?';$args[]=$filter;}$sql='SELECT p.*,u.full_name,e.title event_title,t.name tournament_name,(SELECT COUNT(*) FROM post_interactions x WHERE x.post_id=p.id AND x.type="like") likes FROM community_posts p JOIN users u ON u.id=p.user_id LEFT JOIN events e ON e.id=p.event_id LEFT JOIN tournaments t ON t.id=p.tournament_id'.$where.' ORDER BY p.created_at DESC';$q=$pdo->prepare($sql);$q->execute($args);$posts=$q->fetchAll();$shareEvents=$pdo->query('SELECT id,title FROM events WHERE status IN("scheduled","ongoing") ORDER BY start_at DESC LIMIT 30')->fetchAll();$shareTournaments=$pdo->query('SELECT id,name FROM tournaments ORDER BY id DESC LIMIT 30')->fetchAll();$commentsByPost=[];if($posts){$ids=array_map(fn($x)=>(int)$x['id'],$posts);$ph=implode(',',array_fill(0,count($ids),'?'));$cq=$pdo->prepare('SELECT x.*,u.full_name FROM post_interactions x JOIN users u ON u.id=x.user_id WHERE x.post_id IN ('.$ph.') AND x.type="comment" ORDER BY x.created_at ASC');$cq->execute($ids);foreach($cq->fetchAll() as $c)$commentsByPost[(int)$c['post_id']][]=$c;}
?>
<div class="page-intro"><div><span class="eyebrow">SPORTSYNC COMMUNITY</span><h2>Community wall</h2><p>Share tournament news, event updates, photos, results, and activities with the sports community.</p></div></div>
<div class="feed-tabs"><a class="<?=$filter==='all'?'active':''?>" href="?page=community&feed=all">All posts</a><a class="<?=$filter==='event'?'active':''?>" href="?page=community&feed=event">Events</a><a class="<?=$filter==='tournament'?'active':''?>" href="?page=community&feed=tournament">Tournaments</a><a class="<?=$filter==='community'?'active':''?>" href="?page=community&feed=community">Community</a></div>
<div class="community-layout enhanced"><aside><section class="panel composer-card"><div class="composer-head"><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div><div><strong>Share an update</strong><small>Post to the Sporty Ni Migo community</small></div></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="post"><textarea class="form-control mb-3" name="content" rows="4" required placeholder="What is happening in your event or tournament?"></textarea><div class="row g-2"><div class="col-md-6"><select name="post_type" id="postType" class="form-select"><option value="community">Community update</option><option value="event">Event update</option><option value="tournament">Tournament update</option></select></div><div class="col-md-6"><input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp"></div></div><div id="eventLinkWrap" class="d-none mt-2"><select name="event_id" class="form-select"><option value="">Choose linked event</option><?php foreach($shareEvents as $e):?><option value="<?=$e['id']?>"><?=e($e['title'])?></option><?php endforeach;?></select></div><div id="tournamentLinkWrap" class="d-none mt-2"><select name="tournament_id" class="form-select"><option value="">Choose linked tournament</option><?php foreach($shareTournaments as $t):?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php endforeach;?></select></div><button class="btn btn-primary w-100 mt-3"><i class="fa-solid fa-paper-plane"></i>Publish post</button></form></section></aside><section class="community-feed"><?php if(!$posts):?><div class="panel empty-state"><i class="fa-solid fa-people-group"></i><h4>No posts in this feed yet</h4><p>Be the first to share an event, tournament, or community update.</p></div><?php else:foreach($posts as $p):?><article class="panel community-post"><div class="post-head"><div class="avatar"><?=e(strtoupper(substr($p['full_name'],0,1)))?></div><div><strong><?=e($p['full_name'])?></strong><small><?=e(date('M d, Y · g:i A',strtotime($p['created_at'])))?></small></div><span class="post-type"><?=e(ucfirst($p['post_type']))?></span><?php if($isAdmin || (int)$p['user_id']===(int)$u['id']):?><div class="ms-auto d-flex gap-1"><button class="btn btn-sm btn-soft" data-bs-toggle="collapse" data-bs-target="#editPost<?=$p['id']?>" title="Edit post"><i class="fa-solid fa-pen"></i></button><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?=$p['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this post and its comments?" title="Delete post"><i class="fa-solid fa-trash"></i></button></form></div><?php endif;?></div><div class="collapse" id="editPost<?=$p['id']?>"><form method="post" class="edit-inline-card"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_post"><input type="hidden" name="post_id" value="<?=$p['id']?>"><textarea class="form-control" name="content" required><?=e($p['content'])?></textarea><button class="btn btn-primary btn-sm">Save post</button></form></div><?php if($p['event_title']):?><a class="linked-post" href="?page=events"><i class="fa-solid fa-calendar-days"></i><?=e($p['event_title'])?></a><?php endif;?><?php if($p['tournament_name']):?><a class="linked-post" href="?page=tournament"><i class="fa-solid fa-trophy"></i><?=e($p['tournament_name'])?></a><?php endif;?><p class="post-content"><?=nl2br(e($p['content']))?></p><?php if($p['image_path'] && is_file(SPORTSYNC_ROOT.'/'.ltrim($p['image_path'],'/'))):?><img src="<?=e($p['image_path'])?>" class="post-image" alt="Community post image"><?php endif;?><div class="post-actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="like"><input type="hidden" name="post_id" value="<?=$p['id']?>"><button class="btn btn-soft btn-sm"><i class="fa-regular fa-thumbs-up"></i><?=$p['likes']?> Like</button></form><form method="post" class="comment-form"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="post_id" value="<?=$p['id']?>"><input name="content" class="form-control form-control-sm" placeholder="Write a comment..." maxlength="500" required><button class="btn btn-primary btn-sm">Comment</button></form></div><?php $postComments=$commentsByPost[(int)$p['id']]??[];if($postComments):?><div class="comment-list"><?php foreach($postComments as $c):?><div class="comment-item"><div class="comment-avatar"><?=e(strtoupper(substr($c['full_name'],0,1)))?></div><div class="comment-body"><div><strong><?=e($c['full_name'])?></strong><small><?=e(date('M d, g:i A',strtotime($c['created_at'])))?></small></div><p><?=e($c['content'])?></p><?php if($isAdmin || (int)$c['user_id']===(int)$u['id']):?><div class="comment-tools"><button class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#editComment<?=$c['id']?>">Edit</button><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_id" value="<?=$c['id']?>"><button class="btn btn-link text-danger btn-sm p-0" data-confirm="Delete this comment?">Delete</button></form></div><div class="collapse" id="editComment<?=$c['id']?>"><form method="post" class="d-flex gap-2 mt-2"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_comment"><input type="hidden" name="comment_id" value="<?=$c['id']?>"><input class="form-control form-control-sm" name="content" value="<?=e($c['content'])?>" maxlength="500" required><button class="btn btn-primary btn-sm">Save</button></form></div><?php endif;?></div></div><?php endforeach;?></div><?php endif;?></article><?php endforeach;endif;?></section></div>

<?php elseif($page==='announcements'):
$notices=$pdo->query('SELECT a.*,u.full_name FROM announcements a LEFT JOIN users u ON u.id=a.created_by WHERE expires_at IS NULL OR expires_at>NOW() ORDER BY FIELD(a.type,"emergency","urgent","general"),created_at DESC')->fetchAll();$urgentCount=0;foreach($notices as $x)if(in_array($x['type'],['urgent','emergency'],true))$urgentCount++;
?>
<div class="page-intro"><div><span class="eyebrow">IMPORTANT UPDATES</span><h2>Announcements</h2><p>Stay informed about event changes, deadlines, urgent reminders, and emergency instructions.</p></div><?php if(can_role('manage_announcements')):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#announcementModal"><i class="fa-solid fa-plus"></i>New announcement</button><?php endif;?></div>
<div class="announcement-summary"><div><i class="fa-solid fa-bullhorn"></i><span><strong><?=count($notices)?></strong><small>Active notices</small></span></div><div class="urgent"><i class="fa-solid fa-triangle-exclamation"></i><span><strong><?=$urgentCount?></strong><small>Urgent / emergency</small></span></div><?php if(can_role('manage_announcements')):?><div><i class="fa-solid fa-mobile-screen-button"></i><span><strong><?=sportsync_sms_enabled()?'Ready':'Setup'?></strong><small>SMS service</small></span></div><?php endif;?></div>
<?php if(!$notices):?><div class="panel empty-state"><i class="fa-regular fa-bell"></i><h4>No active announcements</h4><p>New reminders and updates will appear here.</p></div><?php else:?><div class="notice-grid"><?php foreach($notices as $n):?><article class="notice-card visual <?=e($n['type'])?>"><div class="notice-banner"><div class="notice-icon"><i class="fa-solid <?=$n['type']==='emergency'?'fa-triangle-exclamation':($n['type']==='urgent'?'fa-bell':'fa-bullhorn')?>"></i></div><div><span><?=e(strtoupper($n['type']))?></span><h3><?=e($n['title'])?></h3></div></div><div class="notice-body"><p><?=nl2br(e($n['body']))?></p><div class="notice-meta"><span><i class="fa-regular fa-user"></i><?=e($n['full_name']??'System')?></span><span><i class="fa-regular fa-clock"></i><?=e(date('M d, Y · g:i A',strtotime($n['created_at'])))?></span><span><i class="fa-solid fa-users"></i><?=e(ucfirst($n['audience']))?></span><?php if($n['sms_total']):?><span><i class="fa-solid fa-mobile-screen-button"></i><?=$n['sms_sent']?>/<?=$n['sms_total']?> SMS accepted</span><?php endif;?></div></div></article><?php endforeach;?></div><?php endif;?>
<?php if(can_role('manage_announcements')):?><div class="modal fade" id="announcementModal"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Publish announcement</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="announcement"><label class="form-label">Title</label><input class="form-control mb-3" name="title" maxlength="180" required><label class="form-label">Message</label><textarea class="form-control mb-3" name="body" rows="5" required></textarea><div class="row g-2"><div class="col-md-6"><label class="form-label">Priority</label><select name="type" id="announcementType" class="form-select"><option value="general">General</option><option value="urgent">Urgent</option><option value="emergency">Emergency</option></select></div><div class="col-md-6"><label class="form-label">Audience</label><select name="audience" class="form-select"><option value="all">All active users</option><option value="participants">Participants & community</option><option value="organizers">Event organizers</option><option value="staff">Staff/coordinators</option><option value="management">Administrators & staff</option></select></div></div><div class="sms-switch"><input class="form-check-input" type="checkbox" name="send_sms" id="sendSms"><label for="sendSms"><strong>Send urgent SMS</strong><small>Available for Urgent and Emergency priorities when SMSGate is configured.</small></label></div><label class="form-label mt-3">Expires</label><input type="datetime-local" name="expires_at" class="form-control"></div><div class="modal-footer"><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i>Publish</button></div></form></div></div><?php endif;?>

<?php elseif($page==='tournament'):
$tours=$pdo->query('SELECT * FROM tournaments ORDER BY id DESC')->fetchAll();$matches=[];foreach($tours as $t){$br=json_decode((string)$t['bracket_json'],true);if(!is_array($br))continue;$tname=$t['name'];foreach(($br['matches']??[]) as $m){$matches[]=['tournament'=>$tname,'round_name'=>$m['round']??'Match','team1'=>$m['team1']??'TBD','team2'=>$m['team2']??'TBD','score1'=>$m['score1']??null,'score2'=>$m['score2']??null,'match_at'=>$m['match_at']??null,'status'=>$m['status']??'scheduled'];}}usort($matches,fn($a,$b)=>strcmp((string)$a['match_at'],(string)$b['match_at']));?>
<div class="page-intro"><div><span class="eyebrow">COMPETITIONS</span><h2>Tournament center</h2><p>Follow brackets and match schedules in clear list and bracket views.</p></div></div><div class="view-switch"><button class="active" data-tour-view="list">List</button><button data-tour-view="bracket">Bracket</button><button data-tour-view="schedule">Schedule</button></div><section id="tour-list" class="panel tour-view"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Tournament</th><th>Format</th><th>Status</th></tr></thead><tbody><?php foreach($tours as $t):?><tr><td><strong><?=e($t['name'])?></strong></td><td><?=e($t['format'])?></td><td><?=status_badge($t['status'])?></td></tr><?php endforeach;?></tbody></table></div></section><section id="tour-bracket" class="panel tour-view d-none"><div class="bracket-grid"><?php foreach($matches as $m):?><article class="match-card"><small><?=e($m['round_name'])?></small><div><strong><?=e($m['team1']??'TBD')?></strong><b><?=e($m['score1']??'-')?></b></div><div><strong><?=e($m['team2']??'TBD')?></strong><b><?=e($m['score2']??'-')?></b></div></article><?php endforeach;?></div></section><section id="tour-schedule" class="panel tour-view d-none"><?php foreach($matches as $m):?><div class="schedule-match"><span><?=e(date('M d, g:i A',strtotime($m['match_at'])))?></span><strong><?=e($m['team1']??'TBD')?> vs <?=e($m['team2']??'TBD')?></strong><small><?=e($m['venue']??'Venue TBA')?></small></div><?php endforeach;?></section>

<?php elseif($page==='participants'):
$regs=$pdo->query('SELECT r.id,r.user_id,r.full_name,r.email,r.team,r.status,r.registered_at,r.event_id,e.title event_title FROM event_registrations r JOIN events e ON e.id=r.event_id ORDER BY r.registered_at DESC')->fetchAll();?>
<div class="page-intro"><div><span class="eyebrow">REGISTRATION</span><h2>Participants</h2><p>Centralized participant records improve accuracy and reduce administrative work.</p></div><?php if($isManager):?><a class="btn btn-soft" href="export.php?type=participants"><i class="fa-solid fa-file-export"></i>Export CSV</a><?php endif;?></div><div class="panel"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Name</th><th>Event</th><th>Team</th><th>Status</th><th>Registered</th></tr></thead><tbody><?php foreach($regs as $r):?><tr><td><strong><?=e($r['full_name'])?></strong><small><?=e($r['email'])?></small></td><td><?=e($r['event_title'])?></td><td><?=e($r['team'])?></td><td><div class="action-row"><?=status_badge($r['status'])?><?php if(can_role('manage_participants')):?><form method="post" class="action-row mt-1"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="registration_status"><input type="hidden" name="registration_id" value="<?=$r['id']?>"><button class="btn btn-soft btn-sm" name="status" value="approved">Approve</button><button class="btn btn-outline-danger btn-sm" name="status" value="rejected">Reject</button></form><?php endif;?></div></td><td><?=e(date('M d, Y',strtotime($r['registered_at'])))?></td></tr><?php endforeach;?></tbody></table></div></div>

<?php elseif($page==='inventory'):
$rows=$pdo->query('SELECT id,name item_name,category,quantity,min_stock,max_stock,unit,location,CASE WHEN quantity<=min_stock THEN "Low Stock" WHEN quantity>max_stock THEN "Overstock" ELSE "Normal" END stock_status FROM items WHERE kind="supply" ORDER BY name')->fetchAll();?>
<div class="page-intro"><div><span class="eyebrow">REAL-TIME CONTROL</span><h2>Inventory monitoring</h2><p>Track shortages and overstocking for supplies, merchandise, and operational materials.</p></div><a href="export.php?type=inventory" class="btn btn-soft"><i class="fa-solid fa-file-export"></i>Export</a></div><div class="resource-grid"><?php foreach($rows as $r):?><article class="inventory-card panel"><div class="inventory-top"><div><h3><?=e($r['item_name'])?></h3><p><?=e($r['category'])?> · <?=e($r['location'])?></p></div><?=status_badge($r['stock_status'])?></div><div class="stock-number"><strong><?=$r['quantity']?></strong><span><?=e($r['unit'])?></span></div><div class="stock-track"><span style="width:<?=min(100,max(4,$r['max_stock']?($r['quantity']/$r['max_stock']*100):0))?>%"></span></div><small>Min <?=$r['min_stock']?> · Max <?=$r['max_stock']?></small></article><?php endforeach;?></div>

<?php elseif($page==='reports' && $isManager):?>
<div class="page-intro"><div><span class="eyebrow">DOCUMENTATION</span><h2>Reports & records</h2><p>Generate digital records for events, participants, inventory, equipment, orders, and sales.</p></div></div><div class="report-grid"><a class="report-card" href="export.php?type=participants"><i class="fa-solid fa-users"></i><div><strong>Participant records</strong><span>CSV export</span></div></a><a class="report-card" href="export.php?type=inventory"><i class="fa-solid fa-boxes-stacked"></i><div><strong>Inventory report</strong><span>CSV export</span></div></a><button class="report-card" onclick="window.print()"><i class="fa-solid fa-print"></i><div><strong>Print current page</strong><span>Save as PDF</span></div></button></div>

<?php elseif($page==='users' && $isAdmin):
$users=$pdo->query('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC')->fetchAll();$roles=$pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();?>
<div class="page-intro"><div><span class="eyebrow">ACCESS CONTROL</span><h2>User accounts</h2><p>Create staff accounts and control active/inactive access.</p></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"><i class="fa-solid fa-user-plus"></i>Add user</button></div><div class="panel"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign in</th><th>Action</th></tr></thead><tbody><?php foreach($users as $x):?><tr><td><strong><?=e($x['full_name'])?></strong><small><?=e($x['email'])?></small></td><td><?=e($x['role_name'])?></td><td><?=status_badge($x['status'])?></td><td><?=e($x['last_login_at']?:'Not recorded')?></td><td><div class="action-row"><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editUser<?=$x['id']?>">Edit</button><?php if($x['id']!=$u['id']):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this user account? Their orders and registrations are removed; audit entries are kept."><i class="fa-solid fa-trash"></i></button></form><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></div><div class="modal fade" id="userModal"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Create user</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_user"><input name="full_name" class="form-control mb-2" placeholder="Full name" required><input type="email" name="email" class="form-control mb-2" placeholder="Email" required><input name="phone" class="form-control mb-2" placeholder="Phone"><input name="address" class="form-control mb-2" placeholder="Home address (for travel estimates)"><input type="password" name="password" class="form-control mb-2" placeholder="Temporary password" required><select name="role_id" class="form-select mb-2"><?php foreach($roles as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select><select name="status" class="form-select"><option>active</option><option>inactive</option></select></div><div class="modal-footer"><button class="btn btn-primary">Create account</button></div></form></div></div>

<?php elseif($page==='manage' && $isAdmin):
$crudVenues=$pdo->query('SELECT * FROM venues ORDER BY name')->fetchAll();
$crudEquipment=$pdo->query('SELECT * FROM equipment ORDER BY name')->fetchAll();
$crudInventory=$pdo->query('SELECT id,name item_name,category,quantity,min_stock,max_stock,unit,location FROM items WHERE kind="supply" ORDER BY name')->fetchAll();
$crudProducts=$pdo->query('SELECT * FROM items WHERE kind="product" ORDER BY name')->fetchAll();
$crudUsers=$pdo->query('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.full_name')->fetchAll();
$crudRoles=$pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
?>
<div class="page-intro"><div><span class="eyebrow">ADMINISTRATION</span><h2>Data management</h2><p>Edit and permanently delete operational records from one protected administrator workspace. Related history is cleaned up automatically.</p></div></div>
<div class="accordion admin-crud" id="adminCrud">
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#crudVenues">Venues (<?=count($crudVenues)?>)</button></h2><div id="crudVenues" class="accordion-collapse collapse show" data-bs-parent="#adminCrud"><div class="accordion-body"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Name</th><th>Capacity</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($crudVenues as $x):?><tr><td><?=e($x['name'])?><small><?=e($x['address'])?></small></td><td><?=$x['capacity']?></td><td><?=status_badge($x['status'])?></td><td><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editVenue<?=$x['id']?>">Edit</button><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_venue"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this venue? Past events keep their records but lose the venue link.">Delete</button></form></td></tr><div class="modal fade" id="editVenue<?=$x['id']?>"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><div class="modal-header"><h5>Edit venue</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_venue"><input type="hidden" name="id" value="<?=$x['id']?>"><div class="row g-3"><div class="col-lg-7"><label class="form-label">Venue name</label><input class="form-control" name="name" value="<?=e($x['name'])?>" required></div><div class="col-lg-5"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['available','maintenance','inactive'] as $st):?><option <?=$x['status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div></div><div class="mb-3"><label class="form-label">Address</label><input class="form-control" name="address" value="<?=e($x['address']??'')?>"></div><div class="mb-3"><label class="form-label">Capacity</label><input type="number" class="form-control" name="capacity" min="0" value="<?=$x['capacity']?>"></div><div class="mb-3"><label class="form-label">Facilities</label><textarea class="form-control" name="facilities" rows="2"><?=e($x['facilities']??'')?></textarea></div><div class="mb-3"><label class="form-label">Layout notes</label><textarea class="form-control" name="layout_notes" rows="2"><?=e($x['layout_notes']??'')?></textarea></div><div class="mb-3"><label class="form-label">Venue photo</label><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp"></div><?php if($x['image_path']&&is_file(SPORTSYNC_ROOT.'/'.ltrim($x['image_path'],'/'))):?><div class="mb-3"><img src="<?=e($x['image_path'])?>" class="venue-thumb" alt="<?=e($x['name'])?>"><label class="form-check-label"><input type="checkbox" name="keep_image" checked> Keep current photo</label></div><?php endif;?><div class="mb-3"><label class="form-label">Pin location on map</label><div id="editVenueMap<?=$x['id']?>" class="venue-map tupi-map" style="height:240px"></div><p class="map-help">Click the map to move the pin. Leave blank to keep the current coordinates.</p><input type="hidden" name="latitude" id="editLat<?=$x['id']?>" value="<?=e($x['latitude'])?>"><input type="hidden" name="longitude" id="editLng<?=$x['id']?>" value="<?=e($x['longitude'])?>"><button type="button" class="btn btn-soft btn-sm" id="clearEditPin<?=$x['id']?>">Clear pin</button></div></div><div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-soft" id="useEditLocation<?=$x['id']?>">Use my location</button><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Save changes</button></div></form></div></div><?php endforeach;?></tbody></table></div></div></div></div>
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#crudEquipment">Equipment (<?=count($crudEquipment)?>)</button></h2><div id="crudEquipment" class="accordion-collapse collapse" data-bs-parent="#adminCrud"><div class="accordion-body"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Name</th><th>Quantity</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($crudEquipment as $x):?><tr><td><?=e($x['name'])?><small><?=e($x['category'])?></small></td><td><?=$x['quantity']?></td><td><?=status_badge($x['status'])?></td><td><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editEq<?=$x['id']?>">Edit</button><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_equipment"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this equipment? Its reservation history is removed too.">Delete</button></form></td></tr><div class="modal fade" id="editEq<?=$x['id']?>"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5>Edit equipment</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_equipment"><input type="hidden" name="id" value="<?=$x['id']?>"><input class="form-control mb-2" name="name" value="<?=e($x['name'])?>" required><input class="form-control mb-2" name="category" value="<?=e($x['category'])?>"><input type="number" class="form-control mb-2" name="quantity" value="<?=$x['quantity']?>"><select class="form-select mb-2" name="condition_status"><?php foreach(['excellent','good','fair','repair'] as $st):?><option <?=$x['condition_status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select><input class="form-control mb-2" name="current_location" value="<?=e($x['current_location'])?>"><select class="form-select" name="status"><?php foreach(['available','checked_out','maintenance'] as $st):?><option <?=$x['status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div><div class="modal-footer"><button class="btn btn-primary">Save changes</button></div></form></div></div><?php endforeach;?></tbody></table></div></div></div></div>
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#crudProducts">Shop products (<?=count($crudProducts)?>)</button></h2><div id="crudProducts" class="accordion-collapse collapse" data-bs-parent="#adminCrud"><div class="accordion-body"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>Actions</th></tr></thead><tbody><?php foreach($crudProducts as $x):?><tr><td><?=e($x['name'])?></td><td>₱<?=number_format($x['price'],2)?></td><td><?=$x['quantity']?></td><td><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editProd<?=$x['id']?>">Edit</button><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_merch"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this product? Past orders keep their item snapshots.">Delete</button></form></td></tr><div class="modal fade" id="editProd<?=$x['id']?>"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><div class="modal-header"><h5>Edit product</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_merch"><input type="hidden" name="id" value="<?=$x['id']?>"><input class="form-control mb-2" name="name" value="<?=e($x['name'])?>" required><textarea class="form-control mb-2" name="description"><?=e($x['description'])?></textarea><input type="number" step="0.01" class="form-control mb-2" name="price" value="<?=$x['price']?>"><input type="number" class="form-control mb-2" name="stock" value="<?=$x['quantity']?>"><select class="form-select mb-2" name="status"><option <?=$x['status']==='active'?'selected':''?>>active</option><option <?=$x['status']==='inactive'?'selected':''?>>inactive</option></select><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp"></div><div class="modal-footer"><button class="btn btn-primary">Save changes</button></div></form></div></div><?php endforeach;?></tbody></table></div></div></div></div>
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#crudInventory">Inventory (<?=count($crudInventory)?>)</button></h2><div id="crudInventory" class="accordion-collapse collapse" data-bs-parent="#adminCrud"><div class="accordion-body"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>Item</th><th>Qty</th><th>Location</th><th>Actions</th></tr></thead><tbody><?php foreach($crudInventory as $x):?><tr><td><?=e($x['item_name'])?><small><?=e($x['category'])?></small></td><td><?=$x['quantity']?> <?=e($x['unit'])?></td><td><?=e($x['location'])?></td><td><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editInv<?=$x['id']?>">Edit</button><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_inventory"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Delete this inventory item?">Delete</button></form></td></tr><div class="modal fade" id="editInv<?=$x['id']?>"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5>Edit inventory item</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_inventory"><input type="hidden" name="id" value="<?=$x['id']?>"><input class="form-control mb-2" name="item_name" value="<?=e($x['item_name'])?>" required><input class="form-control mb-2" name="category" value="<?=e($x['category'])?>"><div class="row g-2"><div class="col"><input type="number" class="form-control" name="quantity" value="<?=$x['quantity']?>"></div><div class="col"><input type="number" class="form-control" name="min_stock" value="<?=$x['min_stock']?>"></div><div class="col"><input type="number" class="form-control" name="max_stock" value="<?=$x['max_stock']?>"></div></div><input class="form-control my-2" name="unit" value="<?=e($x['unit'])?>"><input class="form-control" name="location" value="<?=e($x['location'])?>"></div><div class="modal-footer"><button class="btn btn-primary">Save changes</button></div></form></div></div><?php endforeach;?></tbody></table></div></div></div></div>
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#crudUsers">Users (<?=count($crudUsers)?>)</button></h2><div id="crudUsers" class="accordion-collapse collapse" data-bs-parent="#adminCrud"><div class="accordion-body"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($crudUsers as $x):?><tr><td><?=e($x['full_name'])?><small><?=e($x['email'])?></small></td><td><?=e($x['role_name'])?></td><td><?=status_badge($x['status'])?></td><td><button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#editUser<?=$x['id']?>">Edit</button><?php if($x['id']!=$u['id']):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=$x['id']?>"><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this user account? Their orders and registrations are removed; audit entries are kept.">Delete</button></form><?php endif;?></td></tr><div class="modal fade" id="editUser<?=$x['id']?>"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5>Edit user</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?=$x['id']?>"><input class="form-control mb-2" name="full_name" value="<?=e($x['full_name'])?>" required><input type="email" class="form-control mb-2" name="email" value="<?=e($x['email'])?>" required><input class="form-control mb-2" name="phone" value="<?=e($x['phone'])?>"><input class="form-control mb-2" name="address" value="<?=e($x['address']??'')?>" placeholder="Home address (for travel estimates)"><select name="role_id" class="form-select mb-2"><?php foreach($crudRoles as $r):?><option value="<?=$r['id']?>" <?=$x['role_id']==$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><select name="status" class="form-select"><option <?=$x['status']==='active'?'selected':''?>>active</option><option <?=$x['status']==='inactive'?'selected':''?>>inactive</option></select></div><div class="modal-footer"><button class="btn btn-primary">Save changes</button></div></form></div></div><?php endforeach;?></tbody></table></div></div></div></div>
</div>

<?php elseif($page==='sms' && $isAdmin):
$smsEnabled=sportsync_sms_enabled();
$smsUsers=$pdo->query('SELECT u.id,u.full_name,u.phone,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="active" ORDER BY (u.phone IS NULL OR TRIM(u.phone)=""),u.full_name')->fetchAll();
$withPhone=0;foreach($smsUsers as $x)if(trim((string)$x['phone'])!=='')$withPhone++;
$smsHistory=$pdo->query('SELECT l.*,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.action LIKE "sms_%" ORDER BY l.created_at DESC LIMIT 25')->fetchAll();
?>
<div class="page-intro"><div><span class="eyebrow">ADMINISTRATION</span><h2>SMS notifications</h2><p>Urgent announcements, event reminders, and test messages through the SMSGate SMS API.</p></div></div>
<div class="sms-admin-single">
<section class="panel">
 <div class="panel-head"><div><span class="eyebrow">STATUS</span><h3>Provider connection</h3></div><span class="status-pill <?=$smsEnabled?'status-success':'status-warning'?>"><?=$smsEnabled?'Connected':'Not configured'?></span></div>
 <div class="sms-status-row"><?php
   if($smsEnabled){ echo 'The SMS service is active. Urgent and emergency announcements can reach mobile phones.';
     $dev=sportsync_smsgate_devices();
     if(!$dev['ok']){ echo '</div><div class="sms-status-row sms-warn"><i class="fa-solid fa-triangle-exclamation"></i> Gateway issue: '.e($dev['error']).' Urgent SMS will fail until this is resolved.';
     } else { $on=0; foreach($dev['devices'] as $d){ if($d['online'])$on++; }
       if(count($dev['devices'])===0){ echo '</div><div class="sms-status-row sms-warn"><i class="fa-solid fa-triangle-exclamation"></i> Credentials work, but no Android device is registered to this SMSGate account yet — install the SMSGate app on a phone and sign in with this account to start dispatching.';
       } elseif($on===0){ echo '</div><div class="sms-status-row sms-warn"><i class="fa-solid fa-triangle-exclamation"></i> '.count($dev['devices']).' device(s) registered but none are online right now. Open the SMSGate app on the phone to reconnect.';
       } else { echo '</div><div class="sms-status-row sms-ok"><i class="fa-solid fa-circle-check"></i> '.$on.' of '.count($dev['devices']).' gateway device(s) online and ready to send.';
       }
     }
   } else {
     $missing=[];
     foreach(['SMSGATE_API_URL'=>'API URL','SMSGATE_USERNAME'=>'account username','SMSGATE_PASSWORD'=>'account password','SMS_SENDER'=>'sender label'] as $k=>$lbl){ if(trim((string)sportsync_env($k,''))==='')$missing[]=$lbl; }
     echo 'SMS is not active. Missing: '.e(implode(', ',$missing)).'. Fill the credentials below and set the provider to SMSGate.';
   } ?></div>
 <form method="post" class="row g-3">
  <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_sms_settings">
  <div class="col-12"><label class="form-label">SMS provider</label>
   <div class="payment-methods">
    <label class="payment-option"><input type="radio" name="sms_provider" value="disabled" <?=$smsEnabled?'':'checked'?>> <span><i class="fa-solid fa-plug-circle-xmark"></i>Disabled</span></label>
    <label class="payment-option"><input type="radio" name="sms_provider" value="smsgate" <?=$smsEnabled?'checked':''?>><span><i class="fa-solid fa-mobile-screen-button"></i>SMSGate</span></label>
   </div></div>
  <div class="col-md-6"><label class="form-label">SMSGate API URL</label><input class="form-control" name="smsgate_api_url" placeholder="https://api.sms-gate.app" value="<?=e(sportsync_env('SMSGATE_API_URL',''))?>"><div class="form-text">Use https://api.sms-gate.app for the cloud API, or your local gateway address on the same network.</div></div>
  <div class="col-md-6"><label class="form-label">Account username</label><input class="form-control" name="smsgate_username" placeholder="Your SMSGate Web Dashboard username" value="<?=e(sportsync_env('SMSGATE_USERNAME',''))?>"></div>
  <div class="col-md-6"><label class="form-label">Account password</label><input class="form-control" type="password" name="smsgate_password" placeholder="<?=$smsEnabled?'Stored — type a new password to replace':'Your SMSGate password'?>"></div>
  <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-soft" name="action" value="sms_connection_check"><i class="fa-solid fa-circle-check"></i>Check connection & devices</button></div>
  <div class="col-md-6"><label class="form-label">Sender ID</label><input class="form-control" name="sms_sender" maxlength="15" value="<?=e(sportsync_env('SMS_SENDER','Sporty Ni Migo'))?>"><div class="form-text">Label used in outgoing message text. Recipients see the SIM number of the Android device registered with SMSGate.</div></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i>Save settings</button></div>
 </form>
 <hr class="my-4">
 <form method="post" class="row g-3 align-items-end sms-test-row">
  <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sms_test_send">
  <div class="col-sm-7"><label class="form-label">Send a test message</label><input class="form-control" name="test_phone" placeholder="09171234567" required></div>
  <div class="col-sm-5"><button class="btn btn-soft w-100"><i class="fa-solid fa-paper-plane"></i>Send test SMS</button></div>
 </form>
</section>
<section class="panel sms-broadcast-panel">
 <div class="panel-head"><div><span class="eyebrow">BROADCAST</span><h3>Send SMS to multiple participants</h3></div><span class="status-pill <?=$smsEnabled?'status-success':'status-warning'?>"><?=$smsEnabled?'Ready':'SMS not active'?></span></div>
 <?php
   $bulkEvents=$pdo->query("SELECT id,title FROM events WHERE status<>'cancelled' ORDER BY start_at DESC LIMIT 100")->fetchAll();
   $previewCount=count(sportsync_sms_bulk_recipients($pdo,['audience'=>'all']));
 ?>
 <form method="post" id="bulkSmsForm" class="row g-3">
  <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sms_bulk_send">
  <div class="col-md-6"><label class="form-label">Who receives this message?</label>
   <select class="form-select" name="bulk_audience" id="bulkAudience">
    <option value="all">All active users with a mobile number</option>
    <option value="participants">Participants &amp; community only</option>
    <option value="organizers">Event organizers only</option>
    <option value="staff">Staff / coordinators only</option>
    <option value="management">Administrators &amp; staff only</option>
   </select></div>
  <div class="col-md-6 sms-event-filter" id="bulkEventWrap" style="display:none"><label class="form-label">Registered in event</label>
   <select class="form-select" name="bulk_event_id" id="bulkEventId"><option value="0">— Any event —</option><?php foreach($bulkEvents as $be):?><option value="<?=$be['id']?>"><?=e($be['title'])?></option><?php endforeach;?></select></div>
  <div class="col-md-4" id="bulkRegStatusWrap" style="display:none"><label class="form-label">Registration status</label>
   <select class="form-select" name="bulk_reg_status"><option value="">Any status</option><option value="approved">Approved</option><option value="pending">Pending</option><option value="rejected">Rejected</option></select></div>
  <div class="col-md-4"><label class="form-label">Name / number contains</label><input class="form-control" name="bulk_search" placeholder="e.g. Karabaw or 0917"></div>
  <div class="col-md-4 d-flex align-items-end"><label class="sms-switch w-100 mb-0"><input class="form-check-input" type="checkbox" name="bulk_custom_only" id="bulkCustomOnly" value="1"><span><strong>Classic accounts only</strong><small>Exclude Clerk / Google-linked users</small></span></label></div>
  <div class="col-12"><label class="form-label">Message</label>
   <textarea class="form-control" name="bulk_message" id="bulkMessage" rows="3" required maxlength="400" placeholder="Type the announcement or reminder to text out..."></textarea>
   <div class="d-flex justify-content-between form-text"><span>Sent as "&lt;sender&gt;: &lt;your message&gt;" via your SMSGate device.</span><span id="bulkCharCount">400 left</span></div></div>
  <div class="col-md-8"><label class="form-label">Type SEND to confirm dispatch</label><input class="form-control" name="bulk_confirm" placeholder="SEND" autocomplete="off"></div>
  <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100" data-confirm="Dispatch this SMS broadcast to the filtered recipients now?"><i class="fa-solid fa-paper-plane"></i>Send broadcast</button></div>
 </form>
 <div class="sms-preview mt-2" id="bulkPreview">Loading recipient estimate…</div>
</section>
<script>
(function(){
  var f=document.getElementById('bulkSmsForm'); if(!f) return;
  var aud=document.getElementById('bulkAudience'), ev=document.getElementById('bulkEventId'),
      evWrap=document.getElementById('bulkEventWrap'), rsWrap=document.getElementById('bulkRegStatusWrap'),
      msg=document.getElementById('bulkMessage'), cc=document.getElementById('bulkCharCount'),
      pv=document.getElementById('bulkPreview');
  function syncFilters(){
    var byEvent=(aud.value==='all'||aud.value==='participants')&&ev.value!=='0';
    evWrap.style.display=(aud.value==='all'||aud.value==='participants')?'':'none';
    rsWrap.style.display=byEvent?'':'none';
  }
  aud.addEventListener('change',syncFilters); ev.addEventListener('change',syncFilters);
  function count(){ cc.textContent=(400-msg.value.length)+' left'; }
  msg.addEventListener('input',count); count();
  var timer=null;
  function estimate(){
    var b=new URLSearchParams({action:'sms_bulk_preview',csrf:f.querySelector('input[name=csrf]').value,
      bulk_audience:aud.value,bulk_event_id:ev.value,
      bulk_reg_status:(f.querySelector('[name=bulk_reg_status]')||{}).value||'',
      bulk_custom_only:f.querySelector('[name=bulk_custom_only]').checked?'1':'',
      bulk_search:(f.querySelector('[name=bulk_search]')||{}).value||''});
    fetch(location.pathname,{method:'POST',body:b}).then(function(r){return r.json();}).then(function(d){
      pv.textContent=d.ok?('Estimated recipients right now: '+d.count):(d.error||'Estimate unavailable');
      pv.className='sms-preview mt-2'+(d.ok?(d.count?'':' sms-warn'):' sms-warn');
    }).catch(function(){ pv.textContent='Estimate unavailable'; });
  }
  f.addEventListener('change',function(){ syncFilters(); clearTimeout(timer); timer=setTimeout(estimate,350); });
  f.querySelector('[name=bulk_search]').addEventListener('input',function(){ clearTimeout(timer); timer=setTimeout(estimate,450); });
  syncFilters(); estimate();
})();
</script>
<section class="panel sms-recipients-panel">
 <div class="panel-head"><div><span class="eyebrow">RECIPIENTS</span><h3>Mobile numbers on file</h3></div><span class="status-pill status-primary"><?=$withPhone?>/<?=count($smsUsers)?> reachable</span></div>
 <div class="table-responsive sms-recipients-table"><table class="table modern-table"><thead><tr><th>User</th><th>Role</th><th>Mobile</th></tr></thead><tbody><?php foreach($smsUsers as $x):?><tr><td><strong><?=e($x['full_name'])?></strong></td><td><?=e($x['role_name'])?></td><td><?php if(trim((string)$x['phone'])!==''):?><?=e($x['phone'])?><?php else:?><span class="text-muted">Not set — user adds it on My Profile</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
</section>
<section class="panel sms-log-panel">
 <div class="panel-head"><div><span class="eyebrow">DELIVERY LOG</span><h3>Recent SMS activity</h3></div></div>
 <?php if(!$smsHistory):?><div class="empty-state compact"><i class="fa-solid fa-mobile-screen-button"></i><h4>No SMS activity yet</h4><p>Sent messages and their delivery status will appear here.</p></div><?php else:?><div class="sms-history-list"><?php foreach($smsHistory as $h):?><div class="sms-history-item"><?=str_contains($h['action'],'invalid')||str_contains($h['details'],'status=FAILED')||str_contains($h['details'],'status=REJECTED') ? '<i class="fa-solid fa-circle-xmark bad"></i>' : '<i class="fa-solid fa-circle-check ok"></i>'?><div><strong><?=e($h['full_name']??'Unknown user')?></strong><small><?=e($h['details'])?></small></div><time><?=e(date('M d, g:i A',strtotime($h['created_at'])))?></time></div><?php endforeach;?></div><?php endif;?>
</section>
</div>

<?php elseif($page==='profile'):
$q=$pdo->prepare('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');$q->execute([$u['id']]);$me=$q->fetch();?>
<div class="page-intro"><div><span class="eyebrow">ACCOUNT</span><h2>My profile</h2><p>Keep your personal information current.</p></div></div><div class="profile-layout"><section class="panel profile-card"><div class="profile-avatar"><?=e(strtoupper(substr($me['full_name'],0,1)))?></div><h3><?=e($me['full_name'])?></h3><p><?=e($me['role_name'])?></p><div class="profile-meta"><span><i class="fa-regular fa-envelope"></i><?=e($me['email'])?></span><span><i class="fa-regular fa-clock"></i>Member since <?=e(date('M Y',strtotime($me['created_at'])))?></span></div></section><section class="panel"><h3>Personal information</h3><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_profile"><div class="col-12"><label class="form-label">Full name</label><input class="form-control" name="full_name" value="<?=e($me['full_name'])?>" required></div><div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="<?=e($me['email'])?>" disabled></div><div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?=e($me['phone'])?>"></div><div class="col-12"><label class="form-label">Home address <span class="text-muted small">(used for travel-time estimates in the planner)</span></label><input class="form-control" name="address" maxlength="255" value="<?=e($me['address']??'')?>" placeholder="e.g. Purok Malinis, Brgy. Poblacion, Tupi, South Cotabato"></div><div class="col-12"><button class="btn btn-primary">Save changes</button></div></form></section></div>
<?php endif;?>
</main>
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content logout-modal">
      <div class="modal-body text-center p-4">
        <div class="logout-modal-icon"><i class="fa-solid fa-right-from-bracket"></i></div>
        <h5 id="logoutConfirmTitle" class="mt-3 mb-2">Sign out of Sporty Ni Migo?</h5>
        <p class="text-muted small mb-4">You will need to sign in again to access your Sporty Ni Migo workspace.</p>
        <div class="d-grid gap-2">
          <form method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <button class="btn btn-danger w-100" type="submit"><i class="fa-solid fa-right-from-bracket me-2"></i>Yes, sign out</button>
          </form>
          <button class="btn btn-soft w-100" type="button" data-bs-dismiss="modal">Stay signed in</button>
        </div>
      </div>
    </div>
  </div>
</div>
<footer class="app-footer"><span>© <?=date('Y')?> Sporty Ni Migo · Sports Event Logistics & Community Platform</span><span><i class="fa-solid fa-shield-halved"></i>Role-secured workspace</span></footer>
<nav class="mobile-bottom-nav" aria-label="Quick navigation">
 <a href="?page=dashboard" class="<?=$page==='dashboard'?'active':''?>"><i class="fa-solid fa-house"></i><span>Home</span></a>
 <?php if($canPlanEvent):?><a href="?page=plan" class="<?=$page==='plan'?'active':''?>"><i class="fa-solid fa-calendar-plus"></i><span>Plan</span></a><?php else:?><a href="?page=events" class="<?=$page==='events'?'active':''?>"><i class="fa-solid fa-calendar-check"></i><span>Manage</span></a><?php endif;?>
 <a href="?page=events" class="<?=$page==='events'?'active':''?>"><i class="fa-solid fa-calendar-days"></i><span>Events</span></a>
 <a href="?page=communication" class="<?=$page==='communication'?'active':''?>"><i class="fa-solid fa-comments"></i><span>Messages</span></a>
 <a href="?page=profile" class="<?=$page==='profile'?'active':''?>"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/app.js?v=20261002b"></script>
<script>window.SPORTSYNC={csrf:'<?=csrf_token()?>'};</script>
<script>
(function(){
  var form=document.getElementById('checkoutForm');if(!form)return;
  var gcashMode=form.dataset.gcashMode||'manual';
  var radios=form.querySelectorAll('input[name=payment_method]');
  var gcashPanel=document.getElementById('gcashPanel');
  var refWrap=document.getElementById('paymentReferenceWrap');
  var refInput=document.getElementById('paymentReference');
  var refLabel=document.getElementById('paymentReferenceLabel');
  var refHint=document.getElementById('paymentReferenceHint');
  var btnLabel=document.getElementById('checkoutBtnLabel');
  var btnIcon=document.getElementById('checkoutBtnIcon');
  var btnTotal=document.querySelector('.cart-total strong');
  function selected(){for(var i=0;i<radios.length;i++)if(radios[i].checked)return radios[i].value;return 'Cash';}
  function sync(){
    var v=selected();
    if(gcashPanel)gcashPanel.style.display=(v==='GCash')?'':'none';
    if(v==='GCash'&&gcashMode==='auto'){
      if(refWrap)refWrap.classList.add('d-none');
      if(btnLabel)btnLabel.textContent=' Pay with GCash · '+(btnTotal?btnTotal.textContent:'');
      if(btnIcon)btnIcon.className='fa-solid fa-mobile-screen-button';
    } else if(v==='GCash'&&gcashMode==='manual'){
      if(refWrap){refWrap.classList.remove('d-none');if(refLabel)refLabel.textContent='GCash reference number';if(refHint)refHint.textContent='From your GCash receipt — needed so we can verify your payment.';}
      if(btnLabel)btnLabel.textContent=' Submit GCash payment · '+(btnTotal?btnTotal.textContent:'');
      if(btnIcon)btnIcon.className='fa-solid fa-mobile-screen-button';
    } else {
      if(refWrap)refWrap.classList.remove('d-none');
      if(refLabel)refLabel.textContent=v+' reference (optional)';
      if(refHint)refHint.textContent='Sporty Ni Migo stores the reference only. It does not collect card numbers.';
      if(btnLabel)btnLabel.textContent=' Checkout · '+(btnTotal?btnTotal.textContent:'');
      if(btnIcon)btnIcon.className=v==='Cash'?'fa-solid fa-money-bill-wave':'fa-regular fa-credit-card';
    }
  }
  radios.forEach(function(r){r.addEventListener('change',sync);});
  form.addEventListener('submit',function(e){
    var v=selected();
    if(v==='GCash'&&gcashMode==='manual'&&(refInput&&refInput.value.trim()==='')){
      e.preventDefault();if(refInput)refInput.focus();return;
    }
    if(v==='GCash'&&gcashMode==='auto'){
      var b=document.getElementById('checkoutSubmitBtn');
      if(b){b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i><span> Opening GCash…</span>';}
    }
  });
  sync();
})();
</script>
