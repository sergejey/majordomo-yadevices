<?php
chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

const RECONNECT_TIME = 60;
// Сколько секунд ждём ответ станции на отправленную команду и сколько таких команд помним
const ANSWER_TTL = 120;
const ANSWER_MAX = 20;

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

/* ------------------------------------------------------------------
 * Диагностика работы цикла.
 *
 * Вывод через echo уходит в /dev/null, если в config.php не включён
 * LOG_CYCLES, поэтому все значимые события дублируются в DebMes:
 * он пишет всегда, в cms/debmes/<дата>/.
 *
 * Причина остановки записывается в cycle_yadevicesLastError — именно это
 * поле диспетчер cycle.php подставляет в сообщение «Цикл остановлен».
 * Без него сообщение содержит только код сигнала и ничего о причине.
 * ------------------------------------------------------------------ */
const CYCLE_NAME = 'cycle_yadevices';

function logEvent($message, $is_error = false)
{
	$line = date('H:i:s') . ' ' . $message;
	echo $line . PHP_EOL;                       // виден при LOG_CYCLES=1
	DebMes($message, $is_error ? 'yadevices_error' : 'yadevices');
}

function saveStopReason($reason)
{
	// Значение колонки ограничено 255 символами
	if (!function_exists('saveCycleToCache')) return;
	// saveCycleToCache() сверяет длину через strlen(), то есть в БАЙТАХ, и при
	// превышении 255 не обрезает значение, а удаляет запись. Кириллица занимает
	// два байта на символ, поэтому режем mb_strcut'ом по байтам, не разрывая символ.
	$reason = mb_strcut(preg_replace('/\s+/u', ' ', (string)$reason), 0, 240);
	@saveCycleToCache(CYCLE_NAME . 'LastError', $reason);
}

function saveCycleState($stations)
{
	if (!function_exists('saveCycleToCache')) return;
	$connected = 0;
	$pending = 0;
	if (is_array($stations)) {
		foreach ($stations as $st) {
			if (isset($st['CONNECT'])) $connected++;
			if (isset($st['ANSWER']) && is_array($st['ANSWER'])) $pending += count($st['ANSWER']);
		}
	}
	// Снимок пишется раз в минуту. После убийства процесса сигналом (его перехватить нельзя)
	// последний снимок показывает, сколько памяти цикл занимал перед смертью.
	@saveCycleToCache(CYCLE_NAME . 'State', mb_strcut(sprintf(
		'%s память %.1f МБ (пик %.1f МБ), станций %d, подключено %d, ждут ответа %d',
		date('H:i:s'),
		memory_get_usage(true) / 1048576,
		memory_get_peak_usage(true) / 1048576,
		is_array($stations) ? count($stations) : 0,
		$connected,
		$pending
	), 0, 240));
}

set_exception_handler(function (Throwable $e) {
	$msg = 'Необработанное исключение: ' . get_class($e) . ': ' . $e->getMessage()
		. ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
	saveStopReason($msg);
	logEvent($msg, true);
});

register_shutdown_function(function () {
	$err = error_get_last();
	if ($err !== null && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
		$msg = 'Фатальная ошибка: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
			. ' Память: ' . round(memory_get_usage(true) / 1048576, 1) . ' МБ';
		saveStopReason($msg);
		DebMes($msg, 'yadevices_error');
	}
});

$latest_check_cycle = 0;
$latest_state_saved = 0;
$latest_check = 0;
$sendPlayerState = false;
$volRefresh = '';
$num_changed_streams = 0;
// Массив должен существовать до первого обращения: без авторизации и без станций в БД он остаётся пустым
$stations = array();

// Причина прошлой остановки уже показана диспетчером, очищаем перед новым запуском
saveStopReason('');
logEvent('Запуск ' . basename(__FILE__) . ': модуль ' . YADEVICES_VERSION . ' от ' . YADEVICES_VERSION_DATE
	. ', PHP ' . PHP_VERSION . ', memory_limit ' . ini_get('memory_limit') . ', PID ' . getmypid());

