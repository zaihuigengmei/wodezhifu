<?php
// Fixed, explicitly channel/user/subchannel-bound endpoint. Do not route through loadForPay.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
    header('Allow: POST'); http_response_code(405); exit('POST required');
}
$binding = [];
foreach(['channel','uid','sub'] as $key){
    $value = $_GET[$key] ?? null;
    if(!is_string($value) || !preg_match($key === 'sub' ? '/\A(?:0|[1-9][0-9]{0,9})\z/' : '/\A[1-9][0-9]{0,9}\z/', $value)){
        http_response_code(400); exit('Invalid binding');
    }
    $binding[$key] = $value;
}
if((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144){ http_response_code(413); exit('Event too large'); }
$raw = file_get_contents('php://input', false, null, 0, 262145);
if($raw === false || strlen($raw) > 262144){ http_response_code(413); exit('Event too large'); }
$headers = [];
foreach(['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $key){
    $headers[$key] = $_SERVER['HTTP_PAYPAL_'.strtoupper($key)] ?? null;
}
$nosession = true;
try {
    require_once dirname(__DIR__, 2).'/includes/common.php';
    require_once __DIR__.'/paypal_plugin.php';
    $result = paypal_plugin::fixedWebhook($binding, $raw, $headers);
    http_response_code($result['status']);
    echo $result['body'];
} catch (Throwable $e) {
    http_response_code(503); echo 'Webhook processing unavailable';
}
