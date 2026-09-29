<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
// This GET starts a gateway checkout and may update order profit sharing. Only
// accept navigation from the admin UI, not a cross-site link or arbitrary order.
if($_SERVER['REQUEST_METHOD'] !== 'GET' || !checkRefererHost()){
    http_response_code(403);
    exit('Forbidden');
}
$submit2=true;

$trade_no=$_GET['trade_no'] ?? '';
if(!is_string($trade_no) || !preg_match('/^[0-9]{8,32}$/D', $trade_no)){
    http_response_code(400);
    exit('Invalid trade number');
}
$order=$DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no AND tid=3 AND uid=:uid LIMIT 1', [':trade_no'=>$trade_no, ':uid'=>$conf['test_pay_uid']]);
if(!$order)sysmsg('测试订单号不存在，请返回来源地重新发起请求！');

$paytype=$DB->getRow("SELECT id,name,status FROM pre_type WHERE id='{$order['type']}' LIMIT 1");
if(!$paytype)sysmsg('支付方式不存在');

$channelrow=$DB->getRow("SELECT id,plugin,apptype FROM pre_channel WHERE id='{$order['channel']}' LIMIT 1");
if(!$channelrow)sysmsg('支付通道不存在');

$order['typename'] = $paytype['name'];
$order['plugin'] = $channelrow['plugin'];
$order['profits'] = \lib\Payment::updateOrderProfits($order, $channelrow['plugin']);

try{
	$result = \lib\Plugin::loadForSubmit($channelrow['plugin'], $trade_no);
	$result['submit'] = true;
	\lib\Payment::echoDefault($result);
}catch(Exception $e){
	sysmsg($e->getMessage());
}