//Конфиг
$yadevices->getConfig();
if(!empty($yadevices->config['AUTHORIZED'])){
	//Создаем необходимые ключи для подключения к Quasar и добавляем его в массив Станций
	$quasar['TITLE'] = 'Quasar';
	$quasar['IP'] = 'Quasar';
	$quasar['DEVICE_TOKEN'] = 'Quasar';
	$quasar['IS_CONNECT'] = time();
	$quasar['ANSWER'] = '';
	$stations[] = $quasar;
} else {
	logEvent('Авторизация отсутствует, подключение к облаку не производится.', true);
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
$reloadTime = (int)($yadevices->config['RELOAD_TIME'] ?? 10);
if ($reloadTime < 5) $reloadTime = 10;

while(true) {
	$stations = connect($stations);
	$ar_read = [];
	$ar_write = [];
	$ar_ex = [];
	foreach($stations as $key => $station){
		if($station['IS_CONNECT'] == 0){
			//Если соединение не ресурс или последнее сообщение было больше 1.5 минут назад, соединение потеряно
			if(isset($station['CONNECT']) and is_resource($station['CONNECT']->getSocket()) and ($station['LAST_MESSAGE'] ?? 0) > time()-90){
				$ar_read[] = $station['CONNECT']->getSocket();
			} else {
				$stations[$key]['IS_CONNECT'] = time();
				unset($stations[$key]['CONNECT']);
				logEvent('Соединение с ' . $station['TITLE'] . ' прервано (нет данных дольше 90 секунд). Попытка соединения.');
			}
		}
	}
	if(!empty($ar_read)){
		try{
			if (($num_changed_streams = stream_select($ar_read, $ar_write, $ar_ex, 0, 200000)) === false) {
				logEvent('Ошибка stream_select(), сокетов в наборе: ' . count($ar_read), true);
			}
		} catch (Throwable $e) {
			logEvent('Исключение в stream_select(): ' . $e->getMessage(), true);
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
						} catch(Throwable $e){
							$stations[$key]['IS_CONNECT'] = time();
							unset($stations[$key]['CONNECT']);
							logEvent('Соединение с ' . $station['TITLE'] . ' прервано при чтении: ' . $e->getMessage());
							continue;
						}
						$stations[$key]['LAST_MESSAGE'] = time();
						if($station['TITLE'] == "Quasar"){
							$response = json_decode($response, true);
							if(!isset($response['message'])){
								$stations[$key]['IS_CONNECT'] = time();
								unset($stations[$key]['CONNECT']);
								logEvent('Соединение с облаком прервано: ответ без поля message. Получено: '
									. mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 200), true);
								continue;
							}
							if(($response['operation'] ?? '') == 'update_states')
								$yadevices->receiveQuasar($response);
						} else {
							if($station['CONNECT']->getLastOpcode() == 'ping'){
								try{
									$station['CONNECT']->send($response, 'pong');
								} catch(Throwable $e){
									$stations[$key]['IS_CONNECT'] = time();
									unset($stations[$key]['CONNECT']);
									logEvent('Соединение с ' . $station['TITLE'] . ' прервано при ответе pong: ' . $e->getMessage());
									continue;
								}
							} else {
								$response_arr = json_decode($response, true);
								//if($station['TITLE'] == "Яндекс Станция") print_r($response_arr);
								if(!isset($response_arr['state'])) {
									logEvent('Неожиданное сообщение от ' . $station['TITLE'] . ': ' . mb_substr((string)$response, 0, 200));
									continue;
								}
								if(isset($response_arr['requestId'])){
									if(isset($station['ANSWER'][$response_arr['requestId']])) {
										if(($response_arr['status'] ?? '') != "SUCCESS"){
											logEvent('Станция ' . $station['TITLE'] . ' отклонила команду '
												. $station['ANSWER'][$response_arr['requestId']]['command'] . ': '
												. $station['ANSWER'][$response_arr['requestId']]['value'] . ' - ' . $response_arr['status'], true);
										}
										unset($stations[$key]['ANSWER'][$response_arr['requestId']]);
									}
								}
								$state = $response_arr['state'];
								if(!is_array($state)) continue;
								$stateVolume = (float)($state['volume'] ?? 0);
								$statePlaying = !empty($state['playing']);
								//Если прибавляли громкость, провераяем состояние Станции или убавляем по таймауту
								if(isset($station['TVOLUME'])){
									if((int)$station['TVOLUME']['start'] <= time()){
										if(($state['aliceState'] ?? '')=='IDLE'){
											$volRefresh = ['DATANAME'=>$key,'DATAVALUE'=>'setVolume^'.$station['TVOLUME']['volume']];
											unset($stations[$key]['TVOLUME']);
										}
									}
								} else if((float)($station['VOLUME'] ?? -1) != $stateVolume * 10){
									$stations[$key]['VOLUME'] = $stateVolume * 10;
									updateData($station, $stateVolume * 10, 'VOLUME');
								}
								$playing = $statePlaying ? 1 : 0;
								if((int)($station['PLAYING'] ?? -1) != $playing){
									$stations[$key]['PLAYING'] = $playing;
									SQLExec("UPDATE yastations SET PLAYING = " . $playing . " WHERE STATION_ID = '" . dbSafe($station['STATION_ID']) . "'");
									//Отправляем в вебсокет
									postToWebSocket('YADEVICES_STATE_'.$station['ID'], ['playing'=>$statePlaying], 'PostEvent');
								}
								if($statePlaying or $sendPlayerState){
									if(isset($state['playerState']) and is_array($state['playerState']) and !empty($state['playerState']['title'])){
										$playerState = $state['playerState'];
										if(!empty($playerState['subtitle']) and ($station['ARTIST'] ?? '') != $playerState['subtitle']){
											updateData($station, $playerState['subtitle'], 'ARTIST');
											$stations[$key]['ARTIST'] = $playerState['subtitle'];
										}
										if(!empty($playerState['title']) and ($station['TRACK'] ?? '') != $playerState['title']){
											updateData($station, $playerState['title'], 'TRACK');
											$stations[$key]['TRACK'] = $playerState['title'];
										}
										if(!empty($playerState['extra']['coverURI']) and ($station['COVER'] ?? '') != $playerState['extra']['coverURI']){
											$cover = str_replace('%%', '', $playerState['extra']['coverURI']);
											updateData($station, $cover, 'COVER');
											$stations[$key]['COVER'] = $playerState['extra']['coverURI'];
										}
										//Отправляем в вебсокет
										$cover = $playerState['extra']['coverURI'] ?? '';
										postToWebSocket('YADEVICES_TRACKS_'.$station['ID'], ['on'=>true, 'title'=>$playerState['title'], 'subtitle'=>$playerState['subtitle'] ?? '', 'cover'=>$cover, 'duration'=>$playerState['duration'] ?? 0, 'progress'=>(int)($playerState['progress'] ?? 0), 'volume'=>round($stateVolume*10, 1), 'playing'=>$statePlaying], 'PostEvent');
									} else {
										postToWebSocket('YADEVICES_TRACKS_'.$station['ID'], ['on'=>false, 'title'=>false, 'subtitle'=>false, 'cover'=>false, 'duration'=>0, 'progress'=>0, 'volume'=>round($stateVolume*10, 1), 'playing'=>$statePlaying, 'online'=>(int)($station['ONLINE'] ?? 0)], 'PostEvent');
									}
									if($sendPlayerState){
										postToWebSocket('YADEVICES_STATE_'.$station['ID'], ['playing'=>$statePlaying], 'PostEvent');
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
	if(!is_array($operations)) $operations = array();
	if(is_array($volRefresh)){
		$operations[] = $volRefresh;
		$volRefresh = '';
	}
	$operationsCount = count($operations);
	for ($i=0; $i<$operationsCount; $i++) {
		$station_id = $operations[$i]["DATANAME"];
		if(!empty($station_id)){
			if(isset($stations[$station_id]['CONNECT'])){
				if(strpos((string)$operations[$i]["DATAVALUE"], '^') !== false){
					$data = explode('^', $operations[$i]["DATAVALUE"]);
					$command = $data[0];
					$value = $data[1];
					if(isset($data[2])){
						$stations[$station_id]['TVOLUME'] = ['start'=>time()+2, 'end'=>time() + 30, 'volume'=>$stations[$station_id]['VOLUME']*0.1];
						echo date("H:i:s")." Отправляем на ".$stations[$station_id]['TITLE']." setVolume". ": " . $data[2]*0.1.PHP_EOL;
						try {
							$stations[$station_id]['CONNECT']->send($yadevices->message('setVolume', $data[2]*0.1, $stations[$station_id]['DEVICE_TOKEN']));
						} catch (Throwable $e) {
							logEvent('Ошибка отправки setVolume на ' . $stations[$station_id]['TITLE'] . ': ' . $e->getMessage(), true);
						}
					}
				} else {
					$command = $operations[$i]["DATAVALUE"];
					$value = '';
				}
				$id = uniqid('');
				if(!isset($stations[$station_id]['ANSWER']) or !is_array($stations[$station_id]['ANSWER'])) $stations[$station_id]['ANSWER'] = [];
				// Станция отвечает не на каждую команду, поэтому очередь ожидания
				// чистится по времени и ограничена по длине - иначе она растёт без предела,
				// а при переподключении все накопленные команды уходят на станцию повторно
				foreach($stations[$station_id]['ANSWER'] as $oldId => $oldAnswer){
					if(($oldAnswer['time'] ?? 0) < time() - ANSWER_TTL) unset($stations[$station_id]['ANSWER'][$oldId]);
				}
				while(count($stations[$station_id]['ANSWER']) >= ANSWER_MAX){
					array_shift($stations[$station_id]['ANSWER']);
				}
				$stations[$station_id]['ANSWER'][$id] = ['command'=>$command,'value'=>$value,'time'=>time()];
				if($command == 'playerState') $sendPlayerState = true;
				$message = $yadevices->message($command, $value, $stations[$station_id]['DEVICE_TOKEN'], $id);
				if($value != '') $value = ': '.$value;
				echo date("H:i:s")." Отправляем на ".$stations[$station_id]['TITLE']." " . $command . $value.PHP_EOL;
				if(!empty($message)) {
					try {
						$stations[$station_id]['CONNECT']->send($message);
					} catch (Throwable $e) {
						logEvent('Ошибка отправки команды ' . $command . ' на ' . $stations[$station_id]['TITLE'] . ': ' . $e->getMessage(), true);
						$stations[$station_id]['IS_CONNECT'] = time();
						unset($stations[$station_id]['CONNECT']);
					}
				}
			}
			else logEvent('Станция ' . ($stations[$station_id]['TITLE'] ?? $station_id) . ' не в сети, сообщение не передано: '
				. $operations[$i]["DATAVALUE"], true);
		}
	}
	
	if ($latest_check_cycle + 15 < time()) {
       $latest_check_cycle = time();
       setGlobal(CYCLE_NAME . 'Run', $latest_check_cycle, 1);
       // раз в минуту дополнительно сохраняем снимок состояния для разбора аварий
       if ($latest_state_saved + 60 < time()) {
           $latest_state_saved = time();
           saveCycleState($stations);
       }
    }
	
	if ((time()-$latest_check) > $reloadTime) {
		$latest_check = time();
		   callAPI('/api/module/yadevices', 'GET', array('getonline' => true));
	}
	if (file_exists('./reboot') || isset($_GET['onetime'])) {
		foreach($stations as $station){
			// Соединение есть далеко не у каждой станции - вызов close() на пустом значении обрывал цикл фатальной ошибкой
			if(!isset($station['CONNECT'])) continue;
			try {
				$station['CONNECT']->close();
			} catch (Throwable $e) {
				// станция уже отключена, закрывать нечего
			}
		}
		exit;
	}
}

function connect($stations){
	global $yadevices;
	//Подключаемся к Станциям, у которых прописан локальный IP и получен токен
	foreach($stations as $key=>$station){
		// Переменная переиспользуется в цикле: без сброса станция могла получить соединение соседней станции
		unset($connect);
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
					} catch(Throwable $e) {
						if(!isset($station['CONNECTION_OFF'])){
							echo '.....Не успешно. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
							logEvent('Подключение к облаку не удалось: ' . $e->getMessage()
								. '. Повтор раз в ' . RECONNECT_TIME . ' секунд.', true);
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
					} catch(Throwable $e) {
						unset($connect);
						unset($stations[$key]['CONNECT']);
					}
				}
				if(isset($connect)){
					$token = $station['DEVICE_TOKEN'] ?? '';
					if($station['TITLE'] != 'Quasar'){
						//Обновляем токен
						$token = $yadevices->getDeviceToken($station['STATION_ID'], $station['PLATFORM']);
						if(!$token){
							$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
							logEvent('Ошибка получения локального токена для ' . $station['TITLE']
								. ', соединение разорвано. Повтор раз в ' . RECONNECT_TIME . ' секунд.', true);
							$stations[$key]['CONNECTION_OFF'] = 1;
							try { $connect->close(); } catch (Throwable $e) {}
							continue;
						} else {
							$stations[$key]['DEVICE_TOKEN'] = $token;
							updateData($station, 1, 'online');
							$stations[$key]['ONLINE'] = 1;
							$connect->send($yadevices->message('softwareVersion', '', $token));
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
					//Если есть неотправленное сообщение, например, при устаревании токена (Invalid token)
					if(isset($station['ANSWER']) and is_array($station['ANSWER'])){
						foreach($station['ANSWER'] as $id=>$answer){
							if(($answer['time'] ?? 0) < time() - ANSWER_TTL){
								unset($stations[$key]['ANSWER'][$id]);
								continue;
							}
							try {
								$connect->send($yadevices->message($answer['command'], $answer['value'], $token, $id));
								echo date("H:i:s")." Повторно отправляем на ".$station['TITLE']. " " . $answer['command'] .": ". $answer['value'].PHP_EOL;
							} catch (Throwable $e) {
								logEvent('Ошибка повторной отправки на ' . $station['TITLE'] . ': ' . $e->getMessage(), true);
							}
						}
					}
				} else {
					$stations[$key]['IS_CONNECT'] = time()+RECONNECT_TIME;
					if($station['TITLE'] != 'Quasar'){
						updateData($station, 0, 'online');
						$stations[$key]['ONLINE'] = 0;
					}
					if(!isset($station['CONNECTION_OFF'])){
						echo '.....Не успешно. Попытки подключения раз в '. RECONNECT_TIME .' секунд.'.PHP_EOL;
						logEvent('Подключение к ' . $station['TITLE'] . ' (' . $station['IP'] . ') не удалось. Повтор раз в '
							. RECONNECT_TIME . ' секунд.', true);
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
	$allowedProps = ['ARTIST', 'TRACK', 'COVER', 'VOLUME', 'PLAYING', 'online'];
	if(!in_array($prop, $allowedProps, true)) return;
	if(empty($station['IOT_ID'])) return;

	// Запись читается до формирования $params: раньше ALLOWPARAMS всегда уходил пустым
	$property = SQLSelectOne("SELECT yadevices_capabilities.* FROM yadevices_capabilities LEFT JOIN yadevices ON yadevices_capabilities.YADEVICE_ID=yadevices.ID WHERE yadevices.IOT_ID='" . dbSafe($station['IOT_ID']) . "' AND yadevices_capabilities.TITLE='local." . dbSafe(strtolower($prop)) . "'");

	$params = array();
	if($prop != 'online'){
		SQLExec("UPDATE yastations SET `".$prop."` = '" . dbSafe($value) . "' WHERE STATION_ID = '" . dbSafe($station['STATION_ID']) . "'");
		$params['OLD_VALUE'] = $station[$prop] ?? '';
		$params['DEVICE_STATE'] = '1';
		$params['ALLOWPARAMS'] = $property['ALLOWPARAMS'] ?? '';
		$params['UPDATED'] = date('Y-m-d H:i:s');
		$params['MODULE'] = 'yadevices';
	}
	$params['NEW_VALUE'] = $value;

	if(empty($property['ID'])) return;
	$yadevices->setProperty($property, $value, $params);
	$property['VALUE'] = $value;
	$property['UPDATED'] = date('Y-m-d H:i:s');
	SQLUpdate('yadevices_capabilities', $property);
}