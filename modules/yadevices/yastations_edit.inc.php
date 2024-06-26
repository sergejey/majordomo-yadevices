<?php
/*
* @version 0.1 (wizard)
*/
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$table_name = 'yastations';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='$id'");

if ($this->mode == 'send_text') {
    $out['CLOUD']=gr('cloud','int');
    $out['TEXT']=gr('text');
    $out['ID']=$id;
	
	$out['SENDAS']=gr('sendas');
	
    if ($out['CLOUD'] == 1) {
		if($out['SENDAS'] == 1) {
			$result = $this->sendCommandToStationCloud($rec['ID'], gr('text'), 'text_action');
		} else {
			$result = $this->sendCommandToStationCloud($rec['ID'], gr('text'), 'phrase_action');
		}
    } else {
        $result = $this->sendCommandToStation($rec['ID'],gr('text'));
    }
	
    $this->redirect("?view_mode=".$this->view_mode."&id=".$rec['ID']);
}

if ($this->mode == 'update') {
    $ok = 1;

    $rec['IP'] = gr('ip');
    $rec['TTS'] = gr('tts', 'int');
    $rec['MIN_LEVEL_TEXT'] = gr('min_level_text');
    if ($rec['TTS']==1) {
        $rec['TTS_EFFECT'] = gr('tts_effect');
        $rec['TTS_ANNOUNCE'] = gr('tts_announce');
    } else {
        $rec['TTS_EFFECT']='';
        $rec['TTS_ANNOUNCE']='';
    }

    $rec['ALLOW_ASK'] = gr('allow_ask', 'int');
    //$rec['DEVICE_TOKEN'] = gr('device_token');


    //UPDATING RECORD
    if ($ok) {
        if ($rec['ID']) {
            SQLUpdate($table_name, $rec); // update
        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
        }

        if ($rec['TTS']==1) {
            $token = $this->getDeviceToken($rec['STATION_ID'], $rec['PLATFORM'], true);
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

if (isset($rec['ID']) && isset($rec['MIN_LEVEL']) && !isset($rec['MIN_LEVEL_TEXT'])) {
    $rec['MIN_LEVEL_TEXT']=$rec['MIN_LEVEL'];
}

outHash($rec, $out);
