<?php
require 'config/db.php';
require 'config/auth.php';
require_login();
require_role(['Administrator']);
require 'config/sms.php';

$checks=[];
function add_check(array &$checks,string $name,bool $ok,string $detail): void {$checks[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail];}
add_check($checks,'HTTPS',sportsync_is_https(),sportsync_is_https()?'Secure HTTPS detected.':'HTTPS is not detected. Enable SSL before production use.');
add_check($checks,'Production mode',APP_ENV==='production' && !APP_DEBUG,(APP_ENV==='production'&&!APP_DEBUG)?'Production error handling is enabled.':'Set APP_ENV=production and APP_DEBUG=0.');
add_check($checks,'Configured application URL',APP_URL!=='',APP_URL!==''?APP_URL:'Set APP_URL in .env to the public HTTPS URL.');
try{$pdo->query('SELECT 1')->fetchColumn();add_check($checks,'Database',true,'MySQL connection successful.');}catch(Throwable $e){add_check($checks,'Database',false,'Database connection failed.');}
foreach(['uploads','uploads/community','uploads/merchandise','uploads/voice','storage/logs'] as $dir){$path=SPORTSYNC_ROOT.'/'.$dir;add_check($checks,'Writable '.$dir,is_dir($path)&&is_writable($path),is_dir($path)&&is_writable($path)?'Writable.':'Create the directory and grant the web server write permission.');}
add_check($checks,'Fileinfo extension',extension_loaded('fileinfo'),'Required for secure upload MIME validation.');
add_check($checks,'PDO MySQL extension',extension_loaded('pdo_mysql'),'Required for MySQL access.');
add_check($checks,'OpenSSL extension',extension_loaded('openssl'),'Recommended for secure online operation.');
add_check($checks,'Mail sender configured',(bool)sportsync_env('MAIL_FROM',''),sportsync_env('MAIL_FROM','')?'MAIL_FROM is configured.':'Set MAIL_FROM and configure PHP mail delivery on your host.');
add_check($checks,'cURL extension',extension_loaded('curl'),'Required for SMSGate SMS delivery.');
add_check($checks,'Urgent SMS provider',sportsync_sms_enabled(),sportsync_sms_enabled()?'SMSGate SMS is configured.':'Set SMS_PROVIDER=smsgate, SMSGATE_API_URL, SMSGATE_USERNAME, SMSGATE_PASSWORD, and SMS_SENDER in .env.');
add_check($checks,'Reminder cron token',(bool)sportsync_env('CRON_TOKEN',''),sportsync_env('CRON_TOKEN','')?'CRON_TOKEN is configured for optional web-triggered reminders.':'Set a long random CRON_TOKEN, or use CLI cron only.');
$allOk=!in_array(false,array_column($checks,'ok'),true);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production Check · Sporty Ni Migo</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/app.css"></head><body><main class="container py-5" style="max-width:900px"><div class="d-flex justify-content-between align-items-start gap-3 mb-4"><div><span class="eyebrow">ADMINISTRATOR TOOL</span><h1 class="h3">Online production readiness</h1><p class="text-muted">Run this after deployment. It does not display database passwords or other secrets.</p></div><a href="index.php" class="btn btn-outline-primary">Back to Sporty Ni Migo</a></div><div class="alert <?=$allOk?'alert-success':'alert-warning'?>"><strong><?=$allOk?'Core checks passed.':'Some production checks need attention.'?></strong></div><div class="card border-0 shadow-sm"><div class="list-group list-group-flush"><?php foreach($checks as $c):?><div class="list-group-item py-3 d-flex gap-3"><div class="fs-4 <?=$c['ok']?'text-success':'text-danger'?>"><?=$c['ok']?'✓':'!'?></div><div><strong><?=e($c['name'])?></strong><div class="text-muted small"><?=e($c['detail'])?></div></div></div><?php endforeach;?></div></div></main></body></html>
