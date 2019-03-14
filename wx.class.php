<?php 
class Wx
{

    function getMillisecond()
    {
        list($t1, $t2) = explode(' ', microtime());
        return $t2 . ceil(($t1 * 1000));
    }

    private $appid = 'wx782c26e4c19acffb';

    /**
     * 获取唯一的uuid用于生成二维码
     * @return $uuid
     */
    public function get_uuid()
    {
        $url = 'https://login.weixin.qq.com/jslogin';
        $url .= '?appid=' . $this->appid;
        $url .= '&fun=new';
        $url .= '&lang=zh_CN';
        $url .= '&_=' . time();

        $content = $this->curlPost($url);
        //也可以使用正则匹配
        $content = explode(';', $content);

        $content_uuid = explode('"', $content[1]);

        $uuid = $content_uuid[1];
        $this->uuid = $uuid;
        return $uuid;
    }

    /**
     * 生成二维码
     * @param $uuid
     * @return img
     */
    public function qrcode($uuid)
    {
        $url = 'https://login.weixin.qq.com/qrcode/' . $uuid . '?t=webwx';
        $img = "<img class='img' src=" . $url . "/>";
        return $img;
    }

    /**
     * 扫描登录
     * @param $uuid
     * @param string $icon
     * @return array code 408:未扫描;201:扫描未登录;200:登录成功; icon:用户头像
     */
    public function login($uuid, $icon = 'true')
    {
        //$url = 'https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=' . $icon . '&r=' . ~time() . '&uuid=' . $uuid . '&tip=0&_=' . getMillisecond();
        $url = 'https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=' . $icon . '&r=' . ~time() . '&uuid=' . $uuid . '&tip=0&_=' . time();
        $content = $this->curlPost($url);
        preg_match('/\d+/', $content, $match);
        $code = $match[0];
        preg_match('/([\'"])([^\'"\.]*?)\1/', $content, $icon);

        $user_icon = !empty($icon) ? $icon[2] : array();
        if ($user_icon) {
            $data = array(
                'code' => $code,
                'icon' => $user_icon,
            );
        } else {
            $data['code'] = $code;
        }
        //echo json_encode($data);//改之前
        return $data;
    }

    /**
     * 登录成功回调
     * @param $uuid
     * @return array $callback
     */
    public function get_uri($uuid)
    {
        $url = 'https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?uuid=' . $uuid . '&tip=0&_=e' . time();
        $content = $this->curlPost($url);
        $content = explode(';', $content);
        $content_uri = explode('"', $content[1]);
        $uri = $content_uri[1];

        preg_match("~^https:?(//([^/?#]*))?~", $uri, $match);
        $https_header = $match[0];
        $_SESSION['https_header'] = $https_header;//补这一句
        $post_url_header = $https_header . "/cgi-bin/mmwebwx-bin";

        $new_uri = explode('scan', $uri);
        $uri = $new_uri[0] . 'fun=new&scan=' . time();
        $getXML = $this->curlPost($uri);

        $XML = simplexml_load_string($getXML);

        $callback = array(
            'post_url_header' => $post_url_header,
            'Ret' => (array)$XML,
        );
        return $callback;
    }

    /**
     * 获取post数据
     * @param array $callback
     * @return object $post
     */
    public function post_self($callback)
    {
        $post = new stdClass();
        $Ret = $callback['Ret'];
        $status = $Ret['ret'];
        if ($status == '1203') {
            $this->error('未知错误,请2小时后重试');
        }
        if ($status == '0') {
            $post->BaseRequest = array(
                'Uin' => $Ret['wxuin'],
                'Sid' => $Ret['wxsid'],
                'Skey' => $Ret['skey'],
                'DeviceID' => 'e' . rand(10000000, 99999999) . rand(1000000, 9999999),
            );

            $post->skey = $Ret['skey'];

            $post->pass_ticket = $Ret['pass_ticket'];

            $post->sid = $Ret['wxsid'];

            $post->uin = $Ret['wxuin'];

            return $post;
        }
    }

