<?php
require 'config/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo->query('SELECT 1')->fetchColumn();
    echo json_encode(['ok'=>true,'service'=>'SportSync','database'=>'connected','time'=>date(DATE_ATOM)]);
} catch (Throwable $e) {
    sportsync_log($e);
    http_response_code(503);
    echo json_encode(['ok'=>false,'service'=>'SportSync','database'=>'unavailable']);
}
