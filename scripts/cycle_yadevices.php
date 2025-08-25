<?php
chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

const RECONNECT_TIME = 60;

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
spl_autoload_register(function ($class_name) {
    $path = DIR_MODULES . 'yadevices/' . $class_name . '.php';
    $path = str_replace('\\', '/', $path);
    @include_once $path;
});

use WSSC\WebSocketClient;
use \WSSC\Components\ClientConfig;

$ctl = new control_modules();

include_once(DIR_MODULES . 'yadevices/yadevices.class.php');
$yadevices = new yadevices();

$latest_check_cycle = 0;
$latest_check = 0;
$sendPlayerState = false;
$volRefresh = '';

echo date("H:i:s") . " запуск " . basename(__FILE__) . PHP_EOL;

//Конфиг
$yadevices->getConfig();
if(!empty($yadevices->config['AUTHORIZED'])){
	//Создаем необходимые ключи для подключения к Quasar и добавляем его в массив Станций
	$quasar['TITLE'] = 'Quasar';
	$quasar['IP'] = 'Quasar';
	$quasar['DEVICE_TOKEN'] = 'Quasar';
	$quasar['IS_CONNECT'] = time();
	$stations[] = $quasar;
} else {
	echo date("H:i:s") . " Авторизация отсутствует! Подключение к облаку не производится." . PHP_EOL;
}

//Сделаем массив с ключамм в виде IOT_ID
$stations_temp = SQLSelect("SELECT * FROM yastations");
foreach($stations_temp as $station){
	$stations[$station['IOT_ID']] = $station;
	//Подключиться нужно сейчас
	$stations[$station['IOT_ID']]['IS_CONNECT'] = time();
	$stations[$station['IOT_ID']]['ANSWER'] = '';
}
unset($stations_temp);
$reloadTime = $yadevices->config['RELOAD_TIME'];

