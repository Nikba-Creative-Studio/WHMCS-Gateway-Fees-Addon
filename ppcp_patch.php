<?php

/**
 * Gateway Fees — PayPal Payments (PPCP) order-total patcher.
 *
 * PayPal Payments (paypal_ppcpv / paypal_acdc) creates its PayPal order
 * in-context on the checkout page, BEFORE a WHMCS invoice (and therefore our
 * fee) exists. The checkout interceptor calls this endpoint with the freshly
 * created PayPal order id; we recompute the configured gateway fee on the
 * server, then PATCH the remote PayPal order so the amount PayPal authorises
 * includes the fee — matching the invoice total generated afterwards.
 *
 * Opt-in: only runs when "Adjust PayPal Payments order total" is enabled.
 * Fail-safe: any error returns JSON and never blocks the PayPal flow.
 *
 * Security notes:
 *  - The fee is (re)computed on the server from the PayPal order's own amount;
 *    the browser only supplies the order id + gateway, never the fee value.
 *  - PayPal API credentials are read server-side from the gateway module and
 *    are never exposed to the client.
 *  - The patch only ADDS the configured surcharge and is idempotent.
 *
 * @package    WHMCS\Addon\GatewayFees
 * @author     Nikba Creative Studio
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway;

require __DIR__ . '/../../../init.php';

header('Content-Type: application/json');

/**
 * Emit a JSON response and stop.
 */
