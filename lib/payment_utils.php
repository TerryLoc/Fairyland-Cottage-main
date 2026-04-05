<?php
declare(strict_types=1);

function fc_ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function fc_send_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
}

function fc_log_error(string $message, array $context = []): void
{
    $logDirectory = __DIR__ . '/../logs';
    fc_ensure_directory($logDirectory);

    $line = '[' . gmdate('c') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    file_put_contents($logDirectory . '/paypal_errors.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function fc_render_error_page(int $statusCode, string $title, string $headline, string $message, ?string $orderId = null, string $ctaHref = '/contact.html', string $ctaLabel = 'Contact support'): void
{
    fc_send_no_cache_headers();
    http_response_code($statusCode);

    $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $escapedHeadline = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $escapedOrderId = $orderId !== null && $orderId !== '' ? htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') : '';
    $escapedCtaHref = htmlspecialchars($ctaHref, ENT_QUOTES, 'UTF-8');
    $escapedCtaLabel = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . $escapedTitle . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Josefin+Slab:wght@300;400;600;700&display=swap" rel="stylesheet">';
    echo '<style>';
    echo ':root{--main-color:#ded5cd;--secondary-color:#f3eae2;--font-color1:#41462d;--font-color2:#e9ead6;}';
    echo '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:linear-gradient(160deg,#f7f1eb 0%,#efe4d8 44%,#ded5cd 100%);color:var(--font-color1);font-family:"Josefin Slab",serif;letter-spacing:.4px}';
    echo '.panel{width:min(760px,100%);background:rgba(243,234,226,.95);border:1px solid rgba(65,70,45,.18);border-radius:28px;box-shadow:0 18px 50px rgba(65,70,45,.12);padding:34px 26px;text-align:center}';
    echo 'h1{margin:0 0 14px;font-size:clamp(2rem,5vw,3.2rem);letter-spacing:.12em;text-transform:uppercase}';
    echo 'p{margin:12px auto;max-width:56ch;font-size:1.14rem;line-height:1.6}';
    echo '.order{display:inline-block;margin-top:16px;padding:10px 14px;border-radius:999px;background:var(--main-color);border:1px solid rgba(65,70,45,.2);font-weight:700}';
    echo '.actions{margin-top:24px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap}';
    echo '.actions a{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:999px;background:var(--font-color1);color:var(--font-color2);text-decoration:none;font-weight:700;letter-spacing:.08em;text-transform:uppercase}';
    echo '.actions a.secondary{background:transparent;color:var(--font-color1);border:1px solid var(--font-color1)}';
    echo '.actions a:hover{opacity:.92}';
    echo '</style>';
    echo '</head>';
    echo '<body><main class="panel">';
    echo '<h1>' . $escapedHeadline . '</h1>';
    echo '<p>' . $escapedMessage . '</p>';
    if ($escapedOrderId !== '') {
        echo '<p class="order">Order reference: ' . $escapedOrderId . '</p>';
    }
    echo '<div class="actions">';
    echo '<a href="' . $escapedCtaHref . '">' . $escapedCtaLabel . '</a>';
    echo '<a class="secondary" href="/shop.html">Return to shop</a>';
    echo '</div>';
    echo '</main></body></html>';
}

function fc_request_paypal(string $method, string $url, ?string $accessToken = null, ?string $body = null, bool $useBasicAuth = false, string $contentType = 'application/json'): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialise cURL.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    if ($useBasicAuth) {
        $options[CURLOPT_USERPWD] = PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET;
    }

    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        throw new RuntimeException('PayPal request failed: ' . $curlError . ' (' . $curlErrno . ')');
    }

    $decoded = json_decode($response, true);

    return [
        'http_code' => $httpCode,
        'raw' => $response,
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

function fc_get_paypal_access_token(): string
{
    if (PAYPAL_CLIENT_ID === '' || PAYPAL_SECRET === '') {
        throw new RuntimeException('PayPal credentials are missing from config.php.');
    }

    $response = fc_request_paypal(
        'POST',
        PAYPAL_BASE_URL . '/v1/oauth2/token',
        null,
        'grant_type=client_credentials',
        true,
        'application/x-www-form-urlencoded'
    );

    $payload = $response['data'];
    if (!is_array($payload) || empty($payload['access_token'])) {
        throw new RuntimeException('Unable to obtain a PayPal access token. Response: ' . $response['raw']);
    }

    return (string) $payload['access_token'];
}

function fc_create_paypal_order(string $accessToken): array
{
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'fairyland-cottage-book-bundle',
            'description' => PRODUCT_NAME,
            'custom_id' => 'fairyland-cottage-book-bundle',
            'amount' => [
                'currency_code' => CURRENCY,
                'value' => PRICE,
            ],
        ]],
        'application_context' => [
            'brand_name' => 'Fairyland Cottage',
            'landing_page' => 'LOGIN',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => SUCCESS_URL,
            'cancel_url' => CANCEL_URL,
        ],
    ];

    return fc_request_paypal(
        'POST',
        PAYPAL_BASE_URL . '/v2/checkout/orders',
        $accessToken,
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
}

