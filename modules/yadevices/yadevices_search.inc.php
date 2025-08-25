<?php

if ($this->mode == 'switch') {
	$iot_id = gr('iot_id');
	if(gr('value') == 1) $value = 0;
	else $value = 1;
	$sendCMD = $this->sendValueToYandex($iot_id, 'devices.capabilities.on_off', $value);
	usleep(500000);
    $this->redirect("?view_mode=".$this->view_mode);
}

//Добавим иконки из БД
include_once "utils/devices_url.php";
$devices = SQLSelect("SELECT * FROM yadevices ORDER BY TITLE");
//$devices = SQLSelect("SELECT yadevices.*, yadevices_capabilities.VALUE FROM yadevices LEFT JOIN yadevices_capabilities ON yadevices.ID=yadevices_capabilities.YADEVICE_ID WHERE yadevices_capabilities.TITLE = 'devices.capabilities.on_off' ORDER BY TITLE");
$properties_temp = SQLSelect("SELECT * FROM yadevices_capabilities");
//Если есть статус включения, добавим его значение в отдельный массив
foreach($properties_temp as $prop){
	if($prop['TITLE'] == 'devices.capabilities.on_off' or $prop['TITLE']=='local.online' and $prop['VALUE'] == 1){
		$properties[$prop['YADEVICE_ID']] = $prop['VALUE'];
	}
}
unset($properties_temp);
foreach($devices as $key=>$device){
	$on = '';
	if(isset($properties[$device['ID']])){
		$devices[$key]["VALUE"] = 0;
		if($properties[$device['ID']] == 1){
			$on = '_on';
			$devices[$key]["VALUE"] = 1;
		}
	}
	if(stripos($device['DEVICE_TYPE'], 'devices.types.station') !== false) unset($devices[$key]["VALUE"]);
	$devices[$key]["ICON"] = $devices_URL[$device['DEVICE_TYPE'].$on] ?? 'https://yastatic.net/s3/pudya/app/_/cf97acc6d0252b23.webp';
}
if ($devices[0]['ID']) {
    $out['RESULT'] = $devices;
}