    /**
     * 初始化
     * @param $post
     * @return json $json
     */
    public function wxinit($post)
    {

        $url = $_SESSION['https_header'] . '/cgi-bin/mmwebwx-bin/webwxinit?pass_ticket=' . $post->pass_ticket . '&skey=' . $post->skey . '&r=' . time();

        $post = array(
            'BaseRequest' => $post->BaseRequest,
        );
        $json = $this->curlPost($url, $post);

        return $json;
    }

    /**
     * 获取MsgId
     * @param $post
     * @param $json
     * @param $post_url_header
     * @return array $data
     */
    public function wxstatusnotify($post, $json, $post_url_header)
    {
        $init = json_decode($json, true);

        $User = $init['User'];
        $url = $post_url_header . '/webwxstatusnotify?lang=zh_CN&pass_ticket=' . $post->pass_ticket;

        $params = array(
            'BaseRequest' => $post->BaseRequest,
            "Code" => 3,
            "FromUserName" => $User['UserName'],
            "ToUserName" => $User['UserName'],
            "ClientMsgId" => time()
        );

        $data = $this->curlPost($url, $params);

        $data = json_decode($data, true);

        return $data;
    }

    /**
     * 获取联系人
     * @param $post
     * @param $post_url_header
     * @return array $data
     */
    public function webwxgetcontact($post, $post_url_header)
    {

        $url = $post_url_header . '/webwxgetcontact?pass_ticket=' . $post->pass_ticket . '&seq=0&skey=' . $post->skey . '&r=' . time();

        $params['BaseRequest'] = $post->BaseRequest;

        $data = $this->curlPost($url, $params);

        return $data;
    }

    /**
     * 获取当前活跃群信息
     * @param $post
     * @param $post_url_header
     * @param $group_list 从获取联系人和初始化中获取
     * @return array $data
     */
    public function webwxbatchgetcontact($post, $post_url_header, $group_list)
    {

        $url = $post_url_header . '/webwxbatchgetcontact?type=ex&lang=zh_CN&r=' . time() . '&pass_ticket=' . $post->pass_ticket;

        $params['BaseRequest'] = $post->BaseRequest;

        $params['Count'] = count($group_list);

        foreach ($group_list as $key => $value) {
            if ($value[MemberCount] == 0) {
                $params['List'][] = array(
                    'UserName' => $value['UserName'],
                    'ChatRoomId' => "",
                );
            }
            $params['List'][] = array(
                'UserName' => $value['UserName'],
                'EncryChatRoomId' => "",
            );

        }

        $data = $this->curlPost($url, $params);

        $data = json_decode($data, true);

        return $data;
    }

    /**
     * 心跳检测 0正常；1101失败／登出；2新消息；7不要耍手机了我都收不到消息了；
     * @param $post
     * @param $SyncKey 初始化方法中获取
     * @return array $status
     */
    public function synccheck($post, $SyncKey)
    {
        if (!$SyncKey['List']) {
            $SyncKey = $_SESSION['json']['SyncKey'];
        }
        foreach ($SyncKey['List'] as $key => $value) {
            if ($key == 1) {
                $SyncKey_value = $value['Key'] . '_' . $value['Val'];
            } else {
                $SyncKey_value .= '|' . $value['Key'] . '_' . $value['Val'];
            }

        }

        $header = array(
            '0' => 'https://webpush.wx2.qq.com',
            '1' => 'https://webpush.wx.qq.com',
        );

        foreach ($header as $key => $value) {

            $url = $value . "/cgi-bin/mmwebwx-bin/synccheck?r=" . getMillisecond() . "&skey=" . urlencode($post->skey) . "&sid=" . $post->sid . "&deviceid=" . $post->BaseRequest['DeviceID'] . "&uin=" . $post->uin . "&synckey=" . urlencode($SyncKey_value) . "&_=" . getMillisecond();

            $data[] = $this->curlPost($url);
        }

        foreach ($data as $k => $val) {

            $rule = '/window.synccheck={retcode:"(\d+)",selector:"(\d+)"}/';

            preg_match($rule, $data[$k], $match);

            if ($match[1] == '0') {
                $retcode = $match[1];
                $selector = $match[2];
            }
        }

        $status = array(
            'ret' => $retcode,
            'sel' => $selector,
        );

        return $status;
    }

