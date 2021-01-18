<?php		
//Выгружаем данные по девайсу
$rec = SQLSelectOne("SELECT * FROM yadevices WHERE ID = '".dbSafe((int)$id)."'");
//$this->refreshDevicesData($rec['ID']);
if($this->config['RELOADAFTEROPEN'] == 1 || $this->mode == 'refreshDeviceData') {
	$this->refreshDevicesData($rec['ID']);
}

//if ($this->mode == 'refreshDeviceData') {
	//SQLExec("DELETE FROM yadevices_capabilities WHERE YADEVICE_ID = '".dbSafe($devID)."'");
//}

//Выгружаем умения девайся
$properties = SQLSelect("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=".$rec['ID']." ORDER BY TITLE");
$total = count($properties);

if($total == 0 || $this->mode == 'refreshDeviceData') {
	$data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/devices/'.$rec['IOT_ID']);

	//Циклом пройдемся по всем умениям
	if(is_array($data["capabilities"])) {
		foreach($data["capabilities"] as $capabilitie) {
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
			
			if (is_bool($capabilitie['state']['value']) == true) {
				if($capabilitie['state']['value'] == true) {
					$value = 1;
				} else {
					$value = 0;
				}
			} else if($capabilitie['state']['instance'] == 'color') {
				$value = $capabilitie['state']['value']['id'];
			} else {
				$value = $capabilitie['state']['value'];
			}

			//Обработка для модов
			if(is_array($capabilitie["parameters"]['modes'])) {
				$allowparam = '';
				foreach($capabilitie["parameters"]['modes'] as $allowparams) {
					$allowparam .= $allowparams['value'].',';
				}
			} else if(is_array($capabilitie["parameters"]['range'])) {
				$allowparam = 'От '.$capabilitie["parameters"]['range']['min'].' до '.$capabilitie["parameters"]['range']['max'].'. С шагом '.$capabilitie["parameters"]['range']['precision'].' ';
			} else if(is_array($capabilitie["parameters"]['palette'])) {
				$allowparam = '';
			} else {
				$allowparam = '';
			}
			
			//Запросим из БД текущие значения
			$c_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $rec['ID'] . " AND TITLE='" . $c_type . "'");
			
			if($allowparam) {
				$c_rec['ALLOWPARAMS'] = substr($allowparam,0,-1);
			}
			
			//Если нет такого умения
			if (!$c_rec['ID']) {
				$c_rec['VALUE'] = $value;
				$c_rec['UPDATED'] = date('Y-m-d H:i:s');
				$c_rec['YADEVICE_ID'] = $rec['ID'];
				$c_rec['TITLE'] = $c_type;
				$p_rec['READONLY'] = 0;
				$c_rec['ID'] = SQLInsert('yadevices_capabilities', $c_rec);
			}
		}
	}

	//Значения датчиков
	if(is_array($data["properties"])) {
		//Запихнем еще наш статус в массив
		$onlineArray = [
			'type' => 'devices.online',
			'state' => [
				'value' => '?',
			],
		];
		
		
		array_push($data["properties"], $onlineArray);
		
		
		foreach($data["properties"] as $propertie) {
			if($propertie['type'] == 'devices.online') {
				$p_type = $propertie['type'];
			} else {
				$p_type = $propertie['type'].'.'.$propertie['parameters']['instance'];
			}
			$value = $propertie['state']['value'];

			//Запросим из БД текущие значения
			$p_rec = SQLSelectOne("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=" . $rec['ID'] . " AND TITLE='" . $p_type . "'");
			
			//Если нет такого умения
			if (!$p_rec['ID']) {
				$p_rec['VALUE'] = $value;
				$p_rec['UPDATED'] = date('Y-m-d H:i:s');
				$p_rec['YADEVICE_ID'] = $rec['ID'];
				$p_rec['TITLE'] = $p_type;
				$p_rec['READONLY'] = 1;
				$p_rec['ID'] = SQLInsert('yadevices_capabilities', $p_rec);
			}
		}
	}
	
	$this->refreshDevicesData($rec['ID']);
	$this->redirect("?view_mode=" . $this->view_mode . "&id=" . $this->id);
}

$property_id = gr('property_id','int');
$send_value = gr('send_value');

for($i=0;$i<$total;$i++) {
	//Включить выключить, когда цикл до них дойдет
    // if ($properties[$i]['ID']==$property_id) {
        // $this->sendValueToYandex($rec['IOT_ID'],$properties[$i]['TITLE'],$send_value);
        // $this->redirect("?id=".$rec['ID']."&view_mode=".$this->view_mode);
    // }
	
	//То, чем можно управлять
    //if (in_array($properties[$i]['TITLE'],array('devices.capabilities.on_off'))) {
        if ($this->mode == 'update') {
            global ${'linked_object'.$properties[$i]['ID']};
            $OLD_LINKED_OBJECT = $properties[$i]['LINKED_OBJECT'];
            $properties[$i]['LINKED_OBJECT']=trim(${'linked_object'.$properties[$i]['ID']});
			
            global ${'linked_property'.$properties[$i]['ID']};
			$OLD_LINKED_PROPERTY = $properties[$i]['LINKED_PROPERTY'];
            $properties[$i]['LINKED_PROPERTY']=trim(${'linked_property'.$properties[$i]['ID']});
			
			global ${'linked_method'.$properties[$i]['ID']};
            $properties[$i]['LINKED_METHOD']=trim(${'linked_method'.$properties[$i]['ID']});
			
			SQLUpdate('yadevices_capabilities', $properties[$i]);
			
			if ($properties[$i]['LINKED_OBJECT'] && $properties[$i]['LINKED_PROPERTY']) {
				addLinkedProperty($properties[$i]['LINKED_OBJECT'], $properties[$i]['LINKED_PROPERTY'], $this->name);
				//Сразу установим значение
				setglobal($properties[$i]['LINKED_OBJECT'].'.'.$properties[$i]['LINKED_PROPERTY'], $properties[$i]['VALUE'], 0, $this->name.'.'.$properties[$i]['TITLE']);
			} else {
				removeLinkedProperty($OLD_LINKED_OBJECT, $OLD_LINKED_PROPERTY, $this->name);
			}
        }
        
        if ($properties[$i]['TITLE']=='devices.capabilities.on_off') {
            $properties[$i]['SDEVICE_TYPE'] = 'relay';
        }
        $properties[$i]['CAN_LINK']=1;
    //}
}
$out['PROPERTIES'] = $properties;
$out['PROPERTIES_COUNT'] = $total;

//Получаем данные к какому скилу принадлежит устройство
$data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/devices/'.$rec['IOT_ID']);
//Далее идем в скилы
$skills = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/skills/'.$data['skill_id']);
$out['SKILLS_ID'] = $data['skill_id'];
$out['SKILLS_NAME'] = $skills['name'];
$out['SKILLS_DESCRIPTION'] = $skills['description'];
$out['SKILLS_DEVELOPER_NAME'] = $skills['developer_name'];



outHash($rec,$out);