function ppcp_respond(array $data, int $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// --- Guards -----------------------------------------------------------------

if (!function_exists('gatewayfees_setting') || !function_exists('gatewayfees_calculate')) {
    ppcp_respond(['success' => false, 'error' => 'module_not_loaded']);
}

if (gatewayfees_setting('enable_ppcp_patch', 'off') !== 'on') {
    ppcp_respond(['success' => false, 'error' => 'disabled']);
}

$orderId = isset($_POST['orderID']) ? trim((string) $_POST['orderID']) : '';
$gateway = isset($_POST['gateway']) ? trim((string) $_POST['gateway']) : '';

$allowed = function_exists('gatewayfees_ppcp_gateways')
    ? gatewayfees_ppcp_gateways()
    : ['paypal_ppcpv', 'paypal_acdc'];

if (!in_array($gateway, $allowed, true)) {
    ppcp_respond(['success' => false, 'error' => 'invalid_gateway']);
}
if (!preg_match('/^[A-Za-z0-9]{5,40}$/', $orderId)) {
    ppcp_respond(['success' => false, 'error' => 'invalid_order']);
}

$rule = gatewayfees_get_rule($gateway);
if (!$rule) {
    ppcp_respond(['success' => false, 'error' => 'no_rule']);
}

// --- PayPal credentials -----------------------------------------------------

try {
    $params = Gateway::factory('paypal_ppcpv')->getParams();
} catch (\Throwable $e) {
    ppcp_respond(['success' => false, 'error' => 'gateway_unavailable']);
}

[$clientId, $secret, $sandbox] = gatewayfees_ppcp_credentials(is_array($params) ? $params : []);
if (!$clientId || !$secret) {
    ppcp_respond(['success' => false, 'error' => 'missing_credentials']);
}

$apiBase = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

// --- OAuth ------------------------------------------------------------------

$token = gatewayfees_ppcp_token($apiBase, $clientId, $secret);
if (!$token) {
    ppcp_respond(['success' => false, 'error' => 'auth_failed']);
}

// --- Read the order ---------------------------------------------------------

$order = gatewayfees_ppcp_request('GET', $apiBase . '/v2/checkout/orders/' . rawurlencode($orderId), $token);
if (!is_array($order) || empty($order['purchase_units'][0]['amount'])) {
    ppcp_respond(['success' => false, 'error' => 'order_read_failed']);
}

$amount   = $order['purchase_units'][0]['amount'];
$currency = $amount['currency_code'] ?? '';
$value    = isset($amount['value']) ? (float) $amount['value'] : 0.0;

if ($value <= 0 || !$currency) {
    ppcp_respond(['success' => false, 'error' => 'order_amount_invalid']);
}

// Idempotency: strip any handling we may have added on a previous call so the
// fee is computed from the genuine base and re-applying is a no-op.
$breakdown       = isset($amount['breakdown']) && is_array($amount['breakdown']) ? $amount['breakdown'] : null;
$existingHandling = 0.0;
if ($breakdown && isset($breakdown['handling']['value'])) {
    $existingHandling = (float) $breakdown['handling']['value'];
}
$base = round($value - $existingHandling, 2);

$fee = round((float) gatewayfees_calculate($rule, $base), 2);
if ($fee <= 0) {
    // Discounts are not supported for PPCP (PayPal handling cannot be negative).
    ppcp_respond(['success' => true, 'patched' => false, 'reason' => 'no_positive_fee']);
}

$newValue = round($base + $fee, 2);

// --- Build the PATCH amount -------------------------------------------------

$newAmount = [
    'currency_code' => $currency,
    'value'         => number_format($newValue, 2, '.', ''),
];

if ($breakdown) {
    // Keep breakdown integrity: value must equal the sum of the components.
    $breakdown['handling'] = [
        'currency_code' => $currency,
        'value'         => number_format($fee, 2, '.', ''),
    ];
    $newAmount['breakdown'] = $breakdown;
}

$patch = [[
    'op'    => 'replace',
    'path'  => "/purchase_units/@reference_id=='default'/amount",
    'value' => $newAmount,
]];

$ok = gatewayfees_ppcp_request(
    'PATCH',
    $apiBase . '/v2/checkout/orders/' . rawurlencode($orderId),
    $token,
    $patch,
    true
);

if ($ok === false) {
    ppcp_respond(['success' => false, 'error' => 'patch_failed']);
}

ppcp_respond([
    'success'  => true,
    'patched'  => true,
    'base'     => number_format($base, 2, '.', ''),
    'fee'      => number_format($fee, 2, '.', ''),
    'total'    => number_format($newValue, 2, '.', ''),
    'currency' => $currency,
]);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Discover PPCP API credentials from the gateway module params (defensive:
 * exact key names vary between WHMCS builds).
 *
 * @param array $params
 * @return array [clientId, secret, sandbox]
 */
function gatewayfees_ppcp_credentials(array $params)
{
    $sandbox = false;
    foreach (['useSandbox', 'sandbox', 'testMode', 'sandboxMode', 'test_mode'] as $k) {
        if (!empty($params[$k]) && in_array(strtolower((string) $params[$k]), ['on', '1', 'true', 'yes'], true)) {
            $sandbox = true;
            break;
        }
    }

    if ($sandbox) {
        $clientId = $params['sandboxClientId'] ?? $params['sandbox_client_id'] ?? '';
        $secret   = $params['sandboxClientSecret'] ?? $params['sandbox_client_secret'] ?? '';
    } else {
        $clientId = $params['clientId'] ?? $params['clientID'] ?? $params['client_id'] ?? '';
        $secret   = $params['clientSecret'] ?? $params['secretKey'] ?? $params['secret'] ?? '';
    }

    // Fuzzy fallback if standard keys are missing
    if (!$clientId || !$secret) {
        foreach ($params as $k => $v) {
            if (!is_string($v) || $v === '') {
                continue;
            }
            $lk = strtolower($k);
            if (!$clientId && strpos($lk, 'client') !== false && strpos($lk, 'secret') === false && strpos($lk, 'id') !== false) {
                $clientId = $v;
            }
            if (!$secret && strpos($lk, 'secret') !== false) {
                $secret = $v;
            }
        }
    }

    return [$clientId, $secret, $sandbox];
}

/**
 * Fetch a PayPal OAuth access token via client-credentials.
 *
 * @param string $apiBase
 * @param string $clientId
 * @param string $secret
 * @return string
 */
function gatewayfees_ppcp_token($apiBase, $clientId, $secret)
{
    $ch = curl_init($apiBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$body) {
        return '';
    }
    $data = json_decode($body, true);
    return $data['access_token'] ?? '';
}

/**
 * Perform a PayPal Orders v2 request.
 *
 * @param string     $method
 * @param string     $url
 * @param string     $token
 * @param array|null $payload
 * @param bool       $expectNoContent  PATCH returns 204 with no body.
 * @return array|bool|null  Decoded body, true on 2xx-no-content, or false on error.
 */
function gatewayfees_ppcp_request($method, $url, $token, $payload = null, $expectNoContent = false)
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return false;
    }
    if ($expectNoContent) {
        return true;
    }
    return json_decode((string) $body, true);
}
