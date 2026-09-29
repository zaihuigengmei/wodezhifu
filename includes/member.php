<?php
$clientip=real_ip($conf['ip_type']?$conf['ip_type']:0);

if(isset($_COOKIE["admin_token"]))
{
	$token=authcode(daddslashes($_COOKIE['admin_token']), 'DECODE', SYS_KEY);
	list($user, $sid, $expiretime) = explode("\t", $token);
	$session=md5($conf['admin_user'].$conf['admin_pwd'].$password_hash);
	if($session==$sid && $expiretime>time()) {
		$islogin=1;
	}
}
if(isset($_COOKIE["user_token"]))
{
	$token=authcode(daddslashes($_COOKIE['user_token']), 'DECODE', SYS_KEY);
	list($uid, $sid, $expiretime) = explode("\t", $token);
	$uid = intval($uid);
	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid=:uid limit 1", [':uid'=>$uid]);
	$session=$userrow ? epay_user_session_digest($userrow) : '';
	if($userrow && is_string($sid) && hash_equals($session, $sid) && $expiretime>time()) {
		$islogin2=1;
	}
}
// Include the stored password so password changes revoke every older merchant cookie.
function epay_user_session_digest(array $row){
    global $password_hash;
    return hash_hmac('sha256', json_encode([(string)$row['uid'], (string)$row['key'], (string)($row['pwd'] ?? '')]), $password_hash);
}
function epay_oauth_actor(){
    global $islogin2, $uid;
    return isset($islogin2) && $islogin2 == 1 ? (string)$uid : '0';
}
function epay_oauth_issue($provider, $context = ''){
    $state = bin2hex(random_bytes(32));
    $_SESSION['epay_oauth'][$provider] = ['state'=>$state, 'expires'=>time()+300, 'actor'=>epay_oauth_actor(), 'context'=>$context];
    return $state;
}
function epay_oauth_consume($provider, $state, $context = ''){
    $flow = $_SESSION['epay_oauth'][$provider] ?? null;
    unset($_SESSION['epay_oauth'][$provider]);
    if(!is_array($flow) || !is_string($state) || $state === '' || !isset($flow['state']) || !is_string($flow['state']) || $flow['state'] === '' || !hash_equals($flow['state'], $state) || $flow['expires'] < time() || $flow['actor'] !== epay_oauth_actor() || $flow['context'] !== $context){
        throw new \RuntimeException('OAuth state无效或已过期，请重新发起授权');
    }
    return $flow;
}
function epay_result_order($tradeNo, $tid, $owner){
    global $DB;
    if(!is_string($tradeNo) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tradeNo)) return false;
    $row = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no AND tid=:tid AND status=1 LIMIT 1', [':trade_no'=>$tradeNo, ':tid'=>$tid]);
    if(!$row) return false;
    $param = json_decode($row['param'], true);
    return is_array($param) && isset($param['uid']) && is_scalar($param['uid']) && (string)$param['uid'] === (string)$owner ? $row : false;
}