while(true) {
	$stations = connect($stations);
	$ar_read = [];
	$ar_write = [];
	$ar_ex = [];
	foreach($stations as $key => $station){
		if($station['IS_CONNECT'] == 0){
			//Если соединение не ресурс или последнее сообщение было больше 1.5 минуты назад, соединение потеряно
			if(is_resource($station['CONNECT']->getSocket()) and $station['LAST_MESSAGE'] > time()-90){
				$ar_read[] = $station['CONNECT']->getSocket();
			} else {
				$stations[$key]['IS_CONNECT'] = time();
				unset($stations[$key]['CONNECT']);
				echo date('H:i:s') . ' Соединение с '. $station['TITLE'] . ' прервано. Попытка соединения.' . PHP_EOL;
			}
		}
	}
	if(!empty($ar_read)){
		try{
			if (($num_changed_streams = stream_select($ar_read, $ar_write, $ar_ex, 0, 200000)) === false) {
				echo date('H:i:s') . ' Error stream_select()' . PHP_EOL;
			}
		} catch (Throwable $e) {
			var_dump($e->getMessage());
		} 
		//нечего читать, просто ждём
	} else {
		sleep(1);
	}
   if(!empty($num_changed_streams)){
		if (!empty($ar_read)) {
			foreach($ar_read as $socket){
				foreach($stations as $key => $station){
					if(isset($station['CONNECT']) and $socket == $station['CONNECT']->getSocket()){
						try{
							$response = $station['CONNECT']->receive();
						} catch(Exception $e){
							$stations[$key]['IS_CONNECT'] = time();
							unset($stations[$key]['CONNECT']);
							echo date('H:i:s') . ' Соединение с '. $station['TITLE'] . ' прервано.' . PHP_EOL;
							continue;
						}
						$stations[$key]['LAST_MESSAGE'] = time();
						if($station['TITLE'] == "Quasar"){
							$response = json_decode($response, true);
							if(!isset($response['message'])){
								print_r($response);
								$stations[$key]['IS_CONNECT'] = time();
								unset($stations[$key]['CONNECT']);
								echo date('H:i:s') . ' Соединение с '. $station['TITLE'] . ' прервано.' . PHP_EOL;
								continue;
							}
							$message = json_decode($response['message'], true);
							if($response['operation'] == 'update_states')
								$yadevices->receiveQuasar($response);
						} else {
							if($station['CONNECT']->getLastOpcode() == 'ping'){
								$station['CONNECT']->send($response, 'pong');
							} else {
								$response_arr = json_decode($response, true);
								//if($station['TITLE'] == "Яндекс Станция") print_r($response_arr);
								if(!isset($response_arr['state'])) {
									echo date('H:i:s') . ' Неожиданное сообщение от ' . $station['TITLE'] . ": " . $response . PHP_EOL;
									continue;
								}
								if(isset($response_arr['requestId'])){
									if(isset($station['ANSWER'][$response_arr['requestId']])) {
										if($response_arr['status'] != "SUCCESS"){
											echo date('H:i:s') . ' Ошибка выполнения команды '. $station['ANSWER'][$response_arr['requestId']]['command'].': '.$station['ANSWER'][$response_arr['requestId']]['value'].' - '.$response_arr['status'] . PHP_EOL;
											$yadevices->writeLog("Ошибка в ответ на отправленную команду: ". $station['ANSWER'][$response_arr['requestId']]['command'].': '.$station['ANSWER'][$response_arr['requestId']]['value'].' - '.$response_arr['status']);
										}
										unset($stations[$key]['ANSWER'][$response_arr['requestId']]);
									}
								}
								$state = $response_arr['state'];
								//Если прибавляли громкость, провераяем состояние Станции или убавляем по таймауту
								if(isset($station['TVOLUME'])){
									if((int)$station['TVOLUME']['start'] <= time()){
										if($state['aliceState']=='IDLE'){
											$volRefresh = ['DATANAME'=>$key,'DATAVALUE'=>'setVolume^'.$station['TVOLUME']['volume']];
											unset($stations[$key]['TVOLUME']);
										}
									}
								} else if($station['VOLUME'] != $state['volume'] * 10){
									$stations[$key]['VOLUME'] = $state['volume'] * 10;
									updateData($station, $state['volume'] * 10, 'VOLUME');
								}
								if($station['PLAYING'] != $state['playing']){
									$playing = $state['playing'] == true ? 1 : 0;
									$stations[$key]['PLAYING'] = $playing;
									SQLExec("UPDATE yastations SET PLAYING = '" . $playing . "' WHERE STATION_ID = '" . $station['STATION_ID'] . "'");
									//Отправляем в вебсокет
									postToWebSocket('YADEVICES_STATE_'.$station['ID'], ['playing'=>$state['playing']], 'PostEvent');
								}
								if($state['playing'] or $sendPlayerState){
									if(isset($state['playerState']) and !empty($state['playerState']['title'])){
										$playerState = $state['playerState'];
										if(!empty($playerState['subtitle']) and $station['ARTIST'] != $playerState['subtitle']){
											updateData($station, $playerState['subtitle'], 'ARTIST');
											$stations[$key]['ARTIST'] = $playerState['subtitle'];
										}
										if(!empty($playerState['title']) and $station['TRACK'] != $playerState['title']){
											updateData($station, $playerState['title'], 'TRACK');
											$stations[$key]['TRACK'] = $playerState['title'];
										}
										if(!empty($playerState['extra']['coverURI']) and $station['COVER'] != $playerState['extra']['coverURI']){
											$cover = str_replace('%%', '', $playerState['extra']['coverURI']);
											updateData($station, $cover, 'COVER');
											$stations[$key]['COVER'] = $playerState['extra']['coverURI'];
										}
										//Отправляем в вебсокет
										$cover = $playerState['extra']['coverURI'] ?? '';
										postToWebSocket('YADEVICES_TRACKS_'.$station['ID'], ['on'=>true, 'title'=>$playerState['title'], 'subtitle'=>$playerState['subtitle'], 'cover'=>$cover, 'duration'=>$playerState['duration'], 'progress'=>(int)$playerState['progress'], 'volume'=>round($state['volume']*10, 1), 'playing'=>$state['playing']], 'PostEvent');
									} else {
										postToWebSocket('YADEVICES_TRACKS_'.$station['ID'], ['on'=>false, 'title'=>false, 'subtitle'=>false, 'cover'=>false, 'duration'=>0, 'progress'=>0, 'volume'=>round($state['volume']*10, 1), 'playing'=>$state['playing'], 'online'=>$station['ONLINE'] = 0], 'PostEvent');
									}
									if($sendPlayerState){
										postToWebSocket('YADEVICES_STATE_'.$station['ID'], ['playing'=>$state['playing']], 'PostEvent');
										$sendPlayerState = false;
									}
								}
							}
						}
					}
				}
			}
		}
	}
	//Получаем команды для отправки на Станции
	$operations = checkOperationsQueue('yadevices');
	if(is_array($volRefresh)){
		$operations[] = $volRefresh;
		$volRefresh = '';
	}
	for ($i=0; $i<count($operations); $i++) {
		$station_id = $operations[$i]["DATANAME"];
		if(!empty($station_id)){
			if(isset($stations[$station_id]['CONNECT'])){
				if(stripos($operations[$i]["DATAVALUE"], '^')){
					$data = explode('^', $operations[$i]["DATAVALUE"]);
					$command = $data[0];
					$value = $data[1];
					if(isset($data[2])){
						$stations[$station_id]['TVOLUME'] = ['start'=>time()+2, 'end'=>time() + 30, 'volume'=>$stations[$station_id]['VOLUME']*0.1];
						echo date("H:i:s")." Отправляем на ".$stations[$station_id]['TITLE']." setVolume". ": " . $data[2]*0.1.PHP_EOL;
						$stations[$station_id]['CONNECT']->send($yadevices->message('setVolume', $data[2]*0.1, $stations[$station_id]['DEVICE_TOKEN']));
					}
				} else {
					$command = $operations[$i]["DATAVALUE"];
					$value = '';
				}
				$id = uniqid('');
				$answer = [$id=>['command'=>$command,'value'=>$value]];
				$stations[$station_id]['ANSWER'] = $answer;
				if($command == 'playerState') $sendPlayerState = true;
				$message = $yadevices->message($command, $value, $stations[$station_id]['DEVICE_TOKEN'], $id);
				if($value != '') $value = ': '.$value;
				echo date("H:i:s")." Отправляем на ".$stations[$station_id]['TITLE']." " . $command . $value.PHP_EOL;
				if(!empty($message)) $stations[$station_id]['CONNECT']->send($message);
			}
			else echo date("H:i:s")." Станция ".$stations[$station_id]['TITLE']." не в сети. Сообщение не передано.".PHP_EOL;
		}
	}
	
	if ($latest_check_cycle + 15 < time()) {
       $latest_check_cycle = time();
       setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', $latest_check_cycle, 1);
    }
	
	if ((time()-$latest_check) > $reloadTime) {
		$latest_check = time();
		   callAPI('/api/module/yadevices', 'GET', array('getonline' => true));
	}
	if (file_exists('./reboot') || isset($_GET['onetime'])) {
		foreach($stations as $station){
			$station['CONNECT']->close();
		}
		exit;
	}
}

