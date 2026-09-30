<?php
namespace lib;

use Exception;

class Transfer
{

    static public $payee_err_code = [ //收款方原因导致的失败编码
		'PAYEE_NOT_EXIST','PAYEE_ACCOUNT_STATUS_ERROR','CARD_BIN_ERROR','PAYEE_CARD_INFO_ERROR','PERM_AML_NOT_REALNAME_REV','PAYEE_USER_INFO_ERROR','PAYEE_ACC_OCUPIED','PERMIT_NON_BANK_LIMIT_PAYEE','PAYEE_TRUSTEESHIP_ACC_OVER_LIMIT','PAYEE_ACCOUNT_NOT_EXSIT','PAYEE_USERINFO_STATUS_ERROR','TRUSTEESHIP_RECIEVE_QUOTA_LIMIT','EXCEED_LIMIT_UNRN_DM_AMOUNT','INVALID_CARDNO','RELEASE_USER_FORBBIDEN_RECIEVE','PAYEE_USER_TYPE_ERROR','PAYEE_NOT_RELNAME_CERTIFY','PERMIT_LIMIT_PAYEE',

		'OPENID_ERROR','NAME_MISMATCH','V2_ACCOUNT_SIMPLE_BAN','MONEY_LIMIT','EXCEED_PAYEE_ACCOUNT_LIMIT','PAYEE_ACCOUNT_ABNORMAL','APPID_OR_OPENID_ERR',

		'REALNAME_CHECK_ERROR','RE_USER_NAME_CHECK_ERROR','ERR_TJ_BLACK','USER_FROZEN','TRANSFER_FAIL','TRANSFER_FEE_LIMIT_ERROR',

		'ACCOUNT_FROZEN','REAL_NAME_CHECK_FAIL','NAME_NOT_CORRECT','OPENID_INVALID','TRANSFER_QUOTA_EXCEED','DAY_RECEIVED_QUOTA_EXCEED','MONTH_RECEIVED_QUOTA_EXCEED','DAY_RECEIVED_COUNT_EXCEED','ID_CARD_NOT_CORRECT','ACCOUNT_NOT_EXIST','TRANSFER_RISK','REALNAME_ACCOUNT_RECEIVED_QUOTA_EXCEED','RECEIVE_ACCOUNT_NOT_PERMMIT','PAYEE_ACCOUNT_ABNORMAL','BLOCK_B2C_USERLIMITAMOUNT_BSRULE_MONTH','BLOCK_B2C_USERLIMITAMOUNT_MONTH',
	];

    //通用转账
    //type alipay:支付宝,wxpay:微信,qqpay:QQ钱包,bank:银行卡
    public static function submit($type, $channel, $out_biz_no, $payee_account, $payee_real_name, $money, $title = null, $desc = null){
        global $conf;

        $bizParam = [
            'type' => $type,
            'out_biz_no' => $out_biz_no,
            'payee_account' => $payee_account,
            'payee_real_name' => $payee_real_name,
            'money' => $money,
            'transfer_name' => $title?$title:$conf['transfer_name'],
            'transfer_desc' => $desc?$desc:$conf['transfer_desc'],
        ];
        return \lib\Plugin::call('transfer', $channel, $bizParam);
    }

