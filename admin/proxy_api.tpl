<?php

define('API_KEY', '{apikey}'); //此处填写API密钥
// 可选：限制代理只允许访问指定域名，多个域名用英文逗号分隔；留空则允许公网 http/https 域名。
define('ALLOWED_HOSTS', '');

if (function_exists("ignore_user_abort")) @ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

$url = isset($_POST['url']) ? $_POST['url'] : exit('{"code":-1,"msg":"No url"}');
$sign = isset($_POST['sign']) ? $_POST['sign'] : exit('{"code":-1,"msg":"No sign"}');
$timestamp = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : exit('{"code":-1,"msg":"No timestamp"}');

if(abs(time() - $timestamp) > 300){
    exit('{"code":-1,"msg":"时间戳异常"}');
}
if(!hash_equals(md5($url . $timestamp . API_KEY), $sign)){
    exit('{"code":-1,"msg":"签名验证失败"}');
}

$url = base64_decode($url, true);
if($url === false || !is_safe_proxy_url($url, $resolved)){
    exit('{"code":-1,"msg":"URL不合法或不允许访问"}');
}

$content = curl_get_safe($url, $resolved);
if($content === false){
    exit('{"code":-1,"msg":"代理请求失败"}');
}
echo $content;

function curl_get_safe($url, $resolved)
{
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? intval($parts['port']) : ($scheme === 'https' ? 443 : 80);

    $ch = curl_init($url);
    // Direct connection is required: a proxy can bypass CURLOPT_RESOLVE DNS pinning.
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');
    $httpheader = array(
        "Accept: */*",
        "Accept-Language: zh-CN,zh;q=0.8",
        "Connection: close"
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
    curl_setopt($ch, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $resolved[0]));
    if (defined('CURLOPT_PROTOCOLS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    $content = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if($content === false || $status >= 300){
        return false;
    }
    return $content;
}

function is_safe_proxy_url($url, &$resolved)
{
    $resolved = array();
    if(!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url)){
        return false;
    }
    $parts = parse_url($url);
    if(!$parts || empty($parts['scheme']) || empty($parts['host'])){
        return false;
    }
    $scheme = strtolower($parts['scheme']);
    if($scheme !== 'http' && $scheme !== 'https'){
        return false;
    }
    if(isset($parts['user']) || isset($parts['pass'])){
        return false;
    }
    $port = isset($parts['port']) ? intval($parts['port']) : ($scheme === 'https' ? 443 : 80);
    if(($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)){
        return false;
    }

    $host = strtolower(trim($parts['host'], '[]'));
    if($host === 'localhost' || $host === 'localhost.localdomain'){
        return false;
    }
    if(!is_allowed_host($host)){
        return false;
    }

    if(filter_var($host, FILTER_VALIDATE_IP)){
        $resolved = array($host);
    } else {
        if(!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)){
            return false;
        }
        $resolved = resolve_public_ips($host);
    }
    if(empty($resolved)){
        return false;
    }
    foreach($resolved as $ip){
        if(!is_public_ip($ip)){
            return false;
        }
    }
    return true;
}

function is_allowed_host($host)
{
    $allow = trim(ALLOWED_HOSTS);
    if($allow === ''){
        return true;
    }
    $items = array_filter(array_map('trim', explode(',', strtolower($allow))));
    foreach($items as $item){
        if($host === $item || (substr($item, 0, 2) === '*.' && substr($host, -strlen(substr($item, 1))) === substr($item, 1))){
            return true;
        }
    }
    return false;
}

function resolve_public_ips($host)
{
    $ips = array();
    if(function_exists('dns_get_record')){
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if(is_array($records)){
            foreach($records as $record){
                if(!empty($record['ip'])) $ips[] = $record['ip'];
                if(!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
    }
    if(empty($ips)){
        $fallback = @gethostbynamel($host);
        if(is_array($fallback)) $ips = array_merge($ips, $fallback);
    }
    return array_values(array_unique($ips));
}

function is_public_ip($ip)
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
