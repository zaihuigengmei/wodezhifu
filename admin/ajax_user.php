<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');
csrf_check_json('admin');

@header('Content-Type: application/json; charset=UTF-8');

function epay_ajax_uint($value, $min=1, $max=2147483647){
    if((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) || strlen((string)$value)>10 || $value<$min || $value>$max)
        exit('{"code":-1,"msg":"整数参数不合法"}');
    return (int)$value;
}
function epay_ajax_batch($values, $tokens=false){
    if(!is_array($values) || count($values)<1 || count($values)>500 || array_keys($values)!==range(0,count($values)-1))
        exit('{"code":-1,"msg":"批量参数必须是1至500项的列表"}');
    $result=[];
    foreach($values as $value){
        if($tokens){
            if((!is_string($value) && !is_int($value)) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',(string)$value))
                exit('{"code":-1,"msg":"订单号不合法"}');
            $result[]=(string)$value;
        }else $result[]=epay_ajax_uint($value);
    }
    return array_values(array_unique($result, SORT_STRING));
}

function admin_safe_column($column, $allowed){
	if(!is_string($column)) exit('{"code":-1,"msg":"筛选字段不合法"}');
	$column = trim((string)$column);
	if(!in_array($column, $allowed, true)) exit('{"code":-1,"msg":"筛选字段不合法"}');
	return $column;
}
function admin_safe_date($value){
	if(!is_string($value) && !is_int($value)) exit('{"code":-1,"msg":"参数类型不合法"}');
	$value = trim((string)$value);
	if($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) exit('{"code":-1,"msg":"日期格式不合法"}');
	return $value;
}


function admin_safe_token($value, $name='参数'){
	if(!is_string($value) && !is_int($value)) exit('{"code":-1,"msg":"参数类型不合法"}');
	$value = trim((string)$value);
	if($value === '' || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $value)) exit('{"code":-1,"msg":"'.$name.'不合法"}');
	return $value;
}
function admin_safe_text($value, $max=128){
	if(!is_string($value) && !is_int($value)) exit('{"code":-1,"msg":"参数类型不合法"}');
	$value = trim((string)$value);
	if(strlen($value) > $max * 3) exit('{"code":-1,"msg":"文本过长"}');
	return $value;
}
function admin_html($value){
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

foreach(['value','column','dstatus','order','uid','gid','upid','order_days','type','name','domain','pay_type','pay_account','pay_name'] as $key){
	foreach([$_POST,$_GET] as $input) if(isset($input[$key]) && !is_string($input[$key]) && !is_int($input[$key])) exit('{"code":-1,"msg":"参数类型不合法"}');
}
switch($act){
case 'userList':
	$usergroup = [0=>'默认用户组'];
	$rs = $DB->getAll("SELECT * FROM pre_group");
	foreach($rs as $row){
		$usergroup[$row['gid']] = $row['name'];
	}
	unset($rs);

	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['dstatus']) && !empty($_POST['dstatus'])) {
		$dstatus = explode('_',$_POST['dstatus'],2);
		$col = admin_safe_column($dstatus[0], ['status','pay','settle','cert','mode','gid']);
		$val = intval($dstatus[1]);
		[$sql, $params] = [$sql." AND `{$col}`=:b49", $params + [':b49'=>"{$val}"]];
	}
	if(isset($_POST['gid']) && $_POST['gid']!=='') {
		$gid = intval($_POST['gid']);
		[$sql, $params] = [$sql." AND `gid`=:b50", $params + [':b50'=>"$gid"]];
	}
	if(isset($_POST['upid']) && $_POST['upid']!=='') {
		$upid = intval($_POST['upid']);
		[$sql, $params] = [$sql." AND `upid`=:b51", $params + [':b51'=>"$upid"]];
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['uid','upid','gid','phone','email','qq','url','account','username','status','pay','settle','cert']);
		$value = $_POST['value'];
		[$sql, $params] = [$sql." AND `{$column}`=:b52", $params + [':b52'=>"{$value}"]];
	}
	if(isset($_POST['order_days']) && !empty($_POST['order_days'])) {
		$order_days = intval($_POST['order_days']);
		[$sql, $params] = [$sql." AND uid NOT IN (SELECT DISTINCT uid FROM pre_order WHERE date>=NOW()-INTERVAL :b53 DAY)", $params + [':b53'=>$order_days]];
	}
	$order = "uid desc";
	if(isset($_POST['order']) && !empty($_POST['order'])) {
		$order_raw = trim($_POST['order']);
		if(!preg_match('/^(uid|money|addtime|lasttime|status|gid)_(asc|desc)$/', $order_raw, $m)) exit('{"code":-1,"msg":"排序字段不合法"}');
		$order = $m[1].' '.$m[2];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_user WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_user WHERE{$sql} order by {$order} limit $offset,$limit", $params);
	$list2 = [];
	foreach($list as $row){
		if($row['endtime']!=null && strtotime($row['endtime'])<time()){
			$DB->exec("UPDATE pre_user SET gid=0,endtime=NULL WHERE uid=:b48", [':b48'=>$row['uid']]);
			$row['gid']=0;
		}elseif($row['endtime']!=null){
			$row['endtime'] = date("Y-m-d", strtotime($row['endtime']));
		}
		$row['groupname'] = $usergroup[$row['gid']];
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'recordList':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		[$sql, $params] = [$sql." AND `uid`=:b54", $params + [':b54'=>"$uid"]];
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			[$sql, $params] = [$sql." AND `date`>=:b55", $params + [':b55'=>"{$starttime} 00:00:00"]];
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			[$sql, $params] = [$sql." AND `date`<=:b56", $params + [':b56'=>"{$endtime} 23:59:59"]];
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['id','uid','type','action','trade_no','date','money','domain','content','status']);
		$value = $_POST['value'];
		[$sql, $params] = [$sql." AND `{$column}`=:b57", $params + [':b57'=>"{$value}"]];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_record WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_record WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'record_stats':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		[$sql, $params] = [$sql." AND `uid`=:b58", $params + [':b58'=>"$uid"]];
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			[$sql, $params] = [$sql." AND `date`>=:b59", $params + [':b59'=>"{$starttime} 00:00:00"]];
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			[$sql, $params] = [$sql." AND `date`<=:b60", $params + [':b60'=>"{$endtime} 23:59:59"]];
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['id','uid','type','action','trade_no','date','money','domain','content','status']);
		$value = $_POST['value'];
		[$sql, $params] = [$sql." AND `{$column}`=:b61", $params + [':b61'=>"{$value}"]];
	}
	$result = $DB->getRow("SELECT 
        SUM(CASE WHEN action = 1 THEN money ELSE 0 END) AS incMoney,
        SUM(CASE WHEN action = 2 THEN money ELSE 0 END) AS decMoney
        FROM pre_record WHERE {$sql}", $params);
	$data = [
        'incMoney' => number_format($result['incMoney'] ?? 0, 2, '.', ''),
        'decMoney' => number_format($result['decMoney'] ?? 0, 2, '.', ''),
        'totalMoney' => number_format(($result['incMoney'] ?? 0) - ($result['decMoney'] ?? 0), 2, '.', ''),
    ];
    exit(json_encode(['code' => 0, 'data' => $data]));
break;

case 'userPayStat':
	$startday = admin_safe_date($_POST['startday']);
	$endday = admin_safe_date($_POST['endday']);
	$method = trim($_POST['method']);
	$type = intval($_POST['type']);
	if(!$startday || !$endday)exit(json_encode(['code'=>0, 'msg'=>'no day']));
	$data = [];
	$columns = ['uid'=>'商户ID', 'total'=>'总计'];

	if($method == 'type'){
		$paytype = [];
		$rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
		foreach($rs as $row){
			$paytype[$row['id']] = $row['showname'];
			if($type == 4){
				$columns['type_'.$row['name']] = $row['showname'];
			}else{
				$columns['type_'.$row['id']] = $row['showname'];
			}
		}
		unset($rs);
	}else{
		$channel = [];
		$rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
		foreach($rs as $row){
			$channel[$row['id']] = $row['name'];
		}
		unset($rs);
	}

	if($type == 4){
		$startday .= ' 00:00:00';
		$endday .= ' 23:59:59';
		$rs=$DB->query("SELECT uid,type,channel,money from pre_transfer where status=1 and paytime>=:b62 and paytime<=:b63", [':b62'=>"$startday", ':b63'=>"$endday"]);
		while($row = $rs->fetch())
		{
			$money = (float)$row['money'];
			if(!array_key_exists($row['uid'], $data)) $data[$row['uid']] = ['uid'=>$row['uid'], 'total'=>0];
			$data[$row['uid']]['total'] += $money;
			if($method == 'type'){
				$ukey = 'type_'.$row['type'];
				if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
				else $data[$row['uid']][$ukey] += $money;
			}else{
				$ukey = 'channel_'.$row['channel'];
				if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
				else $data[$row['uid']][$ukey] += $money;
				if(!in_array($ukey, $columns)) $columns[$ukey] = $channel[$row['channel']];
			}
		}
	}else{
		$rs=$DB->query("SELECT uid,type,channel,money,realmoney,getmoney,profitmoney from pre_order where status=1 and date>=:b64 and date<=:b65", [':b64'=>"$startday", ':b65'=>"$endday"]);
		while($row = $rs->fetch())
		{
			if($type == 3){
				$money = (float)$row['profitmoney'];
			}elseif($type == 2){
				$money = (float)$row['getmoney'];
			}elseif($type == 1){
				$money = (float)$row['realmoney'];
			}else{
				$money = (float)$row['money'];
			}
			if(!array_key_exists($row['uid'], $data)) $data[$row['uid']] = ['uid'=>$row['uid'], 'total'=>0];
			$data[$row['uid']]['total'] += $money;
			if($method == 'type'){
				$ukey = 'type_'.$row['type'];
				if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
				else $data[$row['uid']][$ukey] += $money;
			}else{
				$ukey = 'channel_'.$row['channel'];
				if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
				else $data[$row['uid']][$ukey] += $money;
				if(!in_array($ukey, $columns)) $columns[$ukey] = $channel[$row['channel']];
			}
		}
	}
	ksort($data);
	//计算总计
	$total = ['uid'=>'总计'];
	foreach($data as $row){
		foreach($row as $key=>$val){
			if($key=='uid')continue;
			if(!array_key_exists($key, $total)) $total[$key] = $val;
			else $total[$key] += $val;
		}
	}
	array_unshift($data, $total);
	$list = [];
	foreach($data as $row){
		$list[] = $row;
	}
	exit(json_encode(['code'=>0, 'columns'=>$columns, 'data'=>$list]));
break;

case 'userTransferStat':
	$startday = admin_safe_date($_POST['startday']);
	$endday = admin_safe_date($_POST['endday']);
	$method = trim($_POST['method']);
	if(!$startday || !$endday)exit(json_encode(['code'=>0, 'msg'=>'no day']));
	$data = [];
	$columns = ['uid'=>'商户ID', 'total'=>'总计'];

	if($method == 'type'){
		$paytype = [];
		$rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
		foreach($rs as $row){
			$paytype[$row['name']] = $row['showname'];
			$columns['type_'.$row['name']] = $row['showname'];
		}
		unset($rs);
	}else{
		$channel = [];
		$rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
		foreach($rs as $row){
			$channel[$row['id']] = $row['name'];
		}
		unset($rs);
	}

	$rs=$DB->query("SELECT uid,type,channel,money from pre_transfer where status=1 and paytime>=:b66 and paytime<=:b67", [':b66'=>"$startday", ':b67'=>"$endday"]);
	while($row = $rs->fetch())
	{
		$money = (float)$row['money'];
		if(!array_key_exists($row['uid'], $data)) $data[$row['uid']] = ['uid'=>$row['uid'], 'total'=>0];
		$data[$row['uid']]['total'] += $money;
		if($method == 'type'){
			$ukey = 'type_'.$row['type'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
		}else{
			$ukey = 'channel_'.$row['channel'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
			if(!in_array($ukey, $columns)) $columns[$ukey] = $channel[$row['channel']];
		}
	}
	ksort($data);
	$list = [];
	foreach($data as $row){
		$list[] = $row;
	}
	exit(json_encode(['code'=>0, 'columns'=>$columns, 'data'=>$list]));
break;

case 'buyerStat':
	$startday = admin_safe_date($_POST['startday']);
	$endday = admin_safe_date($_POST['endday']);
	$method = intval($_POST['method']);
	if($method == '2') $column = 'mobile';
	else if($method == '1') $column = 'ip';
	else $column = 'buyer';
	if(!$startday || !$endday)exit(json_encode(['code'=>0, 'msg'=>'no day']));
	[$sql, $params] = ["`date` BETWEEN :b68 AND :b69 AND {$column} is not null AND status>0", [':b68'=>"{$startday}", ':b69'=>"{$endday}"]];
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = intval($_POST['type']);
		[$sql, $params] = [$sql." AND `type`=:b70", $params + [':b70'=>"$type"]];
	}
	$list = $DB->getAll("SELECT A.*,ISNULL(B.id) is_black
		FROM (SELECT {$column} `user`,COUNT(*) AS order_count,MAX(trade_no) trade_no
		FROM pre_order
		WHERE {$sql}
		GROUP BY {$column}
		ORDER BY order_count DESC) A
		LEFT JOIN pre_blacklist B ON A.`user`=B.content", $params);
	exit(json_encode($list));
break;

case 'logList':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['value']) && $_POST['value']!=='') {
		$column = admin_safe_column($_POST['column'], ['id','uid','type','date','city','data']);
		$value = $_POST['value'];
		[$sql, $params] = [$sql." AND `{$column}`=:b71", $params + [':b71'=>"{$value}"]];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_log WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_log WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'domainList':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		[$sql, $params] = [$sql." AND `uid`=:b72", $params + [':b72'=>"$uid"]];
	}
	if(isset($_POST['kw']) && $_POST['kw'] !== '') {
		$kw = admin_safe_text($_POST['kw'], 128);
		[$sql, $params] = [$sql." AND `domain`=:b73", $params + [':b73'=>"{$kw}"]];
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = intval($_POST['dstatus']);
		[$sql, $params] = [$sql." AND `status`=:b74", $params + [':b74'=>$dstatus]];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_domain WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_domain WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'blackList':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['kw']) && $_POST['kw'] !== '') {
		$kw = admin_safe_text($_POST['kw'], 128);
		[$sql, $params] = [$sql." AND `content`=:b75", $params + [':b75'=>"{$kw}"]];
	}
	if(isset($_POST['type']) && $_POST['type']>-1) {
		$type = intval($_POST['type']);
		[$sql, $params] = [$sql." AND `type`=:b76", $params + [':b76'=>$type]];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_blacklist WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_blacklist WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'getGroup': //用户组
	$gid=intval($_GET['gid']);
	$row=$DB->getRow("select * from pre_group where gid=:b77 limit 1", [':b77'=>"$gid"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前用户组不存在！"}');
	$result = ['code'=>0,'msg'=>'succ','gid'=>$gid,'name'=>$row['name'],'info'=>json_decode($row['info'],true),'config'=>$row['config']?json_decode($row['config'],true):[],'settings'=>$row['settings']];
	exit(json_encode($result));
break;
case 'delGroup':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$gid=intval($_POST['gid']);
	$row=$DB->getRow("select * from pre_group where gid=:b78 limit 1", [':b78'=>"$gid"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前用户组不存在！"}');
	[$sql, $params] = ["DELETE FROM pre_group WHERE gid=:b80", [':b80'=>"$gid"]];
	if($DB->exec($sql, $params)){
		$DB->exec("UPDATE pre_user SET gid=0 WHERE gid=:b79", [':b79'=>"$gid"]);
		exit('{"code":0,"msg":"删除用户组成功！"}');
	}
	else exit('{"code":-1,"msg":"删除用户组失败['.$DB->error().']"}');
break;
case 'saveGroup':
	if($_POST['action'] == 'add'){
		$name=trim($_POST['name']);
		$row=$DB->getRow("select * from pre_group where name=:b81 limit 1", [':b81'=>"$name"]);
		if($row)
			exit('{"code":-1,"msg":"用户组名称重复"}');
		$info=json_encode($_POST['info']);
		$config=json_encode($_POST['config']);
		$settings=trim($_POST['settings']);
		if($settings && !checkGroupSettings($settings))exit('{"code":-1,"msg":"用户变量格式不正确"}');
		$data = ['name'=>$name, 'info'=>$info, 'config'=>$config, 'settings'=>$settings];
		if($DB->insert('group', $data))exit('{"code":0,"msg":"新增用户组成功！"}');
		else exit('{"code":-1,"msg":"新增用户组失败['.$DB->error().']"}');
	}elseif($_POST['action'] == 'changebuy'){
		$gid=intval($_POST['gid']);
		$status=intval($_POST['status']);
		if($DB->update('group',['isbuy'=>$status],['gid'=>$gid]))exit('{"code":0,"msg":"修改上架状态成功！"}');
		else exit('{"code":-1,"msg":"修改上架状态失败['.$DB->error().']"}');
	}else{
		$gid=intval($_POST['gid']);
		$name=trim($_POST['name']);
		$row=$DB->getRow("select * from pre_group where name=:b82 and gid<>:b83 limit 1", [':b82'=>"$name", ':b83'=>$gid]);
		if($row)
			exit('{"code":-1,"msg":"用户组名称重复"}');
		$info=json_encode($_POST['info']);
		$config=json_encode($_POST['config']);
		$settings=trim($_POST['settings']);
		if($settings && !checkGroupSettings($settings))exit('{"code":-1,"msg":"用户变量格式不正确"}');
		$data = ['name'=>$name, 'info'=>$info, 'config'=>$config, 'settings'=>$settings];
		if($DB->update('group', $data, ['gid'=>$gid])!==false)exit('{"code":0,"msg":"修改用户组成功！"}');
		else exit('{"code":-1,"msg":"修改用户组失败['.$DB->error().']"}');
	}
break;
case 'saveGroupPrice':
	$prices = $_POST['price'];
	$expires = $_POST['expire'];
	$sorts = $_POST['sort'];
	$visibles = $_POST['visible'];
	foreach($prices as $gid=>$item){
		$price = trim($item);
		$expire = intval($expires[$gid]);
		$sort = trim($sorts[$gid]);
		$visible = str_replace('，',',',trim($visibles[$gid]));
		if(!is_numeric($price)||$price<0)exit('{"code":-1,"msg":"GID:'.$gid.'的售价填写错误"}');
		$DB->update('group', ['price'=>$price, 'expire'=>$expire, 'sort'=>$sort, 'visible'=>$visible], ['gid'=>$gid]);
	}
	exit('{"code":0,"msg":"保存成功！"}');
break;

case 'addUser':
	$key = random(32);
	$data = [
		'gid' => intval($_POST['gid']),
		'key' => $key,
		'settle_id' => intval($_POST['settle_id']),
		'account' => trim($_POST['account']),
		'username' => trim($_POST['username']),
		'money' => '0.00',
		'url' => trim($_POST['url']),
		'email' => trim($_POST['email']),
		'qq' => trim($_POST['qq']),
		'phone' => trim($_POST['phone']),
		'mode' => intval($_POST['mode']),
		'cert' => 0,
		'pay' => intval($_POST['pay']),
		'settle' => intval($_POST['settle']),
		'status' => intval($_POST['status']),
		'addtime' => 'NOW()',
	];

	if(empty($data['phone']) && empty($data['email'])) exit('{"code":-1,"msg":"手机号和邮箱不能都为空"}');

	if(!empty($data['phone'])){
		if($DB->find('user','*',['phone'=>$data['phone']])) exit('{"code":-1,"msg":"手机号已存在！"}');
	}
	if(!empty($data['email'])){
		if($DB->find('user','*',['email'=>$data['email']])) exit('{"code":-1,"msg":"邮箱已存在！"}');
	}

	$uid = $DB->insert('user', $data);
	if($uid!==false){
		if(!empty($_POST['pwd'])){
			$pwd = getMd5Pwd(trim($_POST['pwd']), $uid);
			$DB->update('user', ['pwd'=>$pwd], ['uid'=>$uid]);
		}
		exit(json_encode(['code'=>0, 'uid'=>$uid, 'key'=>$key]));
	}else{
		exit('{"code":-1,"msg":"添加商户失败！'.$DB->error().'"}');
	}
break;
case 'editUser':
	$uid=intval($_GET['uid']);
	$rows=$DB->getRow("select * from pre_user where uid=:b84 limit 1", [':b84'=>"$uid"]);
	if(!$rows) exit('{"code":-1,"msg":"当前商户不存在！"}');
	$data = [
		'gid' => intval($_POST['gid']),
		'upid' => intval($_POST['upid']),
		'settle_id' => intval($_POST['settle_id']),
		'account' => trim($_POST['account']),
		'username' => trim($_POST['username']),

		'url' => trim($_POST['url']),
		'email' => trim($_POST['email']),
		'qq' => trim($_POST['qq']),
		'phone' => trim($_POST['phone']),
		'cert' => intval($_POST['cert']),
		'certtype' => intval($_POST['certtype']),
		'certmethod' => intval($_POST['certmethod']),
		'certno' => trim($_POST['certno']),
		'certname' => trim($_POST['certname']),
		'certcorpno' => trim($_POST['certcorpno']),
		'certcorpname' => trim($_POST['certcorpname']),
		'ordername' => trim($_POST['ordername']),
		'mode' => intval($_POST['mode']),
		'pay' => intval($_POST['pay']),
		'settle' => intval($_POST['settle']),
		'status' => intval($_POST['status']),
		'open_code' => intval($_POST['open_code']),
		'remain_money' => !empty($_POST['remain_money']) ? trim($_POST['remain_money']) : null,

	];

	if($DB->update('user', $data, ['uid'=>$uid])!==false){
		if(!empty($_POST['pwd'])){
			$pwd = getMd5Pwd(trim($_POST['pwd']), $uid);
			$DB->update('user', ['pwd'=>$pwd], ['uid'=>$uid]);
		}
		exit('{"code":0,"msg":"商户资料已保存；余额和保证金不通过资料编辑修改，请使用资金流水入口"}');
	}else{
		exit('{"code":-1,"msg":"修改商户信息失败！'.$DB->error().'"}');
	}
break;
case 'edit_keytype':
	$uid=intval($_POST['uid']);
	$keytype=intval($_POST['keytype']);
	$sqs = $DB->update('user', ['keytype'=>$keytype], ['uid'=>$uid]);
	if($sqs!==false){
		exit('{"code":1,"msg":"succ"}');
	}else{
		exit('{"code":-1,"msg":"保存失败！'.$DB->error().'"}');
	}
break;
case 'resetKey':
	$uid=intval($_POST['uid']);
	$key = random(32);
	[$sql, $params] = ["UPDATE pre_user SET `key`=:b85 WHERE uid=:b86", [':b85'=>"$key", ':b86'=>"$uid"]];
	if($DB->exec($sql, $params)!==false)exit('{"code":0,"msg":"重置密钥成功","key":"'.$key.'"}');
	else exit('{"code":-1,"msg":"重置密钥失败['.$DB->error().']"}');
break;
case 'createRsaPair':
	$uid=intval($_POST['uid']);
	$keypair = generate_key_pair();
	$DB->update('user', ['publickey'=>$keypair['public_key']], ['uid'=>$uid]);
	exit(json_encode(['code'=>0, 'msg'=>'succ', 'public_key'=>$keypair['public_key'], 'private_key'=>$keypair['private_key']]));
break;
case 'editUserChannelInfo':
	$uid=intval($_GET['uid']);
	$rows=$DB->getRow("select * from pre_user where uid=:b87 limit 1", [':b87'=>"$uid"]);
	if(!$rows) exit('{"code":-1,"msg":"当前商户不存在！"}');
	$setting=$_POST['setting'];
	$channelinfo = json_encode($setting);
	if($DB->update('user', ['channelinfo'=>$channelinfo], ['uid'=>$uid])!==false){
		exit('{"code":0}');
	}else{
		exit('{"code":-1,"msg":"修改商户信息失败！'.$DB->error().'"}');
	}
break;
case 'delUser':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$uid=intval($_POST['uid']);
	if($DB->exec("DELETE FROM pre_user WHERE uid=:b88", [':b88'=>"$uid"])){
		$DB->exec("DELETE FROM pre_subchannel WHERE uid=:b89", [':b89'=>"$uid"]);
		exit('{"code":0}');
	}else{
		exit('{"code":-1,"msg":"删除商户失败！'.$DB->error().'"}');
	}
break;
case 'setUser':
	$uid=intval($_POST['uid']);
	$type=trim($_POST['type']);
	$status=intval($_POST['status']);
	if($type=='pay')[$sql, $params] = ["UPDATE pre_user SET pay=:b90 WHERE uid=:b91", [':b90'=>"$status", ':b91'=>"$uid"]];
	elseif($type=='settle')[$sql, $params] = ["UPDATE pre_user SET settle=:b92 WHERE uid=:b93", [':b92'=>"$status", ':b93'=>"$uid"]];
	elseif($type=='group')[$sql, $params] = ["UPDATE pre_user SET gid=:b94 WHERE uid=:b95", [':b94'=>"$status", ':b95'=>"$uid"]];
	else [$sql, $params] = ["UPDATE pre_user SET status=:b96 WHERE uid=:b97", [':b96'=>"$status", ':b97'=>"$uid"]];
	if($DB->exec($sql, $params)!==false)exit('{"code":0,"msg":"修改用户成功！"}');
	else exit('{"code":-1,"msg":"修改用户失败['.$DB->error().']"}');
break;
case 'setUserGroup':
	$uid=intval($_POST['uid']);
	$gid=intval($_POST['gid']);
	$endtime=trim($_POST['endtime']);
	if(changeUserGroup($uid, $gid, $endtime)!==false)exit('{"code":0,"msg":"修改用户成功！"}');
	else exit('{"code":-1,"msg":"修改用户失败['.$DB->error().']"}');
break;
case 'resetUser':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$uid=intval($_POST['uid']);
	$key = random(32);
	[$sql, $params] = ["UPDATE pre_user SET `key`=:b98 WHERE uid=:b99", [':b98'=>"$key", ':b99'=>"$uid"]];
	if($DB->exec($sql, $params)!==false)exit('{"code":0,"msg":"重置密钥成功","key":"'.$key.'"}');
	else exit('{"code":-1,"msg":"重置密钥失败['.$DB->error().']"}');
break;
case 'user_settle_info':
	$uid=intval($_GET['uid']);
	$rows=$DB->getRow("select * from pre_user where uid=:b100 limit 1", [':b100'=>"$uid"]);
	if(!$rows)
		exit('{"code":-1,"msg":"当前用户不存在！"}');
	$data = '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算方式</div><select class="form-control" id="pay_type" default="'.admin_html($rows['settle_id']).'">'.($conf['settle_alipay']?'<option value="1">支付宝</option>':null).''.($conf['settle_wxpay']?'<option value="2">微信</option>':null).''.($conf['settle_qqpay']?'<option value="3">QQ钱包</option>':null).''.($conf['settle_bank']?'<option value="4">银行卡</option>':null).'</select></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算账号</div><input type="text" id="pay_account" value="'.admin_html($rows['account']).'" class="form-control" required/></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">真实姓名</div><input type="text" id="pay_name" value="'.admin_html($rows['username']).'" class="form-control" required/></div></div>';
	$data .= '<input type="submit" id="save" onclick="saveInfo('.$uid.')" class="btn btn-primary btn-block" value="保存">';
	$result=array("code"=>0,"msg"=>"succ","data"=>$data,"pay_type"=>$rows['settle_id']);
	exit(json_encode($result));
break;
case 'user_settle_save':
	$uid=intval($_POST['uid']);
	$pay_type=trim($_POST['pay_type']);
	$pay_account=trim($_POST['pay_account']);
	$pay_name=trim($_POST['pay_name']);
	$sds=$DB->exec("update `pre_user` set `settle_id`=:b101,`account`=:b102,`username`=:b103 where `uid`=:b104", [':b101'=>"$pay_type", ':b102'=>"$pay_account", ':b103'=>"$pay_name", ':b104'=>"$uid"]);
	if($sds!==false)
		exit('{"code":0,"msg":"修改记录成功！"}');
	else
		exit('{"code":-1,"msg":"修改记录失败！'.$DB->error().'"}');
break;
case 'user_cert':
	$uid=intval($_GET['uid']);
	$rows=$DB->getRow("select cert,certtype,certmethod,certno,certname,certcorpno,certcorpname,certtime from pre_user where uid=:b105 limit 1", [':b105'=>"$uid"]);
	if(!$rows)
		exit('{"code":-1,"msg":"当前用户不存在！"}');
	$rows['certmethodname'] = show_cert_method($rows['certmethod']);
	$result = ['code'=>0,'msg'=>'succ','uid'=>$uid,'data'=>$rows];
	exit(json_encode($result));
break;
case 'recharge':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$uid=epay_ajax_uint($_POST['uid'] ?? null);
	$do=$_POST['actdo'] ?? null;
	if(!in_array($do, ['0','1',0,1], true)) exit('{"code":-1,"msg":"加扣款操作不合法"}');
	try {
		$rmb=$_POST['rmb'] ?? null;
		if(!is_string($rmb) && !is_int($rmb)) throw new \RuntimeException('金额格式错误');
		$cents=\lib\Finance::cents($rmb);
		if($cents<=0) throw new \RuntimeException('金额必须大于零');
		$actual=\lib\Finance::transaction(function() use ($uid,$do,$cents){
			$row=\lib\Finance::row('SELECT money FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
			if(!$row) throw new \RuntimeException('当前用户不存在！');
			$amount=$cents;
			if((int)$do===1){
				$available=\lib\Finance::cents($row['money'], true);
				if($available<=0) throw new \RuntimeException('当前用户余额不足');
				$amount=min($amount,$available);
			}
			$money=\lib\Finance::amount($amount);
			\lib\Finance::change($uid,$money,(int)$do===0,(int)$do===0 ? '后台加款' : '后台扣款',null,true);
			return $money;
		});
		exit(json_encode(['code'=>0,'msg'=>((int)$do===0 ? '成功加款' : '成功扣款').$actual.'元','money'=>$actual]));
	} catch (\Throwable $e) { exit(json_encode(['code'=>-1,'msg'=>$e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : '资金操作失败，请核对后处理'])); }
break;

case 'addDomain':
	$uid=intval($_POST['uid']);
	$domain = trim($_POST['domain']);
	if(empty($domain))exit('{"code":-1,"msg":"域名不能为空"}');
	if(!checkDomain($domain))exit('{"code":-1,"msg":"域名格式不正确"}');
	$row=$DB->getRow("select uid from pre_user where uid=:b107 limit 1", [':b107'=>"$uid"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前用户不存在！"}');
	if($DB->getRow("select * from pre_domain where uid=:uid and domain=:domain limit 1", [':uid'=>$uid, ':domain'=>$domain]))
		exit('{"code":-1,"msg":"该域名已存在，请勿重复添加"}');
	if(!$DB->exec("INSERT INTO `pre_domain` (`uid`,`domain`,`status`,`addtime`,`endtime`) VALUES (:uid, :domain, 1, NOW(), NOW())", [':uid'=>$uid, ':domain'=>$domain]))exit('{"code":-1,"msg":"添加失败'.$DB->error().'"}');
	exit(json_encode(['code'=>0, 'msg'=>'添加域名成功！']));
break;
case 'setDomainStatus':
	$id=intval($_POST['id']);
	$status=intval($_POST['status']);
	if($DB->exec("UPDATE pre_domain SET status=:b108,endtime=NOW() WHERE id=:b109", [':b108'=>"$status", ':b109'=>"$id"])!==false)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"修改失败['.$DB->error().']"}');
break;
case 'delDomain':
	$id=intval($_POST['id']);
	if($DB->exec("DELETE FROM pre_domain WHERE id=:b110", [':b110'=>"$id"])!==false)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"删除失败['.$DB->error().']"}');
break;
case 'domain_operation':
    if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
    $status=epay_ajax_uint($_POST['status'] ?? null, 0, 3);
    $checkbox=epay_ajax_batch($_POST['checkbox'] ?? null);
    $i=0; $failed=0; $unchanged=0;
    foreach($checkbox as $id){
        if($status===3) $changed=$DB->exec("DELETE FROM pre_domain WHERE id=:id", [':id'=>$id]);
        else $changed=$DB->exec("UPDATE pre_domain SET status=:status,endtime=NOW() WHERE id=:id", [':status'=>$status, ':id'=>$id]);
        if($changed===false) $failed++;
        elseif($changed>0) $i++;
        else $unchanged++;
    }
    exit(json_encode(['code'=>$failed ? -1 : 0, 'msg'=>'成功改变'.$i.'个记录状态', 'changed'=>$i, 'failed'=>$failed, 'unchanged'=>$unchanged, 'total'=>count($checkbox)]));
break;

case 'getChannels':
	$typeid = intval($_GET['typeid']);
	$type=$DB->getColumn("SELECT name FROM pre_type WHERE id=:b114", [':b114'=>"$typeid"]);
	if(!$type)
		exit('{"code":-1,"msg":"当前支付方式不存在！"}');
	$list=$DB->getAll("SELECT id,name FROM pre_channel WHERE `type`=:b115 AND status=1 ORDER BY id ASC", [':b115'=>"$typeid"]);
	if($list){
		$result = ['code'=>0,'msg'=>'succ','data'=>$list];
		exit(json_encode($result));
	}
	else exit('{"code":-1,"msg":"该支付方式下没有可用的支付通道"}');
break;
case 'getSubChannel':
	$id=intval($_GET['id']);
	$row=$DB->getRow("SELECT A.*,B.type FROM pre_subchannel A LEFT JOIN pre_channel B ON A.channel=B.id WHERE A.id=:b116", [':b116'=>"$id"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前子通道不存在！"}');
	$result = ['code'=>0,'msg'=>'succ','data'=>$row];
	exit(json_encode($result));
break;
case 'setSubChannel':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$status=intval($_POST['status']);
	$row=$DB->getRow("SELECT * FROM pre_subchannel WHERE id=:b117", [':b117'=>"$id"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前子通道不存在！"}');
	[$sql, $params] = ["UPDATE pre_subchannel SET status=:b118 WHERE id=:b119", [':b118'=>"$status", ':b119'=>"$id"]];
	if($DB->exec($sql, $params))exit('{"code":0,"msg":"修改子通道成功！"}');
	else exit('{"code":-1,"msg":"修改子通道失败['.$DB->error().']"}');
break;
case 'delSubChannel':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$row=$DB->getRow("SELECT * FROM pre_subchannel WHERE id=:b120", [':b120'=>"$id"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前子通道不存在！"}');
	[$sql, $params] = ["DELETE FROM pre_subchannel WHERE id=:b121", [':b121'=>"$id"]];
	if($DB->exec($sql, $params))exit('{"code":0,"msg":"删除子通道成功！"}');
	else exit('{"code":-1,"msg":"删除子通道失败['.$DB->error().']"}');
break;
case 'saveSubChannel':
	if($_POST['action'] == 'add'){
		$uid=intval($_POST['uid']);
		$name=trim($_POST['name']);
		$type=intval($_POST['type']);
		$channel=intval($_POST['channel']);
		$row=$DB->getRow("SELECT * FROM pre_subchannel WHERE name=:b122 AND uid=:b123 LIMIT 1", [':b122'=>"$name", ':b123'=>"$uid"]);
		if($row)
			exit('{"code":-1,"msg":"子通道备注重复"}');
		$data = ['channel'=>$channel, 'uid'=>$uid, 'name'=>$name, 'addtime'=>'NOW()', 'usetime'=>'NOW()'];
		if($DB->insert('subchannel', $data))exit('{"code":0,"msg":"新增子通道成功！"}');
		else exit('{"code":-1,"msg":"新增子通道失败['.$DB->error().']"}');
	}else{
		$id=intval($_POST['id']);
		$row=$DB->getRow("SELECT * FROM pre_subchannel WHERE id=:b124", [':b124'=>"$id"]);
		if(!$row) exit('{"code":-1,"msg":"当前子通道不存在！"}');
		$uid=intval($_POST['uid']);
		$name=trim($_POST['name']);
		$type=intval($_POST['type']);
		$channel=intval($_POST['channel']);
		$nrow=$DB->getRow("SELECT * FROM pre_subchannel WHERE name=:b125 AND uid=:b126 AND id<>:b127 LIMIT 1", [':b125'=>"$name", ':b126'=>"$uid", ':b127'=>$id]);
		if($nrow)
			exit('{"code":-1,"msg":"子通道名称重复"}');
		$data = ['channel'=>$channel, 'name'=>$name];
		if($DB->update('subchannel', $data, ['id'=>$id])!==false){
			exit('{"code":0,"msg":"修改子通道成功！"}');
		}else exit('{"code":-1,"msg":"修改子通道失败['.$DB->error().']"}');
	}
break;
case 'subChannelInfo':
	$id=intval($_GET['id']);
	$subrow=$DB->getRow("SELECT * FROM pre_subchannel WHERE id=:b128", [':b128'=>"$id"]);
	if(!$subrow)
		exit('{"code":-1,"msg":"当前子通道不存在！"}');
	$row=$DB->getRow("SELECT * FROM pre_channel WHERE id=:b129", [':b129'=>$subrow['channel']]);
	if(!$row)
		exit('{"code":-1,"msg":"当前子通道对应支付通道不存在！"}');
	$plugin = \lib\Plugin::getConfig($row['plugin']);
	if(!$plugin)
		exit('{"code":-1,"msg":"当前支付插件不存在！"}');

	$info = json_decode($subrow['info'], true) ?: [];
	$config = json_decode($row['config'],true) ?: [];
	$data = '<div class="modal-body"><form class="form" id="form-info"><input type="hidden" name="id" value="'.intval($id).'">';
	foreach($plugin['inputs'] as $key=>$input){
		if(isset($config[$key]) && substr($config[$key],0,1)=='['){
			$key = substr($config[$key],1,-1);
			if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $key)) continue;
			$name = admin_html($input['name'] ?? $key);
			$note = admin_html($input['note'] ?? '');
			$value = admin_html($info[$key] ?? '');
			$key_html = admin_html($key);
			if(($input['type'] ?? '') == 'textarea'){
				$data .= '<div class="form-group"><label>'.$name.'：</label><br/><textarea id="'.$key_html.'" name="info['.$key_html.']" rows="2" class="form-control" placeholder="'.$note.'">'.$value.'</textarea></div>';
			}elseif(($input['type'] ?? '') == 'select'){
				$addOptions = '';
				foreach(($input['options'] ?? []) as $k=>$v){
					$k_html = admin_html($k);
					$v_html = admin_html($v);
					$addOptions.='<option value="'.$k_html.'" '.(($info[$key] ?? null)==$k?'selected':'').'>'.$v_html.'</option>';
				}
				$data .= '<div class="form-group"><label>'.$name.'：</label><br/><select class="form-control" name="info['.$key_html.']" default="'.$value.'">'.$addOptions.'</select></div>';
			}else{
				$data .= '<div class="form-group"><label>'.$name.'：</label><br/><input type="text" id="'.$key_html.'" name="info['.$key_html.']" value="'.$value.'" class="form-control" placeholder="'.$note.'"/></div>';
			}
		}
	}

	$data .= '<button type="button" id="save" onclick="saveInfo('.intval($id).')" class="btn btn-primary btn-block">保存</button></form></div>';
	$result=array("code"=>0,"msg"=>"succ","data"=>$data);
	exit(json_encode($result));
break;
case 'saveSubChannelInfo':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
    $id=epay_ajax_uint($_POST['id'] ?? null);
    $subrow=$DB->getRow("SELECT * FROM pre_subchannel WHERE id=:id", [':id'=>$id]);
    if(!$subrow) exit('{"code":-1,"msg":"当前子通道不存在！"}');
    $row=$DB->getRow("SELECT * FROM pre_channel WHERE id=:id", [':id'=>$subrow['channel']]);
    if(!$row) exit('{"code":-1,"msg":"支付通道不存在！"}');
    $plugin=\lib\Plugin::getConfig($row['plugin']);
    if(!$plugin || !isset($plugin['inputs']) || !is_array($plugin['inputs'])) exit('{"code":-1,"msg":"支付插件不存在！"}');
    $config=json_decode($row['config'], true) ?: [];
    $allowed=[];
    foreach($plugin['inputs'] as $key=>$input){
        if(isset($config[$key]) && is_string($config[$key]) && preg_match('/^\[([a-zA-Z0-9_.:-]{1,64})\]$/D', $config[$key], $match)) $allowed[$match[1]]=$input;
    }
    $info=$_POST['info'] ?? [];
    if(!is_array($info) || count($info)>count($allowed)) exit('{"code":-1,"msg":"支付参数不合法"}');
    foreach($info as $key=>$value){
        if(!array_key_exists($key, $allowed) || !is_string($value) || strlen($value)>65536) exit('{"code":-1,"msg":"支付参数不合法"}');
        if(($allowed[$key]['type'] ?? '')==='select' && !array_key_exists($value, $allowed[$key]['options'] ?? [])) exit('{"code":-1,"msg":"支付参数选项不合法"}');
    }
    $info=$info ? json_encode($info) : null;
	if($DB->update('subchannel', ['info'=>$info], ['id'=>$id])!==false)exit('{"code":0,"msg":"修改自定义支付参数成功！"}');
	else exit('{"code":-1,"msg":"修改自定义支付参数失败['.$DB->error().']"}');
break;

case 'addBlack':
	$type=intval($_POST['type']);
	$content = trim($_POST['content']);
	$days=intval($_POST['days']);
	$remark = trim($_POST['remark']);
	if(empty($content))exit('{"code":-1,"msg":"拉黑内容不能为空"}');
	if($DB->getRow("select * from pre_blacklist where type=:type and content=:content limit 1", [':type'=>$type, ':content'=>$content]))
		exit('{"code":-1,"msg":"该黑名单记录已存在，请勿重复添加"}');
	$endtime = $days > 0 ? date('Y-m-d H:i:s', strtotime('+'.$days.' days')) : null;
	$data = ['type'=>$type, 'content'=>$content, 'addtime'=>'NOW()', 'endtime'=>$endtime, 'remark'=>$remark];
	if($DB->insert('blacklist', $data))exit(json_encode(['code'=>0, 'msg'=>'添加黑名单成功！']));
	else exit('{"code":-1,"msg":"添加失败'.$DB->error().'"}');
break;
case 'delBlack':
	$id=intval($_POST['id']);
	if($DB->exec("DELETE FROM pre_blacklist WHERE id=:b130", [':b130'=>"$id"])!==false)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"删除失败['.$DB->error().']"}');
break;
case 'batchdelBlack':
    if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
    $ids=epay_ajax_batch($_POST['checkbox'] ?? null);
    $holders=[]; $params=[];
    foreach($ids as $n=>$id){ $holders[]=':id'.$n; $params[':id'.$n]=$id; }
    $i=$DB->exec('DELETE FROM pre_blacklist WHERE id IN ('.implode(',', $holders).')', $params);
    exit(json_encode(['code'=>$i===false ? -1 : 0, 'msg'=>$i===false ? '删除失败' : '成功删除了'.$i.'个黑名单', 'changed'=>$i===false ? 0 : $i]));
break;

case 'delRecord':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	if($DB->exec("DELETE FROM pre_record WHERE id=:b131", [':b131'=>"$id"])!==false)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"删除失败['.$DB->error().']"}');
break;

case 'checkuid':
	$uid=intval($_GET['uid']);
	$row=$DB->getRow("select * from pre_user where uid=:b132 limit 1", [':b132'=>"$uid"]);
	if($row)
		exit('{"code":0,"msg":"succ"}');
	else
		exit('{"code":-1,"msg":"当前商户ID不存在"}');
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}