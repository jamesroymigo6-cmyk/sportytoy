<?php
require 'config/db.php'; require 'config/auth.php'; require_api_login(); validate_session_user($pdo, true); header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
try{
 if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')) throw new Exception('Invalid security token. Refresh and try again.');
 if(empty($_FILES['audio'])||$_FILES['audio']['error']!==UPLOAD_ERR_OK) throw new Exception('No audio received.');
 if($_FILES['audio']['size']>8*1024*1024) throw new Exception('Audio exceeds the 8 MB limit.');
 $receiver=(int)($_POST['receiver_id']??0);$q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND status="active"');$q->execute([$receiver]);if(!$receiver||!$q->fetchColumn())throw new Exception('Choose a valid active recipient.');
 $mime=class_exists('finfo')?(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['audio']['tmp_name']):($_FILES['audio']['type']??'application/octet-stream');$allowed=['audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','application/octet-stream'=>'webm'];if(!isset($allowed[$mime]))throw new Exception('Unsupported audio format: '.($mime?:'unknown').'. Try recording again in Chrome, Edge, or Firefox.');
 if(!is_writable(SPORTSYNC_ROOT.'/uploads') && !is_writable(SPORTSYNC_ROOT)) throw new Exception('Voice messages are disabled on this hosting environment. Audio storage needs a host with persistent storage.');
 $publicDir='uploads/voice';$dir=SPORTSYNC_ROOT.'/'.$publicDir;if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir))throw new Exception('Voice upload folder is unavailable.');$file=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$absolute=$dir.'/'.$file;if(!move_uploaded_file($_FILES['audio']['tmp_name'],$absolute))throw new Exception('Unable to save the recording.');@chmod($absolute,0644);$name=$publicDir.'/'.$file;
 $duration=max(0,min(3600,(int)($_POST['duration']??0)));$uid=(int)current_user()['id'];
 // Voice messages are stored in the unified messages table (message_type = 'voice').
 $stmt=$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,message,file_path,duration_seconds) VALUES(?,?, "voice", "Voice message", ?, ?)');
 $stmt->execute([$uid,$receiver,$name,$duration]);
 if($stmt->rowCount()!==1)throw new Exception('The voice message could not be saved.');
 try{$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,message_type,subject,message) VALUES(NULL,?,"text","New voice message",?)')->execute([$receiver,current_user()['name'].' sent you a voice message.']);}catch(Throwable $notifyError){sportsync_log($notifyError);}
 try{$pdo->prepare('INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)')->execute([$uid,'voice_message_sent','Voice message sent to user #'.$receiver]);}catch(Throwable $logError){sportsync_log($logError);}
 echo json_encode(['ok'=>true,'message'=>'Voice message sent.']);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>sportsync_public_error($e)]);}