    public static function add($uid, $type, $out_biz_no, $payee_account, $payee_real_name, $money, $title = null, $desc = null, $bookid = null, $channelid = null){
        global $conf, $DB, $userrow, $siteurl;
        $biz_no = $out_biz_no;
        if(strlen($biz_no)!=19 || !is_numeric($biz_no)) $biz_no = date("YmdHis").rand(11111,99999);

        if($uid > 0){
            if($conf['transfer_minmoney']>0 && $money<$conf['transfer_minmoney']) return ['code'=>-1, 'msg'=>'单笔最小代付金额限制为'.$conf['transfer_minmoney'].'元'];
            if($conf['transfer_maxmoney']>0 && $money>$conf['transfer_maxmoney']) return ['code'=>-1, 'msg'=>'单笔最大代付金额限制为'.$conf['transfer_maxmoney'].'元'];
            if($conf['transfer_maxlimit']>0){
                $a_count = $DB->getColumn('SELECT count(*) FROM pre_transfer WHERE uid=:uid AND type=:type AND account=:account AND paytime>=:paytime', [':uid'=>$uid, ':type'=>$type, ':account'=>$payee_account, ':paytime'=>date('Y-m-d').' 00:00:00']);
                if($a_count >= $conf['transfer_maxlimit']){
                    return ['code'=>-1, 'msg'=>'您今天向该账号的转账次数已达到上限'];
                }
            }
        }
        
        if(!$channelid){
            if($type=='alipay'){
                $channelid = $conf['transfer_alipay'];
            }elseif($type=='wxpay'){
                $channelid = $conf['transfer_wxpay'];
            }elseif($type=='qqpay'){
                if (!is_numeric($payee_account) || strlen($payee_account)<6 || strlen($payee_account)>10) return ['code'=>-1, 'msg'=>'QQ号码格式错误'];
                $channelid = $conf['transfer_qqpay'];
            }elseif($type=='bank'){
                $channelid = $conf['transfer_bank'];
            }else{
                return ['code'=>-1, 'msg'=>'type参数错误'];
            }
            if(!$channelid) return ['code'=>-1, 'msg'=>'未开启此转账方式'];
        }
        if($channelid > 0){
            $channel = \lib\Channel::get($channelid, $userrow['channelinfo']);
            if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];
        }

        if($uid > 0){
            if(($conf['alipay_satf'] ?? 0)==1 && ($type=='alipay' || $type=='bank' && $conf['transfer_alipay']==$conf['transfer_bank'])){
                if(!class_exists('\\lib\\AlipaySATF\\AlipaySATF')) return ['code'=>-2,'msg'=>'安全发SATF已配置但SDK类缺失，无法付款，请联系管理员补齐并审核SDK；未改用普通代付'];
                if(!$bookid) $bookid = $DB->findColumn('satf_account_book', 'id', ['uid'=>$uid, 'status'=>1], 'money DESC');
                $satf = new \lib\AlipaySATF\AlipaySATF();
                $params = [
                    'out_biz_no' => $out_biz_no,
                    'account' => $payee_account,
                    'name' => $payee_real_name,
                    'money' => $money,
                    'remark' => $desc,
                ];
                $result = $satf->transfer($bookid, $type=='bank' ? 2 : 1, $params, $uid);
                return $result;
            }
        }

