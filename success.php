<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/payment_utils.php';

fc_send_no_cache_headers();

$orderId = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($orderId === '' || !preg_match('/^[A-Z0-9-]{10,}$/', $orderId)) {
    fc_render_error_page(
        400,
        'Missing order reference',
        'We could not verify your PayPal order.',
        'The success page was opened without a valid order reference. Please return to the shop and try again, or contact support if your payment went through.',
        null,
        '/shop.html',
        'Return to shop'
    );
    exit;
}

try {
    $accessToken = fc_get_paypal_access_token();
    $captureResponse = fc_capture_paypal_order($orderId, $accessToken);
    $capturePayload = is_array($captureResponse['data']) ? $captureResponse['data'] : [];

    if (!fc_is_completed_status($capturePayload)) {
        if (fc_is_already_captured_response($capturePayload)) {
            $orderResponse = fc_get_paypal_order($orderId, $accessToken);
            $orderPayload = is_array($orderResponse['data']) ? $orderResponse['data'] : [];
            if (!fc_is_completed_status($orderPayload)) {
                throw new RuntimeException('PayPal reported the order as already captured, but the order status was not completed. Raw response: ' . $orderResponse['raw']);
            }
            $capturePayload = $orderPayload;
        } else {
            throw new RuntimeException('PayPal capture did not complete successfully. Raw response: ' . $captureResponse['raw']);
        }
    }

    $purchaseUnit = $capturePayload['purchase_units'][0] ?? [];
    $captures = $purchaseUnit['payments']['captures'][0] ?? [];
    $supportOrderId = (string) ($capturePayload['id'] ?? $orderId);

    if (($capturePayload['status'] ?? '') !== 'COMPLETED') {
        throw new RuntimeException('Capture response status was not COMPLETED. Raw response: ' . json_encode($capturePayload, JSON_UNESCAPED_SLASHES));
    }

    $downloadExpiry = time() + 3600;
    $bookToken = fc_generate_download_token('book', $downloadExpiry, $supportOrderId);
    $audioToken = fc_generate_download_token('audio', $downloadExpiry, $supportOrderId);

    $bookLink = '/download.php?file=book&expires=' . $downloadExpiry . '&token=' . rawurlencode($bookToken);
    $audioLink = '/download.php?file=audio&expires=' . $downloadExpiry . '&token=' . rawurlencode($audioToken);
    $amount = (string) ($captures['amount']['value'] ?? PRICE);
    $currency = (string) ($captures['amount']['currency_code'] ?? CURRENCY);
    $payerName = trim((string) (($capturePayload['payer']['name']['given_name'] ?? '') . ' ' . ($capturePayload['payer']['name']['surname'] ?? '')));
    $payerName = $payerName !== '' ? htmlspecialchars($payerName, ENT_QUOTES, 'UTF-8') : 'friend';
} catch (Throwable $exception) {
    fc_log_error('success.php failed', [
        'order_id' => $orderId,
        'error' => $exception->getMessage(),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    fc_render_error_page(
        503,
        'Payment not completed',
        'We could not confirm your PayPal payment.',
        'Your order may still be processing. Please wait a moment and refresh the page once. If the issue continues, contact support and include the order reference below.',
        $orderId,
        '/contact.html',
        'Contact support'
    );
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="referrer" content="no-referrer" />
  <title>Fairyland Cottage - Download your purchase</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Slab:wght@300;400;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --main-color: #ded5cd;
      --secondary-color: #f3eae2;
      --font-color1: #41462d;
      --font-color2: #e9ead6;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Josefin Slab', serif;
      color: var(--font-color1);
      background:
        radial-gradient(circle at top left, rgba(233, 234, 214, 0.9), transparent 38%),
        radial-gradient(circle at bottom right, rgba(222, 213, 205, 0.9), transparent 36%),
        linear-gradient(160deg, #fbf6f1 0%, #f3eae2 45%, #ded5cd 100%);
    }

    .page {
      width: min(1100px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 42px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 14px 18px;
      border: 1px solid rgba(65, 70, 45, 0.16);
      border-radius: 24px;
      background: rgba(243, 234, 226, 0.75);
      backdrop-filter: blur(8px);
      box-shadow: 0 12px 36px rgba(65, 70, 45, 0.08);
      margin-bottom: 24px;
    }

    .brand-mark {
      width: 58px;
      height: 58px;
      border-radius: 18px;
      background: linear-gradient(135deg, var(--font-color1), #667149);
      display: grid;
      place-items: center;
      color: var(--font-color2);
      font-size: 1.8rem;
      flex: 0 0 auto;
    }

    .brand h1 {
      margin: 0;
      font-size: clamp(1.8rem, 3vw, 3rem);
      letter-spacing: 0.18em;
      text-transform: uppercase;
      line-height: 1.05;
    }

    .brand p {
      margin: 4px 0 0;
      font-size: 1rem;
      letter-spacing: 0.08em;
    }

    .card {
      display: grid;
      gap: 22px;
      grid-template-columns: minmax(0, 1.2fr) minmax(260px, 0.8fr);
      background: rgba(243, 234, 226, 0.92);
      border: 1px solid rgba(65, 70, 45, 0.16);
      border-radius: 28px;
      padding: 28px;
      box-shadow: 0 18px 50px rgba(65, 70, 45, 0.12);
    }

    .card h2 {
      margin: 0 0 12px;
      font-size: clamp(2rem, 4vw, 3.4rem);
      line-height: 1.08;
    }

    .card p {
      margin: 0 0 14px;
      font-size: 1.12rem;
      line-height: 1.65;
      max-width: 55ch;
    }

    .label {
      display: inline-block;
      margin-bottom: 14px;
      padding: 8px 14px;
      border-radius: 999px;
      background: var(--main-color);
      border: 1px solid rgba(65, 70, 45, 0.15);
      text-transform: uppercase;
      letter-spacing: 0.15em;
      font-weight: 700;
      font-size: 0.8rem;
    }

    .details {
      margin: 18px 0 24px;
      padding: 0;
      list-style: none;
    }

    .details li {
      position: relative;
      padding-left: 1.4rem;
      margin-bottom: 10px;
      font-size: 1.05rem;
      line-height: 1.5;
    }

    .details li::before {
      content: '•';
      position: absolute;
      left: 0;
      color: var(--font-color1);
      font-size: 1.2rem;
      top: -0.05rem;
    }

    .price-row {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      margin-top: 8px;
      margin-bottom: 18px;
    }

    .price {
      font-size: clamp(2rem, 4vw, 3rem);
      font-weight: 700;
      letter-spacing: 0.08em;
    }

    .buy-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-width: 220px;
      padding: 14px 22px;
      border-radius: 999px;
      border: 1px solid var(--font-color1);
      background: var(--font-color1);
      color: var(--font-color2);
      text-decoration: none;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-weight: 700;
      box-shadow: 0 12px 28px rgba(65, 70, 45, 0.18);
      transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
    }

    .buy-button:hover {
      transform: translateY(-1px);
      background: var(--main-color);
      color: var(--font-color1);
    }

    .paypal-note {
      margin-top: 14px;
      font-size: 0.95rem;
      color: rgba(65, 70, 45, 0.9);
    }

    .support-box {
      align-self: stretch;
      background: rgba(222, 213, 205, 0.55);
      border-radius: 24px;
      padding: 20px;
      border: 1px solid rgba(65, 70, 45, 0.12);
    }

    .support-box h3 {
      margin: 0 0 12px;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 1.05rem;
    }

    .reference {
      word-break: break-all;
      font-size: 0.95rem;
      line-height: 1.5;
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(243, 234, 226, 0.95);
      border: 1px solid rgba(65, 70, 45, 0.12);
      margin-bottom: 16px;
    }

    .download-list {
      display: grid;
      gap: 14px;
      margin-top: 16px;
    }

    .download-link {
      display: block;
      text-align: center;
      padding: 14px 18px;
      border-radius: 18px;
      text-decoration: none;
      background: #e9ead6;
      color: var(--font-color1);
      border: 1px solid rgba(65, 70, 45, 0.16);
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .download-link:hover {
      background: var(--font-color1);
      color: var(--font-color2);
    }

    .expiry {
      margin-top: 16px;
      font-size: 0.95rem;
    }

    .support-link {
      color: var(--font-color1);
      font-weight: 700;
    }

    @media (max-width: 860px) {
      .card {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 620px) {
      .page {
        width: min(100% - 20px, 100%);
        padding-top: 10px;
      }

      .brand {
        padding: 12px 14px;
      }

      .brand-mark {
        width: 48px;
        height: 48px;
        border-radius: 16px;
      }

      .card {
        padding: 22px 18px;
        border-radius: 22px;
      }
    }
  </style>
</head>
<body>
  <div class="page">
    <header class="brand">
      <div class="brand-mark">✿</div>
      <div>
        <h1>Fairyland Cottage</h1>
        <p>Secure digital downloads</p>
      </div>
    </header>

    <main class="card">
      <section>
        <span class="label">Payment complete</span>
        <h2>Thank you, <?php echo $payerName; ?>.</h2>
        <p>Your PayPal payment for <strong><?php echo htmlspecialchars(PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?></strong> was completed successfully.</p>
        <p>You can now download both files below. Each secure link expires in one hour, and you can return to this page using your order reference if you need support.</p>

        <ul class="details">
          <li>Includes the PDF ebook and the WAV audio book</li>
          <li>Secure HMAC download links generated just for your order</li>
          <li>Files are delivered directly from the private downloads folder</li>
        </ul>

        <div class="price-row">
          <div class="price"><?php echo htmlspecialchars(CURRENCY, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(PRICE, ENT_QUOTES, 'UTF-8'); ?></div>
          <a class="buy-button" href="/contact.html">Need help? Contact us</a>
        </div>

        <p class="paypal-note">Your payment was processed through PayPal and the order reference is shown in the support panel.</p>
      </section>

      <aside class="support-box">
        <h3>Order reference</h3>
        <div class="reference"><?php echo htmlspecialchars($supportOrderId, ENT_QUOTES, 'UTF-8'); ?></div>
        <h3>Download links</h3>
        <div class="download-list">
          <a class="download-link" href="<?php echo htmlspecialchars($bookLink, ENT_QUOTES, 'UTF-8'); ?>">Download PDF ebook</a>
          <a class="download-link" href="<?php echo htmlspecialchars($audioLink, ENT_QUOTES, 'UTF-8'); ?>">Download WAV audio book</a>
        </div>
        <p class="expiry">These links expire in 1 hour. If you need new links, please use the <a class="support-link" href="/contact.html">contact page</a> and include your order reference.</p>
      </aside>
    </main>
  </div>
</body>
</html>