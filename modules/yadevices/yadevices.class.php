<?php

/*
 * greetings to https://github.com/AlexxIT/YandexStation/ :)
 */

Define('YADEVICES_COOKIE_PATH', ROOT . "cms/yadevices/cookie.txt");

spl_autoload_register(function ($class_name) {
    $path = DIR_MODULES . 'yadevices/' . $class_name . '.php';
    $path = str_replace('\\', '/', $path);
    @include_once $path;
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
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
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

        global $station;
        global $update;
        global $zoom;
        global $bgcolor;
        global $textcolor;

        if (isset($station)) {
            $this->station = $station;
        }
        if (isset($update)) {
            $this->update = $update;
        }
        if (isset($zoom)) {
            $this->zoom = $zoom;
        }
        if (isset($bgcolor)) {
            $this->bgcolor = $bgcolor;
        }
        if (isset($textcolor)) {
            $this->textcolor = $textcolor;
        }

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
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
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
        $this->getConfig();
        if (!empty($params['runscenario'])) {
            $this->runScenario($params['runscenario']);
        }

        DebMes("API call: " . json_encode($params), 'yadevices');

        if ($params['station'] && (isset($params['command']) || isset($params['say']))) {
            $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$params['station']);
            $effect = isset($params['effect']) ? $params['effect'] : '';
            $announce = isset($params['announce']) ? $params['announce'] : '';
            if (!$effect && $station['TTS_EFFECT']) {
                $effect = $station['TTS_EFFECT'];
            }
            if (!$announce && $station['TTS_ANNOUNCE']) {
                $announce = $station['TTS_ANNOUNCE'];
            }
            //TTS=2 AND IOT_ID!=''
            if ($station['TTS'] == 2 && $station['IOT_ID'] != '') {
                if ($params['say']) {
                    $msg = $params['say'];
                    return $this->sendCloudTTS($station['IOT_ID'], $msg);
                } else {
                    return $this->sendCloudTTS($station['IOT_ID'], $params['command'], 'text_action');
                }
            } else {
                if (($params['command'] == 'setVolume') && $params['volume']) {
                    return $this->sendCommandToStation((int)$params['station'], $params['command'], $params['volume']);
                } elseif ($params['say']) {
                    $this->sendCommandToStation((int)$params['station'], 'повтори за мной ' . $params['say']);
                } else {
                    return $this->sendCommandToStation((int)$params['station'], $params['command']);
                }
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
        $out['OAUTH_TOKEN'] = $this->config['OAUTH_TOKEN'];
        $out['FULL_NAME'] = $this->config['FULL_NAME'];

        if ($this->view_mode == 'update_settings') {
            global $api_username;
            $this->config['API_USERNAME'] = $api_username;
            global $api_password;
            $this->config['API_PASSWORD'] = $api_password;

            $token = $this->firstAuth($api_username, $api_password);
            if ($token != '') {
                $this->config['OAUTH_TOKEN'] = $token;

                require_once('client.php');
                $newClient = new Client($token);
                $accountInfo = $newClient->accountStatus();
                $this->config['FULL_NAME'] = $accountInfo->account->fullName;

                //$this->refreshStations();
                //$this->refreshDevices();

            } else {
                $this->config['OAUTH_TOKEN'] = '[NON-TOKEN-ACCOUNT]';
                $this->config['FULL_NAME'] = '[Резервный способ входа]';

                //$out['AUTH_ERROR'] = 'Ошибка получения OAUTH токена! Повторите попытку или обратитесь в службу поддержки Яндекс. Так же, ошибка может быть вызвана неверным логином или паролем!';
            }
            $this->saveConfig();
            $this->redirect("?");
        }
        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }

        if ($this->view_mode == 'auth') {
            $this->auth($out);
        }

        if ($this->view_mode == 'refreshScenarios') {
            $this->addScenarios();

            $this->redirect("?");
        }

        if ($this->view_mode == 'logout') {
            $this->config['OAUTH_TOKEN'] = '';
            $this->config['FULL_NAME'] = '';
            $this->config['API_USERNAME'] = '';
            $this->config['API_PASSWORD'] = '';

            //$this->clearAll();

            $cookie = YADEVICES_COOKIE_PATH;
            @unlink($cookie);

            $this->saveConfig();
            $this->redirect("?");
        }
        if ($this->data_source == 'yastations' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_yastations') {
                $this->search_yastations($out);
                $out['LOGIN_STATUS'] = (int)$this->checkLogin();
            }
            if ($this->view_mode == 'search_yadevices') {
                $this->search_yadevices($out);
            }
            if ($this->view_mode == 'search_scenarios') {
                $this->search_scenarios($out);
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
                $this->redirect("?tab=" . $this->tab . "&view_mode=" . $this->view_mode);
            }
        }
        if ($this->view_mode == 'upload_Cookie') {
            global $file;
            if (!empty($file) && $_FILES["file"]["type"] == 'text/plain' && $_FILES["file"]["size"] <= '100000') {

                move_uploaded_file($file, YADEVICES_COOKIE_PATH);
                //https://iot.quasar.yandex.ru/m/user/scenarios
                $checkCookie = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');

                if ($checkCookie['status'] != 'ok') {
                    $cookie = YADEVICES_COOKIE_PATH;
                    @unlink($cookie);
                    $out['UPLOAD_ERROR'] = 'Файл который вы загружаете не является Cookie файлом с сайта Яндекс или он устарел.';
                    return;
                }

                //Пытаемся фиксить оаутх токен
                if ($this->config['OAUTH_TOKEN'] == '[NON-TOKEN-ACCOUNT]') {
                    $generateOAUTHToken = $this->fixOAUTHToken();
                    if ($generateOAUTHToken != false) {
                        $this->config['OAUTH_TOKEN'] = $generateOAUTHToken;
                        //Запросим еще раз инфо
                        require_once('client.php');
                        $newClient = new Client($generateOAUTHToken);
                        $accountInfo = $newClient->accountStatus();
                        $this->config['FULL_NAME'] = $accountInfo->account->fullName;
                        $this->saveConfig();
                    }
                }

                //$this->clearAll();

                $this->refreshStations();
                $this->refreshDevices();

                $this->redirect("?");
            } else {
                $out['UPLOAD_ERROR'] = 'Допускается загрузка текстовых документов размером не более 100кб.';
            }
        }

        if ($this->view_mode == 'generate_dev_token') {
            global $id;
            $this->getDeviceTokenByHand($id);
        }

        if ($this->view_mode == 'update_settings_cycle') {
            global $cycleIsOn;
            global $cycleIsOnTime;
            global $reloadAfterOpen;
            global $errorMonitor;
            global $errorMonitorType;

            if ($errorMonitor == 'on') {
                $this->config['ERRORMONITOR'] = 1;
                $this->config['ERRORMONITORTYPE'] = $errorMonitorType;
            } else {
                $this->config['ERRORMONITOR'] = 0;
                $this->config['ERRORMONITORTYPE'] = 0;
            }

            if ($cycleIsOn == 'on') {
                $this->config['STATUS_CYCLE'] = 1;
            } else {
                $this->config['STATUS_CYCLE'] = 0;
            }

            if ($reloadAfterOpen == 'on') {
                $this->config['RELOADAFTEROPEN'] = 1;
            } else {
                $this->config['RELOADAFTEROPEN'] = 0;
            }

            $this->config['RELOAD_TIME'] = $cycleIsOnTime;
            $this->saveConfig();

            //setGlobal('ThisComputer.cycle_yadevicesRun','');
            setGlobal('ThisComputer.cycle_yadevicesControl', 'restart');

            $this->redirect("?");
        }

        //Проверка существования куки
        if (file_exists(YADEVICES_COOKIE_PATH)) {
            $out['COOKIE_FILE'] = 1;
        } else {
            $out['COOKIE_FILE'] = 0;
        }


        $out['RELOAD_TIME'] = $this->config['RELOAD_TIME'];
        $out['RELOADAFTEROPEN'] = $this->config['RELOADAFTEROPEN'];
        $out['STATUS_CYCLE'] = $this->config['STATUS_CYCLE'];
        $out['ERRORMONITOR'] = $this->config['ERRORMONITOR'];
        $out['ERRORMONITORTYPE'] = $this->config['ERRORMONITORTYPE'];
    }

    function auth(&$out) {
        include_once(DIR_MODULES.'yadevices/auth.inc.php');
    }

    function fixOAUTHToken()
    {
        // getAuth token
        $ya_music_client_id = '23cabbbdc6cd418abb4b39c32c41195d';
        $url = "https://oauth.yandex.ru/authorize?response_type=token&client_id=" . $ya_music_client_id;

        $cookie = YADEVICES_COOKIE_PATH;

        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        //curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        $result = curl_exec($YaCurl);

        if (preg_match('/^Found.*access_token=([^<]+?)&/is', $result, $m)) {
            $oauth_token = $m[1];
            return $oauth_token;
        } else {
            return false;
        }
    }

    function getDeviceTokenByHand($id)
    {
        $req = SQLSelectOne("SELECT STATION_ID, PLATFORM FROM yastations WHERE ID='" . dbSafe($id) . "'");
        $this->getDeviceToken($req['STATION_ID'], $req['PLATFORM']);
        $this->redirect("?id=" . $id . "&view_mode=edit_yastations");
    }

    function firstAuth($login, $password)
    {
        require_once('client.php');
        $newClient = new Client();
        //Запрос на получение токена
        $getToken = $newClient->fromCredentials($login, $password, false);
        return $getToken;
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

    function refreshDevicesData($devID = '')
    {
        //TODO сделать блокировку функции если цикл выключен
        //TODO выгрузка по ИД
        if ($devID != '') {
            $req[0]['YADEVICE_ID'] = $devID;
        } else {
            $req = SQLSelect("SELECT distinct YADEVICE_ID FROM yadevices_capabilities");
        }

        foreach ($req as $key_device => $device) {
            //Узнаем IOT_ID
            $iotID = SQLSelectOne("SELECT IOT_ID FROM yadevices WHERE ID = '" . dbSafe($device['YADEVICE_ID']) . "'");
            //Запрашиваем инфу по устройству
            $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/devices/' . $iotID['IOT_ID']);


            if (!is_array($data)) continue;

            if (isset($data['state']) && $data['state'] == 'online') {
                $currentStatus = 1;
            } else {
                $currentStatus = 0;
            }

            $onlineArray = [
                'type' => 'devices',
                'state' => [
                    'value' => $currentStatus,
                ],
                'parameters' => [
                    'instance' => 'online',
                ],
            ];
			
			if(isset($data["properties"])){
				array_push($data["properties"], $onlineArray);
			}

            //Циклом пройдемся по всем умениям
            if (isset($data["capabilities"]) && is_array($data["capabilities"])) {
                foreach ($data["capabilities"] as $capabilitie) {
                    if ($capabilitie['type'] == 'devices.capabilities.on_off') {
                        $c_type = $capabilitie['type'];
                    } else {
                        if (isset($capabilitie['state']['instance'])) {
                            $c_type = $capabilitie['type'] . '.' . $capabilitie['state']['instance'];
                        } else if ($capabilitie['parameters']['instance']) {
                            $c_type = $capabilitie['type'] . '.' . $capabilitie['parameters']['instance'];
                        } else {
                            $c_type = $capabilitie['type'] . '.unknown';
                        }
                    }

                    $req_skills = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE TITLE = '" . dbSafe($c_type) . "' AND YADEVICE_ID = '" . dbSafe($device['YADEVICE_ID']) . "'");

                    //Основные умения, меняем значение
                    if (isset($capabilitie['state']['value'])){
						if(is_bool($capabilitie['state']['value']) == true) {
							if ($capabilitie['state']['value'] == true) {
								$value = 1;
							} else {
								$value = 0;
							}
						} else if (isset($capabilitie['state']['instance'])){
							if($capabilitie['state']['instance']== 'color') {
								$value = $capabilitie['state']['value']['id'];
							} else if ($capabilitie['state']['instance']== 'scene') { //xor2016: добавлена сцена для Я.лампочки
								$value = $capabilitie['state']['value']['id'];
							}
						} else {
							$value = $capabilitie['state']['value'];
						}  
                    } else {
						$value = '?';
					}

                    $new_value = $value;
                    $old_value = $req_skills['VALUE'];

                    //debMes($device['YADEVICE_ID'].' - '.$capabilitie['type'].' - NEW: '.$value.', OLD - '.$req_skills['VALUE']);
                    //debMes("SELECT * FROM yadevices_capabilities WHERE TITLE = '".dbSafe($c_type)."'");

                    if ($new_value != $old_value && !empty($req_skills['LINKED_OBJECT']) && !empty($req_skills['LINKED_PROPERTY'])) {
                        if ($new_value != gg($req_skills['LINKED_OBJECT'] . '.' . $req_skills['LINKED_PROPERTY'])) //xor2016: иначе пишется второй раз в свойство
                            setGlobal($req_skills['LINKED_OBJECT'] . '.' . $req_skills['LINKED_PROPERTY'], $new_value, 0, $this->name . '.' . $c_type);
                    }

                    if ($new_value != $old_value) {
                        $req_skills['VALUE'] = $new_value;
                        $req_skills['UPDATED'] = date('Y-m-d H:i:s');

                        //if($device['YADEVICE_ID'] == '790') {
                        //	echo '<pre>';
                        //	var_dump($req_skills);
                        //}

                        //dprint($req_skills, false);
                        SQLUpdate('yadevices_capabilities', $req_skills);
                    }
                    if (!empty($req_skills['LINKED_OBJECT']) && !empty($req_skills['LINKED_METHOD']) && $new_value != $old_value) {
                        callMethod($req_skills['LINKED_OBJECT'] . '.' . $req_skills['LINKED_METHOD'], array('NEW_VALUE' => $new_value, 'OLD_VALUE' => $old_value, 'DEVICE_STATE' => $currentStatus, 'UPDATED' => $req_skills['UPDATED'], 'ALLOWPARAMS' => $req_skills['ALLOWPARAMS'], 'MODULE' => $this->name));
                    }
                }
            }

            //Значения датчиков
            if (isset($data["properties"]) && is_array($data["properties"])) {
                foreach ($data["properties"] as $propertie) {
                    $p_type = $propertie['type'] . '.' . $propertie['parameters']['instance'];

                    //Получаем по каждом свойству по отдельности
                    $req_prop = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE TITLE = '" . dbSafe($p_type) . "' AND YADEVICE_ID = '" . dbSafe($device['YADEVICE_ID']) . "'");

                    //Основные датчики
                    $value = $propertie['state']['value'];

                    $new_value = $value;
                    $old_value = $req_prop['VALUE'];

                    //debMes($device['YADEVICE_ID'].' - '.$propertie['type'].'.'.$propertie['parameters']['instance'].' - NEW: '.$value.', OLD - '.$req_prop['VALUE']);

                    if ($new_value != $old_value && !empty($req_prop['LINKED_OBJECT']) && !empty($req_prop['LINKED_PROPERTY'])) {
                        setGlobal($req_prop['LINKED_OBJECT'] . '.' . $req_prop['LINKED_PROPERTY'], $new_value, 0, $this->name . '.' . $p_type);
                    }
                    if ($new_value != $old_value) {
                        $req_prop['VALUE'] = $new_value;
                        $req_prop['UPDATED'] = date('Y-m-d H:i:s');

                        SQLUpdate('yadevices_capabilities', $req_prop);
                    }
                    if ($new_value != $old_value && !empty($req_prop['LINKED_OBJECT']) && !empty($req_prop['LINKED_METHOD'])) {
                        callMethod($req_prop['LINKED_OBJECT'] . '.' . $req_prop['LINKED_METHOD'], array('NEW_VALUE' => $new_value, 'OLD_VALUE' => $old_value, 'DEVICE_STATE' => $currentStatus, 'UPDATED' => $req_prop['UPDATED'], 'ALLOWPARAMS' => $req_prop['ALLOWPARAMS'], 'MODULE' => $this->name));
                    }
                }
            }

        }
    }

    function refreshDevices()
    {
        //SQLExec('TRUNCATE TABLE yadevices');
        //Делаем анлинк
        // $req = SQLSelect("SELECT * FROM yadevices_capabilities WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");

        // foreach($req as $prop) {
        // removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
        // }
        //Чистим таблицу
        //SQLExec('TRUNCATE TABLE yadevices_capabilities');

        $iot_ids = array();
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
                        $quasar_id = $device['quasar_info']['device_id'];
                        $iot_ids[] = $iot_id;

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

                        if (preg_match('/^devices.types.smart_speaker/uis', $type)) {
                            $rec = SQLSelectOne("SELECT * FROM yastations WHERE TITLE='" . DBSafe($name) . "'");
                            if (!$rec['ID'] && $quasar_id) {
                                $rec = SQLSelectOne("SELECT * FROM yastations WHERE STATION_ID='" . DBSafe($quasar_id) . "'");
                            }
                            if ($rec['ID']) {
                                $rec['IOT_ID'] = $iot_id;
                                $rec['UPDATED'] = date('Y-m-d H:i:s');
                                SQLUpdate('yastations', $rec);
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
                $quasar_id = $speaker['quasar_info']['device_id'];
                $iot_ids[] = $iot_id;
                $rec = SQLSelectOne("SELECT * FROM yastations WHERE TITLE='" . DBSafe($name) . "'");
                if (!$rec['ID'] && $quasar_id) {
                    $rec = SQLSelectOne("SELECT * FROM yastations WHERE STATION_ID='" . DBSafe($quasar_id) . "'");
                }
                if ($rec['ID']) {
                    $rec['IOT_ID'] = $iot_id;
                    $rec['UPDATED'] = date('Y-m-d H:i:s');
                    SQLUpdate('yastations', $rec);
                }
            }
        }

        $all_devices = SQLSelect("SELECT ID, IOT_ID, TITLE FROM yadevices WHERE IOT_ID!=''");
        $total = count($all_devices);
        for ($i = 0; $i < $total; $i++) {
            if (!in_array($all_devices[$i]['IOT_ID'], $iot_ids)) {
                $this->delete_yadevice($all_devices[$i]['ID']);
            }
        }

    }

    function yandex_encode($in)
    {
        $in = strtolower($in);
        $MASK_EN = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f', '-');
        $MASK_RU = array('о', 'е', 'а', 'и', 'н', 'т', 'с', 'р', 'в', 'л', 'к', 'м', 'д', 'п', 'у', 'я', 'ы');
        return 'мжд ' . str_replace($MASK_EN, $MASK_RU, $in);
    }

    function yandex_decode($in)
    {
        $in = mb_substr($in, 4);
        $MASK_EN = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f', '-');
        $MASK_RU = array('о', 'е', 'а', 'и', 'н', 'т', 'с', 'р', 'в', 'л', 'к', 'м', 'д', 'п', 'у', 'я', 'ы');
        return str_replace($MASK_RU, $MASK_EN, $in);
    }

    function addScenarios($repeating = 0)
    {
        $some_added = 0;
        $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');
        $scenarios = array();

        if (isset($data['scenarios']) && is_array($data['scenarios'])) {
            foreach ($data['scenarios'] as $scenario) {
                $scenarios[$this->yandex_decode($scenario['name'])] = $scenario;
            }
        }
        $stations = SQLSelect("SELECT * FROM yastations ORDER BY ID");
        foreach ($stations as $station) {
            $station_id = $station['IOT_ID'];
            if (!isset($scenarios[strtolower($station_id)])) {
                // add scenario
                $nameEncode = $this->yandex_encode($station_id);
                $payload = array( //xor2016: изменения у Яндекса
                    'name' => $nameEncode,
                    'icon' => 'home',

                    'triggers' => array(array(
                        'type' => 'scenario.trigger.voice',
                        'value' => mb_substr($nameEncode, 4),
                    )),
                    'steps' => array(array(
                        'type' => 'scenarios.steps.actions',
                        'parameters' => array(
                            'requested_speaker_capabilities' => array(),
                            'launch_devices' => array(array(
                                'id' => $station_id,
                                'capabilities' => array(array(
                                    'type' => 'devices.capabilities.quasar.server_action',
                                    'state' => array(
                                        'instance' => 'phrase_action',
                                        'value' => 'Сценарий для МДМ. НЕ УДАЛЯТЬ!'
                                    )
                                ))
                            ))
                        )
                    ))
                );
                $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/', 'POST', $payload);  //xor2016: изменения у Яндекса
                if (isset($result['status']) && $result['status'] == 'ok') {
                    $some_added = 1;
                }
            } else {
                $station['TTS_SCENARIO'] = $scenarios[strtolower($station_id)]['id'];
                SQLUpdate('yastations', $station);
            }
        }
        if ($some_added && !$repeating) {
            $this->addScenarios(1);
        }
    }

    function reloadSkills($skill_id)
    {
        if ($skill_id) return false;

        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/skills/' . $skill_id . '/discovery', 'POST', array());

        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
            if ($result["status"] == 'error') {
                registerError('YaDevice -> reloadSkills()', 'Ошибка обновления устройств скилла. Ответ от Яндекс: ' . $result["message"]);
            }
        } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            if ($result["status"] == 'error') {
                DebMes('Ошибка обновления устройств скилла. Ответ от Яндекс: ' . $result["message"], 'yadevices');
            } else {
                DebMes('Запрошено обновление устройств для скилла: ' . $skill_id, 'yadevices');
            }
        }

        return $result;
    }

    function runScenario($scenario_id)
    {
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . '/actions', 'POST', array());

        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
            if ($result["status"] == 'error') {
                registerError('YaDevice -> runScenario()', 'Ошибка запуска сценария. Ответ от Яндекс: ' . $result["message"]);
            }
        } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            if ($result["status"] == 'error') {
                DebMes('Ошибка запуска сценария. Ответ от Яндекс: ' . $result["message"], 'yadevices');
            } else {
                DebMes('Запрошено выполнение сценария: ' . $result["request_id"], 'yadevices');
            }
        }

        return $result;
    }

    function delScenario($scenario_id)
    {
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id, 'DELETE');

        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
            if ($result["status"] == 'error') {
                registerError('YaDevice -> delScenario()', 'Ошибка удаления сценария. Ответ от Яндекс: ' . $result["message"]);
            }
        } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            if ($result["status"] == 'error') {
                DebMes('Ошибка удаления сценария. Ответ от Яндекс: ' . $result["message"], 'yadevices');
            } else {
                DebMes('Запрошено удаление сценария: ' . $result["request_id"], 'yadevices');
            }
        }

        return $result;
    }

    function sendCloudTTS($iot_id, $phrase, $action = 'phrase_action')
    {
        $station_rec = SQLSelectOne("SELECT * FROM yastations WHERE IOT_ID='" . $iot_id . "'");

        $phrase = str_replace(array('(', ')'), ' ', $phrase);
        $phrase = preg_replace('/<.+?>/u', '', $phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase);

        if (mb_strlen($phrase, 'UTF-8') >= 100) {
            $phrase = mb_substr($phrase, 0, 99, 'UTF-8');
        }

        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            DebMes("Sending cloud '$action: $phrase' to " . $station_rec['TITLE'], 'yadevices');
        }


        //dprint($station_rec);

        //$action = 'phrase';
        //phrase_action - просто сказать и не ждать
        //text_action - выполнит команду

        if (!$station_rec['TTS_SCENARIO']) return;

        $nameEncode = $this->yandex_encode($iot_id);

        $payload = array( //xor2016: изменения у Яндекса
            'name' => $nameEncode,
            'icon' => 'home',
            'triggers' => array(array(
                'type' => 'scenario.trigger.voice',
                'value' => $nameEncode,
            )),
            'steps' => array(array(
                'type' => 'scenarios.steps.actions',
                'parameters' => array(
                    'requested_speaker_capabilities' => array(),
                    'launch_devices' => array(array(
                        'id' => $iot_id,
                        'capabilities' => array(array(
                            'type' => 'devices.capabilities.quasar.server_action',
                            'state' => array(
                                'instance' => $action,
                                'value' => $phrase
                            )
                        ))
                    ))
                )
            ))
        );
        $scenario_id = $station_rec['TTS_SCENARIO'];
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id, 'PUT', $payload);
        //DebMes('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . " PUT:\n" . json_encode($payload), 'station_' . $station_rec['TITLE']);
        //DebMes(json_encode($result), 'station_' . $station_rec['TITLE']);

        if (is_array($result) && $result['status'] == 'ok') {
            $payload = array();
            $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . '/actions', 'POST', $payload);
            //DebMes('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . " POST:\n" . json_encode($payload), 'station_' . $station_rec['TITLE']);
            //DebMes(json_encode($result), 'station_' . $station_rec['TITLE']);
            if (is_array($result) && $result['status'] == 'ok') {
                return true;
            } else {
                if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                    registerError('YaDevice -> sendCloudTTS()', 'Ошибка вызова сценария для запуска CloudTTS. Ошибка: ' . json_encode($result));
                } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                    DebMes("Failed to run TTS scenario: " . json_encode($result), 'yadevices');
                }
            }
        } else {
            if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                registerError('YaDevice -> sendCloudTTS()', 'Ошибка обновления сценария для запуска CloudTTS. Ошибка: ' . json_encode($result) . "<br>" . $result['message']);
            } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                DebMes("Failed to update TTS scenario: " . json_encode($result) . "\n" . $result['message'], 'yadevices');
            }
        }
        return false;
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
                $rec['OWNER'] = $this->config['API_USERNAME'];
                $rec['TITLE'] = $item['name'];
                $rec['ICON_URL'] = $item['icon'];
                $rec['PLATFORM'] = $item['platform'];
                $rec['SCREEN_CAPABLE'] = (int)$item['screen_capable'];
                $rec['SCREEN_PRESENT'] = (int)$item['screen_present'];
                $rec['IS_ONLINE'] = (int)$item['online'];
                SQLUpdate('yastations', $rec);
            }
        }
        //Удаляем станции не принадлежащие этой учетке
        SQLExec("DELETE FROM yastations WHERE OWNER != '" . $this->config['API_USERNAME'] . "'");

        $this->addScenarios();
    }

    function apiRequest($url, $method = 'GET', $params = 0, $repeating = 0)
    {

        $debug = 0;

        if ($method != 'GET' && !isset($this->csrf_token)) {
            $token = $this->getToken();
        }

        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, YADEVICES_COOKIE_PATH);
        if ($method == 'GET') {
            curl_setopt($YaCurl, CURLOPT_POST, false);
        } else {
            $headers = array(
                'Content-type: application/json',
                'x-csrf-token: ' . $this->csrf_token
            );
            curl_setopt($YaCurl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($YaCurl, CURLOPT_POST, true);
            if ($method != 'POST') {
                curl_setopt($YaCurl, CURLOPT_CUSTOMREQUEST, $method);
            } else {
                //curl_setopt($YaCurl, CURLOPT_POST, true);
            }
            if (is_array($params)) {
                curl_setopt($YaCurl, CURLOPT_POSTFIELDS, json_encode($params)); //, JSON_UNESCAPED_SLASHES
            }
        }
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLINFO_HEADER_OUT, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
        //curl_setopt($YaCurl, CURLOPT_HEADER, true);

        $result = curl_exec($YaCurl);
        $info = curl_getinfo($YaCurl);
        curl_close($YaCurl);

        $request_headers = isset($info['request_header']) ? $info['request_header'] : '';
        if ($debug) {
            dprint("REQUEST HEADERS:",false);
            dprint($request_headers,false);
        }
        $result_code = $info['http_code'];
        $data = json_decode($result, true);

        if (!is_array($data) && $debug) {
            dprint($method." ".$url.'<br/>'.$result,false);
        }

        if (!$repeating &&
            (!isset($data['code']) || $data['code']!= 'BAD_REQUEST') &&   
            ($result_code==403 || (isset($data['status']) && $data['status'] == 'error'))
        ) {
            if ($debug) {
                dprint("REPEATING: ".$method." ".$url,false);
            }
            $this->csrf_token = '';
            $data = $this->apiRequest($url, $method, $params, 1);
        }
        return $data;
    }

    function getCSRFToken($cookie_file = YADEVICES_COOKIE_PATH) {
        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/am?app_platform=android');
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($YaCurl, CURLOPT_HEADER, 1);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_VERBOSE, false);
        curl_setopt($YaCurl, CURLOPT_FOLLOWLOCATION, 1);
        $result = curl_exec($YaCurl);
        curl_close($YaCurl);

        if (preg_match('/"csrf_token" value="(.+?)"/', $result, $m)) {
            $token = $m[1];
            return $token;
        } else {
            if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                DebMes("Failed to get CSRF token:\n" . $result, 'yadevices');
            }
            return false;
        }
    }

    function getToken($url = 'https://yandex.ru/quasar/iot')
    {
        //Получение токенов для отправки запросов в Яндекс
        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, YADEVICES_COOKIE_PATH);
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, YADEVICES_COOKIE_PATH);
        curl_setopt($YaCurl, CURLOPT_HEADER, 1);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($YaCurl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($YaCurl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($YaCurl, CURLOPT_VERBOSE, false);

        curl_setopt($YaCurl, CURLOPT_FOLLOWLOCATION, 1);

        $result = curl_exec($YaCurl);
        curl_close($YaCurl);

        if (preg_match('/"csrfToken2":"(.+?)"/', $result, $m)) {
            $token = $m[1];
            $this->csrf_token = $token;
            return $token;
        } else {
            if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                registerError('YaDevice -> getToken()', 'Ошибка получения csrfToken2 токена');
            } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                DebMes("Ошибка получения csrfToken2 токена", 'yadevices');
            }
            return false;
        }
    }

    function getDeviceToken($device_id, $platform, $force = false)
    {
        if ($force) {
            $oauth_token = '';
        } else {
            $oauth_token = $this->config['OAUTH_TOKEN'];
        }

        if ($oauth_token == '') {

            $post = array(
                'client_secret' => 'ad0a908f0aa341a182a37ecd75bc319e',
                'client_id' => 'c0ebe342af7d48fbbbfcf2d2eedb8f9e',
            );
            $postvars = '';
            foreach($post as $key=>$value) {
                $postvars .= $key . "=" . urlencode($value) . "&";
            }

            $cookie_data = LoadFile(YADEVICES_COOKIE_PATH);
            $new_cookies = array();
            $lines = explode("\n",$cookie_data);
            foreach($lines as $line) {
                if (preg_match('/^(.*?)\.yandex\.ru/',$line)) {
                    $values = explode("\t",$line);
                    $cookie_title = $values[5];
                    $cookid_value = $values[6];
                    if ($cookie_title == 'yaexpflags') continue;
                    $new_cookies[]=$cookie_title.'='.$cookid_value;
                }
            }
            $cookies_line = implode("; ",$new_cookies);
            $headers = array(
                'Ya-Client-Host: passport.yandex.ru',
                'Ya-Client-Cookie: ' . $cookies_line
            );

            $YaCurl = curl_init();
            curl_setopt($YaCurl, CURLOPT_URL, 'https://mobileproxy.passport.yandex.net/1/bundle/oauth/token_by_sessionid');
            curl_setopt($YaCurl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($YaCurl, CURLOPT_POST, true);
            curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
            curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($YaCurl, CURLOPT_COOKIEFILE, YADEVICES_COOKIE_PATH);
            curl_setopt($YaCurl, CURLOPT_COOKIEJAR, YADEVICES_COOKIE_PATH);
            curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
            $result = curl_exec($YaCurl);
            curl_close($YaCurl);

            $data = json_decode($result, true);


            if ($data['access_token']) {
                // getAuth token
                $post = array(
                    'client_secret' => '53bc75238f0c4d08a118e51fe9203300',
                    'client_id' => '23cabbbdc6cd418abb4b39c32c41195d',
                    'grant_type' => 'x-token',
                    'access_token' => $data['access_token'],
                );
                $postvars = '';
                foreach($post as $key=>$value) {
                    $postvars .= $key . "=" . urlencode($value) . "&";
                }
                $YaCurl = curl_init();
                curl_setopt($YaCurl, CURLOPT_URL, 'https://oauth.mobile.yandex.net/1/token');
                curl_setopt($YaCurl, CURLOPT_POST, true);
                curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
                curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($YaCurl, CURLOPT_COOKIEFILE, YADEVICES_COOKIE_PATH);
                curl_setopt($YaCurl, CURLOPT_COOKIEJAR, YADEVICES_COOKIE_PATH);
                curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
                $result = curl_exec($YaCurl);
                curl_close($YaCurl);

                $data = json_decode($result,true);
                if ($data['access_token']) {
                    $oauth_token = $data['access_token'];
                    $this->config['OAUTH_TOKEN'] = $oauth_token;
                    $this->saveConfig();
                } else {
                    if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                        DebMes("Failed to get x-token token:\n" . $result, 'yadevices');
                    }
                    return false;
                }

            } else {
                if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                    DebMes("Failed to get device access token:\n" . $result, 'yadevices');
                }
                return false;
            }

        }



        if (!$oauth_token) {
            return false;
        }

        $url = "https://quasar.yandex.net/glagol/token?device_id=" . $device_id . "&platform=" . $platform;

        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, YADEVICES_COOKIE_PATH);
        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_POST, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);

        $header = array();
        $header[] = 'Content-type: application/json';
        $header[] = 'Authorization: Oauth ' . $oauth_token;

        curl_setopt($YaCurl, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($YaCurl);

        //dprint($result);

        $data = json_decode($result, true);
        if ($data['status'] == 'ok' && $data['token']) {
            //Запишем токен
            SQLExec("UPDATE yastations SET DEVICE_TOKEN = '" . dbSafe($data['token']) . "' WHERE STATION_ID = '" . dbSafe($device_id) . "'");
            return $data['token'];
        } else {
            if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                DebMes("Failed to get device local token:\n" . $result, 'yadevices');
            }
            return false;
        }

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

        //Функции плеера
        global $station;
        if (empty($station)) {
            $station = $this->id;
            if (empty($station)) {
                $station = $this->station;
            }
        }

        global $bgcolor;
        if (empty($bgcolor)) {
            $bgcolor = $this->bgcolor;
        }
        if (!$bgcolor) $bgcolor = '0,0,0';
        $out['BGCOLOR'] = $bgcolor;

        global $textcolor;
        if (empty($textcolor)) {
            $textcolor = isset($this->textcolor) ? $this->textcolor : '';
        }
        if (!$textcolor) $textcolor = 'white';
        $out['TEXTCOLOR'] = $textcolor;

        //if(empty((int)$station)) {
        //	http_response_code(400);
        //	die();
        //}

        $out['STATION_ID'] = $station;
        global $update;
        if (empty($update)) {
            $update = $this->update;
        }

        if ($update < 1 || $update) $update = 5;
        $out['UPDATE_TIME'] = $update;

        global $zoom;
        if (empty($zoom)) {
            $zoom = $this->zoom;
        }
        $out['ZOOM_PLAYER'] = $zoom;

        $ajax = gr('ajax');

        $rec = SQLSelectOne("SELECT TITLE FROM yastations WHERE ID = '" . dbSafe($station) . "'");
        $out['TITLE'] = $rec['TITLE'];

        if ($ajax && $station && $out['TITLE']) {
            header("HTTP/1.0: 200 OK\n");
            header('Content-Type: text/html; charset=utf-8');
            $control = gr('control');

            if (!empty(strip_tags($control))) {
                if ($control == 'prev') {
                    $cmdToStation = 'предыдущий трек';
                } else if ($control == 'play') {
                    $cmdToStation = 'продолжи песню';
                } else if ($control == 'pause') {
                    $cmdToStation = 'пауза';
                } else if ($control == 'next') {
                    $cmdToStation = 'следующий трек';
                } else if ($control == 'volDown') {
                    $cmdToStation = 'тише';
                } else if ($control == 'volUp') {
                    $cmdToStation = 'громче';
                }

                callAPI('/api/module/yadevices', 'GET', array('station' => $station, 'command' => $cmdToStation));

                echo json_encode(array('status' => 'ok'));
            } else {
                $this->getToken();

                $rec = SQLSelectOne("SELECT IP, PLATFORM, STATION_ID FROM yastations WHERE ID = '" . dbSafe($station) . "'");

                $rec['DEVICE_TOKEN'] = $this->getDeviceToken($rec['STATION_ID'], $rec['PLATFORM']);

                if (!$rec) {
                    http_response_code(400);
                    die();
                }

                $status = $this->getStatus($rec['DEVICE_TOKEN'], $rec['IP']);

                echo json_encode($status);
            }
            exit;
        }
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

    function search_scenarios(&$out)
    {
        require(DIR_MODULES . $this->name . '/yascenarios_search.inc.php');
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
    function clearAll()
    {
        SQLExec("DELETE FROM yastations");
        //Отвяжемся от свойств
        $req = SQLSelect("SELECT * FROM yadevices_capabilities WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");

        foreach ($req as $prop) {
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
        }
        SQLExec("DELETE FROM yadevices_capabilities");
    }

    function delete_yastations($id)
    {
        $rec = SQLSelectOne("SELECT * FROM yastations WHERE ID='$id'");
        // some action for related tables
        SQLExec("DELETE FROM yastations WHERE ID='" . $rec['ID'] . "'");
    }

    function delete_yadevice($id)
    {
        //Отвяжемся от свойств
        $req = SQLSelect("SELECT * FROM yadevices_capabilities WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != '' AND YADEVICE_ID = '" . (int)$id . "'");

        foreach ($req as $prop) {
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
        }

        SQLExec("DELETE FROM yadevices_capabilities WHERE YADEVICE_ID=" . (int)$id);
        SQLExec("DELETE FROM yadevices WHERE ID=" . (int)$id);
    }

    function sendDataToStation($command, $token, $ip, $port = 1961, $dopParam = 0)
    {
        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            DebMes("Отправляем команду '$command' на устройство $ip", 'yadevices');
        }

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
                    if ($status['state']['aliceState'] == 'IDLE') {
                        break;
                    }
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
        if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
            DebMes("Останавливаем прослушивание сокета $ip", 'yadevices');
        }

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
        $result = $client->receive();
        $client->close();
        $result_data = json_decode($result, true);
        //var_dump($result_data);

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
        $result = $client->receive();
        $client->close();
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
        $this->sendCloudTTS($station['IOT_ID'], $command, $dopParam);
    }

    function sendCommandToStation($id, $command, $dopParam = 0)
    {
        if (!$command) return false;

        $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$id);
        if (!$station['ID'] || !$station['IP']) return false;

        $this->getToken();
        $token = $this->getDeviceToken($station['STATION_ID'], $station['PLATFORM']);

        if (!$token) return false;

        $station['DEVICE_TOKEN'] = $token;
        SQLUpdate('yastations', $station);


        if ($station['DEVICE_TOKEN']) {
            if ($dopParam && ($command == 'setVolume')) {
                $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP'], 1961, $dopParam);
            } else {
                $result = $this->sendDataToStation($command, $station['DEVICE_TOKEN'], $station['IP']);
            }
            if (is_array($result)) {
                return true;
            }
        } else {
            if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                registerError('YaDevice -> sendCommandToStation()', 'Перед тем, как отправлять команды на станцию - сформируйте токен доступа!');
            } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                debMes('sendCommandToStation() -> Перед тем, как отправлять команды на станцию - сформируйте токен доступа!', 'yadevices');
            }
        }
    }

    function processSubscription($event, $details = '')
    {
        $this->getConfig();

        if ($event == 'SAY' || $event == 'ASK') {
            DebMes("$event: " . json_encode($details), 'yadevices');
        }

        if ($event == 'ASK') {
            $message = $details['message'];
            $message = preg_replace('/\?$/', '', $message);
            $qry = "ALLOW_ASK=1";
            if ($details['destination']) {
                $qry .= " AND yastations.TITLE LIKE '%" . DBSafe($details['destination']) . "%'";
            }
            $stations = SQLSelect("SELECT * FROM yastations WHERE " . $qry);
            foreach ($stations as $station) {
                callAPI('/api/module/yadevices', 'GET', array('station' => $station['ID'], 'command' => 'попроси навык дом мажордом задать вопрос "' . $message . '"'));
            }
        }

        if ($event == 'SAY' || $event == 'SAYTO') {
            $level = (int)$details['level'];
            $message = $details['message'];

            // TTS CLOUD
            $qry = "TTS=2 AND IOT_ID!=''";
            if (isset($details['destination'])) {
                $qry .= " AND yastations.TITLE LIKE '%" . DBSafe($details['destination']) . "%'";
            }
            $stations = SQLSelect("SELECT * FROM yastations WHERE " . $qry);
            foreach ($stations as $station) {
                $min_level = 0;
                if ($station['MIN_LEVEL_TEXT'] != '') {
                    $min_level = processTitle($station['MIN_LEVEL_TEXT']);
                } elseif ($station['MIN_LEVEL']) {
                    $min_level = $station['MIN_LEVEL'];
                }
                if ($level >= $min_level) {
                    //$this->sendCloudTTS($station['IOT_ID'],$message);
                    callAPI('/api/module/yadevices', 'GET', array('station' => $station['ID'], 'say' => $message));
                }
            }

            //TTS LOCAL
            $qry = "TTS=1";
            if (isset($details['destination'])) {
                $qry .= " AND yastations.TITLE LIKE '%" . DBSafe($details['destination']) . "%'";
            }
            $stations = SQLSelect("SELECT * FROM yastations WHERE ".$qry);
            foreach ($stations as $station) {
                $min_level = 0;
                if ($station['MIN_LEVEL_TEXT'] != '') {
                    $min_level = processTitle($station['MIN_LEVEL_TEXT']);
                } elseif ($station['MIN_LEVEL']) {
                    $min_level = $station['MIN_LEVEL'];
                }
                if ($level >= $min_level) {
                    //$this->sendCommandToStation($station['ID'], 'повтори за мной ' . $message);
                    callAPI('/api/module/yadevices', 'GET', array('station' => $station['ID'], 'say' => $message));
                }
            }

        }
    }

    function sendValueToYandex($iot_id, $command_type, $value)
    {
        $command_type = explode('.', $command_type);

        $url = "https://iot.quasar.yandex.ru/m/user/devices/" . $iot_id . "/actions";
        if ($command_type[0] . '.' . $command_type[1] . '.' . $command_type[2] == 'devices.capabilities.on_off') {
            if ($value) {
                $value = true;
            } else {
                $value = false;
            }
            $data = array('actions' => array(
                array('state' => array('instance' => 'on', 'value' => $value),
                    'type' => $command_type[0] . '.' . $command_type[1] . '.' . $command_type[2]
                )));

            //debMes(json_encode($data));

            $result = $this->apiRequest($url, 'POST', $data);
            return $result;
        } else if ($command_type[0] . '.' . $command_type[1] . '.' . $command_type[2] == 'devices.capabilities.mode') {
            //Мод, например work_speed
            $mode = $command_type[3];

            $data = array('actions' => array(
                array('state' => array('instance' => $mode, 'value' => $value),
                    'type' => $command_type[0] . '.' . $command_type[1] . '.' . $command_type[2]
                )));

            //debMes(json_encode($data));

            $result = $this->apiRequest($url, 'POST', $data);
            return $result;
        } else if ($command_type[0] . '.' . $command_type[1] . '.' . $command_type[2] == 'devices.capabilities.toggle') {
            //Мод, например work_speed
            $toggle = $command_type[3];

            if ($value == 1) {
                $value = true;
            } else {
                $value = false;
            }

            $data = array('actions' => array(
                array('state' => array('instance' => $toggle, 'value' => $value),
                    'type' => $command_type[0] . '.' . $command_type[1] . '.' . $command_type[2]
                )));

            //debMes(json_encode($data));

            $result = $this->apiRequest($url, 'POST', $data);
            return $result;
        } else if ($command_type[0] . '.' . $command_type[1] . '.' . $command_type[2] == 'devices.capabilities.range') {
            //Мод, например work_speed

            $data = array('actions' => array(
                array('type' => $command_type[0] . '.' . $command_type[1] . '.' . $command_type[2], 'state' => array('instance' => $command_type[3], 'value' => (int)$value),)));


            //debMes(json_encode($data));
            $result = $this->apiRequest($url, 'POST', $data);
            //debMes(json_encode($result));
            return $result;
        } else if ($command_type[0] . '.' . $command_type[1] . '.' . $command_type[2] == 'devices.capabilities.color_setting') { //xor2016: для Я.Лампочки
            $mode = $command_type[3];

            $data = array('actions' => array(
                array('state' => array('instance' => $mode, 'value' => $value), 'type' => $command_type[0] . '.' . $command_type[1] . '.' . $command_type[2])));

            $result = $this->apiRequest($url, 'POST', $data);
            return $result;
        }

    }

    function propertySetHandle($object, $property, $value)
    {
        $properties = SQLSelect("SELECT yadevices_capabilities.*, yadevices.IOT_ID FROM yadevices_capabilities LEFT JOIN yadevices ON yadevices_capabilities.YADEVICE_ID=yadevices.ID WHERE yadevices_capabilities.LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND yadevices_capabilities.LINKED_PROPERTY LIKE '" . DBSafe($property) . "'");
        $total = count($properties);
        for ($i = 0; $i < $total; $i++) {
            if ($properties[$i]['READONLY'] == 0) {
                $sendCMD = $this->sendValueToYandex($properties[$i]['IOT_ID'], $properties[$i]['TITLE'], $value);
                $sendCMD = json_encode($sendCMD);
                $sendCMD = json_decode($sendCMD);

                if ($sendCMD->status == 'ok') {
                    //Обновим в теблице, чтобы не дергать лишний раз
                    $this->refreshDevicesData($properties[$i]['YADEVICE_ID']);

                    debMes('sendValueToYandex() -> Успешно! ' . $object . '.' . $property . ' = ' . $value, 'yadevices');
                } else {
                    if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                        registerError('YaDevice -> sendValueToYandex()', 'Неверная команда: ' . $object . '.' . $property . ' = ' . $value . '<br>Вызов из: ' . $object . '.' . $property . '<br>');
                    } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                        debMes('sendValueToYandex() -> Неверная команда: ' . $object . '.' . $property . ' = ' . $value, 'yadevices');
                    }
                }
            } else {
                if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
                    registerError('YaDevice -> sendValueToYandex()', 'Свойство ' . $properties[$i]['TITLE'] . ' доступно только для чтения!<br>Вызов из: ' . $object . '.' . $property . '<br>');
                } else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
                    debMes('sendValueToYandex() -> Свойство ' . $properties[$i]['TITLE'] . ' доступно только для чтения!', 'yadevices');
                }
            }
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
        subscribeToEvent($this->name, 'SAYTO');
        subscribeToEvent($this->name, 'ASK');

        $cookie_dir = dirname(YADEVICES_COOKIE_PATH);
        if (!is_dir($cookie_dir)) {
            umask(0);
            mkdir($cookie_dir, 0777);
        }

        $old_cookie_file = ROOT . 'cms/cached/yadevices/new_yandex_coockie.txt';
        if (file_exists($old_cookie_file)) {
            copy($old_cookie_file, YADEVICES_COOKIE_PATH);
            unlink($old_cookie_file);
            chmod(YADEVICES_COOKIE_PATH, 0666);
        }

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
        SQLExec('DROP TABLE IF EXISTS yadevices');

        //Отвяжемся от свойств
        $req = SQLSelect("SELECT * FROM yadevices_capabilities WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");

        foreach ($req as $prop) {
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
        }

        SQLExec('DROP TABLE IF EXISTS yadevices_capabilities');
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
 yastations: OWNER varchar(255) NOT NULL DEFAULT ''
 yastations: STATION_ID varchar(100) NOT NULL DEFAULT ''
 yastations: IP varchar(100) NOT NULL DEFAULT ''
 yastations: MIN_LEVEL_TEXT varchar(255) NOT NULL DEFAULT ''
 yastations: TTS int(3) NOT NULL DEFAULT '0'
 yastations: ALLOW_ASK int(3) NOT NULL DEFAULT '0'
 yastations: IOT_ID varchar(255) NOT NULL DEFAULT ''
 yastations: PLATFORM varchar(100) NOT NULL DEFAULT ''
 yastations: ICON_URL varchar(255) NOT NULL DEFAULT ''
 yastations: DEVICE_TOKEN varchar(255) NOT NULL DEFAULT ''
 yastations: TTS_SCENARIO varchar(255) NOT NULL DEFAULT ''
 yastations: TTS_EFFECT varchar(255) NOT NULL DEFAULT ''
 yastations: TTS_ANNOUNCE varchar(255) NOT NULL DEFAULT ''
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
 yadevices_capabilities: READONLY tinyint(1) NOT NULL DEFAULT 0
 yadevices_capabilities: ALLOWPARAMS varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: LINKED_OBJECT varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: LINKED_PROPERTY varchar(255) NOT NULL DEFAULT ''
 yadevices_capabilities: LINKED_METHOD varchar(255) NOT NULL DEFAULT ''
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
