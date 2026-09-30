<?php
require __DIR__.'/../includes/common.php';
if (($islogin ?? 0) != 1) { http_response_code(403); header('Cache-Control: no-store'); exit('Forbidden'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
\lib\PrivateReceipt::respond($_GET['id'] ?? null, 'admin', 0);
