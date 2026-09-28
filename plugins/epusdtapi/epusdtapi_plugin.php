<?php

class epusdtapi_plugin
{
    public static $info = [
        'name'     => 'epusdtapi',
        'showname' => '加密支付接入方式GMPay',
        'author'   => 'GMWalletApp / local integration',
        'link'     => 'https://github.com/GMWalletApp/epusdt',
        'types'    => [
            // 对齐当前 GMPay 后台实际可添加的钱包链：tron、ethereum、solana、binance、polygon、plasma、ton、aptos。
            'usdt.tron',
            'trx.tron',
            'usdt.ethereum',
            'usdc.ethereum',
            'eth.ethereum',
            'usdt.solana',
            'usdc.solana',
            'sol.solana',
            'usdt.binance',
            'usdc.binance',
            'bnb.binance',
            'usdt.polygon',
            'usdc.polygon',
            'matic.polygon',
            'usdt.plasma',
            'usdc.plasma',
            'usdt.ton',
            'gram.ton',
            'usdt.aptos',
            'usdc.aptos',
        ],
        'inputs'   => [
            'appurl' => [
                'name' => '网关地址',
                'type' => 'input',
                'note' => '例如：https://epusdt.example.com ，不要填写 /payments/... 路径',
            ],
            'appid' => [
                'name' => '商户 PID',
                'type' => 'input',
                'note' => 'Epusdt 后台 API Keys 中的 pid，例如 1000',
            ],
            'appkey' => [
                'name' => '签名密钥',
                'type' => 'input',
                'note' => 'Epusdt 后台 API Keys 中 pid 对应的 secret_key',
            ],
            'fiat' => [
                'name' => '交易法币',
                'type' => 'select',
                'options' => [
                    'cny' => '人民币 (CNY)',
                    'usd' => '美元 (USD)',
                    'eur' => '欧元 (EUR)',
                    'gbp' => '英镑 (GBP)',
                    'jpy' => '日元 (JPY)',
                ],
            ],
        ],
        'select'   => null,
        'note'     => '本插件使用 Epusdt GMPay API：/payments/gmpay/v1/order/create-transaction，签名为 HMAC-SHA256。兼容跳转版插件 epusdt 保留不变。',
    ];

    public static function submit(): array
    {
        global $siteurl, $channel, $order, $conf, $DB;

        // Sub2API/外部系统可能会重复打开同一笔 Epay 订单。
        // GMPay 的 order_id 要求唯一，重复创建会返回 10002；因此成功创建后必须复用本地已保存的 payurl。
        if (!empty($order['payurl'])) {
            return ['type' => 'jump', 'url' => $order['payurl']];
        }

        [$token, $network] = self::parseTradeType((string)$order['typename']);
        if ($token === '' || $network === '') {
            return ['type' => 'error', 'msg' => 'Epusdt API 支付类型格式错误，应为 token.network，例如 usdt.tron'];
        }

        $parameter = [
            'pid'          => trim((string)$channel['appid']),
            'order_id'     => TRADE_NO,
            'currency'     => strtolower(trim((string)($channel['fiat'] ?: 'cny'))),
            'token'        => strtolower($token),
            'network'      => strtolower($network),
            'amount'       => self::formatAmount($order['realmoney']),
            'notify_url'   => $conf['localurl'] . 'pay/notify/' . TRADE_NO . '/',
            'redirect_url' => $siteurl . 'pay/return/' . TRADE_NO . '/',
            'name'         => (string)$order['name'],
        ];
        $parameter['signature'] = self::sign($parameter, trim((string)$channel['appkey']));

        $url = self::gateway((string)$channel['appurl']) . '/payments/gmpay/v1/order/create-transaction';
        $data = self::postForm($url, $parameter);
        if (!is_array($data)) {
            return ['type' => 'error', 'msg' => '请求失败，请检查服务器是否能正常请求 Epusdt 网关'];
        }
        if ((int)($data['status_code'] ?? 0) !== 200) {
            $msg = (string)($data['message'] ?? '未知错误');
            $requestId = (string)($data['request_id'] ?? '');
            return ['type' => 'error', 'msg' => 'Epusdt 下单失败：' . $msg . ($requestId !== '' ? '（request_id: ' . $requestId . '）' : '')];
        }

        $paymentUrl = $data['data']['payment_url'] ?? '';
        if ($paymentUrl === '') {
            return ['type' => 'error', 'msg' => 'Epusdt 下单成功但未返回 payment_url'];
        }
        $DB->update('order', ['payurl' => $paymentUrl], ['trade_no' => TRADE_NO]);
        return ['type' => 'jump', 'url' => $paymentUrl];
    }

    public static function notify()
    {
        global $channel, $order;

        ob_clean();
        header('Content-Type: text/plain; charset=utf-8');

        $data = self::readCallbackData();
        if (empty($data)) {
            exit('fail');
        }
        if (!self::verify($data, trim((string)$channel['appkey']))) {
            exit('fail - sign error');
        }

        $outTradeNo = (string)($data['order_id'] ?? '');
        $tradeNo = (string)($data['trade_id'] ?? '');
        $money = $data['amount'] ?? 0;
        $status = (int)($data['status'] ?? 0);
        $buyer = (string)($data['block_transaction_id'] ?? ($data['receive_address'] ?? ''));

        if ($status === 2 && $outTradeNo === TRADE_NO && round((float)$money, 2) === round((float)$order['realmoney'], 2)) {
            processNotify($order, $tradeNo, $buyer);
            exit('ok');
        }

        exit('fail - status error');
    }

    public static function return(): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    private static function parseTradeType(string $type): array
    {
        $type = strtolower(trim($type));
        $parts = explode('.', $type, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }
        return [$parts[0], $parts[1]];
    }

    private static function formatAmount($amount): string
    {
        $formatted = number_format((float)$amount, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function gateway(string $appurl): string
    {
        return rtrim(trim($appurl), '/');
    }

    private static function sign(array $parameter, string $secretKey): string
    {
        unset($parameter['signature']);
        ksort($parameter, SORT_STRING);
        $pairs = [];
        foreach ($parameter as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }
        return hash_hmac('sha256', implode('&', $pairs), $secretKey);
    }

    private static function verify(array $parameter, string $secretKey): bool
    {
        if (empty($parameter['signature'])) {
            return false;
        }
        return hash_equals(self::sign($parameter, $secretKey), (string)$parameter['signature']);
    }

    private static function readCallbackData(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
            parse_str($raw, $form);
            if (is_array($form) && !empty($form)) {
                return $form;
            }
        }
        return array_merge($_GET ?: [], $_POST ?: []);
    }

    private static function postForm(string $url, array $parameter)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameter));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Connection: close',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno || !is_string($resp) || $resp === '') {
            return null;
        }
        return json_decode($resp, true);
    }
}