    /**
     * 获取最新消息
     * @param $post
     * @param $post_url_header
     * @param $SyncKey
     * @return array $data
     */
    public function webwxsync($post, $post_url_header, $SyncKey)
    {
        $url = $post_url_header . '/webwxsync?sid=' . $post->sid . '&skey=' . $post->skey . '&pass_ticket=' . $post->pass_ticket;

        $params = array(
            'BaseRequest' => $post->BaseRequest,
            'SyncKey' => $SyncKey,
            'rr' => ~time(),
        );
        $data = $this->curlPost($url, $params);

        return $data;
    }


    /**
     * 发送消息
     * @param $post
     * @param $post_url_header
     * @param $to 发送人
     * @param $word
     * @return array $data
     */
    public function webwxsendmsg($post, $json, $post_url_header, $to, $word)
    {

        // header("Content-Type: application/json; charset=UTF-8");
        // header("Content-Type: application/x-www-form-urlencoded; charset=UTF-8");
        $url = $post_url_header . '/webwxsendmsg?pass_ticket=' . $post->pass_ticket;

        //$clientMsgId = getMillisecond() * 1000 + rand(1000, 9999);//原方法
        $clientMsgId = time() * 1000 + rand(1000, 9999);//原方法
        $init = json_decode($json, true);
        $User = $init['User'];
        $params = array(
            'BaseRequest' => $post->BaseRequest,
            'Msg' => array(
                "Type" => 1,
                "Content" => $word,
                "FromUserName" => $User['UserName'],
                "ToUserName" => $to,
                "LocalID" => $clientMsgId,
                "ClientMsgId" => $clientMsgId
            ),
            'Scene' => 0,
        );
        $data = $this->sendCurlPost($url, $params, 1);

        return $data;
    }

    /**
     *退出登录
     * @param $post
     * @param $post_url_header
     * @return bool
     */
    public function wxloginout($post, $post_url_header)
    {
        $url = $post_url_header . '/webwxlogout?redirect=1&type=1&skey=' . urlencode($post->skey);
        $param = array(
            'sid' => $post->sid,
            'uin' => $post->uin,
        );
        $this->curlPost($url, $param);

        return true;
    }


    public function curlPost($url, $data = '', $is_gbk = false, $timeout = 30, $CA = false)
    {
        $cacert = getcwd() . '/cacert.pem'; //CA根证书

        $SSL = substr($url, 0, 8) == "https://" ? true : false;

        //$header = 'ContentType: application/json; charset=UTF-8';
        $header[] = 'ContentType: application/json;';
        $header[] = "charset:UTF-8";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout - 2);
        if ($SSL && $CA) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 只信任CA颁布的证书
            curl_setopt($ch, CURLOPT_CAINFO, $cacert); // CA根证书（用来验证的网站证书是否是CA颁布）
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 检查证书中是否设置域名，并且是否与提供的主机名匹配
        } else if ($SSL && !$CA) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 检查证书中是否设置域名
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:')); //避免data数据过长问题
        if ($data) {
            if ($is_gbk) {
                $data = json_encode($data);

            } else {
                $data = json_encode($data);
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); //data with URLEncode
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    public function sendCurlPost($url, $data = '', $is_gbk = false, $timeout = 30, $CA = false)
    {
        $cacert = getcwd() . '/cacert.pem'; //CA根证书
        // var_dump($cacert); die;
        // 没有证书 测试到此为止
        $SSL = substr($url, 0, 8) == "https://" ? true : false;

        //$header = 'ContentType: application/json; charset=UTF-8';
        $header[] = 'ContentType: application/json;';
        $header[] = "charset:UTF-8";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout - 2);
        if ($SSL && $CA) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 只信任CA颁布的证书
            curl_setopt($ch, CURLOPT_CAINFO, $cacert); // CA根证书（用来验证的网站证书是否是CA颁布）
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 检查证书中是否设置域名，并且是否与提供的主机名匹配
        } else if ($SSL && !$CA) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 检查证书中是否设置域名
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:')); //避免data数据过长问题
        if ($data) {
            if ($is_gbk) {
                $data = urldecode(json_encode($data));

            } else {
                $data = urldecode(json_encode($data));
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); //data with URLEncode
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }
}