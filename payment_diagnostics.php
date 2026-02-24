<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

function respond(int $code, string $message): void {
    http_response_code($code);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Payment Diagnostics</title></head><body>';
    echo '<h2>Payment Diagnostics</h2>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    $local = $parts[0];
    $domain = $parts[1];
    $visible = substr($local, 0, 2);
    return $visible . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
}

function sanitize_line(string $line): string {
    return preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', function ($m) {
        return mask_email($m[0]);
    }, $line) ?? $line;
}

function tail_file(string $path, int $maxLines = 40): array {
    if (!file_exists($path)) {
        return ['exists' => false, 'lines' => []];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return ['exists' => true, 'lines' => ['Unable to read log file']];
    }
    $slice = array_slice($lines, -$maxLines);
    $slice = array_map('sanitize_line', $slice);
    return ['exists' => true, 'lines' => $slice];
}

function append_diag_log(string $path, string $message): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function send_test_email(string $root, string $toEmail): array {
    $from = 'info@fairylandcottage.com';
    $subject = 'Fairyland Cottage Diagnostics Test Email';
    $message = "This is a test email from payment_diagnostics.php.\n\nUTC: " . gmdate('c') . "\n";
    $logsPath = $root . '/logs/sent_emails.log';

    // Prefer PHPMailer if available
    if (file_exists($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
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

            $mail->setFrom($from, 'Fairyland Cottage');
            $mail->addAddress($toEmail);
            $mail->addBCC($from);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->send();

            append_diag_log($logsPath, 'Diagnostics test email sent via PHPMailer to ' . $toEmail);
            return ['ok' => true, 'transport' => 'phpmailer', 'detail' => 'Email sent'];
        } catch (Exception $e) {
            append_diag_log($logsPath, 'Diagnostics PHPMailer error: ' . $e->getMessage());
            // Continue to fallback mail() below
        }
    }

    // Fallback to PHP mail()
    if (function_exists('mail')) {
        $headers = "From: $from\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "Bcc: $from\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mail_ok = @mail($toEmail, $subject, $message, $headers);
        append_diag_log($logsPath, 'Diagnostics test email via mail() to ' . $toEmail . ' result=' . ($mail_ok ? 'success' : 'failure'));
        return [
            'ok' => $mail_ok,
            'transport' => 'mail',
            'detail' => $mail_ok ? 'Email sent' : 'mail() returned false',
        ];
    }

    append_diag_log($logsPath, 'Diagnostics test email failed: no mail transport available');
    return ['ok' => false, 'transport' => 'none', 'detail' => 'No PHPMailer config and mail() unavailable'];
}

$expectedKey = getenv('DELIVERY_DIAG_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';

if ($expectedKey === '') {
    respond(503, 'Diagnostics is disabled. Set DELIVERY_DIAG_KEY on the server, then open this page with ?key=YOUR_KEY');
}

if (!hash_equals($expectedKey, (string)$providedKey)) {
    respond(403, 'Unauthorized. Supply the correct ?key= value.');
}

$root = __DIR__;
$logsDir = $root . '/logs';
$dataDir = $root . '/data';
$privateDir = $root . '/private_downloads';

$testSendResult = null;
if (isset($_GET['send_test']) && (string)$_GET['send_test'] === '1') {
    $candidate = trim((string)($_GET['to'] ?? ''));
    $defaultEmail = getenv('DIAG_TEST_EMAIL') ?: 'info@fairylandcottage.com';
    $targetEmail = $candidate !== '' ? $candidate : $defaultEmail;

    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        $testSendResult = [
            'ok' => false,
            'transport' => 'validation',
            'detail' => 'Invalid recipient email',
            'to' => $targetEmail,
        ];
    } else {
        $testSendResult = send_test_email($root, $targetEmail);
        $testSendResult['to'] = $targetEmail;
    }
}

$checks = [
    'time_utc' => gmdate('c'),
    'php_version' => PHP_VERSION,
    'notify_listener_url_expected' => 'https://fairylandcottage.com/ipn_listener.php',
    'paths' => [
        'logs_dir_exists' => is_dir($logsDir),
        'logs_dir_writable' => is_dir($logsDir) ? is_writable($logsDir) : false,
        'data_dir_exists' => is_dir($dataDir),
        'data_dir_writable' => is_dir($dataDir) ? is_writable($dataDir) : false,
        'private_downloads_exists' => is_dir($privateDir),
        'private_downloads_writable' => is_dir($privateDir) ? is_writable($privateDir) : false,
        'db_exists' => file_exists($dataDir . '/tokens.sqlite'),
        'tokens_txt_exists' => file_exists($root . '/tokens.txt'),
        'vendor_autoload_exists' => file_exists($root . '/vendor/autoload.php'),
    ],
    'product_files' => [
        'ebook_pdf' => file_exists($privateDir . '/My Journey to Simple, Sustainable Living -  ebook.pdf'),
        'audiobook_wav' => file_exists($privateDir . '/My Journey to Simple, Sustainable Living - Audiobook.wav'),
    ],
    'env' => [
        'smtp_host_set' => getenv('SMTP_HOST') ? true : false,
        'smtp_user_set' => getenv('SMTP_USER') ? true : false,
        'smtp_pass_set' => getenv('SMTP_PASS') ? true : false,
        'paypal_mode' => getenv('PAYPAL_MODE') ?: '(unset)',
        'paypal_webhook_id_set' => getenv('PAYPAL_WEBHOOK_ID') ? true : false,
    ],
    'logs' => [
        'ipn_listener' => tail_file($logsDir . '/ipn_listener.log', 50),
        'paypal_webhooks' => tail_file($logsDir . '/paypal_webhooks.log', 50),
        'sent_emails' => tail_file($logsDir . '/sent_emails.log', 50),
    ],
    'test_send' => $testSendResult,
];

if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Payment Diagnostics</title>';
echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:980px;margin:2rem auto;padding:0 1rem;}pre{background:#f5f5f5;padding:1rem;overflow:auto;border-radius:8px;}h2,h3{margin-bottom:0.25rem;}small{color:#666;}</style>';
echo '</head><body>';
echo '<h2>Payment Diagnostics</h2>';
echo '<small>Use ?format=json for JSON output.</small>';
echo '<p><small>Send test email: add <strong>&amp;send_test=1</strong> and optional <strong>&amp;to=you@example.com</strong>.</small></p>';

if (is_array($testSendResult)) {
    $status = !empty($testSendResult['ok']) ? 'SUCCESS' : 'FAILED';
    echo '<h3>Test email result</h3><pre>' . htmlspecialchars(json_encode($testSendResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<p><small>Status: ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</small></p>';
}

echo '<h3>System checks</h3><pre>' . htmlspecialchars(json_encode([
    'time_utc' => $checks['time_utc'],
    'php_version' => $checks['php_version'],
    'paths' => $checks['paths'],
    'product_files' => $checks['product_files'],
    'env' => $checks['env'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';

echo '<h3>Recent IPN log</h3><pre>' . htmlspecialchars(implode("\n", $checks['logs']['ipn_listener']['lines']), ENT_QUOTES, 'UTF-8') . '</pre>';
echo '<h3>Recent Webhook log</h3><pre>' . htmlspecialchars(implode("\n", $checks['logs']['paypal_webhooks']['lines']), ENT_QUOTES, 'UTF-8') . '</pre>';
echo '<h3>Recent Email log</h3><pre>' . htmlspecialchars(implode("\n", $checks['logs']['sent_emails']['lines']), ENT_QUOTES, 'UTF-8') . '</pre>';

echo '</body></html>';

?>