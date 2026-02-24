<?php
// paypal_webhook.php
// Validates PayPal Webhook signatures and issues download tokens on completed payments.

// Load DB helper if present
if (file_exists(__DIR__ . '/lib/db.php')) {
    require_once __DIR__ . '/lib/db.php';
}

// Helper: write logs
function webhook_log($msg) {
    if (!is_dir(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0755, true);
    file_put_contents(__DIR__ . '/logs/paypal_webhooks.log', "[".date('c')."] " . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// Read incoming request body and headers
$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$json = json_decode($body, true);

// Environment configuration
$mode = getenv('PAYPAL_MODE') ?: 'sandbox'; // 'sandbox' or 'live'
$client_id = getenv('PAYPAL_CLIENT_ID') ?: '';
$client_secret = getenv('PAYPAL_SECRET') ?: '';
$webhook_id = getenv('PAYPAL_WEBHOOK_ID') ?: ''; // set this to the webhook ID PayPal shows
$skip_verify = getenv('PAYPAL_SKIP_VERIFY') === '1';

// Extract required PayPal transmission headers
$transmission_id = $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ($headers['Paypal-Transmission-Id'] ?? '');
$transmission_time = $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? ($headers['Paypal-Transmission-Time'] ?? '');
$cert_url = $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? ($headers['Paypal-Cert-Url'] ?? '');
$auth_algo = $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? ($headers['Paypal-Auth-Algo'] ?? '');
$transmission_sig = $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? ($headers['Paypal-Transmission-Sig'] ?? '');

webhook_log("Received webhook: " . ($json['event_type'] ?? json_last_error_msg()));

// Determine endpoints
if ($mode === 'live') {
    $api_base = 'https://api-m.paypal.com';
} else {
    $api_base = 'https://api-m.sandbox.paypal.com';
}

// Verify webhook signature unless skipped for local dev
$verified = false;
if ($skip_verify) {
    webhook_log('PAYPAL_SKIP_VERIFY enabled: skipping signature verification');
    $verified = true;
} else {
    if (empty($client_id) || empty($client_secret) || empty($webhook_id)) {
        webhook_log('Missing PAYPAL_CLIENT_ID or PAYPAL_SECRET or PAYPAL_WEBHOOK_ID; cannot verify');
    } else {
        // Obtain access token
        $ch = curl_init($api_base . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: en_US']);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            webhook_log('Error obtaining access token: ' . $err);
        } else {
            $tok = json_decode($resp, true);
            $access_token = $tok['access_token'] ?? '';
            if (!$access_token) {
                webhook_log('No access_token in token response: ' . $resp);
            } else {
                // Build verification payload
                $verify_payload = [
                    'auth_algo' => $auth_algo,
                    'cert_url' => $cert_url,
                    'transmission_id' => $transmission_id,
                    'transmission_sig' => $transmission_sig,
                    'transmission_time' => $transmission_time,
                    'webhook_id' => $webhook_id,
                    'webhook_event' => json_decode($body, true),
                ];

                $ch = curl_init($api_base . '/v1/notifications/verify-webhook-signature');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verify_payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token]);
                $vres = curl_exec($ch);
                $verr = curl_error($ch);
                curl_close($ch);
                if ($verr) {
                    webhook_log('Error verifying webhook signature: ' . $verr);
                } else {
                    $vobj = json_decode($vres, true);
                    webhook_log('Verify response: ' . $vres);
                    if (isset($vobj['verification_status']) && $vobj['verification_status'] === 'SUCCESS') {
                        $verified = true;
                    }
                }
            }
        }
    }
}

if (!$verified) {
    http_response_code(400);
    echo "Invalid webhook signature\n";
    exit;
}

// At this point the webhook is verified. Handle event types we care about.
$event_type = $json['event_type'] ?? '';
$resource = $json['resource'] ?? [];

// Determine buyer email and a label for the item
$buyer_email = $resource['payer']['email_address'] ?? $resource['payer']['payer_info']['email'] ?? ($resource['payer_email'] ?? null);
$item_name = $resource['invoice_id'] ?? $json['event_type'] ?? 'PayPal Purchase';

// Only act on completed payments (capture completed / sale completed / checkout order approved)
$is_complete = false;
if (isset($resource['status']) && in_array(strtoupper($resource['status']), ['COMPLETED','CAPTURED','APPROVED'])) $is_complete = true;
// Some webhook types use different fields
if ($event_type === 'CHECKOUT.ORDER.COMPLETED' || $event_type === 'CHECKOUT.ORDER.APPROVED') $is_complete = true;

if ($is_complete) {
    // Create token + email as with ipn_listener
    $token = bin2hex(random_bytes(16));
    $expiry = time() + (24 * 3600);
    $pdf_filename = 'My Journey to Simple, Sustainable Living -  ebook.pdf';
    $audio_filename = 'My Journey to Simple, Sustainable Living - Audiobook.wav';
    $downloads_per_file = 3;
    $files_map = [ $pdf_filename => $downloads_per_file, $audio_filename => $downloads_per_file ];

    if (function_exists('init_db')) {
        init_db();
        insert_token_with_files($token, $buyer_email ?? 'unknown@example.com', $expiry, $files_map);
    } else {
        // fallback to tokens.txt
        $files_field = $pdf_filename . ':' . $downloads_per_file . ',' . $audio_filename . ':' . $downloads_per_file;
        file_put_contents(__DIR__ . '/tokens.txt', $token . '|' . ($buyer_email ?? 'unknown@example.com') . '|' . $expiry . '|' . $files_field . "\n", FILE_APPEND | LOCK_EX);
    }

    // Build download links
    $host = $_SERVER['HTTP_HOST'] ?? 'fairylandcottage.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . $host;
    $pdf_url = $base . '/download.php?token=' . urlencode($token) . '&file=' . urlencode($pdf_filename);
    $audio_url = $base . '/download.php?token=' . urlencode($token) . '&file=' . urlencode($audio_filename);

    // Send email (log + PHPMailer if available)
    $subject = "Your Fairyland Cottage Purchase";
    $message = "Thank you for your purchase!\n\n";
    $message .= "Download your files (links expire in 24 hours):\n";
    $message .= "- Ebook (PDF): $pdf_url\n";
    $message .= "- Audiobook (WAV): $audio_url\n\n";
    $message .= "If you have issues, contact info@fairylandcottage.com\n";

    webhook_log("Prepared download email for $buyer_email (token=$token)");

    // Log message for local debugging
    file_put_contents(__DIR__ . '/logs/sent_emails.log', "[".date('c')."] To: " . ($buyer_email ?? 'unknown') . "\nSubject: $subject\nFrom: info@fairylandcottage.com\n\n" . $message . "----\n", FILE_APPEND | LOCK_EX);

    // PHPMailer via vendor if present
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $smtp_host = getenv('SMTP_HOST');
            $smtp_user = getenv('SMTP_USER');
            $smtp_pass = getenv('SMTP_PASS');
            $smtp_port = getenv('SMTP_PORT') ?: 587;
            $smtp_secure = getenv('SMTP_SECURE') ?: 'tls';
            if ($smtp_host && $smtp_user && $smtp_pass) {
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = $smtp_secure;
                $mail->Port = (int)$smtp_port;
            }
            $mail->setFrom('info@fairylandcottage.com', 'Fairyland Cottage');
            $mail->addAddress($buyer_email ?? 'unknown@example.com');
            $mail->addBCC('info@fairylandcottage.com');
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->send();
            webhook_log('PHPMailer: email sent to ' . ($buyer_email ?? 'unknown'));
        } catch (Exception $e) {
            webhook_log('PHPMailer error: ' . $e->getMessage());
        }
    }
}

// Always respond 200 to PayPal unless you want retries
http_response_code(200);
echo "OK";
