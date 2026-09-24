<?php

define('TELEGRAM_BOT_TOKEN', '8962443252:AAEHMV-5lO10UDEqhGoHAUlYvzHhsa9Wyu4');
define('TELEGRAM_CHAT_ID', '-5315353875');

/**
 * Envía un evento con sus datos al chat de Telegram configurado.
 * No lanza errores si Telegram falla: el logging nunca debe romper el flujo de pago.
 */
function telegram_log(string $title, array $fields = []): void {
    $lines = ['*' . telegram_escape($title) . '*'];

    foreach ($fields as $key => $value) {
        if ($value === null || $value === '') {
            $value = '—';
        }
        $lines[] = '*' . telegram_escape((string) $key) . ':* ' . telegram_escape((string) $value);
    }

    $lines[] = '_' . date('Y-m-d H:i:s') . '_';

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'                  => TELEGRAM_CHAT_ID,
            'text'                     => implode("\n", $lines),
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function telegram_escape(string $text): string {
    return str_replace(['*', '_', '`', '['], '', $text);
}
