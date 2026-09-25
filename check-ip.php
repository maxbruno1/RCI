<?php

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN
|--------------------------------------------------------------------------
*/

$proxyHost = getenv('PROXY_HOST') ?: '';
$proxyPort = (int)(getenv('PROXY_PORT') ?: 0);
$proxyUser = getenv('PROXY_USER') ?: '';
$proxyPass = getenv('PROXY_PASS') ?: '';

$ipService = 'https://api.ipify.org?format=json';


/*
|--------------------------------------------------------------------------
| FUNCIÓN DE PRUEBA
|--------------------------------------------------------------------------
*/

function testConnection(
    string $name,
    string $url,
    bool $useProxy,
    string $proxyHost = '',
    int $proxyPort = 0,
    string $proxyUser = '',
    string $proxyPass = ''
): array {

    $start = microtime(true);

    $ch = curl_init();

    $options = [

        CURLOPT_URL =>
            $url,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_TIMEOUT =>
            20,

        CURLOPT_CONNECTTIMEOUT =>
            10,

        CURLOPT_IPRESOLVE =>
            CURL_IPRESOLVE_V4,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Railway-IP-Diagnostic/1.0',
        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | PROXY
    |--------------------------------------------------------------------------
    */

    if ($useProxy) {

        if (
            $proxyHost === '' ||
            $proxyPort <= 0
        ) {

            return [

                'test' => $name,

                'success' => false,

                'error' =>
                    'Proxy no configurado correctamente.',

                'proxy_host' =>
                    $proxyHost ?: null,

                'proxy_port' =>
                    $proxyPort ?: null,

            ];
        }


        $options[CURLOPT_PROXY] =
            $proxyHost;

        $options[CURLOPT_PROXYPORT] =
            $proxyPort;

        $options[CURLOPT_PROXYTYPE] =
            CURLPROXY_HTTP;


        if (
            $proxyUser !== '' &&
            $proxyPass !== ''
        ) {

            $options[CURLOPT_PROXYUSERPWD] =
                $proxyUser . ':' . $proxyPass;
        }
    }


    curl_setopt_array(
        $ch,
        $options
    );


    /*
    |--------------------------------------------------------------------------
    | EJECUTAR
    |--------------------------------------------------------------------------
    */

    $response =
        curl_exec($ch);


    $elapsed =
        round(
            (microtime(true) - $start) * 1000,
            2
        );


    /*
    |--------------------------------------------------------------------------
    | INFORMACIÓN
    |--------------------------------------------------------------------------
    */

    $errno =
        curl_errno($ch);

    $error =
        curl_error($ch);

    $info =
        curl_getinfo($ch);


    curl_close($ch);


    /*
    |--------------------------------------------------------------------------
    | DECODIFICAR IP
    |--------------------------------------------------------------------------
    */

    $decoded = null;

    if ($response !== false) {

        $decoded =
            json_decode(
                $response,
                true
            );
    }


    /*
    |--------------------------------------------------------------------------
    | RESULTADO
    |--------------------------------------------------------------------------
    */

    return [

        'test' =>
            $name,

        'success' =>
            $response !== false &&
            $errno === 0,

        'proxy_used' =>
            $useProxy,

        'proxy_host' =>
            $useProxy
                ? $proxyHost
                : null,

        'proxy_port' =>
            $useProxy
                ? $proxyPort
                : null,

        'proxy_credentials' =>
            $useProxy
                ? (
                    $proxyUser !== '' &&
                    $proxyPass !== ''
                        ? 'configured'
                        : 'missing'
                )
                : null,

        'public_ip' =>
            $decoded['ip'] ?? null,

        'raw_response' =>
            $response !== false
                ? substr($response, 0, 500)
                : null,

        'http_code' =>
            $info['http_code'] ?? null,

        'curl_errno' =>
            $errno,

        'curl_error' =>
            $error ?: null,

        'primary_ip' =>
            $info['primary_ip'] ?? null,

        'local_ip' =>
            $info['local_ip'] ?? null,

        'namelookup_time' =>
            $info['namelookup_time'] ?? null,

        'connect_time' =>
            $info['connect_time'] ?? null,

        'total_time' =>
            $info['total_time'] ?? null,

        'duration_ms' =>
            $elapsed,

    ];
}


/*
|--------------------------------------------------------------------------
| INFORMACIÓN DEL SERVIDOR RAILWAY
|--------------------------------------------------------------------------
*/

$serverInfo = [

    'php_version' =>
        PHP_VERSION,

    'server_software' =>
        $_SERVER['SERVER_SOFTWARE'] ?? null,

    'server_address' =>
        $_SERVER['SERVER_ADDR'] ?? null,

    'remote_address' =>
        $_SERVER['REMOTE_ADDR'] ?? null,

    'hostname' =>
        gethostname(),

    'proxy_configured' =>
        $proxyHost !== '' &&
        $proxyPort > 0,

];


/*
|--------------------------------------------------------------------------
| PRUEBA 1
|--------------------------------------------------------------------------
|
| Railway → Internet
|
*/

$direct = testConnection(
    'Railway directo',
    $ipService,
    false
);


/*
|--------------------------------------------------------------------------
| PRUEBA 2
|--------------------------------------------------------------------------
|
| Railway → Proxy colombiano → Internet
|
*/

$proxy = testConnection(
    'Railway → Proxy',
    $ipService,
    true,
    $proxyHost,
    $proxyPort,
    $proxyUser,
    $proxyPass
);


/*
|--------------------------------------------------------------------------
| COMPARACIÓN
|--------------------------------------------------------------------------
*/

$comparison = [

    'direct_ip' =>
        $direct['public_ip'] ?? null,

    'proxy_ip' =>
        $proxy['public_ip'] ?? null,

    'different_ips' =>
        !empty($direct['public_ip']) &&
        !empty($proxy['public_ip']) &&
        $direct['public_ip'] !== $proxy['public_ip'],

    'proxy_working' =>
        ($proxy['success'] ?? false) &&
        !empty($proxy['public_ip']),

];


/*
|--------------------------------------------------------------------------
| RESULTADO FINAL
|--------------------------------------------------------------------------
*/

$result = [

    'timestamp' =>
        date('c'),

    'server' =>
        $serverInfo,

    'tests' => [

        'direct' =>
            $direct,

        'proxy' =>
            $proxy,

    ],

    'comparison' =>
        $comparison,

];


/*
|--------------------------------------------------------------------------
| LOG LOCAL
|--------------------------------------------------------------------------
*/

$logDir =
    __DIR__ . '/logs';

if (!is_dir($logDir)) {

    @mkdir(
        $logDir,
        0775,
        true
    );
}


$logFile =
    $logDir . '/check-ip.log';


/*
 * Guardamos el diagnóstico pero nunca
 * la contraseña del proxy.
 */

@file_put_contents(
    $logFile,
    json_encode(
        $result,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT
    )
    . PHP_EOL,
    FILE_APPEND | LOCK_EX
);


/*
|--------------------------------------------------------------------------
| RESPUESTA
|--------------------------------------------------------------------------
*/

echo json_encode(
    $result,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_PRETTY_PRINT
);