        try {
            if($DB->db->inTransaction()) throw new \RuntimeException('付款不得在未提交事务内调用');
            Finance::cents($money);
            if(Finance::cents($money)<=0) throw new \RuntimeException('金额必须大于0');
            $intent = Finance::transaction(function() use ($DB, $uid, $type, $out_biz_no, $biz_no, $channelid, $payee_account, $payee_real_name, $money, $title, $desc, $conf){
                // uid=0 also needs a stable serialization point for duplicate external IDs.
                Finance::checked($DB->query('SELECT uid FROM pre_user ORDER BY uid LIMIT 1 FOR UPDATE'));
                $u = $uid>0 ? Finance::row('SELECT * FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]) : null;
                $old = $DB->find('transfer', '*', ['uid'=>$uid,'out_biz_no'=>$out_biz_no]);
                if($old){
                    if($old['type']!=$type || $old['account']!=$payee_account || $old['username']!=$payee_real_name || Finance::cents($old['money'])!=Finance::cents($money)) throw new \RuntimeException('交易号参数不一致');
                    return ['existing'=>$old];
                }
                $cost = $money;
                if($uid>0){
                    if(!$u || !$u['settle']) throw new \RuntimeException('商户无法使用代付');
                    $cost = number_format(round($money*(1+($conf['transfer_rate']?:$conf['settle_rate'])/100),2),2,'.','');
                    $available = Finance::cents($u['money']);
                    if($conf['settle_type']==1) $available -= Finance::cents(number_format((float)$DB->getColumn('SELECT COALESCE(SUM(realmoney),0) FROM pre_order WHERE uid=:uid AND tid<>2 AND status=1 AND endtime>=:day', [':uid'=>$uid,':day'=>date('Y-m-d').' 00:00:00']),2,'.',''));
                    if(Finance::cents($cost)>$available) throw new \RuntimeException('需支付金额大于可转账余额');
                }
                Finance::checked($DB->insert('transfer', ['biz_no'=>$biz_no,'out_biz_no'=>$out_biz_no,'uid'=>$uid,'type'=>$type,'channel'=>$channelid,'account'=>$payee_account,'username'=>$payee_real_name,'money'=>$money,'costmoney'=>$cost,'addtime'=>'NOW()','status'=>$channelid==-1?3:5,'desc'=>$title?:$desc,'result'=>'待核对：已持久化付款intent，禁止重新提交']));
                if($uid>0) Finance::change($uid,$cost,false,'代付',$biz_no,true);
                return ['cost'=>$cost];
            });
            if(isset($intent['existing'])){
                $old=$intent['existing'];
                return ['code'=>in_array((int)$old['status'],[0,1,3],true)?0:-2,'status'=>$old['status'],'biz_no'=>$old['biz_no'],'out_biz_no'=>$out_biz_no,'orderid'=>$old['pay_order_no'],'msg'=>'该交易已存在，请查询原交易；禁止重复付款'];
            }
            if($channelid==-1) return ['code'=>0,'status'=>3,'biz_no'=>$biz_no,'out_biz_no'=>$out_biz_no,'msg'=>'提交成功！等待管理员审核'];
            try { $result=self::submit($type,$channel,$biz_no,$payee_account,$payee_real_name,$money,$title,$desc); }
            catch(\Throwable $e){ return ['code'=>-2,'biz_no'=>$biz_no,'msg'=>'付款结果不确定，请查询原交易，禁止重新提交']; }
            if(!isset($result['code']) || $result['code']!=0 || !in_array((int)($result['status']??-1),[0,1],true)) return ['code'=>-2,'biz_no'=>$biz_no,'msg'=>'付款结果未确认，请查询原交易，禁止重新提交'];
            self::finish($biz_no,(int)$result['status'],null,$result);
            $result['biz_no']=$biz_no; $result['out_biz_no']=$out_biz_no; $result['cost_money']=$intent['cost'];
            if(isset($result['wxpackage'])) $result['jumpurl']=$siteurl.'paypage/wxtrans.php?type=transfer&id='.$biz_no;
            $result['msg']=$result['status']==1?'转账成功':'已提交，请查询原交易';
            return $result;
        } catch(\Throwable $e){ return ['code'=>-2,'biz_no'=>$biz_no,'msg'=>'本地资金操作未完成，请核对原交易，禁止重复付款']; }
    }

    public static function finish($biz_no,$status,$reason=null,$result=[]){
        global $DB;
        if(!in_array((int)$status,[0,1,2],true)) throw new \RuntimeException('状态不合法');
        return Finance::transaction(function() use ($DB,$biz_no,$status,$reason,$result){
            $o=Finance::row('SELECT * FROM pre_transfer WHERE biz_no=:n FOR UPDATE',[':n'=>$biz_no]);
            if(!$o) return false;
            if(in_array((int)$o['status'],[1,2],true)) {
                if((int)$status!==(int)$o['status']) throw new \RuntimeException('终态冲突，需人工核对');
                return true;
            }
            if((int)$o['status']===4 && (int)$status!==2) throw new \RuntimeException('未领取红包只能取消退回');
            if(!in_array((int)$o['status'],[0,3,4,5,6],true)) throw new \RuntimeException('当前状态不能确认付款');
            $data=['status'=>$status,'result'=>$reason??''];
            if($status==1) $data['paytime']=$result['paydate']??'NOW()';
            if(isset($result['orderid'])) $data['pay_order_no']=$result['orderid'];
            if(isset($result['wxpackage'])) $data['ext']=$result['wxpackage'];
            if($status==2 && $o['uid']>0) Finance::change($o['uid'],$o['costmoney'],true,'代付退回',$biz_no);
            Finance::checked($DB->update('transfer',$data,['biz_no'=>$biz_no]));
            return true;
        });
    }