function connect($stations){
	global $yadevices;
	//Подключаемся к Станциям, у которых прописан локальный IP и получен токен
	foreach($stations as $key=>$station){
		if($station['IS_CONNECT'] != 0 and $station['IS_CONNECT'] <= time()){
			if(!empty($station['IP']) and !empty($station['DEVICE_TOKEN'])){
				if(!isset($station['CONNECTION_OFF'])){
					echo date('H:i:s') . ' Устанавливаем соединение с '. $station['TITLE'];
				}
				if($station['TITLE'] == 'Quasar'){
					$url = $yadevices->refreshDevices();
					if(!$url){
						if(!isset($station['CONNECTION_OFF'])){
							echo '.....URL не получен. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
							$stations[$key]['CONNECTION_OFF'] = 1;
						}
						$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
						continue;
					}
					$quazarConfig = new ClientConfig();
					$quazarConfig->setTimeout(1);
					try{
						$connect = new WebSocketClient($url, $quazarConfig);
					} catch(Exception $e) {
						if(!isset($station['CONNECTION_OFF'])){
							echo '.....Не успешно. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
							$stations[$key]['CONNECTION_OFF'] = 1;
						}
						$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
						continue;
					}
				} else {
					$glagolConfig = new ClientConfig();
					$glagolConfig->setContextOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
					$glagolConfig->setTimeout(1);
					try{
						$connect = new WebSocketClient('wss://'. $station['IP'].':'.GLAGOL_PORT, $glagolConfig);
					} catch(Exception $e) {
						unset($connect);
						unset($stations[$key]['CONNECT']);
					}
				}
				if(isset($connect)){
					if($station['TITLE'] != 'Quasar'){
						//Обновляем токен
						$token = $yadevices->getDeviceToken($station['STATION_ID'], $station['PLATFORM']);
						if(!$token){
							$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
							echo date('H:i:s') . '.....ошибка получения токена! Разрываем соединение. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
							$stations[$key]['CONNECTION_OFF'] = 1;
							$connect->close();
							continue;
						} else {
							$stations[$key]['DEVICE_TOKEN'] = $token;
							updateData($station, 1, 'online');
							$stations[$key]['ONLINE'] = 1;
							$connect->send($yadevices->message('softwareVersion', '', $station['DEVICE_TOKEN']));
						}
					}
					$stations[$key]['CONNECT'] = $connect;
					$stations[$key]['LAST_MESSAGE'] = time();
					$stations[$key]['IS_CONNECT'] = 0;
					if(!isset($station['CONNECTION_OFF'])){
						echo '.....Успешно!'.PHP_EOL;
					} else {
						echo date('H:i:s') . ' Cоединение с '. $station['TITLE'] . ' успешно!'. PHP_EOL;
						unset($stations[$key]['CONNECTION_OFF']);
					}
				} else {
					$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
					if($station['TITLE'] != 'Quasar'){
						updateData($station, 0, 'online');
						$stations[$key]['ONLINE'] = 0;
					}
					if(!isset($station['CONNECTION_OFF'])){
						echo '.....Не успешно. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
						$stations[$key]['CONNECTION_OFF'] = 1;
					} 
				}
			}
		}
	}
	return $stations;
}

function updateData($station, $value, $prop){
	global $yadevices;
	if($prop != 'online'){
		SQLExec("UPDATE yastations SET ".$prop." = '" . dbSafe($value) . "' WHERE STATION_ID = '" . $station['STATION_ID'] . "'");
		$params['OLD_VALUE'] = $station[$prop];
		$params['DEVICE_STATE'] = '1';
		$params['ALLOWPARAMS'] = $property['ALLOWPARAMS'] ?? '';
		$params['UPDATED'] = date('Y-m-d H:i:s');
		$params['MODULE'] = 'yadevices';
	}
	$params['NEW_VALUE'] = $value;
	$property = SQLSelectOne("SELECT yadevices_capabilities.* FROM yadevices_capabilities LEFT JOIN yadevices ON yadevices_capabilities.YADEVICE_ID=yadevices.ID WHERE yadevices.IOT_ID LIKE '" . $station['IOT_ID'] . "' AND yadevices_capabilities.TITLE LIKE 'local." .strtolower($prop). "'");
	$yadevices->setProperty($property, $value, $params);
	$property['VALUE'] = $value;
	$property['UPDATED'] = date('Y-m-d H:i:s');
	SQLUpdate('yadevices_capabilities', $property);
}