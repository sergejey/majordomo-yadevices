<?php

if ($this->mode == 'switch') {
	$id = gr('id');
	$iot_id = gr('iot_id');
	if(gr('value') == 1) $value = 0;
	else $value = 1;
	$sendCMD = $this->sendValueToYandex($iot_id, 'devices.capabilities.on_off', $value);
	usleep(500000);
    $this->redirect("?view_mode=".$this->view_mode."&id=".$id);
}

//Выгружаем данные по девайсу с IP Станции с таким же IOT_ID
$device = SQLSelectOne("SELECT yadevices.*, yastations.IP, yastations.ID as yastationID FROM yadevices LEFT JOIN yastations ON yadevices.IOT_ID=yastations.IOT_ID WHERE yadevices.ID = '".dbSafe((int)$id)."'");

include_once "utils/devices_url.php";

$properties = SQLSelect("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=".$device['ID']." ORDER BY TITLE");
$total = count($properties);
$state = '';
for($i=0;$i<$total;$i++) {
    if ($this->mode == 'update') {
		$old_linked_object=$properties[$i]['LINKED_OBJECT'];
		$old_linked_property=$properties[$i]['LINKED_PROPERTY'];
		global ${'linked_object'.$properties[$i]['ID']};
		$properties[$i]['LINKED_OBJECT']=trim(${'linked_object'.$properties[$i]['ID']});
		global ${'linked_property'.$properties[$i]['ID']};
		$properties[$i]['LINKED_PROPERTY']=trim(${'linked_property'.$properties[$i]['ID']});
		global ${'linked_method'.$properties[$i]['ID']};
		$properties[$i]['LINKED_METHOD']=trim(${'linked_method'.$properties[$i]['ID']});
		// Если юзер удалил привязанные свойство и метод, но забыл про объект, то очищаем его.
		if ($properties[$i]['LINKED_OBJECT'] != '' && ($properties[$i]['LINKED_PROPERTY'] == '' && $properties[$i]['LINKED_METHOD'] == '')) {
			$properties[$i]['LINKED_OBJECT'] = '';
		}
		SQLUpdate('yadevices_capabilities', $properties[$i]);
		if ($old_linked_object && $old_linked_object!=$properties[$i]['LINKED_OBJECT'] || $old_linked_property && $old_linked_property!=$properties[$i]['LINKED_PROPERTY']) {
		removeLinkedProperty($old_linked_object, $old_linked_property, $this->name);
		}
		if ($properties[$i]['LINKED_OBJECT'] && $properties[$i]['LINKED_PROPERTY']) {
		addLinkedProperty($properties[$i]['LINKED_OBJECT'], $properties[$i]['LINKED_PROPERTY'], $this->name);
		//Сразу установим значение
		$this->setProperty($properties[$i], $properties[$i]['VALUE'], $properties[$i], $properties[$i]['TITLE']);
		}
    }
	
    //Скроем local умения, если у Станции не прописан IP и пропишем ID
	if(stripos($device['DEVICE_TYPE'], 'devices.types.station') !== false){
		if ($properties[$i]['TITLE']=='local.online' and $properties[$i]['VALUE'] == 1){
			$state = '_on';
		}			
		if(empty($device['IP']) and stripos($properties[$i]['TITLE'], 'local') !== false){
			$properties[$i]['HIDE'] = 1;
		}
	} else $properties[$i]['HIDE'] = 0;
		
    if ($properties[$i]['TITLE']=='devices.capabilities.on_off') {
        $properties[$i]['SDEVICE_TYPE'] = 'relay';
		if($properties[$i]['VALUE'] == 1){
			$device['VALUE'] = 1;
			$state = '_on';
		} else $device["VALUE"] = 0;
    }
    $properties[$i]['CAN_LINK'] = 1;
}

if(stripos($device['DEVICE_TYPE'], 'devices.types.station') !== false) {
	$device['STATION'] = true;
}

//Добавим иконки из БД
$device["ICON"] = $devices_URL[$device['DEVICE_TYPE'].$state] ?? 'https://yastatic.net/s3/pudya/app/_/cf97acc6d0252b23.webp';

$out['PROPERTIES'] = $properties;
$out['PROPERTIES_COUNT'] = $total;

//Далее идем в скилы
if($device['SKILL_ID'] != 'local'){
	$skills = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/skills/'.$device['SKILL_ID']);
	$out['SKILLS_ID'] = $device['SKILL_ID'] ?? '';
	$out['SKILLS_NAME'] = htmlspecialchars($skills['name']) ?? '';
	$out['SKILLS_DESCRIPTION'] = htmlspecialchars($skills['description']) ?? '';
	$out['SKILLS_DEVELOPER_NAME'] = htmlspecialchars($skills['developer_name']) ?? '';
}
outHash($device,$out);
