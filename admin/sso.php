<?php
include("../includes/common.php");

if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
if($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
if(!checkRefererHost())exit();
csrf_check_page('admin');

$uid=isset($_POST['uid'])?intval($_POST['uid']):0;
if($uid<=0)sysmsg('用户ID错误！');

$userrow=$DB->getRow("select * from pre_user where uid='$uid' limit 1");
if(!$userrow)sysmsg('当前用户不存在！');

$session=epay_user_session_digest($userrow);
$expiretime=time()+604800;
$token=authcode("{$uid}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
epay_set_cookie("user_token", $token, time() + 604800, "/user");

exit("<script language='javascript'>window.location.href='../user/';</script>");
