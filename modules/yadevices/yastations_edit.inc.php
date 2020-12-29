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
    if ($out['CLOUD']) {
        $result = $this->sendCommandToStationCloud($rec['ID'],gr('text'));
    } else {
        $result = $this->sendCommandToStation($rec['ID'],gr('text'));
    }

        if ($result) {
        $this->redirect("?view_mode=".$this->view_mode."&id=".$rec['ID']."&ok_msg=".urlencode('Command sent!'));
    } else {
        $this->redirect("?view_mode=".$this->view_mode."&id=".$rec['ID']."&err_msg=".urldecode('Failed to send command'));
    }
}

if ($this->mode == 'update') {
    $ok = 1;
    //updating '<%LANG_TITLE%>' (varchar, required)
    /*
 $rec['TITLE']=gr('title');
 if ($rec['TITLE']=='') {
  $out['ERR_TITLE']=1;
  $ok=0;
 }



    */


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
    //$rec['DEVICE_TOKEN'] = gr('device_token');


    //UPDATING RECORD
    if ($ok) {
        if ($rec['ID']) {
            SQLUpdate($table_name, $rec); // update
        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
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

if ($rec['ID'] && $rec['MIN_LEVEL'] && !$rec['MIN_LEVEL_TEXT']) {
    $rec['MIN_LEVEL_TEXT']=$rec['MIN_LEVEL'];
}

outHash($rec, $out);
