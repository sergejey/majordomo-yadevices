<?php

/*
 * greetings to https://github.com/AlexxIT/YandexStation/ :)
 */

Define('YADEVICES_COOKIE_PATH', ROOT . "cms/yadevices/cookie.txt");
const GLAGOL_PORT = 1961;

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
		if (!empty($params['getonline'])) {
            $this->onlineStations();
        }
		
        //DebMes("API call: " . json_encode($params, JSON_UNESCAPED_UNICODE), 'yadevices');

        if (isset($params['station']) && (isset($params['command']) || isset($params['say']))) {
            if(!isset($params['command'])) $params['command'] = '';
            $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$params['station']);
			
			if(isset($params['data']) and isset($params['volume'])){
				$params['data'] = $params['data'].'^'.$params['volume'];
			}
            if ($station['TTS'] == 2 && $station['IOT_ID'] != '' || isset($params['cloud'])) {
				if(empty($this->config['AUTHORIZED'])) return;
				if($params['command'] == 'text'){
					$params['command'] = 'phrase_action';
				} else if($params['command'] == 'command' or $params['command'] == 'dialog'){
					$params['command'] = 'text_action';
				} else if ($params['command'] == 'setVolume' and isset($params['volume'])) {
					//У ТВСтанций от 1 до 100
					if($station['PLATFORM'] == "magritte" or $station['PLATFORM'] == "monet") $params['volume'] *= 10;
					$params['command'] = 'text_action';
					$params['data'] = 'громкость на ' . $params['volume'];
				} else if($params['command'] == 'volumeUp'){
					$params['command'] = 'text_action';
					$params['data'] = 'громче';
				} else if($params['command'] == 'volumeDown'){
					$params['command'] = 'text_action';
					$params['data'] = 'тише';
				}
				if (isset($params['say'])) { //для обратной совместимости
					$params['data'] = $params['say'];
					$params['command'] = 'phrase_action';
				} else if(!isset($params['data']) and !isset($params['volume'])){ //для обратной совместимости
					$params['data'] = $params['command'];
					$params['command'] = 'phrase_action';
                }
				return $this->sendCloudTTS($station['IOT_ID'], $params['data'], $params['command']);
            } else {
                if ($params['command'] == 'setVolume') {
					if(isset($params['volume'])){
						(float)$params['data'] = is_float($params['volume']) ? $params['volume'] : $params['volume'] * 0.1;
					} else (float)$params['data'] = $params['data'] * 0.1;
				} else if($params['command'] == 'volumeUp' or $params['command'] == 'volumeDown'){
					$params['data'] = $params['command'];
				} else if (isset($params['say'])) { //для обратной совместимости
					$params['data'] = $params['say'];
					$params['command'] = 'text';
				} else if (!isset($params['data'])) {
                    $params['data'] = "";
                } else if ($params['data'] == 'volumeUp' or $params['data'] == 'volumeDown') {
					$params['command'] = $params['data'];
                }
                return $this->sendCommandToStation($station, $params['command'], $params['data']);
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
		$out['OAUTH_TOKEN'] = $this->config['OAUTH_TOKEN'];

        if ($this->view_mode == 'update_settings') {
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
            $cookie = YADEVICES_COOKIE_PATH;
            @unlink($cookie);

            $this->saveConfig();
            $this->redirect("?");
        }
        if ($this->data_source == 'yastations' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_yastations') {
                $this->search_yastations($out);
                //$out['LOGIN_STATUS'] = (int)$this->checkLogin();
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
                $this->refreshDevices();
                $this->redirect("?tab=" . $this->tab . "&view_mode=" . $this->view_mode);
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

            $this->config['RELOAD_TIME'] = $cycleIsOnTime ?? 10;
            $this->saveConfig();

            setGlobal('cycle_yadevicesControl', 'restart');

            $this->redirect("?");
        }

        //Проверка существования куки
        if (file_exists(YADEVICES_COOKIE_PATH)) {
            $out['COOKIE_FILE'] = 1;
        } else {
            $out['COOKIE_FILE'] = 0;
        }
		
		if(empty($this->config['AUTHORIZED'])){
			$out['AUTHORIZED'] = 0;
		} else {
            $out['AUTHORIZED'] = 1;
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

	function receiveQuasar($data){
		if($data['service'] == 'alice-iot'){
			if($data['operation'] == 'update_states'){
				$message = json_decode($data['message'], true);
				$devices = $message['updated_devices'];
				foreach($devices as $device){
					//Получаем девайс из базы
					$rec_device = SQLSelectOne("SELECT * FROM yadevices WHERE IOT_ID = '" . dbSafe($device['id']) . "'");
					if(empty($rec_device['ID'])){
						//Если такого устройства нет, обновляем девайсы
						$this->refreshDevices();
						continue;
					}
					//добавим статус в массив для дальнейшей обработки
					if (isset($device['state']) && $device['state'] == 'online') {
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
					$device["properties"][] = $onlineArray;
					//Циклом пройдемся по всем умениям
					if (isset($device["capabilities"]) and is_array($device["capabilities"])) {
						foreach ($device["capabilities"] as $capabilitie) {
							if ($capabilitie['type'] == 'devices.capabilities.quasar.server_action') {
								$c_type = 'cloud.aswr_scenario';
							} else if ($capabilitie['type'] == 'devices.capabilities.on_off') {
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
							$req_skills = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE TITLE = '" . dbSafe($c_type) . "' AND YADEVICE_ID = '" . $rec_device['ID'] . "'");
							if(empty($req_skills)) $this->refreshDevices;
							//Основные умения, меняем значение
							$value = '?';
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
									} else if ($capabilitie['state']['instance']== 'scene') {
										$value = $capabilitie['state']['value']['id'];
									} else if ($capabilitie['state']['instance']== 'text_action') {
										$value = $capabilitie['state']['value'];
									} else {
										$value = $capabilitie['state']['value'];
									}
								} else {
									$value = $capabilitie['state']['value'];
								}  
							}
							//Ответы на сценарии обновляем всегда
							if ($c_type == 'cloud.aswr_scenario' or $value != $req_skills['VALUE']) {
								$params['NEW_VALUE'] = $value;
								$params['OLD_VALUE'] = $req_skills['VALUE'];
								$params['DEVICE_STATE'] = $currentStatus;
								$params['ALLOWPARAMS'] = $req_skills['ALLOWPARAMS'];
								$params['UPDATED'] = date('Y-m-d H:i:s');
								$params['MODULE'] = $this->name;
								$this->setProperty($req_skills, $value, $params, $c_type);
								$req_skills['VALUE'] = $value;
								$req_skills['UPDATED'] = date('Y-m-d H:i:s');
								SQLUpdate('yadevices_capabilities', $req_skills);
							}
						}
					}
					//Значения датчиков
					if (isset($data["properties"]) && is_array($data["properties"])) {
						foreach ($data["properties"] as $propertie) {
							$p_type = $propertie['type'] . '.' . $propertie['parameters']['instance'];
							//Получаем по каждом свойству по отдельности
							$req_prop = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE TITLE = '" . dbSafe($p_type) . "' AND YADEVICE_ID = '" . $rec_device['YADEVICE_ID'] . "'");
							//Основные датчики
							$value = $propertie['state']['value'] ?? '';
							if ($value != $req_prop['VALUE']) {
								$params['NEW_VALUE'] = $value;
								$params['OLD_VALUE'] = $req_prop['VALUE'];
								$params['DEVICE_STATE'] = $currentStatus;
								$params['ALLOWPARAMS'] = $req_prop['ALLOWPARAMS'];
								$params['UPDATED'] = date('Y-m-d H:i:s');
								$params['MODULE'] = $this->name;
								$this->setProperty($req_prop, $value, $params, $p_type);
								$req_prop['VALUE'] = $value;
								$req_prop['UPDATED'] = date('Y-m-d H:i:s');
								SQLUpdate('yadevices_capabilities', $req_prop);
							}
						}
					}
				}
			} else {
				$this->writeLog($data);
				$this->writeLog('Не update-states');
			}
		} else {
			$this->writeLog($data);
			$this->writeLog('Не alice-iot');
		}
	}
	
    function refreshDevices()
    {
		$this->getConfig();
		if($this->config['AUTHORIZED'] == 0) return false;
		$this->writeLog('Обновляем устройства.');
        $iot_ids = array();
        $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/v3/user/devices');
		if($data == 'Unauthorized') return false;
		if(!isset($data['status']) or $data['status'] != 'ok'){
			$this->writeLog('Ошибка получения списка устройсте', true);
			return false;
		}
		//Пройдемся по домам
		foreach($data['households'] as $house){
			//Пройдёмся по всем устройствам в доме
			foreach($house['all'] as $device){
				//Если это Станция
				if(preg_match('/^devices.types.smart_speaker/uis', $device['type'])) {
					$rec = SQLSelectOne("SELECT * FROM yastations WHERE IOT_ID='" . DBSafe($device['id']) . "'");
					$rec['OWNER'] = $this->config['API_USERNAME'];
					$rec['TITLE'] = $device['name'];
					$rec['PLATFORM'] = $device['quasar_info']['platform'];
					$rec['ICON_URL'] = $this->type2url($device['type']);
					$rec['STATION_ID'] = $device['quasar_info']['device_id'];
					$rec['IS_ONLINE'] = $device['state'] == 'online' ? 1 : 0;
					$rec['UPDATED'] = date('Y-m-d H:i:s');
					if(empty($rec['ID'])) {
						$rec['IOT_ID'] = $device['id'];
						$rec['ID'] = SQLInsert('yastations', $rec);
					} else {
						SQLUpdate('yastations', $rec);
					}
					//Создадим Станцию
					$device_rec = SQLSelectOne("SELECT * FROM yadevices WHERE IOT_ID='" . $device['id'] . "'");
					if(empty($device_rec['ID'])) {
						$device_rec['TITLE'] = $device['name'];
						$device_rec['DEVICE_TYPE'] = str_replace('smart_speaker.yandex.', '', $device['type']);
						$device_rec['HOUSE'] = $house['name'];
						$device_rec['ROOM'] = $device['room_name'];
						$device_rec['SKILL_ID'] = 'local';
						$device_rec['UPDATED'] = date('Y-m-d H:i:s');
						$device_rec['IOT_ID'] = $device['id'];
						$device_rec['ID'] = SQLInsert('yadevices', $device_rec);
					}
					//И умения и свойства Станции
					//Добавим локальные возможности
					$local = ['artist' => 'Исполнитель', 'track' => 'Название трека', 'cover' => 'Картинка альбома', 'text' => 'Алиса произнесёт текст', 'command' => 'Алиса выполнит команду', 'dialog' => 'Алиса произнесёт текст и будет ждать ответ', 'audio' => 'Ссылка на аудиофайл или поток', 'other' => 'Другие комады вида gif:URL', 'online' => 'Подключение к Станции установлено', "volume"=>'Громкость от 1 до 10'];
					foreach($local as $title => $desc){
						$c_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $device_rec['ID'] . " AND TITLE='" .'local.'. $title . "'");
						//Если нет такого умения
						if(empty($c_rec['ID'])) {
							$c_rec['YADEVICE_ID'] = $device_rec['ID'];
							$c_rec['TITLE'] = 'local.'.$title;
							$c_rec['ALLOWPARAMS'] = $desc;
							$c_rec['VALUE'] = '';
							if($title == 'artist' or $title == 'track' or $title == 'cover' or $title == 'aswr_scenario') $c_rec['READONLY'] = 1;
							else $c_rec['READONLY'] = 0;
							$c_rec['UPDATED'] = date('Y-m-d H:i:s');
							$c_rec['ID'] = SQLInsert('yadevices_capabilities', $c_rec);
						}
					}
					//Добавим облачные возможности
					$cloud = ['text' => 'Алиса произнесёт текст', 'command' => 'Алиса выполнит команду', 'aswr_scenario'=> 'Текст ответа на выполнение сценариев', 'online' => 'Станция онлайн'];
					foreach($cloud as $title => $desc){
						$c_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $device_rec['ID'] . " AND TITLE='" .'cloud.'. $title . "'");
						//Если нет такого умения
						if(empty($c_rec['ID'])) {
							$c_rec['YADEVICE_ID'] = $device_rec['ID'];
							$c_rec['TITLE'] = 'cloud.'.$title;
							$c_rec['ALLOWPARAMS'] = $desc;
							$c_rec['VALUE'] = '';
							if($title == 'artist' or $title == 'track' or $title == 'cover' or $title == 'aswr_scenario') $c_rec['READONLY'] = 1;
							else $c_rec['READONLY'] = 0;
							$c_rec['UPDATED'] = date('Y-m-d H:i:s');
							$c_rec['ID'] = SQLInsert('yadevices_capabilities', $c_rec);
						}
					}
					//Запоминаем, чтобы подтвердить актуальность устройства
					$iot_ids[] = $device['id'];
				//Если другое устройство
				} else {
					$device_rec = SQLSelectOne("SELECT * FROM yadevices WHERE IOT_ID='" . $device['id'] . "'");
					$device_rec['TITLE'] = $device['name'];
					$device_rec['DEVICE_TYPE'] = $device['type'];
					$device_rec['HOUSE'] = $house['name'];
					$device_rec['ROOM'] = $device['room_name'] ?? "";
					$device_rec['SKILL_ID'] = $device['skill_id'];
					$device_rec['UPDATED'] = date('Y-m-d H:i:s');
					if(empty($device_rec['ID'])) {
						$device_rec['IOT_ID'] = $device['id'];
						$device_rec['ID'] = SQLInsert('yadevices', $device_rec);
					} else {
						SQLUpdate('yadevices', $device_rec);
					}
				
					//Циклом пройдемся по всем умениям
					if(isset($device["capabilities"]) and is_array($device["capabilities"])) {
						foreach($device["capabilities"] as $capabilitie) {
							if($capabilitie['type'] == 'devices.capabilities.on_off') {
								$c_type = $capabilitie['type'];
							} else {
								if($capabilitie['state']['instance']) {
									$c_type = $capabilitie['type'].'.'.$capabilitie['state']['instance'];
								} else if($capabilitie['parameters']['instance']) {
									$c_type = $capabilitie['type'].'.'.$capabilitie['parameters']['instance'];
								} else {
									$c_type = $capabilitie['type'].'.unknown';
								}
							}
							
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
									} else if ($capabilitie['state']['instance']== 'scene') {
										$value = $capabilitie['state']['value']['id'];
									} else if ($capabilitie['state']['instance']== 'text_action') {
										$value = $capabilitie['state']['value'];
									} else {
										$value = $capabilitie['state']['value'];
									}
								} else {
									$value = $capabilitie['state']['value'];
								}  
							}
							if (is_null($value)) $value = 0;
				
							//Обработка для модов
							if(isset($capabilitie["parameters"]['modes']) and is_array($capabilitie["parameters"]['modes'])) {
								$allowparam = '';
								foreach($capabilitie["parameters"]['modes'] as $allowparams) {
									$allowparam .= $allowparams['value'].',';
								}
							} else if(isset($capabilitie["parameters"]['range']) and is_array($capabilitie["parameters"]['range'])) {
								$allowparam = 'От '.$capabilitie["parameters"]['range']['min'].' до '.$capabilitie["parameters"]['range']['max'].'. С шагом '.$capabilitie["parameters"]['range']['precision'].' ';
							} else if(isset($capabilitie["parameters"]['palette']) and is_array($capabilitie["parameters"]['palette'])) {
								$allowparam = '';
								foreach($capabilitie["parameters"]['palette'] as $allowparams) {
									$allowparam .= $allowparams['id'].', ';
								}
							} else {
								$allowparam = '';
							}
							
							//Запросим из БД текущие значения
							$c_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $device_rec['ID'] . " AND TITLE='" . $c_type . "'");
							
							if($allowparam) {
								$c_rec['ALLOWPARAMS'] = substr($allowparam,0,-1);
							}
							$c_rec['VALUE'] = $value;
							$c_rec['YADEVICE_ID'] = $device_rec['ID'];
							$c_rec['TITLE'] = $c_type;
							$c_rec['READONLY'] = 0;
							$c_rec['UPDATED'] = date('Y-m-d H:i:s');
							//Если нет такого умения
							if (empty($c_rec['ID'])) {
								$c_rec['ID'] = SQLInsert('yadevices_capabilities', $c_rec);
							} else {
								SQLUpdate('yadevices_capabilities', $c_rec);
							}
						}
					}
					//Значения датчиков
					if(isset($device["properties"]) and is_array($device["properties"])) {
						//Запихнем еще наш статус в массив
						$onlineArray = [
							'type' => 'devices.online',
							'state' => [
								'value' => $device['state'] == 'online' ? 1 : 0,
							],
						];
						$device["properties"][] = $onlineArray;
						foreach($device["properties"] as $propertie) {
							if($propertie['type'] == 'devices.online') {
								$p_type = $propertie['type'];
							} else {
								$p_type = $propertie['type'].'.'.$propertie['parameters']['instance'];
							}
							$value = $propertie['state']['value'] ?? '';
				
							//Запросим из БД текущие значения
							$p_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $device_rec['ID'] . " AND TITLE='" . $p_type . "'");
							$p_rec['VALUE'] = $value;
							$p_rec['UPDATED'] = date('Y-m-d H:i:s');
							$p_rec['YADEVICE_ID'] = $device_rec['ID'];
							$p_rec['TITLE'] = $p_type;
							$p_rec['READONLY'] = 1;
							//Если нет такого умения
							if (empty($p_rec['ID'])) {
								$p_rec['ID'] = SQLInsert('yadevices_capabilities', $p_rec);
							} else {
								SQLUpdate('yadevices_capabilities', $p_rec);
							}
						}
					}
					$iot_ids[] = $device['id'];
				}
			}
		}
        $all_devices = SQLSelect("SELECT ID, IOT_ID, TITLE FROM yadevices WHERE IOT_ID!=''");
		//dprint($all_devices);
        $total = count($all_devices);
        for ($i = 0; $i < $total; $i++) {
            if (!in_array($all_devices[$i]['IOT_ID'], $iot_ids)) {
				$this->writeLog('Устройство'.$all_devices[$i]['TITLE'].' удалено.');
                $this->delete_yadevice($all_devices[$i]['ID']);
            }
        }
		$this->addScenarios();
		return $data['updates_url'];
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
                $payload = array(
                    'name' => $nameEncode,
                    'icon' => 'home',
                    'triggers' => array(array(
                        'trigger' => array(
                            'type' => 'scenario.trigger.voice',
                            'value' => mb_substr($nameEncode, 4),
                        )
                    )),
                    'steps' => array(array(
                        'type' => 'scenarios.steps.actions.v2',
                        'parameters' => array(
                            'items' => array(array(
                                'id' => $station_id,
                                'type' => 'step.action.item.device',
                                'value' => array(
                                    'id' => $station_id,
                                    'item_type' => 'device',
                                    'capabilities' => array(array(
                                        'type' => 'devices.capabilities.quasar',
                                        'state' => array(
                                            'instance' => 'tts',
                                            'value' => array(
                                                'text' => 'Сценарий для МДМ. НЕ УДАЛЯТЬ!'
                                            )
                                        )
                                   ))
                                )
                            ))
                        )
                    ))
                );
                
                //dprint($payload, 0);
                $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/', 'POST', $payload);
                //dprint($result, 0);
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


    function runScenario($scenario_id)
    {
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . '/actions', 'POST', array());

        if ($result["status"] == 'error') {
            $this->writeLog('Ошибка запуска сценария. Ответ от Яндекс: ' . $result["message"], true);
        } else {
            $this->writeLog('Запрошено выполнение сценария: ' . $result["request_id"]);
        }

        return $result;
    }

    function delScenario($scenario_id)
    {
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id, 'DELETE');

        if ($result["status"] == 'error') {
            $this->writeLog('Ошибка удаления сценария. Ответ от Яндекс: ' . $result["message"], true);
        } else {
            $this->writeLog('Запрошено удаление сценария: ' . $result["request_id"]);
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
        $this->writeLog("Sending cloud '$action: $phrase' to " . $station_rec['TITLE']);


        //$action = 'phrase';
        //phrase_action - просто сказать и не ждать
        //text_action - выполнит команду

        if (!$station_rec['TTS_SCENARIO']) return;

        $nameEncode = $this->yandex_encode($iot_id);

        $payload = array(
            'name' => $nameEncode,
            'icon' => 'home',
            'triggers' => array(array(
                'trigger' => array(
                    'type' => 'scenario.trigger.voice',
                    'value' => $nameEncode,
                )
            )),
            'steps' => array(array(
                'type' => 'scenarios.steps.actions.v2',
                'parameters' => array(
                    'items' => array(array(
                         'id' => $iot_id,
                        'type' => 'step.action.item.device',
                        'value' => array(
                            'id' => $iot_id,
                            'item_type' => 'device',
                            'capabilities' => array(array(
                                'type' => 'devices.capabilities.quasar.server_action',
                                'state' => array(
                                    'instance' => $action,
                                    'value' => $phrase
                                )
                            ))
                        )
                    ))
                )
            ))
        );
        $scenario_id = $station_rec['TTS_SCENARIO'];
        $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/v4/user/scenarios/' . $scenario_id, 'PUT', $payload);

        if (is_array($result) && $result['status'] == 'ok') {
            $payload = array();
            $result = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios/' . $scenario_id . '/actions', 'POST', $payload);
            if (is_array($result) && $result['status'] == 'ok') {
                return true;
            } else {
                $this->writeLog("Fшибка вызова сценария для запуска CloudTTS. Ошибка: " . json_encode($result), true);
            }
        } else {
            $this->writeLog("Ошибка обновления сценария для запуска CloudTTS. Ошибка: " . json_encode($result), true);
        }
        return false;
    }

    function onlineStations()
    {
        $data = $this->apiRequest('https://quasar.yandex.ru/devices_online_stats');
        if (isset($data['items']) and is_array($data['items'])) {
            $items = $data['items'];
            foreach ($items as $item) {
				//Исключаем приложения на телефоне и ТВ
				if($item['platform'] == 'alice_app_ios' or $item['platform'] == 'iot_app_android' or $item['platform'] == 'yandex_tv_mt6681_cv') continue;
                $rec = SQLSelectOne("SELECT * FROM yastations WHERE STATION_ID='" . $item['id'] . "'");
                $rec['UPDATED'] = date('Y-m-d H:i:s');
                /*$rec['OWNER'] = $this->config['API_USERNAME'];
                $rec['TITLE'] = $item['name'];
                $rec['ICON_URL'] = $item['icon'];
                $rec['PLATFORM'] = $item['platform'];
				*/
				if($rec['IS_ONLINE'] != (int)$item['online']){
					$rec['IS_ONLINE'] = (int)$item['online'];
					SQLUpdate('yastations', $rec);
					$params['NEW_VALUE'] = (int)$item['online'];
					$property = SQLSelectOne("SELECT yadevices_capabilities.* FROM yadevices_capabilities LEFT JOIN yadevices ON yadevices_capabilities.YADEVICE_ID=yadevices.ID WHERE yadevices.IOT_ID LIKE '" . $rec['IOT_ID'] . "' AND yadevices_capabilities.TITLE LIKE 'cloud.online'");
					$this->setProperty($property, (int)$item['online'], $params);
					$property['VALUE'] =(int)$item['online'];
					$property['UPDATED'] = date('Y-m-d H:i:s');
					SQLUpdate('yadevices_capabilities', $property);
					//Отправляем плееру в ws, если станция пропала
					postToWebSocket('YADEVICES_ONLINE_'.$rec['ID'], ['online'=>$item['online']], 'PostEvent');
				}
            }
        }
    }

	 //Запись в привязанное свойство/метод
	function setProperty($device, $value, $params = [], $type = ''){
		if (isset($device['LINKED_OBJECT']) && isset($device['LINKED_PROPERTY'])) {
			setGlobal($device['LINKED_OBJECT'] . '.' . $device['LINKED_PROPERTY'], $value, array($this->name=>1), $this->name . '.' . $type);
		}
		if (isset($device['LINKED_OBJECT']) && isset($device['LINKED_METHOD'])) {
			$params['VALUE'] = $value;
			callMethodSafe($device['LINKED_OBJECT'] . '.' . $device['LINKED_METHOD'], $params);
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
        //$this->admin($out);
		//dprint($out, 0);
        //Функции плеера
		$station = gr('station');
		if (empty($station)) $station = $this->id ?? $this->station;
		$out['STATION_ID'] = $station;

		//$bgcolor = gr('bgcolor');
        $bgcolor = $this->bgcolor ?? '0,0,0';
        $out['BGCOLOR'] = $bgcolor;


        $textcolor = $this->textcolor ?? 'white';
        $out['TEXTCOLOR'] = $textcolor;

        $zoom = $this->zoom ?? '';
        $out['ZOOM_PLAYER'] = $zoom;

        $ajax = gr('ajax');

        $rec = SQLSelectOne("SELECT * FROM yastations WHERE ID = '" . dbSafe($station) . "'");
		if (!$rec) {
            http_response_code(400);
            die();
        }
		$out['TITLE'] = $rec['TITLE'];
		
        if ($ajax && $station && $out['TITLE']) {
            header("HTTP/1.0: 200 OK\n");
            header('Content-Type: text/html; charset=utf-8');
            $command = gr('control');
            if (!empty(strip_tags($command))) {
                $this->sendCommandToStation($rec, $command);
                echo json_encode(array('status' => 'ok'));
            } else {
				usleep(200000);
				$this->sendCommandToStation($rec, 'playerState');
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
		$device = SQLSelectOne("SELECT ID FROM yadevices WHERE IOT_ID='".$rec['IOT_ID']."'");
		$this->delete_yadevice($device['ID']);
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

    function sendGlagol($command, $data, $token, $ip)
    {
        $this->writeLog("Отправляем команду '$data' на устройство $ip");

        $clientConfig = new ClientConfig();
        $clientConfig->setHeaders([
            'X-Origin' => 'http://yandex.ru/',
        ]);
        $clientConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $msg = array(
            'conversationToken' => $token,
            'id' => uniqid(''),
            'sentTime' => time() * 1000,
        );
        $client = new WebSocketClient('wss://' . $ip . ':' . GLAGOL_PORT . '/', $clientConfig);
        $client->send($this->message($command, $data, $token));
        $result = $client->receive();

        $result_data = json_decode($result, true);

        if (is_array($result_data)) {
            $client->close();
            return $result_data;
        }
        return false;

    }

    function getStatus($token, $ip)
    {
        $clientConfig = new ClientConfig();
        $clientConfig->setHeaders([
            'X-Origin' => 'http://yandex.ru/',
        ]);
        $clientConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $msg = array(
            'conversationToken' => $token,
            'payload' => array(
                'command' => '',
            )
        );

        $client = new WebSocketClient('wss://' . $ip . ':' . GLAGOL_PORT . '/', $clientConfig);
        $client->send(json_encode($msg));
        $result = $client->receive();
        $client->close();
        $result_data = json_decode($result, true);
		//$this->writeLog($result);
        if (is_array($result_data)) {
            return $result_data;
        }
        return false;

    }


    function sendCommandToStationCloud($id, $command, $data = 0)
    {
        if (!$command) return false;
        $station = SQLSelectOne("SELECT * FROM yastations WHERE ID=" . (int)$id);
        $this->sendCloudTTS($station['IOT_ID'], $command, $data);
    }

    function sendCommandToStation($station, $command, $data = '')
    {
        if (!$command) return false;

        if (!$station['ID'] || !$station['IP']) return false;

        /*$token = $this->getDeviceToken($station['STATION_ID'], $station['PLATFORM']);

        if (!$token) return false;
        $station['DEVICE_TOKEN'] = $token;
        SQLUpdate('yastations', $station);
*/

        if (isset($station['DEVICE_TOKEN']) and !empty($command)) {
			if($data != '') $data = '^' . $data;
			addToOperationsQueue('yadevices', $station['IOT_ID'], $command . $data);
			return true;
			$result = $this->sendGlagol($command, $data, $station['DEVICE_TOKEN'], $station['IP']);
            if (is_array($result)) {
                return true;
            }
        } else {
            $this->writeLog('sendCommandToStation() -> Перед тем, как отправлять команды на станцию - сформируйте токен доступа!', true);
        }
    }

    function processSubscription($event, $details = '')
    {
        $this->getConfig();

        if ($event == 'SAY' || $event == 'ASK') {
            $this->writeLog("$event: " . json_encode($details, JSON_UNESCAPED_UNICODE));
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
                callAPI('/api/module/yadevices', 'GET', array('station' => $station['ID'], 'command' => 'command', 'data' => 'попроси навык дом мажордом задать вопрос "' . $message . '"'));
            }
        }

        if ($event == 'SAY' || $event == 'SAYTO') {
            $level = (int)$details['level'];
            $message = $details['message'];

            // TTS LOCAL & CLOUD
            $qry = "TTS=1 OR TTS=2 AND IOT_ID!=''";
            if (isset($details['destination'])) {
                $qry .= " AND yastations.TITLE LIKE '%" . DBSafe($details['destination']) . "%'";
            }
            $stations = SQLSelect("SELECT * FROM yastations WHERE " . $qry);
            foreach ($stations as $station) {
                $min_level = 0;
                if ($station['MIN_LEVEL_TEXT'] != '') {
                    $min_level = processTitle($station['MIN_LEVEL_TEXT']);
                }
                if ($level >= $min_level) {
                    //$this->sendCloudTTS($station['IOT_ID'],$message);
					$this->sendCommandToStation($station, 'text', $message);
                    //callAPI('/api/module/yadevices', 'GET', array('station' => $station['ID'], 'command' => 'text', 'data' => $message));
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

            //print_r(json_encode($data));

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
				//если имя начинается с local
				if(stripos($properties[$i]['TITLE'], 'local') !== false){
					    // Добавление в очередь, которая обрабатывается в цикле
						$command = str_replace('local.', '', $properties[$i]['TITLE']);
						if($command == 'other'){
							$command = trim(stristr($value, ':', true));
							$value = trim(stristr($value, ':'), ' \n\r\t\v\x00\:');
						} else if($command == 'volume'){
							if($value == 'volumeUp' or $value == 'volumeDown') $command = $value;
							else{
								$command = 'setVolume';
								$value *= 0.1;
							}
						}
						addToOperationsQueue('yadevices', $properties[$i]['IOT_ID'], $command . '^' . $value);
				} else if(stripos($properties[$i]['TITLE'], 'cloud') !== false){
					    // Отправляем в облако
						$command = str_replace('cloud.', '', $properties[$i]['TITLE']);
						if($command == 'command') $command = 'text_action';
						else if($command == 'text') $command = 'phrase_action';
						$this->sendCloudTTS($properties[$i]['IOT_ID'], $value, $command);
				} else {
					$sendCMD = $this->sendValueToYandex($properties[$i]['IOT_ID'], $properties[$i]['TITLE'], $value);
					$sendCMD = json_encode($sendCMD);
	
					if ($sendCMD->status == 'ok') {
						$this->writeLog('sendValueToYandex() -> Успешно! ' . $object . '.' . $property . ' = ' . $value);
					} else {
						$this->writeLog('sendValueToYandex() -> Неверная команда: ' . $object . '.' . $property);
					}					
				}
            } else {
                 $this->writeLog('sendValueToYandex() -> Свойство ' . $properties[$i]['TITLE'] . ' доступно только для чтения!', true);
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
		//Получим список существующих столбцов
		$query = mysqli_fetch_all(SQLExec("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'yastations'"), MYSQLI_NUM);
		$rename = 0;
		//Пройдёмся по именам циклом
		foreach($query as $name) {
			if($name[0] == 'TTS_EFFECT') $rename = 1;
		}
		//Переименовываем, если необходимо
		if($rename){
			SQLExec("ALTER TABLE `yastations` CHANGE COLUMN `TTS_EFFECT` `ARTIST` VARCHAR(255) NOT NULL DEFAULT ''");
			SQLExec("ALTER TABLE `yastations` CHANGE COLUMN `TTS_ANNOUNCE` `TRACK` VARCHAR(255) NOT NULL DEFAULT ''");
			SQLExec("ALTER TABLE `yastations` CHANGE COLUMN `SCREEN_CAPABLE` `COVER` VARCHAR(255) NOT NULL DEFAULT ''");
			SQLExec("ALTER TABLE `yastations` CHANGE COLUMN `SCREEN_PRESENT` `PLAYING` INT(3) NOT NULL DEFAULT '0'");;
		}
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
 yastations: ARTIST varchar(255) NOT NULL DEFAULT ''
 yastations: TRACK varchar(255) NOT NULL DEFAULT ''
 yastations: COVER varchar(255) NOT NULL DEFAULT ''
 yastations: PLAYING int(3) NOT NULL DEFAULT '0'
 yastations: VOLUME int(3) NOT NULL DEFAULT '0'
 yastations: IS_ONLINE int(3) NOT NULL DEFAULT '0'
 yastations: UPDATED datetime

 yadevices: ID int(10) unsigned NOT NULL auto_increment
 yadevices: TITLE varchar(255) NOT NULL DEFAULT ''
 yadevices: IOT_ID varchar(255) NOT NULL DEFAULT ''
 yadevices: DEVICE_TYPE varchar(100) NOT NULL DEFAULT ''
 yadevices: HOUSE varchar(100) NOT NULL DEFAULT ''
 yadevices: ROOM varchar(100) NOT NULL DEFAULT ''
 yadevices: SKILL_ID varchar(100) NOT NULL DEFAULT ''
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
	
///////////////////////////////////////////////Утилиты////////////////////////////////////////////////////////
    function apiRequest($url, $method = 'GET', $params = 0, $repeating = 0)
    {
        $debug = 0;

        if ($method != 'GET' && !isset($this->csrf_token)) {
            $token = $this->getToken();
        }

        $YaCurl = curl_init();
        curl_setopt($YaCurl, CURLOPT_URL, $url);
		curl_setopt($YaCurl, CURLOPT_TIMEOUT, 5);
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
            }
            if (is_array($params)) {
                curl_setopt($YaCurl, CURLOPT_POSTFIELDS, json_encode($params)); 
			}
        }
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLINFO_HEADER_OUT, true);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($YaCurl);
        $info = curl_getinfo($YaCurl);
        curl_close($YaCurl);
		if($result == 'Unauthorized'){
			$this->writeLog('Ошибка авторизации! Облачные функции недоступны!', true);
			$this->getConfig();
			$this->config['AUTHORIZED'] = 0;
			$this->saveConfig();
			return 'Unauthorized';
		}
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


	function curl($url, $cookie = '', $headers = '', $post = '', $options = ''){
		$YaCurl = curl_init();
		curl_setopt($YaCurl, CURLOPT_URL, $url);
		curl_setopt($YaCurl, CURLOPT_TIMEOUT, 3);
		if($headers != '') curl_setopt($YaCurl, CURLOPT_HTTPHEADER, $headers);
		if($post != ''){
			curl_setopt($YaCurl, CURLOPT_POST, true);
			curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $post);
		} else {
			curl_setopt($YaCurl, CURLOPT_POST, false);
		}
		curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
		if($cookie != '') curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
		
		//Подключим дополнительные опции
		if(is_array($options)){
			foreach($options as $option => $value){
				curl_setopt($YaCurl, $option, $value);
			}
		}		
		$result = curl_exec($YaCurl);
		if(curl_error($YaCurl)) {
			$result = '{"error:"'.curl_error($YaCurl).'"}';
		}
        curl_close($YaCurl);
		return $result;
	} 

	// V.A.S.t
	function message($command, $data, $token, $id = ''){
		//Если есть дополнительные параметры в комаде, через запятую
		//Для gif можно отправлять endless - для бесконечного воспроизведения или till_end_of_speech - анимация будет проигрываться, пока Алиса говорит (сперва запускаем разговор, потом анимацию)(Не работает)
		$param = '';
		if($id == '') $id = uniqid('');
		if(strpos($command, ',')){
			$arg = explode(',', $command);
			$command = $arg[0];
			$param = ',"'. $arg[1] . '":true'; 
		}
		$msg = array(
			'conversationToken' => $token,
			'id' => $id,
			'sentTime' => time() * 1000,
		);
		switch($command){
			case 'setVolume':
				$msg['payload'] = ["command" => "setVolume", "volume" => (float)$data];
				break;
			case 'volumeUp':
				$msg['payload'] = $this->external_command('sound_louder');
				break;
			case 'volumeDown':
				$msg['payload'] = $this->external_command('sound_quiter');
				break;
			case 'text':
				$msg['payload'] = $this->update_form('personal_assistant.scenarios.quasar.iot.repeat_phrase', ['phrase_to_repeat' => $data]);
				break;
			case 'command':
				$msg['payload'] = ["command" => "sendText", "text" => $data];
				break;
			case 'dialog':
				$msg['payload'] = $this->update_form('personal_assistant.scenarios.repeat_after_me', ['request' => $this->fix_dialog_text($data)]);
				break;
			case 'gif':
				$msg['payload'] = $this->external_command('draw_led_screen', '{"animation_sequence":[{"frontal_led_image":"'.$data.'"'.$param.'}]}');
				break;
			case 'rewind':
				$msg['payload'] = ["command" => "rewind", "position" => (int)$data];
				break;
			case 'ping':
			case 'softwareVersion':
			case 'play':
			case 'stop':
			case 'prev':
			case 'next':
				$msg['payload'] = ["command" => $command];
				break;
			case 'repeat': //All, One, None
				$msg['payload'] = ["command" => "repeat", "mode" => $data];
				break;
			case 'shuffle': //bool
				$msg['payload'] = ["command" => "shuffle", "enable" => $data];
				break;
			case 'turnOn':
				$msg['payload'] = $this->update_form('personal_assistant.scenarios.player_continue');
				break;
			case 'turnOff':
				$msg['payload'] = $this->update_form('personal_assistant.scenarios.quasar.go_home');
				break;
			case 'playerState':
				$msg['payload'] = ["command" => "ping"];
				break;
			case 'audio':
				$url = $this->extractMediaId($data);
				if($url != false){
					if($url['type'] == 'music_item'){
						$msg['payload'] = ["command" => "playMusic", "type" => $url['type_item'], 'id' => $url['id']];
					} else if($url['type'] == 'music_playlist'){
						$data = json_decode($this->curl("https://api.music.yandex.net/users/{$url['user']}/playlists/{$url['playlist_id']}"), true);
						if(isset($data['result']['owner']['uid'])) $user_id = $data['result']['owner']['uid'];
						else return false;
						$msg['payload'] = ["command" => "playMusic", "type" => "playlist", 'id' => $user_id . ":" . $url['playlist_id']];
					} else if($url['type'] == 'bookmate'){
						$data = json_decode($this->curl("https://api-gateway-rest.bookmate.yandex.net/audiobook/album", '', array(
		'Content-Type: application/json; charset=UTF-8'), json_encode(["audiobook_uuid" => $url['id']])), true);
						if(isset($data['album_id'])) $album_id = $data['album_id'];
						else return false;
						$msg['payload'] = ["command" => "playMusic", "type" => "album", 'id' => $album_id];
					}
				} else {
					$msg['payload'] = $this->external_command('radio_play', '{"streamUrl":"'.$data.'"'.$param.'}');
				}
				break;
			case 'video':
				$url = $this->extractMediaId($data);
				if($url != false){
					if($url['type'] == 'youtube' or $url['type'] == 'kinopoisk' or $url['type'] == 'strm' or $url['type'] == 'yavideo'){
						$msg['payload'] = $this->play_video_by_descriptor($url['type'], $url['id']);
					} else if($url['type'] == 'vk'){
						$msg['payload'] = $this->play_video_by_descriptor('yavideo', 'https://vk.com/' . $url['id']);
					} else if($url['type'] == 'kinopoisk_id'){
						$data = json_decode($this->curl("https://ott-widget.kinopoisk.ru/ott/api/kp-film-status/?kpFilmId=".$url['id']), true);
						if(isset($data['uuid'])) $uuid = $data['uuid'];
						else return false;
						$msg['payload'] = $this->play_video_by_descriptor('kinopoisk', $url['id']);
					}
				}
				break;
			 default:
				$msg['payload'] = $this->update_form('personal_assistant.scenarios.quasar.iot.repeat_phrase', ['phrase_to_repeat' => $command]);
		}
		//print_r($msg);
		//print json_encode($msg, JSON_NUMERIC_CHECK );
		return json_encode($msg, JSON_NUMERIC_CHECK);
	}
	
	function extractMediaId(string $url)
	{
		$patterns = [
			'youtube' => '/https:\/\/(?:youtu\.be\/|www\.youtube\.com\/.+?v=)([0-9A-Za-z_-]{11})/',
			'kinopoisk' => '/https:\/\/hd\.kinopoisk\.ru\/.*([0-9a-z]{32})/',
			'strm' => '/https:\/\/yandex\.ru\/efir\?.*stream_id=([^&]+)/',
			'music_playlist' => '/https:\/\/music\.yandex\.[a-z]+\/users\/(.+?)\/playlists\/(\d+)/',
			'music_item' => '/https:\/\/music\.yandex\.[a-z]+\/.*(artist|track|album)\/(\d+)/',
			'kinopoisk_id' => '/https?:\/\/www\.kinopoisk\.ru\/film\/(\d+)\//',
			'yavideo' => '/(https?:\/\/ok\.ru\/video\/\d+|https?:\/\/vk\.com\/video-?[0-9_]+|https?:\/\/vkvideo\.ru\/video-?[0-9_]+)/',
			'vk' => '/https:\/\/vk\.com\/.*(video-?[0-9_]+)/',
			'bookmate' => '/https:\/\/books\.yandex\.ru\/audiobooks\/(\w+)/'
		];
	
		foreach ($patterns as $type => $pattern) {
			if (preg_match($pattern, $url, $matches)) {
				$result = [
					'type' => $type,
					'id' => $matches[count($matches) - 1]
				];
	
				// Для плейлистов дополнительно возвращаем user_id
				if ($type === 'music_playlist') {
					$result['user'] = $matches[1];
					$result['playlist_id'] = $matches[2];
				}
	
				// Для музыкальных треков дополнительно возвращаем тип
				if ($type === 'music_item') {
					$result['type_item'] = $matches[1];
					$result['id'] = $matches[2];
				}
	
				return $result;
			}
		}
	
		return false;
	}

	function play_video_by_descriptor($provider, $id)
	{
		return ['command' => 'serverAction',
				'serverActionEventPayload' => [
					'type' => 'server_action',
					'name' => 'bass_action',
					'payload' => [
						'data' => [
							'video_descriptor' => [
								'provider_item_id' => $id,
								'provider_name' => $provider
							]
						],
						'name' => 'quasar.play_video_by_descriptor',
					]
				]
			];
	}
	
	function update_form(string $name, array $kwargs = []){
		$response = [
			"command" => "serverAction",
			"serverActionEventPayload" => [
				"type" => "server_action",
				"name" => "update_form",
				"payload" => [
					"form_update" => [
						"name" => $name,
						"slots" => array_map(function($key, $value) {
							return [
								"type" => "string",
								"name" => $key,
								"value" => $value
							];
						}, array_keys($kwargs), array_values($kwargs))
					],
					"resubmit" => true
				]
			]
		];
		
		return $response;
	}

/**
 * Функция для исправления текста диалога
 * 
 * Известные проблемные слова: запа, таблетк, трусы
 * 
 * @param string $text Исходный текст для обработки
 * @return string Текст с преобразованными словами в верхний регистр
 */
function fix_dialog_text(string $text): string 
{
    // Используем правильное регулярное выражение для кириллицы
    return preg_replace_callback(
        '/[а-яё]+/iu', // i - регистронезависимый поиск, u - поддержка юникода
        function($matches) {
            // Преобразуем найденное слово в верхний регистр
            return mb_strtoupper($matches[0], 'UTF-8');
        },
        $text
    );
}

function external_command(string $name, $payload = null): array {
    $data = [1 => $name];
    
    if ($payload !== null) {
        if (is_array($payload)) {
            $payload = json_encode($payload);
        }
        $data[2] = $payload;
    }
    
    require_once 'utils/protobuf.php';
    $protobuf = new Protobuf();
    
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $protobuf->setString($key, $value);
        } else {
            $protobuf->setInt32($key, $value);
        }
    }
    
    $encoded_data = base64_encode($protobuf->serialize());
    
    return [
        "command" => "externalCommandBypass",
        "data" => $encoded_data
    ];
}

function writeLog($message, $is_error = false){
	$this->getConfig();
	if ($is_error && $this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 1) {
		$trace = debug_backtrace();
		$caller = $trace[1];
		registerError("YaDevice -> {$caller['function']}", $message);
	} else if ($this->config['ERRORMONITOR'] == 1 && $this->config['ERRORMONITORTYPE'] == 2) {
		DebMes($message, 'yadevices');
	}
}

function parseUserName(){
	$this->getConfig();
	$cookies = $this->extractCookies(file_get_contents(YADEVICES_COOKIE_PATH));
	foreach($cookies as $cookie){
		if($cookie['name'] == 'yandex_login'){
			$this->config['API_USERNAME'] = $cookie['value'];
			break;
		}
	}
	//Так как данная функция вызывается после успешной авторизации, запишем, что авторизация пройдена и перезапустим цикл
	$this->config['AUTHORIZED'] = 1;
	setGlobal('cycle_yadevicesControl', 'restart');
	$this->saveConfig();
}

function extractCookies($string) {
    $cookies = array();
    
    $lines = explode("\n", $string);

    // iterate over lines
    foreach ($lines as $line) {

        // we only care for valid cookie def lines
        if (isset($line[0]) && substr_count($line, "\t") == 6) {

            // get tokens in an array
            $tokens = explode("\t", $line);

            // trim the tokens
            $tokens = array_map('trim', $tokens);

            $cookie = array();

            // Extract the data
            $cookie['domain'] = $tokens[0];
            $cookie['flag'] = $tokens[1];
            $cookie['path'] = $tokens[2];
            $cookie['secure'] = $tokens[3];

            // Convert date to a readable format
            $cookie['expiration'] = date('Y-m-d h:i:s', $tokens[4]);

            $cookie['name'] = $tokens[5];
            $cookie['value'] = $tokens[6];

            // Record the cookie.
            $cookies[] = $cookie;
        }
    }
    
    return $cookies;
}

function type2url($type){
	include "utils/devices_url.php";
	$type_arr = explode('.', $type);
	$station = end($type_arr);
	foreach($devices_URL as $key=>$url){
		if(strpos($key, $station . '_on') !== false){
			return $url;
		}
	}
	return $devices_URL['devices.types.image_icon'];
}


//////////////////////////////Авторизация и токены//////////////////////////////////////////

    function getCSRFToken($cookie_file = YADEVICES_COOKIE_PATH) {
		$result = $this->curl('https://passport.yandex.ru/am?app_platform=android', $cookie_file, '', '', [CURLOPT_HEADER=>true,CURLOPT_VERBOSE=>false,CURLOPT_FOLLOWLOCATION=>true]);
		$data = json_decode($result, true);
		if(isset($data['error'])){
			$this->writeLog("Ошибка подключении для получения CSRF токена: " . $data['error']);
			return false;
		}
        if (preg_match('/"csrf_token" value="(.+?)"/', $result, $m)) {
            $token = $m[1];
            return $token;
        } else {
            $this->writeLog("Failed to get CSRF token:\n" . $result);
            return false;
        }
    }

    function getToken($url = 'https://yandex.ru/quasar/iot')
    {
        //Получение токенов для отправки запросов в Яндекс
		$result = $this->curl($url, YADEVICES_COOKIE_PATH, '', '', [CURLOPT_HEADER=>true,CURLOPT_VERBOSE=>false,CURLOPT_ENCODING=>'gzip',CURLOPT_FOLLOWLOCATION=>true,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
		$data = json_decode($result, true);
		if(isset($data['error'])){
			$this->writeLog("Ошибка подключении для получения csrfToken2 токена: " . $data['error']);
			return false;
		}
        if (preg_match('/"csrfToken2":"(.+?)"/', $result, $m)) {
            $token = $m[1];
            $this->csrf_token = $token;
            return $token;
        } else {
            $this->writeLog("Ошибка получения csrfToken2 токена:\n" . $result);
            return false;
        }
    }

    function getOAuthToken($force = false)
    {
        if ($force) $oauth_token = '';
        else $oauth_token = $this->config['OAUTH_TOKEN'];

        if ($oauth_token != '') return $oauth_token;
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
            if (!preg_match('/^(.*?)\.yandex\.ru/',$line)) continue;
            $values = explode("\t",$line);
            $cookie_title = $values[5];
            $cookid_value = $values[6];
            if ($cookie_title == 'yaexpflags') continue;
            $new_cookies[]=$cookie_title.'='.$cookid_value;
        }
        $cookies_line = implode("; ",$new_cookies);
        $headers = array(
            'Ya-Client-Host: passport.yandex.ru',
            'Ya-Client-Cookie: ' . $cookies_line
        );
		
		$result = $this->curl('https://mobileproxy.passport.yandex.net/1/bundle/oauth/token_by_sessionid', YADEVICES_COOKIE_PATH, $headers, $postvars);
        $data = json_decode($result, true);
		if(isset($data['error'])){
			$this->writeLog("Ошибка подключении для получения локального токена: " . $data['error']);
			return false;
		}
        if (!$data['access_token']) {
                $this->writeLog("Failed to get access token:\n" . $result);
            return false;
        }
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
		
		$result = $this->curl('https://oauth.mobile.yandex.net/1/token', YADEVICES_COOKIE_PATH, '', $postvars);
        $data = json_decode($result,true);
		if(isset($data['error'])){
			$this->writeLog("Ошибка при подключении для получения x-token токена: " . $data['error']);
			return false;
		}
        if (!$data['access_token']) {
            $this->writeLog("Failed to get x-token token:\n" . $result);
            return false;
        }
        $oauth_token = $data['access_token'];
        $this->config['OAUTH_TOKEN'] = $oauth_token;
        $this->saveConfig();
        return $oauth_token;
    }
	
	function getDeviceTokenByHand($id)
    {
        $req = SQLSelectOne("SELECT STATION_ID, PLATFORM FROM yastations WHERE ID='" . dbSafe($id) . "'");
        $this->getDeviceToken($req['STATION_ID'], $req['PLATFORM']);
        $this->redirect("?id=" . $id . "&view_mode=edit_yastations");
    }

    function getDeviceToken($device_id, $platform, $force = false)
    {
        $oauth_token = $this->getOAuthToken();
		//print $oauth_token.PHP_EOL;
        if (!$oauth_token) return false;
        $url = "https://quasar.yandex.net/glagol/token?device_id=" . $device_id . "&platform=" . $platform;

		$header = array('Content-type: application/json',
						'Authorization: Oauth ' . $oauth_token);
		
		$result = $this->curl($url, YADEVICES_COOKIE_PATH, $header);
		if(!$result){
			$this->writeLog("Ошибка подключения при получении локального токена.");
            return false;
		} else if (is_array($result)){
			$this->writeLog("Неожиданный ответ при получении локального токена: ".$result);
            return false;
		}

        $data = json_decode($result, true);
		if(isset($data['error'])){
			$this->writeLog("Ошибка подключении для получения локального токена: " . $data['error']);
			return false;
		}
        if ($data['status'] == 'ok' && isset($data['token'])) {
            //Запишем токен
            SQLExec("UPDATE yastations SET DEVICE_TOKEN = '" . dbSafe($data['token']) . "' WHERE STATION_ID = '" . dbSafe($device_id) . "'");
            return $data['token'];
        } else {
            $this->writeLog("Failed to get device local token:\n" . $result);
            return false;
        }
    }
	
	////////////////////////////////////////////////////////////////////////////////////////////////

// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgRGVjIDMxLCAyMDE5IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
