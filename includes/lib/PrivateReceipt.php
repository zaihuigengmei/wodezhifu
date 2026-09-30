<?php
namespace lib;

/** Private receipts: browser sessions and independently verified signed API downloads. */
final class PrivateReceipt {
    const MAX_BYTES = 10485760;
    const TTL = 86400;

    private static function token($value) {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)) throw new \RuntimeException('回单对象不合法');
        return $value;
    }
    private static function directory() {
        $path = getenv('EPAY_PRIVATE_RECEIPT_DIR');
        if (!$path || $path[0] !== '/' || strpos($path, "\0") !== false) throw new \RuntimeException('请配置站点外 EPAY_PRIVATE_RECEIPT_DIR');
        // Provision explicitly; never create arbitrary environment-controlled directory trees.
        $dir = realpath($path);
        if (!$dir || is_link($path) || !is_dir($dir)) throw new \RuntimeException('私有回单目录不存在');
        foreach ([realpath(ROOT), realpath($_SERVER['DOCUMENT_ROOT'] ?? ROOT)] as $root) {
            if ($root && ($dir === $root || strpos($dir.'/', $root.'/') === 0 || strpos($root.'/', $dir.'/') === 0)) throw new \RuntimeException('回单目录必须独立于站点目录');
        }
        if ((fileperms($dir) & 0777) !== 0700 || !is_writable($dir)) throw new \RuntimeException('回单目录须为可写0700');
        return $dir;
    }
    public static function context($channel, $bizParam) {
        global $DB, $islogin, $islogin2, $uid;
        $biz = self::token($bizParam['out_biz_no'] ?? null);
        $row = $DB->find('transfer', '*', ['biz_no'=>$biz]);
        if (!$row || (string)$row['channel'] !== (string)($channel['id'] ?? '') || (string)$row['pay_order_no'] !== (string)($bizParam['orderid'] ?? '')) throw new \RuntimeException('回单付款对象不匹配');
        if (defined('API_INIT')) {
            global $userrow, $queryArr, $conf;
            if (($_GET['s'] ?? '') !== 'transfer/proof' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
                || !is_array($userrow) || !is_array($queryArr) || (int)($userrow['status'] ?? 0) !== 1
                || (int)($userrow['transfer'] ?? 0) !== 1 || empty($conf['user_transfer'])
                || (string)($userrow['uid'] ?? '') !== (string)($queryArr['pid'] ?? '')
                || (string)$row['uid'] !== (string)$userrow['uid']) throw new \RuntimeException('回单API身份不匹配');
            // Do not infer identity from globals or browser cookies: verify the actual signed request.
            \lib\ApiHelper::api_verify($userrow, $queryArr);
            $scope = 'api';
        }
        elseif (($islogin ?? 0) == 1) $scope = 'admin';
        elseif (($islogin2 ?? 0) == 1 && (int)$uid > 0 && (string)$row['uid'] === (string)$uid) $scope = 'user';
        else throw new \RuntimeException('回单需要后台登录；API下载鉴权合同尚未集成');
        self::directory();
        return ['biz_no'=>$biz, 'uid'=>(string)$row['uid'], 'channel'=>(string)$row['channel'], 'orderid'=>(string)$row['pay_order_no'], 'scope'=>$scope];
    }
    public static function store($context, $bytes, $format) {
        if (!is_string($bytes) || strlen($bytes) < 8 || strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('回单大小不合法');
        if ($format === 'png') {
            $image = @getimagesizefromstring($bytes);
            if (!$image || $image[2] !== IMAGETYPE_PNG) throw new \RuntimeException('回单不是PNG');
        } elseif ($format !== 'pdf' || substr($bytes, 0, 5) !== '%PDF-') throw new \RuntimeException('回单不是PDF');
        $dir = self::directory();
        $id = bin2hex(random_bytes(24));
        $meta = $context + ['format'=>$format, 'expires'=>time()+self::TTL, 'size'=>strlen($bytes), 'sha256'=>hash('sha256',$bytes)];
        // Metadata is committed last: incomplete writes never become downloadable objects.
        $old = umask(0077);
        try {
            foreach (['.bin'=>$bytes, '.json'=>json_encode($meta, JSON_THROW_ON_ERROR)] as $ext=>$data) {
                $file = $dir.'/'.$id.$ext;
                $fp = @fopen($file, 'x+b');
                if (!$fp) throw new \RuntimeException('回单私有保存失败');
                try {
                    if (fwrite($fp, $data) !== strlen($data) || !fflush($fp)) throw new \RuntimeException('回单私有保存失败');
                } finally { fclose($fp); }
            }
        } catch (\Throwable $e) {
            @unlink($dir.'/'.$id.'.json'); @unlink($dir.'/'.$id.'.bin'); throw $e;
        } finally { umask($old); }
        if ($context['scope'] === 'api') return ['code'=>0, 'msg'=>'电子回单生成成功！', 'receipt_id'=>$id,
            'download_url'=>'/api_receipt.php', 'download_method'=>'POST', 'download_auth'=>'epay-sign',
            'download_expires'=>$meta['expires']];
        return ['code'=>0, 'msg'=>'电子回单生成成功！', 'download_url'=>'/'.$context['scope'].'/receipt.php?id='.$id];
    }
    public static function open($id, $scope, $actor) {
        global $DB;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id) || !in_array($scope,['admin','user'],true) || ($scope==='user' && (int)$actor<=0)) throw new \RuntimeException('回单不可用');
        $dir = self::directory();
        foreach (['.json','.bin'] as $ext) {
            $file = $dir.'/'.$id.$ext;
            if (is_link($file) || !is_file($file) || realpath($file)!==$file || (fileperms($file)&0777)!==0600) throw new \RuntimeException('回单不可用');
        }
        if (filesize($dir.'/'.$id.'.json')>4096) throw new \RuntimeException('回单不可用');
        $meta = json_decode(file_get_contents($dir.'/'.$id.'.json'),true);
        if (!is_array($meta) || ($meta['expires']??0)<time() || !in_array($meta['format']??'', ['png','pdf'],true)) throw new \RuntimeException('回单已过期或不可用');
        $row = $DB->find('transfer', '*', ['biz_no'=>self::token($meta['biz_no']??null)]);
        if (!$row || (string)$row['uid']!==($meta['uid']??null) || (string)$row['channel']!==($meta['channel']??null) || (string)$row['pay_order_no']!==($meta['orderid']??null) || ($scope==='user' && (string)$actor!==$meta['uid'])) throw new \RuntimeException('回单不可用');
        $fp = fopen($dir.'/'.$id.'.bin','rb');
        if (!$fp) throw new \RuntimeException('回单不可用');
        $bytes = stream_get_contents($fp,self::MAX_BYTES+1); fclose($fp);
        if (strlen($bytes)!==($meta['size']??null) || !hash_equals($meta['sha256']??'',hash('sha256',$bytes))) throw new \RuntimeException('回单不可用');
        return ['bytes'=>$bytes, 'format'=>$meta['format']];
    }
    public static function respond($id, $scope, $actor) {
        try {
            $receipt = self::open($id,$scope,$actor);
            header('Content-Type: '.($receipt['format']==='pdf'?'application/pdf':'image/png'));
            header('Content-Disposition: attachment; filename="receipt.'.$receipt['format'].'"');
            header('Content-Length: '.strlen($receipt['bytes']));
            header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
            echo $receipt['bytes'];
        } catch (\Throwable $e) { http_response_code(404); header('Cache-Control: no-store'); echo 'Receipt unavailable'; }
    }
}