function fc_capture_paypal_order(string $orderId, string $accessToken): array
{
    return fc_request_paypal(
        'POST',
        PAYPAL_BASE_URL . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
        $accessToken,
        '{}'
    );
}

function fc_get_paypal_order(string $orderId, string $accessToken): array
{
    return fc_request_paypal(
        'GET',
        PAYPAL_BASE_URL . '/v2/checkout/orders/' . rawurlencode($orderId),
        $accessToken
    );
}

function fc_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function fc_base64url_decode(string $value): string|false
{
    $remainder = strlen($value) % 4;
    if ($remainder !== 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function fc_generate_download_token(string $fileKey, int $expires, string $orderId): string
{
    $payload = json_encode([
        'file' => $fileKey,
        'expires' => $expires,
        'order_id' => $orderId,
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Unable to create download token payload.');
    }

    $signature = hash_hmac('sha256', $payload, DOWNLOAD_SECRET, true);

    return fc_base64url_encode($payload) . '.' . fc_base64url_encode($signature);
}

function fc_decode_download_token(string $token): array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return ['valid' => false, 'reason' => 'format'];
    }

    [$payloadPart, $signaturePart] = $parts;
    $payloadJson = fc_base64url_decode($payloadPart);
    $signature = fc_base64url_decode($signaturePart);

    if ($payloadJson === false || $signature === false) {
        return ['valid' => false, 'reason' => 'decode'];
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload) || !isset($payload['file'], $payload['expires'], $payload['order_id'])) {
        return ['valid' => false, 'reason' => 'payload'];
    }

    $expectedSignature = hash_hmac('sha256', $payloadJson, DOWNLOAD_SECRET, true);
    if (!hash_equals($expectedSignature, $signature)) {
        return ['valid' => false, 'reason' => 'signature', 'payload' => $payload];
    }

    return ['valid' => true, 'payload' => $payload];
}

function fc_normalize_file_key(string $value): string
{
    $candidate = strtolower(trim($value));
    return in_array($candidate, ['book', 'audio'], true) ? $candidate : '';
}

function fc_file_path_for_key(string $fileKey): string
{
    return FILE_PATHS[$fileKey] ?? '';
}

function fc_is_completed_status(array $payload): bool
{
    $status = strtoupper((string) ($payload['status'] ?? ''));
    return $status === 'COMPLETED';
}

function fc_is_already_captured_response(array $payload): bool
{
    $name = strtoupper((string) ($payload['name'] ?? ''));
    $message = strtoupper((string) ($payload['message'] ?? ''));

    return str_contains($name, 'ALREADY_CAPTURED') || str_contains($message, 'ALREADY_CAPTURED') || str_contains($message, 'ALREADY COMPLETED');
}