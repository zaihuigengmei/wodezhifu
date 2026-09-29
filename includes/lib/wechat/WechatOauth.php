<?php
namespace lib\wechat;

use Exception;

class WechatOauth
{
    private $openurl = 'https://open.weixin.qq.com';
    private $appid;
    private $appsecret;

    public function __construct($appid, $appsecret)
    {
        $this->appid = $appid;
        $this->appsecret = $appsecret;
    }

    public function setOpenUrl($url)
    {
        if (empty($url)) return;
        $this->openurl = rtrim($url, '/');
    }

    /**
     * 跳转到微信公众平台登录
     */
    public function login()
    {
        if(session_status() !== PHP_SESSION_ACTIVE) session_start();
        $redirect_uri = (is_https() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $param = [
            "appid" => $this->appid,
            "redirect_uri" => $redirect_uri,
            "response_type" => "code",
            "scope" => "snsapi_base",
            "state" => epay_oauth_issue('wechat:'.$this->appid, $this->stateContext())
        ];
        $url = $this->openurl . '/connect/oauth2/authorize?' . http_build_query($param) . "#wechat_redirect";
        Header("Location: $url");
        exit;
    }

	/**
	 * 从公众平台获取openid
	 * @param string $code 微信跳转回来带上的code
	 *
	 * @return string openid
	 * @throws Exception
	 */
    public function GetOpenidFromMp(string $code): string
    {
        if(session_status() !== PHP_SESSION_ACTIVE) session_start();
        epay_oauth_consume('wechat:'.$this->appid, $_GET['state'] ?? null, $this->stateContext());
        $param = [
            "appid" => $this->appid,
            "secret" => $this->appsecret,
            "code" => $code,
            "grant_type" => "authorization_code"
        ];
        $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?' . http_build_query($param);
        $res = get_curl($url);
        $data = json_decode($res, true);
        if (isset($data['access_token']) && isset($data['openid'])) {
            return $data['openid'];
        } elseif (isset($data['errcode'])) {
            throw new Exception('Openid获取失败 [' . $data['errcode'] . ']' . $data['errmsg']);
        } else {
            throw new Exception('Openid获取失败，原因未知');
        }
    }

	/**
	 * 微信小程序获取Openid
	 * @param string $code 登录时获取的code
	 *
	 * @return string openid
	 * @throws Exception
	 */
    private function stateContext(){
        $query = $_GET;
        unset($query['code'], $query['state']);
        ksort($query);
        return (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '').'?'.http_build_query($query);
    }

    public function AppGetOpenid(string $code): string
    {
        $param = [
            "appid" => $this->appid,
            "secret" => $this->appsecret,
            "js_code" => $code,
            "grant_type" => "authorization_code"
        ];
        $url = 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query($param);
        $res = get_curl($url);
        $data = json_decode($res, true);
        if (isset($data['session_key']) && isset($data['openid'])) {
            return $data['openid'];
        } elseif (isset($data['errcode'])) {
            throw new Exception('获取openid失败 [' . $data['errcode'] . ']' . $data['errmsg']);
        } else {
            throw new Exception('获取openid失败，原因未知');
        }
    }
}