    //转账状态刷新
    public static function status($biz_no){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];
        
        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        try { $result = self::query($order['type'], $channel, $biz_no, $order['pay_order_no']); }
        catch(\Throwable $e){return ['code'=>-2,'msg'=>'查询未确认，请核对原交易'];}
        if(!is_array($result) || !isset($result['code']))return ['code'=>-2,'msg'=>'查询响应无效，请核对原交易'];
        if($result['code'] == 0){
            try { self::finish($biz_no,(int)$result['status'],$result['errmsg']??null,$result); }
            catch(\Throwable $e){return ['code'=>-2,'msg'=>'本地确认失败或终态冲突，请核对原交易'];}
            $result['msg']=$result['status']==1?'转账成功':($result['status']==2?'转账失败，余额已退回':'正在处理');
        }

        return $result;
    }

    //转账查询
    //status 0:处理中 1:成功 2:失败
    public static function query($type, $channel, $biz_no, $pay_order_no){
        $bizParam = [
            'type' => $type,
            'out_biz_no' => $biz_no,
            'orderid' => $pay_order_no
        ];
        return \lib\Plugin::call('transfer_query', $channel, $bizParam);
    }

    //撤销转账
    public static function cancel($biz_no){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];

        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $bizParam = [
            'type' => $order['type'],
            'out_biz_no' => $order['biz_no'],
            'orderid' => $order['pay_order_no'],
        ];
        try {
            $claim=Finance::transaction(function() use($DB,$biz_no){
                $o=Finance::row('SELECT * FROM pre_transfer WHERE biz_no=:n FOR UPDATE',[':n'=>$biz_no]);
                if(!$o || !in_array((int)$o['status'],[0,5],true)) return false;
                Finance::checked($DB->update('transfer',['status'=>6,'result'=>'撤销待核对，禁止重复撤销'],['biz_no'=>$biz_no]));return true;
            });
            if(!$claim)return ['code'=>-2,'msg'=>'撤销已提交或终态不允许，请查询原交易'];
            $result = \lib\Plugin::call('transfer_cancel', $channel, $bizParam);
        }catch(\Throwable $e){return ['code'=>-2,'msg'=>'撤销结果待核对，请查询原交易'];} 
        if($result['code']==0){
            try {self::finish($biz_no,2,'转账已撤销');}
            catch(\Throwable $e){return ['code'=>-2,'msg'=>'撤销结果待核对'];}
        }

        return $result;
    }

    //账户余额查询
    public static function balance($type, $channel, $user_id = null){
        $bizParam = [
            'type' => $type,
            'user_id' => $user_id
        ];
        return \lib\Plugin::call('balance_query', $channel, $bizParam);
    }

    //转账凭证查询
    public static function proof($biz_no){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];
        
        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $bizParam = [
            'type' => $order['type'],
            'out_biz_no' => $biz_no,
            'orderid' => $order['pay_order_no']
        ];
        return \lib\Plugin::call('transfer_proof', $channel, $bizParam);
    }

    //转账回调处理
    public static function processNotify($biz_no, $status, $errmsg = null){
        global $DB;
        if(self::finish($biz_no,(int)$status,$errmsg)) return;
        Finance::transaction(function() use ($DB,$biz_no,$status,$errmsg){
            $o=Finance::row('SELECT * FROM pre_settle WHERE transfer_no=:n FOR UPDATE',[':n'=>$biz_no]);
            if(!$o) return;
            if(!in_array((int)$status,[1,2],true)) return;
            if((int)$o['transfer_status']===2 && (int)$status!==2) throw new \RuntimeException('结算失败终态冲突');
            if((int)$o['transfer_status']===1 && (int)$o['status']===1 && (int)$status!==1) throw new \RuntimeException('结算成功终态冲突');
            if($o['transfer_status']==4) throw new \RuntimeException('结算已取消，回调需人工核对');
            Finance::checked($DB->update('settle',['transfer_status'=>$status,'status'=>$status==1?1:3,'result'=>$errmsg??'','endtime'=>$status==1?'NOW()':null],['id'=>$o['id']]));
        });
    }

    // Existing settlement row is the durable intent. Unknown results are queried, never resubmitted.
    public static function settlePay($id,$type,$channel){
        global $DB;
        try {
            if($DB->db->inTransaction()) throw new \RuntimeException('付款不得在未提交事务中调用');
            $o=Finance::transaction(function() use ($DB,$id,$channel,$type){
                $o=Finance::row('SELECT * FROM pre_settle WHERE id=:id FOR UPDATE',[':id'=>$id]);
                if(!$o || $o['transfer_status']==4) throw new \RuntimeException('结算不存在或已取消');
                if(!empty($o['transfer_no'])) return $o;
                if((int)$o['status']===1) throw new \RuntimeException('已完成结算不能新发起付款');
                if((int)$o['type']!==array_search($type,[1=>'alipay',2=>'wxpay',3=>'qqpay',4=>'bank'],true))throw new \RuntimeException('付款类型不一致');
                $o['transfer_no']=date('YmdHis').str_pad((string)random_int(0,99999),5,'0',STR_PAD_LEFT);
                $o['transfer_channel']=$channel['id'];
                Finance::checked($DB->update('settle',['transfer_no'=>$o['transfer_no'],'transfer_channel'=>$channel['id'],'transfer_status'=>3,'status'=>3,'result'=>'付款待核对，禁止重复提交'],['id'=>$id]));
                $o['new_intent']=true;
                return $o;
            });
            if((int)$o['type']!==array_search($type,[1=>'alipay',2=>'wxpay',3=>'qqpay',4=>'bank'],true) || (int)$o['transfer_channel']!==(int)$channel['id']) throw new \RuntimeException('付款参数不一致');
            $r=!empty($o['new_intent']) ? self::submit($type,$channel,$o['transfer_no'],$o['account'],$o['username'],$o['realmoney']) : self::query($type,$channel,$o['transfer_no'],$o['transfer_result']);
            if(($r['code']??-1)!=0 || !in_array((int)($r['status']??-1),[0,1,2],true)) return ['code'=>-2,'msg'=>'付款待核对，请查询原交易'];
            Finance::transaction(function() use($DB,$id,$o,$r){
                $current=Finance::row('SELECT * FROM pre_settle WHERE id=:id FOR UPDATE',[':id'=>$id]);
                if($current['transfer_no']!==$o['transfer_no'] || $current['transfer_status']==4) throw new \RuntimeException('结算状态冲突');
                if((int)$current['transfer_status']===1 && (int)$current['status']===1 && (int)$r['status']!==1)throw new \RuntimeException('成功终态冲突');
                if($current['transfer_status']==2 && (int)$r['status']!==2) throw new \RuntimeException('失败终态冲突，请人工核对');
                $data=['transfer_status'=>$r['status']==0?3:($r['status']==2?2:1),'status'=>$r['status']==1?1:3,'endtime'=>$r['status']==1?'NOW()':null,'transfer_result'=>$r['orderid']??$current['transfer_result'],'transfer_date'=>$r['paydate']??'NOW()','result'=>$r['errmsg']??''];
                if(isset($r['wxpackage'])) $data['transfer_ext']=$r['wxpackage'];
                Finance::checked($DB->update('settle',$data,['id'=>$id]));
            });
            $r['biz_no']=$o['transfer_no']; if((int)$r['status']===0){$r['code']=-2;$r['msg']='已提交，尚未到账，请查询原交易';} return $r;
        } catch(\Throwable $e){return ['code'=>-2,'msg'=>'付款或本地确认未完成，请核对原交易，禁止重复提交'];}
    }

    public static function settleStatus($id,$status){
        global $DB;
        return Finance::transaction(function() use($DB,$id,$status){
            $o=Finance::row('SELECT * FROM pre_settle WHERE id=:id FOR UPDATE',[':id'=>$id]);
            if(!$o) throw new \RuntimeException('结算不存在');
            if($o['transfer_status']==4) {if($status==4)return true;throw new \RuntimeException('已取消结算不可恢复');}
            if($status==4){
                if($o['status']==1 || $o['status']==2 || (!empty($o['transfer_no']) && $o['transfer_status']!=2)) throw new \RuntimeException('已付款/批次/待核对结算不可退款');
                Finance::change($o['uid'],$o['money'],true,'结算失败退回','settle:'.$id);
                Finance::checked($DB->update('settle',['status'=>3,'transfer_status'=>4,'result'=>'已取消并退回余额'],['id'=>$id]));
            }else{
                if(!in_array($status,[0,1,2,3],true) || !empty($o['transfer_no']) || $o['status']==1) throw new \RuntimeException('此结算需通过付款查询确认');
                Finance::checked($DB->update('settle',['status'=>$status,'endtime'=>$status==1?'NOW()':null],['id'=>$id]));
            }
            return true;
        });
    }

    public static function red_add($uid, $type, $out_biz_no, $money, $desc = null, $channelid = null){
        global $conf, $DB, $userrow;
        $biz_no = $out_biz_no;
        if(strlen($biz_no)!=19 || !is_numeric($biz_no)) $biz_no = date("YmdHis").rand(11111,99999);

        if($uid > 0){
            if($conf['transfer_minmoney']>0 && $money<$conf['transfer_minmoney']) return ['code'=>-1, 'msg'=>'单笔最小代付金额限制为'.$conf['transfer_minmoney'].'元'];
            if($conf['transfer_maxmoney']>0 && $money>$conf['transfer_maxmoney']) return ['code'=>-1, 'msg'=>'单笔最大代付金额限制为'.$conf['transfer_maxmoney'].'元'];
        }
        
        if(!$channelid){
            if($type=='alipay'){
                $channelid = $conf['transfer_alipay'];
            }elseif($type=='wxpay'){
                $channelid = $conf['transfer_wxpay'];
            }else{
                return ['code'=>-1, 'msg'=>'type参数错误'];
            }
            if(!$channelid) return ['code'=>-1, 'msg'=>'未开启此转账方式'];
        }
        $channel = \lib\Channel::get($channelid, $userrow['channelinfo']);
        if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];

        try {
        if($DB->db->inTransaction()) throw new \RuntimeException('红包创建必须独立事务');
        if(Finance::cents($money)<=0) throw new \RuntimeException('金额必须大于0');
        Finance::checked($DB->beginTransaction());
        Finance::checked($DB->query('SELECT uid FROM pre_user ORDER BY uid LIMIT 1 FOR UPDATE'));
        $trans = $DB->find('transfer', '*', ['out_biz_no' => $out_biz_no, 'uid' => $uid]);
        if($trans){$DB->rollBack();return ['code'=>-1,'msg'=>'该交易号已存在，请使用原红包'];}

        $need_money = null;
        if($uid > 0){
            $userrow = $DB->getRow('SELECT * FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
            if($userrow['settle']==0){
                $DB->rollback();
                return ['code'=>-1, 'msg'=>'您的商户出现异常，无法使用代付功能'];
            }
            if($conf['settle_type']==1){
                $today=date("Y-m-d").' 00:00:00';
                $order_today=$DB->getColumn("SELECT SUM(realmoney) from pre_order where uid={$uid} and tid<>2 and status=1 and endtime>='$today'");
                if(!$order_today) $order_today = 0;
                $enable_money=round($userrow['money']-$order_today,2);
                if($enable_money<0)$enable_money=0;
            }else{
                $enable_money=$userrow['money'];
            }
            if(!$conf['transfer_rate'])$conf['transfer_rate'] = $conf['settle_rate'];
            $need_money = round($money + $money*$conf['transfer_rate']/100,2);
            if($need_money>$enable_money){
                $DB->rollback();
                return ['code'=>-1, 'msg'=>'需支付金额大于可转账余额'];
            }
        }
        
        $jumpurl = self::red_url($biz_no);
        $result = ['code'=>0, 'status'=>4, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'jumpurl'=>$jumpurl];

        $data = ['biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'uid'=>$uid, 'type'=>$type, 'channel'=>$channelid, 'account'=>'', 'username'=>'', 'money'=>$money, 'costmoney'=>$need_money??$money, 'addtime'=>'NOW()', 'status'=>$result['status'], 'desc'=>$desc];
        $id = Finance::checked($DB->insert('transfer', $data));
        if($need_money>0 && $id!==false){
            Finance::change($uid, number_format($need_money,2,'.',''), false, '代付', $biz_no, true);
            $result['cost_money'] = $need_money;
        }
        $typename = $type == 'alipay' ? '支付宝' : ($type == 'wxpay' ? '微信' : '未知');
        $result['msg']='红包创建成功！请在'.$typename.'打开 '.$jumpurl.' 确认收款。';
        Finance::checked($DB->commit());
        return $result;
        }catch(\Throwable $e){if($DB->db->inTransaction())$DB->rollBack();return ['code'=>-2,'msg'=>'红包创建未完成'];}
    }

    public static function red_receive($biz_no, $openid){
        global $conf, $DB;

        $func = function() use ($biz_no, $openid){
            global $DB, $userrow;
            $trans = $DB->getRow("SELECT * FROM pre_transfer WHERE biz_no=:biz_no FOR UPDATE", [':biz_no'=>$biz_no]);
            if(!$trans) return ['code'=>-1, 'msg'=>'红包不存在'];
            if($trans['status'] != 4) return ['code'=>-1, 'msg'=>$trans['status']==1?'红包已领取':'红包状态异常，无法领取'];
            $channel = \lib\Channel::get($trans['channel'], $userrow['channelinfo']);
            if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];

            Finance::checked($DB->update('transfer',['status'=>5,'account'=>$openid,'result'=>'领取付款待核对'],['biz_no'=>$biz_no,'status'=>4]));
            Finance::checked($DB->commit());
            $result = self::submit($trans['type'], $channel, $biz_no, $openid, '', $trans['money'], $trans['desc'], $trans['type']=='alipay'?null:$trans['desc']);
            if(($result['code']??-1)==0 && in_array((int)($result['status']??-1),[0,1],true)){
                $data = ['account'=>$openid, 'status'=>$result['status'], 'paytime'=>'NOW()', 'pay_order_no'=>$result['orderid'], 'result'=>''];
                if(isset($result['wxpackage'])){
                    $data['ext'] = $result['wxpackage'];
                    $wxinfo = \lib\Channel::getWeixin($channel['appwxmp']);
                    $result['wxtransfer'] = [
                        'mchId' => $channel['appmchid'],
                        'appId' => $wxinfo['appid'],
                        'package' => $result['wxpackage'],
                    ];
                }
                self::finish($biz_no,(int)$result['status'],null,$result);
            }
            return $result;
        };

        try {
        if($DB->db->inTransaction()) throw new \RuntimeException('领取必须独立事务');
        Finance::checked($DB->beginTransaction());
        $result = $func();
        if($DB->db->inTransaction()) $DB->rollback();
        if(($result['code']??-1)!=0 || !in_array((int)($result['status']??-1),[0,1],true)) $result=['code'=>-2,'msg'=>'领取结果待核对，请查询原交易'];
        return $result;
        }catch(\Throwable $e){if($DB->db->inTransaction())$DB->rollBack();return ['code'=>-2,'msg'=>'领取结果待核对，请查询原交易'];}
    }

    public static function red_url($biz_no){
        global $siteurl;
        $t = time().'';
        $s = md5(SYS_KEY.$biz_no.$t.SYS_KEY);
        return $siteurl.'paypage/red.php?n='.$biz_no.'&t='.$t.'&s='.$s;
    }
}