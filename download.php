<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/payment_utils.php';

fc_send_no_cache_headers();

$fileKey = isset($_GET['file']) ? fc_normalize_file_key((string) $_GET['file']) : '';
$expires = isset($_GET['expires']) ? filter_var($_GET['expires'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($fileKey === '' || $expires === false || $token === '') {
    fc_render_error_page(
        400,
        'Missing download details',
        'We could not verify the download request.',
        'The secure download link is missing one or more required values. Please return to your success page or contact support if your purchase was recent.',
        null,
        '/contact.html',
        'Contact support'
    );
    exit;
}

$orderId = null;
if (time() > (int) $expires) {
    $expiredPayload = fc_decode_download_token($token);
    if (($expiredPayload['valid'] ?? false) === true) {
        $expiredData = $expiredPayload['payload'];
        $expiredFileKey = fc_normalize_file_key((string) ($expiredData['file'] ?? ''));
        $expiredExpires = (int) ($expiredData['expires'] ?? 0);
        if ($expiredFileKey === $fileKey && $expiredExpires === (int) $expires) {
            $orderId = (string) ($expiredData['order_id'] ?? '');
        }
    }

    fc_log_error('Expired download token', [
        'file' => $fileKey,
        'expires' => $expires,
        'order_id' => $orderId,
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    fc_render_error_page(
        410,
        'Download expired',
        'This secure download link has expired.',
        'Your link is only valid for 1 hour. Please contact support for a fresh download link and include your order reference if you have it.',
        $orderId !== '' ? $orderId : null,
        '/contact.html',
        'Contact support'
    );
    exit;
}

$downloadPayload = fc_decode_download_token($token);
if (($downloadPayload['valid'] ?? false) !== true) {
    $reason = (string) ($downloadPayload['reason'] ?? 'invalid');
    fc_log_error('Invalid download token', [
        'file' => $fileKey,
        'expires' => $expires,
        'reason' => $reason,
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    fc_render_error_page(
        403,
        'Invalid download link',
        'This download link could not be verified.',
        'Please use the download page you received after checkout. If the link was copied or changed, request a fresh set of downloads from support.',
        null,
        '/contact.html',
        'Request help'
    );
    exit;
}

$payload = $downloadPayload['payload'];
$payloadFile = fc_normalize_file_key((string) ($payload['file'] ?? ''));
$payloadExpires = (int) ($payload['expires'] ?? 0);
$orderId = (string) ($payload['order_id'] ?? '');

if ($payloadFile !== $fileKey || $payloadExpires !== (int) $expires) {
    fc_log_error('Download token mismatch', [
        'file' => $fileKey,
        'payload_file' => $payloadFile,
        'expires' => $expires,
        'payload_expires' => $payloadExpires,
        'order_id' => $orderId,
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    fc_render_error_page(
        403,
        'Invalid download link',
        'This download link was altered or does not match the original file.',
        'Please do not edit the link. If you need assistance, contact support and include your order reference.',
        $orderId !== '' ? $orderId : null,
        '/contact.html',
        'Contact support'
    );
    exit;
}

$filePath = fc_file_path_for_key($fileKey);
if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
    fc_log_error('Private download file missing or unreadable', [
        'file' => $fileKey,
        'path' => $filePath,
        'order_id' => $orderId,
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    fc_render_error_page(
        503,
        'Download unavailable',
        'We could not find the file for your purchase.',
        'The item is temporarily unavailable. Please contact support and mention your order reference so we can restore access.',
        $orderId !== '' ? $orderId : null,
        '/contact.html',
        'Contact support'
    );
    exit;
}

$mimeType = $fileKey === 'book' ? 'application/pdf' : 'audio/wav';
$downloadName = $fileKey === 'book' ? 'fairyland-book.pdf' : 'fairyland-audiobook.wav';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;