<?php
require_once './wx.class.php';
$wx = new Wx();
if (!empty($_GET['cmd']) && $_GET['cmd'] == 'index') {
    //重新扫描登陆时，清空缓存
    session_start();
    unset($_SESSION);
    session_destroy();
    $uuid = $wx->get_uuid();
    $erweima = $wx->qrcode($uuid);
    echo($erweima);//显示二维码
    echo $uuid;
    echo "<a href='http://127.0.0.1/61/wx/WeChat.php?cmd=login&uuid=" . $uuid . "'>扫描后，点击登陆确认</a>(备注：扫描后点击登陆按钮" . $uuid . ")";
}

if (!empty($_GET['cmd']) && $_GET['cmd'] == 'login' && !empty($_GET['uuid'])) {
    $loginInfo = $wx->login($_GET['uuid']);
    if ($loginInfo['code'] == 200) {
        //获取登录成功回调
        $callback = $wx->get_uri($_GET['uuid']);
        //获取post数据
        $post = $wx->post_self($callback);
        //初始化数据json格式
        $initInfo = $wx->wxinit($post);

        //获取MsgId,参数post，初始化数据initInfo
        //$msgInfo = $wx->wxstatusnotify($post,$initInfo,$callback['post_url_header']);
        //获取联系人
        $contactInfo = $wx->webwxgetcontact($post, $callback['post_url_header']);

        //查询的数据放入缓存
        session_start();
        $_SESSION['callback_post_url_header'] = $callback['post_url_header'];
        $_SESSION['post'] = $post;
        $_SESSION['initInfo'] = $initInfo;
        $_SESSION['contactInfo'] = $contactInfo;
        //print_r($_SESSION['callback_post_url_header']);die;
        header("Location: WeChat.php?cmd=send");
        exit;
    }
    //print_r($loginInfo);
    print_r('登陆失败');
}

if (!empty($_GET['cmd']) && $_GET['cmd'] == 'send') {
    //header("Content-Type: text/html; charset=UTF-8");
    if (!empty($_POST['sendText'])) {
        session_start();

        $callback = $_SESSION['callback_post_url_header'];
        $post = $_SESSION['post'];
        $initInfo = $_SESSION['initInfo'];
        $contactInfo = $_SESSION['contactInfo'];
        //json转数组
        $contactArr = json_decode($contactInfo, true);
        $contactName = anewarray($contactArr['MemberList'], 'UserName');
        //$userName = $contactName['文件传输助手'];//文件助手 ：文件传输助手，好友：荳子麻麻
        //发送信息
        //接受消息
        $sendText = $_POST['sendText'];
        $userName = $_POST['username'];
        
        $word = urlencode($sendText);//中文先转码，最后提交的时候在urldecode(json_encode($data));
        $to = $userName;
        if (!empty($sendText)) {
            $sendmsg = $wx->webwxsendmsg($post, $initInfo, $callback, $to, $word);
            var_dump($sendmsg);
            $msg = $word . date("Y-m-d H:i:s") . "\r\n";
            file_put_contents("./logs/log.log", $msg, FILE_APPEND);//记录日志
            header("Location: WeChat.php?cmd=send");
        }
    } else {
        session_start();

        $contactInfo = $_SESSION['contactInfo'];
        //json转数组
        $contactArr = json_decode($contactInfo, true);
        $contactName = anewarray($contactArr['MemberList'], 'UserName');

        // 好友所有信息
        // var_dump($contactArr);

        echo "<form action='WeChat.php?cmd=send' method='post'>";
        echo "<select name = 'username'>";
        foreach ($contactName as $k => $v) {
            echo "<option value =" . $v . ">" . $k . "</option>";
        }
        echo "</select>";
        echo "消息: <input type='text' name='sendText' id='sendText'>";
        echo "<input type='submit' value='发送'>";
        echo "</form>";
    }


}

function anewarray($array, $filed = 'UserName', $keyName = 'NickName')
{
    $data = array();
    if (!empty($array)) {
        foreach ($array as $key => $val) {
            if (!empty($val[$filed])) {
                $data[$val[$keyName]] = $val[$filed];
            }
        }
        $data = array_filter($data);
    }
    return $data;
}
