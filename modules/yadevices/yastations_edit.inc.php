<?php
/*
* @version 0.1 (wizard)
*/
if (isset($this->owner->name) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$table_name = 'yastations';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID=" . (int)$id);
$previous_ip = $rec['IP'] ?? '';
if (empty($rec['ID'])) {
    // Записи нет - возвращаем пользователя к списку станций вместо пустой формы
    $this->redirect("?");
    return;
}

if ($this->mode == 'send_text') {
    $out['CLOUD']=gr('cloud','int');
    $out['TEXT']=gr('text');
    $out['ID']=$id;
	
	$out['SENDAS']=gr('sendas');
	
    if ($out['CLOUD'] == 1) {
		if($out['SENDAS'] == 1) {
			$command = 'text_action';
		} else {
			$command = 'phrase_action';
		}
		$result = $this->sendCommandToStationCloud($rec, $command, gr('text'));
    } else {
		if($out['SENDAS'] == 1) {
			$command = 'command';
		} else if($out['SENDAS'] == 2) {
			$command = 'dialog';
		} else {
			$command = 'text';
		}
		$result = $this->sendCommandToStation($rec, $command, gr('text'));
    }
	
    $this->redirect("?view_mode=".$this->view_mode."&id=".$rec['ID']);
}

if ($this->mode == 'update') {
    $ok = 1;

    $ip = trim(gr('ip'));
    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
        $out['ERR_MSG'] = 'Указан некорректный IP-адрес станции.';
        $ok = 0;
    }
    $rec['IP'] = $ip;
    $rec['TTS'] = gr('tts', 'int');
    $rec['MIN_LEVEL_TEXT'] = gr('min_level_text');

    $rec['ALLOW_ASK'] = gr('allow_ask', 'int');
    //$rec['DEVICE_TOKEN'] = gr('device_token');


    //UPDATING RECORD
    if ($ok) {
        if (!empty($rec['ID'])) {
            SQLUpdate($table_name, $rec); // update
        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
        }

        /*if ($rec['TTS']==1) {
            $token = $this->getDeviceToken($rec['STATION_ID'], $rec['PLATFORM'], true);
        }*/

		// Фоновый цикл читает адреса станций один раз при старте. Перезапуск нужен
		// только при смене адреса: каждый перезапуск обрывает соединения со всеми
		// станциями и регистрируется в журнале ошибок как аварийная остановка.
		if($rec['IP'] !== $previous_ip){
			setGlobal('cycle_yadevicesControl', 'restart');
			$out['CYCLE_RESTARTED'] = 1;
		}
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars($v);
        }
    }
}

if (isset($rec['MIN_LEVEL']) && !isset($rec['MIN_LEVEL_TEXT'])) {
    $rec['MIN_LEVEL_TEXT']=$rec['MIN_LEVEL'];
}
outHash($rec, $out);
