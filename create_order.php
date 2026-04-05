<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/payment_utils.php';

try {
    $accessToken = fc_get_paypal_access_token();
    $response = fc_create_paypal_order($accessToken);
    $payload = $response['data'];

    if (!is_array($payload) || empty($payload['id'])) {
        throw new RuntimeException('PayPal did not return an order ID. Response: ' . $response['raw']);
    }

    $approvalUrl = '';
    foreach ($payload['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
            $approvalUrl = (string) $link['href'];
            break;
        }
    }

    if ($approvalUrl === '') {
        throw new RuntimeException('PayPal approval link was missing. Response: ' . $response['raw']);
    }

    header('Location: ' . $approvalUrl, true, 303);
    exit;
} catch (Throwable $exception) {
    fc_log_error('create_order.php failed', [
        'error' => $exception->getMessage(),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    fc_render_error_page(
        503,
        'Payment unavailable',
        'We could not start the PayPal checkout.',
        'Please try again in a few minutes. If the problem continues, use the contact link and mention that checkout could not be created.',
        null,
        '/contact.html',
        'Contact Fairyland Cottage'
    );
    exit;
}