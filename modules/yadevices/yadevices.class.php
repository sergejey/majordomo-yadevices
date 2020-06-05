<?php

spl_autoload_register(function ($class_name) {
    $path = DIR_MODULES . 'yadevices/' . $class_name . '.php';
    $path = str_replace('\\', '/', $path);
    include_once $path;
});

use \WSSC\WebSocketClient;
use \WSSC\Components\ClientConfig;


/**
 * YaDevices
 * @package project
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 0.1 (wizard, 15:12:58 [Dec 31, 2019])
 */
//
//
class yadevices extends module
{
    /**
     * yadevices
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "yadevices";
        $this->title = "YaDevices";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 1)
    {
        $p = array();
        if (IsSet($this->id)) {
            $p["id"] = $this->id;
        }
        if (IsSet($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (IsSet($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (IsSet($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (IsSet($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (IsSet($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    function api($params)
    {
        if ($params['station'] && $params['command']) {
            if (($params['command'] == 'setVolume') && $params['volume']) {
                return $this->sendCommandToStation((int)$params['station'], $params['command'], $params['volume']);
            } else {
                return $this->sendCommandToStation((int)$params['station'], $params['command']);
            }
        }
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        $this->getConfig();
        $out['API_USERNAME'] = $this->config['API_USERNAME'];
        $out['API_PASSWORD'] = $this->config['API_PASSWORD'];
        if ($this->view_mode == 'update_settings') {
            global $api_username;
            $this->config['API_USERNAME'] = $api_username;
            global $api_password;
            $this->config['API_PASSWORD'] = $api_password;
            $this->saveConfig();
            $this->redirect("?mode=refresh");
        }
        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'yastations' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_yastations') {
                $this->search_yastations($out);
                $out['LOGIN_STATUS'] = (int)$this->checkLogin();
            }
            if ($this->view_mode == 'search_yadevices') {
                $this->search_yadevices($out);
            }
            if ($this->view_mode == 'edit_yastations') {
                $this->edit_yastations($out, $this->id);
            }
            if ($this->view_mode == 'edit_yadevices') {
                $this->edit_yadevices($out, $this->id);
            }
            if ($this->view_mode == 'delete_yastations') {
                $this->delete_yastations($this->id);
                $this->redirect("?");
            }
            if ($this->mode == 'refresh') {
                $this->refreshStations();
                $this->refreshDevices();
                $this->redirect("?");
            }
        }
    }

    function checkLogin()
    {
        if (!$this->config['API_USERNAME'] || !$this->config['API_PASSWORD']) {
            return 0;
        }
        $token = $this->getToken();
        if ($token) {
            return 1;
        }
    }

    function refreshDevices()
    {
        $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/devices');
        if (is_array($data['rooms'])) {
            $rooms = $data['rooms'];
            foreach ($rooms as $room) {
                $devices = $room['devices'];
                if (is_array($devices)) {
                    foreach ($devices as $device) {
                        $iot_id = $device['id'];
                        $type = $device['type'];
                        $name = $device['name'];

                        $device_rec = SQLSelectOne("SELECT * FROM yadevices WHERE IOT_ID='" . $iot_id . "'");
                        $device_rec['TITLE'] = $name;
                        $device_rec['DEVICE_TYPE'] = $type;
                        $device_rec['UPDATED'] = date('Y-m-d H:i:s');
                        if (!$device_rec['ID']) {
                            $device_rec['IOT_ID'] = $iot_id;
                            $device_rec['ID'] = SQLInsert('yadevices', $device_rec);
                        } else {
                            SQLUpdate('yadevices', $device_rec);
                        }
                        $capabilities = $device['capabilities'];
                        foreach ($capabilities as $capability) {
                            $c_type = $capability['type'];
                            $value = '';
                            if ($c_type == 'devices.capabilities.on_off') {
                                $value = (int)$capability['state']['value'];
                            }
                            $c_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $device_rec['ID'] . " AND TITLE='" . $c_type . "'");
                            $c_rec['VALUE'] = $value;
                            $c_rec['UPDATED'] = date('Y-m-d H:i:s');
                            if (!$c_rec['ID']) {
                                $c_rec['YADEVICE_ID'] = $device_rec['ID'];
                                $c_rec['TITLE'] = $c_type;
                                $c_rec['ID'] = SQLInsert('yadevices_capabilities', $c_rec);
                            } else {
                                SQLUpdate('yadevices_capabilities', $c_rec);
                            }

                        }
                    }
                }
            }
        }
        if (is_array($data['speakers'])) {
            $speakers = $data['speakers'];
            foreach ($speakers as $speaker) {
                $name = $speaker['name'];
                $iot_id = $speaker['id'];
                $rec = SQLSelectOne("SELECT * FROM yastations WHERE TITLE='" . DBSafe($name) . "'");
                if ($rec['ID']) {
                    $rec['IOT_ID'] = $iot_id;
                    $rec['UPDATED'] = date('Y-m-d H:i:s');
                    SQLUpdate('yastations', $rec);
                }
            }
        }
    }

    function yandex_encode($in) {
        $in = strtolower($in);
        $MASK_EN = array('0','1','2','3','4','5','6','7','8','9','a','b','c','d','e','f','-');
        $MASK_RU = array('о','е','а','и','н','т','с','р','в','л','к','м','д','п','у','я','ы');
        return 'мжд '.str_replace($MASK_EN,$MASK_RU,$in);
    }

    function yandex_decode($in) {
        $in = mb_substr($in,4);
        $MASK_EN = array('0','1','2','3','4','5','6','7','8','9','a','b','c','d','e','f','-');
        $MASK_RU = array('о','е','а','и','н','т','с','р','в','л','к','м','д','п','у','я','ы');
        return str_replace($MASK_RU,$MASK_EN,$in);
    }

    function addScenarios($repeating = 0)
    {
        $some_added = 0;
        $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');
        $scenarios = array();
        if (is_array($data['scenarios'])) {
            foreach ($data['scenarios'] as $scenario) {
                $scenarios[$this->yandex_decode($scenario['name'])] = $scenario;
            }
        }
        //dprint($scenarios,false);
        $stations = SQLSelect("SELECT * FROM yastations ORDER BY ID");
        foreach ($stations as $station) {
            $station_id = $station['IOT_ID'];
            if (!isset($scenarios[strtolower($station_id)])) {
                // add scenario
                $payload = array(
                    'name' => $this->yandex_encode($station_id),
                    'icon' => 'home',
                    'trigger_type' => 'scenario.trigger.voice',
                    'devices' => array(),
                    'external_actions' => array(
                        array(
                            'type' => 'scenario.external_action.phrase',
                            'parameters' => array(
                                'current_device' => false,
                                'device_id' => $station_id,
                                'phrase' => '-')
                        )
                    ));
                $result=$this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios','POST',$payload);
                if ($result['status']=='ok') {
                    $some_added = 1;
                }
            } else {
                $station['TTS_SCENARIO'] = $scenarios[strtolower($station_id)]['id'];
                SQLUpdate('yastations',$station);
            }
        }
        if ($some_added && !$repeating) {
            $this->addScenarios(1);
        }
    }

    function sendCloudTTS($iot_id, $phrase, $action = 'phrase') {

        $station_rec=SQLSelectOne("SELECT * FROM yastations WHERE IOT_ID='".$iot_id."'");

        //dprint($station_rec);

        //$action = 'phrase';

        if (!$station_rec['TTS_SCENARIO']) return;

        $payload = array(
            'name' => $this->yandex_encode($iot_id),
            'icon' => 'home',
            'trigger_type' => 'scenario.trigger.voice',
            'devices' => array(),
            'external_actions' => array(
                array('type' => "scenario.external_action." . $action,
                    'parameters' => array(
                        'current_device' => false,
                        'device_id' => $iot_id,
                        $action => $phrase)
                )
            )
        );
        $scenario_id = $station_rec['TTS_SCENARIO'];
        $result=$this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/'.$scenario_id,'PUT',$payload);
        //dprint($result,false);
        $payload = array();
        $result=$this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/'.$scenario_id.'/actions','POST',$payload);
        //dprint($result);
    }

    function refreshStations()
    {
        $data = $this->apiRequest('https://quasar.yandex.ru/devices_online_stats');
        if (is_array($data['items'])) {
            $items = $data['items'];
            foreach ($items as $item) {
                $rec = SQLSelectOne("SELECT * FROM yastations WHERE STATION_ID='" . $item['id'] . "'");
                $rec['UPDATED'] = date('Y-m-d H:i:s');
                if (!$rec['ID']) {
                    $rec['STATION_ID'] = $item['id'];
                    $rec['ID'] = SQLInsert('yastations', $rec);
                }
                $rec['TITLE'] = $item['name'];
                $rec['ICON_URL'] = $item['icon'];
                $rec['PLATFORM'] = $item['platform'];
                $rec['SCREEN_CAPABLE'] = (int)$item['screen_capable'];
                $rec['SCREEN_PRESENT'] = (int)$item['screen_present'];
                $rec['IS_ONLINE'] = (int)$item['online'];
                SQLUpdate('yastations', $rec);
            }
        }
        $this->addScenarios();
    }

    function apiRequest($url, $method = 'GET', $params = 0, $repeating = 0)
    {
        $this->getConfig();
        $token = $this->getToken();
        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        if (IsWindowsOS()) {
            $cookie = ROOT . 'cms\cached\yandex_cookie.txt';
        } else {
            $cookie = ROOT . 'cms/cached/yandex_cookie.txt';
        }
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);

        /*
        if (preg_match('/devices\/(.+)\/actions/', $url, $m)) {
            $referer = "https://quasar.yandex.ru/skills/iot/device/" . $m[1] . "?app_id=unknown&app_platform=unknown&app_version_name=unknown&dp=2&lang=ru&model=unknown&os_version=unknown&size=1080x1920//Referer: https://quasar.yandex.ru/skills/iot/device/8f5ccea3-d631-4cfb-9fea-5cc36abba92e?app_id=unknown&app_platform=unknown&app_version_name=unknown&dp=2&lang=ru&model=unknown&os_version=unknown&size=1080x1920";
            dprint($referer);
            curl_setopt($YaCurl, CURLOPT_REFERER, $referer);
        }
        */

