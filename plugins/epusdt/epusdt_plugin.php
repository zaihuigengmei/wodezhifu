<?php

class epusdt_plugin
{
    public static $info = [
        'name'     => 'epusdt',
        'showname' => '加密支付接入方式EPay兼容',
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
        ],
        'select'   => null,
        'note'     => '本插件使用 Epusdt 的 EPay-compatible redirect checkout，不使用 GMPay API。',
    ];

    public static function submit(): array
    {
        global $siteurl, $channel, $order, $conf, $DB;

        // Sub2API/外部系统可能会重复打开同一笔 Epay 订单。
        // 兼容跳转模式也复用本地已保存的支付入口，避免重复生成同一订单的跳转请求。
        if (!empty($order['payurl'])) {
            return ['type' => 'jump', 'url' => $order['payurl']];
        }

        $parameter = [
            'pid'          => trim($channel['appid']),
            'type'         => $order['typename'],
            'notify_url'   => $conf['localurl'] . 'pay/notify/' . TRADE_NO . '/',
            'return_url'   => $siteurl . 'pay/return/' . TRADE_NO . '/',
            'out_trade_no' => TRADE_NO,
            'name'         => $order['name'],
            'money'        => $order['realmoney'],
            'sign_type'    => 'MD5',
        ];
        $parameter['sign'] = self::sign($parameter, trim($channel['appkey']));

        $submitUrl = self::gateway($channel['appurl']) . '/payments/epay/v1/order/create-transaction/submit.php';
        $payUrl = $submitUrl . '?' . http_build_query($parameter);
        $DB->update('order', ['payurl' => $payUrl], ['trade_no' => TRADE_NO]);
        return ['type' => 'jump', 'url' => $payUrl];
    }

    public static function notify()
    {
        global $channel, $order;
        $data = array_merge($_GET ?: [], $_POST ?: []);
        if (empty($data)) {
            return ['type' => 'html', 'data' => 'fail'];
        }
        if (!self::verify($data, trim($channel['appkey']))) {
            return ['type' => 'html', 'data' => 'fail - sign error'];
        }

        $out_trade_no = $data['out_trade_no'] ?? '';
        $trade_no = $data['trade_no'] ?? ($data['trade_id'] ?? '');
        $money = $data['money'] ?? ($data['amount'] ?? 0);
        $status = $data['trade_status'] ?? ($data['status'] ?? '');
        $pid = isset($data['pid']) ? trim((string)$data['pid']) : trim((string)$channel['appid']);

        if (($status === 'TRADE_SUCCESS' || $status === 'TRADE_FINISHED' || $status === '2' || $status === 2)
            && $out_trade_no == TRADE_NO
            && $pid === trim((string)$channel['appid'])
            && round((float)$money, 2) == round((float)$order['realmoney'], 2)) {
            processNotify($order, $trade_no);
            return ['type' => 'html', 'data' => 'success'];
        }
        return ['type' => 'html', 'data' => 'fail - status error'];
    }

    public static function return(): array
    {
        global $channel, $order;
        $data = array_merge($_GET ?: [], $_POST ?: []);
        if (empty($data)) {
            return ['type' => 'page', 'page' => 'return'];
        }
        if (!self::verify($data, trim($channel['appkey']))) {
            return ['type' => 'error', 'msg' => '签名校验失败'];
        }

        $out_trade_no = $data['out_trade_no'] ?? '';
        $trade_no = $data['trade_no'] ?? ($data['trade_id'] ?? '');
        $money = $data['money'] ?? ($data['amount'] ?? 0);
        $status = $data['trade_status'] ?? ($data['status'] ?? '');
        $pid = isset($data['pid']) ? trim((string)$data['pid']) : trim((string)$channel['appid']);

        if (($status === 'TRADE_SUCCESS' || $status === 'TRADE_FINISHED' || $status === '2' || $status === 2)
            && $out_trade_no == TRADE_NO
            && $pid === trim((string)$channel['appid'])
            && round((float)$money, 2) == round((float)$order['realmoney'], 2)) {
            processReturn($order, $trade_no);
            return ['type' => 'page', 'page' => 'return'];
        }
        return ['type' => 'error', 'msg' => '订单信息校验失败'];
    }

    private static function gateway(string $appurl): string
    {
        $appurl = trim($appurl);
        return rtrim($appurl, '/');
    }

    private static function sign(array $parameter, string $key): string
    {
        ksort($parameter);
        $signstr = '';
        foreach ($parameter as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = rtrim($signstr, '&') . $key;
        return md5($signstr);
    }

    private static function verify(array $parameter, string $key): bool
    {
        if (empty($parameter['sign'])) {
            return false;
        }
        return hash_equals(self::sign($parameter, $key), (string)$parameter['sign']);
    }

    private static function buildAutoSubmitForm(string $url, array $parameter): string
    {
        $html = '<form id="dopay" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" method="post">';
        foreach ($parameter as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"/>';
        }
        $html .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';
        return $html;
    }
}
