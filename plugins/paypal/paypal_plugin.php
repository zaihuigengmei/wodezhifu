<?php

class paypal_plugin
{
	static private function paypalExpectedAmount($order, $channel){
		$rate = $channel['currency_rate'] ?? '';
		if($rate === '') $rate = '1';
		$money = $order['realmoney'] ?? null;
		if(!is_scalar($rate) || !preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,8})?\z/', (string)$rate)
			|| !is_scalar($money) || !preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/', (string)$money)
			|| !function_exists('bcmul') || bccomp((string)$rate, '0', 8) <= 0) throw new Exception('PayPal金额或汇率配置无效（需要BCMath）');
		$scale = in_array(self::paypalCurrency($channel), ['JPY','HUF','TWD'], true) ? 0 : 2;
		$amount = bcadd(bcmul((string)$money, (string)$rate, 10), $scale === 0 ? '0.5' : '0.005', $scale);
		if(bccomp($amount, '0', $scale) <= 0) throw new Exception('PayPal金额必须大于零');
		return $amount;
	}
	static private function paypalCurrency($channel){
		$currency = $channel['currency_code'] ?? 'USD';
		if(!is_string($currency) || !isset(self::$info['inputs']['currency_code']['options'][$currency])) throw new Exception('PayPal币种配置无效');
		return $currency;
	}
	// PayPal value is in major currency units, never cents; no epsilon or unit guessing.
	static private function paypalCaptureValid($capture, $order, $channel){
		try {
			if(!is_array($capture) || ($capture['status'] ?? null) !== 'COMPLETED'
				|| !is_string($capture['id'] ?? null) || $capture['id'] === ''
				|| ($capture['invoice_id'] ?? null) !== (string)$order['trade_no']) return false;
			if(isset($capture['custom_id']) && $capture['custom_id'] !== (string)$order['trade_no']) return false;
			$currency = self::paypalCurrency($channel);
			$value = $capture['amount']['value'] ?? null;
			$scale = in_array($currency, ['JPY','HUF','TWD'], true) ? 0 : 2;
			$pattern = $scale === 0 ? '/\A[0-9]{1,16}\z/' : '/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/';
			return is_string($value) && preg_match($pattern, $value)
				&& ($capture['amount']['currency_code'] ?? null) === $currency
				&& bccomp($value, self::paypalExpectedAmount($order, $channel), $scale) === 0;
		} catch (Exception $e) { return false; }
	}

	static public $info = [
		'name'        => 'paypal', //支付插件英文名称，需和目录名称一致，不能有重复
		'showname'    => 'PayPal', //支付插件显示名称
		'author'      => 'PayPal', //支付插件作者
		'link'        => 'https://www.paypal.com/', //支付插件作者链接
		'types'       => ['paypal'], //支付插件支持的支付方式，可选的有alipay,qqpay,wxpay,bank
		'inputs' => [ //支付插件要求传入的参数以及参数显示名称，可选的有appid,appkey,appsecret,appurl,appmchid
			'appid' => [
				'name' => 'ClientId',
				'type' => 'input',
				'note' => '',
			],
			'appkey' => [
				'name' => 'ClientSecret',
				'type' => 'input',
				'note' => '',
			],
			'appsecret' => [
				'name' => 'Webhook ID（可选）',
				'type' => 'input',
				'note' => '固定地址见插件WEBHOOK.md；留空则安全拒绝异步通知',
			],
			'appswitch' => [
				'name' => '模式选择',
				'type' => 'select',
				'options' => [0=>'线上模式',1=>'沙盒模式'],
			],
			'currency_code' => [
				'name' => '结算货币',
				'type' => 'select',
				'options' => [
					'USD' => '美元 (USD)',
					'AUD' => '澳元 (AUD)',
					'BRL' => '巴西雷亚尔 (BRL)',
					'CAD' => '加拿大元 (CAD)',
					'CNY' => '人民币 (CNY)',
					'CZK' => '克朗 (CZK)',
					'DKK' => '丹麦克朗(DKK)',
					'EUR' => '欧元 (EUR)',
					'HKD' => '港币 (HKD)',
					'HUF' => '匈牙利福林 (HUF)',
					'INR' => '印度卢比 (INR)',
					'ILS' => '以色列新谢克尔 (ILS)',
					'JPY' => '日元 (JPY)',
					'MYR' => '马来西亚林吉特 (MYR)',
					'MXN' => '墨西哥比索 (MXN)',
					'TWD' => '新台币 (TWD)',
					'NZD' => '新西兰元 (NZD)',
					'NOK' => '挪威克朗 (NOK)',
					'PHP' => '菲律宾比索 (PHP)',
					'PLN' => '波兰兹罗提 (PLN)',
					'GBP' => '英镑 (GBP)',
					'RUB' => '俄罗斯卢布 (RUB)',
					'SGD' => '新加坡元 (SGD)',
					'SEK' => '瑞典克朗 (SEK)',
					'CHF' => '瑞士法郎 (CHF)',
					'THB' => '泰铢 (THB)',
				],
			],
			'currency_rate' => [
				'name' => '货币汇率',
				'type' => 'input',
				'note' => '例如1元人民币兑换0.137美元(USD)，则此处填0.137',
			],
		],
		'select' => null,
		'note' => '异步通知须配置Webhook ID和固定 plugins/paypal/webhook.php?channel=通道ID&uid=商户ID&sub=子通道ID（无子通道填0），订阅PAYMENT.CAPTURE.COMPLETED。详见WEBHOOK.md。旧订单路由不支持Webhook；修改汇率前处理完存量订单。',
		'bindwxmp' => false, //是否支持绑定微信公众号
		'bindwxa' => false, //是否支持绑定微信小程序
	];

	static public function submit(){
		global $siteurl, $channel, $order, $ordername, $sitename, $conf, $DB;

		require_once(PAY_ROOT."inc/PayPalClient.php");

		try { $money = self::paypalExpectedAmount($order, $channel); }
		catch (Exception $ex) { return ['type'=>'error','msg'=>$ex->getMessage()]; }

		$parameter = [
            'intent'            => 'CAPTURE',
            'purchase_units'    => [
                [
                    'amount'        => [
                        'currency_code' => self::paypalCurrency($channel),
                        'value'         => $money,
                    ],
                    'description'   => $order['name'],
					'custom_id'     => TRADE_NO,
                    'invoice_id'    => TRADE_NO,
                ],
            ],
            'application_context'=> [
                'cancel_url'    => $siteurl.'pay/cancel/'.TRADE_NO.'/',
                'return_url'    => $siteurl.'pay/return/'.TRADE_NO.'/',
            ],
        ];

		try {
			$approvalUrl = \lib\Payment::lockPayData(TRADE_NO, function() use($channel, $parameter) {
				$client = new PayPalClient($channel['appid'], $channel['appkey'], $channel['appswitch']);
				$result = $client->createOrder($parameter);

				$approvalUrl = null;
				foreach($result['links'] as $link){
					if($link['rel'] == 'approve'){
						$approvalUrl = $link['href'];
					}
				}
				if(empty($approvalUrl)){
					throw new Exception('获取支付链接失败');
				}
				return $approvalUrl;
			});

			return ['type'=>'jump','url'=>$approvalUrl];
		}
		catch (Exception $ex) {
			sysmsg('PayPal下单失败：'.$ex->getMessage());
		}
	}

	//同步回调
	static public function return(){
		global $channel, $order;

		require_once(PAY_ROOT."inc/PayPalClient.php");
		
		if (isset($_GET['token'], $_GET['PayerID']) && is_string($_GET['token']) && preg_match('/\A[A-Z0-9]{8,32}\z/', $_GET['token'])) {
		
			$token = $_GET['token'];
			try {
				$client = new PayPalClient($channel['appid'], $channel['appkey'], $channel['appswitch']);
				$result = $client->captureOrder($token);
			} catch (Exception $ex) {
				return ['type'=>'error','msg'=>'支付订单失败 '.$ex->getMessage()];
			}

			$captures = $result['purchase_units'][0]['payments']['captures'] ?? [];
			if(($result['status'] ?? null) === 'COMPLETED' && ($result['id'] ?? null) === $token
				&& count($result['purchase_units'] ?? []) === 1 && count($captures) === 1
				&& (string)$order['trade_no'] === (string)TRADE_NO
				&& self::paypalCaptureValid($captures[0], $order, $channel)){
				processReturn($order, $captures[0]['id'], $result['payer']['email_address'] ?? null);
			}else{
				return ['type'=>'error','msg'=>'订单信息校验失败'];
			}
		} else {
			return ['type'=>'error','msg'=>'PayPal返回参数错误'];
		}
	}

	static public function cancel(){
		return ['type'=>'page','page'=>'error'];
	}

	static public function webhook(){
		global $channel, $order;
		// The core loader only supports order-bound routes, not a fixed channel webhook.
		// Do not acknowledge payments until channel/order routing is integrated and tested.
		http_response_code(503);
		return ['type'=>'html', 'data'=>'PayPal webhook routing is not configured'];

	}

	// Only the dedicated fixed endpoint calls this; the order loader is unchanged.
	static public function fixedWebhook($binding, $raw, $headers){
		global $DB, $conf, $channel, $order;
		$reply = static function($code, $text){ return ['status'=>$code, 'body'=>$text]; };
		if(!is_array($binding)) return $reply(400, 'Invalid binding');
		foreach(['channel','uid','sub'] as $key){
			if(!is_string($binding[$key] ?? null) || !preg_match($key === 'sub' ? '/\A(?:0|[1-9][0-9]{0,9})\z/' : '/\A[1-9][0-9]{0,9}\z/', $binding[$key])) return $reply(400, 'Invalid binding');
		}
		if(!is_string($raw) || strlen($raw) > 262144) return $reply(413, 'Event too large');
		$event = json_decode($raw, true, 32);
		if(!is_array($event) || !is_string($event['id'] ?? null)) return $reply(400, 'Invalid event');
		$user = $DB->find('user', 'gid,channelinfo', ['uid'=>$binding['uid']]);
		if(!$user) return $reply(404, 'Unknown binding');
		$channel = $binding['sub'] !== '0' ? \lib\Channel::getSub($binding['sub']) : \lib\Channel::get($binding['channel'], $user['channelinfo']);
		if(!$channel || ($channel['plugin'] ?? null) !== 'paypal' || (string)$channel['id'] !== $binding['channel']
			|| (string)($channel['subid'] ?? '0') !== $binding['sub']) return $reply(404, 'Unknown binding');
		if(!is_string($channel['appsecret'] ?? null) || !preg_match('/\A[a-zA-Z0-9]{1,50}\z/', $channel['appsecret'])
			|| empty($channel['appid']) || empty($channel['appkey']) || !in_array((string)($channel['appswitch'] ?? ''), ['0','1'], true)) return $reply(503, 'Webhook configuration required');
		$params = ['webhook_id'=>$channel['appsecret'], 'webhook_event'=>json_decode($raw, false, 32)];
		foreach(['auth_algo'=>100,'cert_url'=>500,'transmission_id'=>50,'transmission_sig'=>500,'transmission_time'=>100] as $key=>$max){
			$value = $headers[$key] ?? null;
			if(!is_string($value) || $value === '' || strlen($value)>$max || preg_match('/[\x00-\x20\x7f]/', $value)) return $reply(400, 'Missing or invalid signature headers');
			$params[$key] = $value;
		}
		$certHost = (string)$channel['appswitch'] === '1' ? 'api.sandbox.paypal.com' : 'api.paypal.com';
		if($params['auth_algo'] !== 'SHA256withRSA' || !preg_match('~\Ahttps://'.preg_quote($certHost,'~').'/v1/notifications/certs/[a-zA-Z0-9-]+\z~', $params['cert_url'])) return $reply(400, 'Invalid signing certificate');
		require_once __DIR__.'/inc/PayPalClient.php';
		try {
			$client = new PayPalClient($channel['appid'], $channel['appkey'], $channel['appswitch']);
			if(!$client->verifyWebhook($params)) return $reply(401, 'Signature verification failed');
			if(($event['event_type'] ?? null) !== 'PAYMENT.CAPTURE.COMPLETED') return $reply(200, 'Ignored event');
			$capture = $event['resource'] ?? [];
			$trade = $capture['invoice_id'] ?? null;
			if(!is_string($trade) || !preg_match('/\A[0-9]{8,32}\z/', $trade)) return $reply(400, 'Invalid invoice');
			$order = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no'=>$trade]);
			if(!$order || (string)$order['uid'] !== $binding['uid'] || (string)$order['channel'] !== $binding['channel']
				|| (string)$order['subchannel'] !== $binding['sub']) return $reply(409, 'Order binding mismatch');
			if(!self::paypalCaptureValid($capture, $order, $channel)) return $reply(409, 'Capture mismatch');
			$remote = $capture['supplementary_data']['related_ids']['order_id'] ?? null;
			if(!is_string($remote) || !preg_match('/\A[A-Z0-9]{8,32}\z/', $remote)) return $reply(409, 'Missing PayPal order');
			// Existing orders store their approval URL in ext; bind its token to the signed event.
			$approval = @unserialize($order['ext'] ?? '', ['allowed_classes'=>false]);
			if(!is_string($approval)) return $reply(409, 'Stored PayPal order unavailable');
			$approvalParts = parse_url($approval);
			$approvalHost = (string)$channel['appswitch'] === '1' ? 'www.sandbox.paypal.com' : 'www.paypal.com';
			if(!$approvalParts || ($approvalParts['scheme'] ?? '') !== 'https' || ($approvalParts['host'] ?? '') !== $approvalHost) return $reply(409, 'Stored PayPal order invalid');
			parse_str($approvalParts['query'] ?? '', $approvalQuery);
			if(($approvalQuery['token'] ?? null) !== $remote) return $reply(409, 'PayPal order mismatch');
			$detail = $client->orderDetail($remote);
			$captures = $detail['purchase_units'][0]['payments']['captures'] ?? [];
			if(($detail['id'] ?? null) !== $remote || ($detail['status'] ?? null) !== 'COMPLETED'
				|| count($detail['purchase_units'] ?? []) !== 1 || count($captures) !== 1
				|| ($captures[0]['id'] ?? null) !== $capture['id'] || !self::paypalCaptureValid($captures[0], $order, $channel)) return $reply(409, 'Verified order mismatch');
			if(!empty($order['api_trade_no']) && $order['api_trade_no'] !== $capture['id']) return $reply(409, 'Capture identity conflict');
			if((int)$order['status'] === 1) return $order['api_trade_no'] === $capture['id'] ? $reply(200, 'Already processed') : $reply(409, 'Paid order capture unavailable');
			if(!in_array((int)$order['status'], [0,4], true)) return $reply(409, 'Order state conflict');
			$conf = array_merge($conf, getGroupConfig($user['gid']));
			$order['plugin'] = 'paypal';
			processNotify($order, $capture['id'], $detail['payer']['email_address'] ?? null);
			// processNotify has no success return contract: acknowledge only persisted success.
			$saved = $DB->getRow('SELECT status,api_trade_no FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no'=>$trade]);
			if(!$saved || (int)$saved['status'] !== 1 || $saved['api_trade_no'] !== $capture['id']) return $reply(503, 'Payment persistence not confirmed');
			return $reply(200, 'OK');
		} catch (\Throwable $e) { return $reply(503, 'Webhook processing unavailable'); }
	}

	//退款
	static public function refund($order){
		global $channel;
		if(empty($order))exit();

		require_once(PAY_ROOT."inc/PayPalClient.php");

		try { $money = self::paypalExpectedAmount(['realmoney'=>$order['refundmoney']], $channel); }
		catch (Exception $ex) { return ['code'=>-1,'msg'=>$ex->getMessage()]; }

		$parameter = [
            'amount'    => [
                'currency_code'  => self::paypalCurrency($channel),
                'value'     => $money,
            ],
        ];

		try{
			$client = new PayPalClient($channel['appid'], $channel['appkey'], $channel['appswitch']);
			$res = $client->refundPayment($order['api_trade_no'], $parameter);
			$result = ['code'=>0, 'trade_no'=>$res['id'], 'refund_fee'=>$res['amount']['value']];
		}catch(Exception $e){
			$result = ['code'=>-1, 'msg'=>$e->getMessage()];
		}
		return $result;
	}

}