        if ($method == 'GET') {
            curl_setopt($YaCurl, CURLOPT_POST, false);
        } else {
            curl_setopt($YaCurl, CURLOPT_HTTPHEADER, array(
                    'Content-type: application/json',
                    'x-csrf-token:' . $token)
            );
            if ($method != 'POST') {
                //curl_setopt($YaCurl, CURLOPT_POST, true);
                curl_setopt($YaCurl, CURLOPT_CUSTOMREQUEST, $method);
            } else {
                curl_setopt($YaCurl, CURLOPT_POST, true);
            }
            curl_setopt($YaCurl, CURLOPT_POSTFIELDS, json_encode($params)); //, JSON_UNESCAPED_SLASHES
        }
        //curl_setopt($YaCurl, CURLOPT_HEADER, 1);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($YaCurl);

        $data = json_decode($result, true);
        if (!$repeating && ($data['code']!='BAD_REQUEST') && (!is_array($data) || $data['status'] == 'error')) {
            $token = $this->getToken();
            if ($token) {
                $data = $this->apiRequest($url, $method, $params, 1);
            } else {
                return false;
            }
        }
        return $data;
    }

    function getToken()
    {
        $token = '';
        $YaCurl = curl_init();
        $Ya_login = $this->config['API_USERNAME'];
        $Ya_pass = $this->config['API_PASSWORD'];

        $oldToken = $this->config['API_TOKEN'];
        if (IsWindowsOS()) {
            $cookie = ROOT . 'cms\cached\yandex_cookie.txt';
        } else {
            $cookie = ROOT . 'cms/cached/yandex_cookie.txt';
        }
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        //curl_setopt($YaCurl, CURLOPT_URL, 'https://frontend.vh.yandex.ru/csrf_token');
        curl_setopt($YaCurl, CURLOPT_URL, 'https://yandex.ru/quasar/iot');
        curl_setopt($YaCurl, CURLOPT_HEADER, 1);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($YaCurl);
        if (preg_match('/"csrfToken2":"(.+?)"/',$result,$m)) {
            $token = $m[1];
        }

        if (!$token) {

            curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
            curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($YaCurl, CURLOPT_USERAGENT, 'Mozilla/4.0 (Windows; U; Windows NT 5.0; En; rv:1.8.0.2) Gecko/20070306 Firefox/1.0.0.4');
            curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/');
            curl_setopt($YaCurl, CURLOPT_POST, false);
            $loginPage = curl_exec($YaCurl);

            curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
            curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/passport?mode=auth&retpath=https://yandex.ru');
            curl_setopt($YaCurl, CURLOPT_POST, true);
            curl_setopt($YaCurl, CURLOPT_HEADER, false);
            curl_setopt($YaCurl, CURLOPT_POSTFIELDS, http_build_query(array('login' => $Ya_login, 'passwd' => $Ya_pass)));
            $loginResult = curl_exec($YaCurl);

        }

        /*
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_URL, 'https://frontend.vh.yandex.ru/csrf_token');
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        $token = curl_exec($YaCurl);
        */

        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        //curl_setopt($YaCurl, CURLOPT_URL, 'https://frontend.vh.yandex.ru/csrf_token');
        curl_setopt($YaCurl, CURLOPT_URL, 'https://yandex.ru/quasar/iot');
        curl_setopt($YaCurl, CURLOPT_HEADER, 1);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($YaCurl);
        if (preg_match('/"csrfToken2":"(.+?)"/',$result,$m)) {
            $token = $m[1];
        }
        if ($token) {
            $this->config['API_TOKEN'] = $token;
            $this->saveConfig();
        } else {
            $this->config['API_TOKEN'] = '';
        }
        return $token;
    }

    function getDeviceToken($device_id, $platform)
    {

        // getAuth token
        $ya_music_client_id = '23cabbbdc6cd418abb4b39c32c41195d';
        $url = "https://oauth.yandex.ru/authorize?response_type=token&client_id=" . $ya_music_client_id;
        if (IsWindowsOS()) {
            $cookie = ROOT . 'cms\cached\yandex_cookie.txt';
        } else {
            $cookie = ROOT . 'cms/cached/yandex_cookie.txt';
        }
        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        $result = curl_exec($YaCurl);

        if (preg_match('/^Found.*access_token=([^<]+?)&/is', $result, $m)) {
            $oauth_token = $m[1];
        } else {
            echo $result;
            exit;
            return false;
        }

        $url = "https://quasar.yandex.net/glagol/token?device_id=" . $device_id . "&platform=" . $platform;

        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_POST, false);

        $header = array();
        $header[] = 'Content-type: application/json';
        $header[] = 'Authorization: Oauth ' . $oauth_token;
        //dprint($header);

        curl_setopt($YaCurl, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($YaCurl);
        $data = json_decode($result, true);
        if ($data['status'] == 'ok' && $data['token']) {
            return $data['token'];
        }
        return false;
    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    /**
     * yastations search
     *
     * @access public
     */
    function search_yastations(&$out)
    {
        require(DIR_MODULES . $this->name . '/yastations_search.inc.php');
    }

    function search_yadevices(&$out)
    {
        require(DIR_MODULES . $this->name . '/yadevices_search.inc.php');
    }

    /**
     * yastations edit/add
     *
     * @access public
     */
    function edit_yastations(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/yastations_edit.inc.php');
    }

    function edit_yadevices(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/yadevices_edit.inc.php');
    }

    /**
     * yastations delete record
     *
     * @access public
     */
    function delete_yastations($id)
    {
        $rec = SQLSelectOne("SELECT * FROM yastations WHERE ID='$id'");
        // some action for related tables
        SQLExec("DELETE FROM yastations WHERE ID='" . $rec['ID'] . "'");
    }

    function sendDataToStation($command, $token, $ip, $port = 1961, $dopParam = 0)
    {
        DebMes("Sending '$command' to $ip", 'yadevices');
        $clientConfig = new ClientConfig();
        $clientConfig->setHeaders([
            'X-Origin' => 'http://yandex.ru/',
        ]);
        $clientConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $msg = array(
            'conversationToken' => $token,
            'id' => uniqid(''),
            'sentTime' => time() * 1000000000,
            'payload' => array(
//                'command' => 'sendText',
//               'text' => $command,
            )
        );
        if ($dopParam && ($command == 'setVolume')) {
            $msg['payload']['command'] = $command;
            $msg['payload']['volume'] = (float)$dopParam;
        } else {
            $msg['payload']['command'] = 'sendText';
            $msg['payload']['text'] = $command;
        }
        $client = new WebSocketClient('wss://' . $ip . ':' . $port . '/', $clientConfig);
        $client->send(json_encode($msg));
        $result = $client->receive();
        $result_data = json_decode($result, true);

        if (is_array($result_data)) {
            if (mb_stripos($command, 'повтори за мной') === 0) {
                while (($status = $this->getStatus($token, $ip, $port)) && is_array($status) && ($status['state']['aliceState'] != 'LISTENING')) {
                    usleep(500000);
                    //DebMes($status['state']['aliceState']);
                    if ($status['state']['aliceState'] == 'IDLE') break;
                }
                $this->stopListening($token, $ip, $port);
            }
            $client->close();
            return $result_data;
        }
        return false;

    }

    function stopListening($token, $ip, $port = 1961)
    {
        DebMes("Sending stop listening to $ip", 'yadevices');
        $clientConfig = new ClientConfig();
        $clientConfig->setHeaders([
            'X-Origin' => 'http://yandex.ru/',
        ]);
        $clientConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $msg = array(
            'conversationToken' => $token,
            'payload' => array(
                'command' => 'serverAction',
                'serverActionEventPayload' => array(
                    'type' => 'server_action',
                    'name' => 'on_suggest'
                )
            )
        );

        $client = new WebSocketClient('wss://' . $ip . ':' . $port . '/', $clientConfig);
        $client->send(json_encode($msg));
        $client->close();
        $result = $client->receive();
        $result_data = json_decode($result, true);
        if (is_array($result_data)) {
            return $result_data;
        }
        return false;

    }

    function getStatus($token, $ip, $port = 1961)
    {
        $clientConfig = new ClientConfig();
        $clientConfig->setHeaders([
            'X-Origin' => 'http://yandex.ru/',
        ]);
        $clientConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $msg = array(
            'conversationToken' => $token,
            'payload' => array(
                'command' => 'ping',
            )
        );

        $client = new WebSocketClient('wss://' . $ip . ':' . $port . '/', $clientConfig);
        $client->send(json_encode($msg));
        $client->close();
        $result = $client->receive();
        $result_data = json_decode($result, true);
        //DebMes($result_data['state']['aliceState']);
        if (is_array($result_data)) {
            return $result_data;
        }
        return false;

    }


    function sendCommandToStationCloud($id, $command, $dopParam = 0)
    {
        if (!$command) return false;
        $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$id);
        $this->sendCloudTTS($station['IOT_ID'],$command,'text');
    }

    function sendCommandToStation($id, $command, $dopParam = 0)
    {
        if (!$command) return false;
        $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$id);
        if (!$station['ID'] || !$station['IP']) return false;

        if ($station['DEVICE_TOKEN']) {
            if ($dopParam && ($command == 'setVolume')) {
                $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP'], 1961, $dopParam);
            } else {
                $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP']);
            }
            if (is_array($result)) {
                return true;
            }
        }

        $this->getToken();
        $token = $this->getDeviceToken($station['STATION_ID'], $station['PLATFORM']);
        if (!$token) return false;

        $station['DEVICE_TOKEN'] = $token;
        SQLUpdate('yastations', $station);

        if ($dopParam && ($command == 'setVolume')) {
            $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP'], 1961, $dopParam);
        } else {
            $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP']);
        }
        if (is_array($result)) {
            return true;
        }
        return false;

    }

    function processSubscription($event, $details = '')
    {
        $this->getConfig();
        if ($event == 'SAY') {
            $level = (int)$details['level'];
            $message = $details['message'];

            //TTS LOCAL
            $stations = SQLSelect("SELECT * FROM yastations WHERE TTS=1");
            foreach ($stations as $station) {
                $min_level = 0;
                if ($station['MIN_LEVEL_TEXT'] != '') {
                    $min_level = processTitle($station['MIN_LEVEL_TEXT']);
                } elseif ($station['MIN_LEVEL']) {
                    $min_level = $station['MIN_LEVEL'];
                }
                if ($level >= $min_level) {
                    $this->sendCommandToStation($station['ID'], 'повтори за мной ' . $message);
                }
            }

            // TTS CLOUD
            $stations = SQLSelect("SELECT * FROM yastations WHERE TTS=2");
            foreach ($stations as $station) {
                $min_level = 0;
                if ($station['MIN_LEVEL_TEXT'] != '') {
                    $min_level = processTitle($station['MIN_LEVEL_TEXT']);
                } elseif ($station['MIN_LEVEL']) {
                    $min_level = $station['MIN_LEVEL'];
                }
                if ($level >= $min_level) {
                    $this->sendCloudTTS($station['IOT_ID'],$message);
                }
            }
        }
    }

    function sendValueToYandex($iot_id, $command_type, $value)
    {
        $url = "https://iot.quasar.yandex.ru/m/user/devices/" . $iot_id . "/actions";
        if ($command_type == 'devices.capabilities.on_off') {
            if ($value) {
                $value = true;
            } else {
                $value = false;
            }
            $data = array('actions' => array(
                array('state' => array('instance' => 'on', 'value' => $value),
                    'type' => $command_type
                )));
            //dprint($data);
            dprint($url, false);
            dprint(json_encode($data), false);
            $result = $this->apiRequest($url, 'POST', $data);
            dprint($result, false);
            //https://iot.quasar.yandex.ru/m/user/devices/<iot_id>/actions
            //{"JSON":{"actions":[{"state":{"instance":"on","value":true},"type":"devices.capabilities.on_off"}]}}
        }
    }

    function propertySetHandle($object, $property, $value)
    {
        $properties = SQLSelect("SELECT yadevices_capabilities.*, yadevices.IOT_ID FROM yadevices_capabilities LEFT JOIN yadevices ON yadevices_capabilities.DEVICE_ID=yadevices.ID WHERE yadevices_capabilities.LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND yadevices_capabilities.LINKED_PROPERTY LIKE '" . DBSafe($property) . "'");
        $total = count($properties);
        for ($i = 0; $i < $total; $i++) {
            $this->sendValueToYandex($properties[$i]['IOT_ID'], $properties[$i]['TITLE'], $value);
        }
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        subscribeToEvent($this->name, 'SAY');
        parent::install();
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS yastations');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        yastations -
        */
        $data = <<<EOD
 yastations: ID int(10) unsigned NOT NULL auto_increment
 yastations: TITLE varchar(255) NOT NULL DEFAULT ''
 yastations: STATION_ID varchar(100) NOT NULL DEFAULT ''
 yastations: IP varchar(100) NOT NULL DEFAULT ''
 yastations: MIN_LEVEL_TEXT varchar(255) NOT NULL DEFAULT ''
 yastations: TTS int(3) NOT NULL DEFAULT '0'
 yastations: IOT_ID varchar(255) NOT NULL DEFAULT ''
 yastations: PLATFORM varchar(100) NOT NULL DEFAULT ''
 yastations: ICON_URL varchar(255) NOT NULL DEFAULT ''
 yastations: DEVICE_TOKEN varchar(255) NOT NULL DEFAULT ''
 yastations: TTS_SCENARIO varchar(255) NOT NULL DEFAULT ''
 yastations: SCREEN_CAPABLE int(3) NOT NULL DEFAULT '0'
 yastations: SCREEN_PRESENT int(3) NOT NULL DEFAULT '0'
 yastations: IS_ONLINE int(3) NOT NULL DEFAULT '0'
 yastations: UPDATED datetime

 yadevices: ID int(10) unsigned NOT NULL auto_increment
 yadevices: TITLE varchar(255) NOT NULL DEFAULT ''
 yadevices: IOT_ID varchar(255) NOT NULL DEFAULT ''
 yadevices: DEVICE_TYPE varchar(100) NOT NULL DEFAULT ''
 yadevices: UPDATED datetime

 yadevices_capabilities: ID int(10) unsigned NOT NULL auto_increment
 yadevices_capabilities: YADEVICE_ID int(10) NOT NULL DEFAULT '0'
 yadevices_capabilities: TITLE varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: VALUE varchar(100) NOT NULL DEFAULT ''
 yadevices_capabilities: LINKED_OBJECT varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: LINKED_PROPERTY varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: UPDATED datetime

EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgRGVjIDMxLCAyMDE5IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
/*

GET https://iot.quasar.yandex.ru/m/user/devices

GET https://quasar.yandex.ru/get_device_config?device_id=<station_id>&platform=yandexstation

GET https://iot.quasar.yandex.ru/m/user/devices/<iot_id>/configuration

GET https://iot.quasar.yandex.ru/m/user/devices/<iot_id>

POST
https://iot.quasar.yandex.ru/m/user/devices/<iot_id>/actions
{"JSON":{"actions":[{"state":{"instance":"on","value":true},"type":"devices.capabilities.on_off"}]}}

GET https://iot.quasar.yandex.ru/m/user/devices/<iot_id>

сценарии:
GET https://iot.quasar.yandex.ru/m/user/scenarios

Запуск
POST https://iot.quasar.yandex.ru/m/user/scenarios/<iot_id>/actions

Работа со станцией:
https://github.com/anVlad11/dd-alicization
 */
