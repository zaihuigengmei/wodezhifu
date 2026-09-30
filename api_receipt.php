<?php
// Signed binary download, deliberately separate from browser-session receipt routes.
$nosession = true;
define('API_INIT', true);
require __DIR__.'/includes/common.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
try {
    $request = $_POST;
    foreach ($request as $value) if (!is_string($value)) throw new RuntimeException('Invalid request');
    foreach (['pid','receipt_id','timestamp','sign'] as $key) if (empty($request[$key])) throw new RuntimeException('Invalid request');
    if (!preg_match('/^[1-9][0-9]{0,10}$/D', $request['pid']) || !preg_match('/^[a-f0-9]{48}$/D', $request['receipt_id'])) throw new RuntimeException('Invalid request');
    $user = $DB->find('user', '*', ['uid'=>$request['pid']]);
    if (!$user || (int)$user['status'] !== 1 || (int)$user['transfer'] !== 1) throw new RuntimeException('Forbidden');
    $group = getGroupConfig($user['gid']);
    $effective = array_merge($conf, $group);
    if (empty($effective['user_transfer'])) throw new RuntimeException('Forbidden');
    \lib\ApiHelper::api_verify($user, $request);
} catch (Throwable $e) { http_response_code(403); header('Content-Type: application/json'); exit('{"code":-3,"msg":"Receipt authorization failed"}'); }
// open() re-reads UID, channel and upstream order; receipt_id alone is never a capability.
\lib\PrivateReceipt::respond($request['receipt_id'], 'user', $user['uid']);
