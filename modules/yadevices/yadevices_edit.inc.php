<?php

$rec = SQLSelectOne("SELECT * FROM yadevices WHERE ID=".(int)$id);


$properties = SQLSelect("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=".$rec['ID']." ORDER BY TITLE");
$total = count($properties);

$property_id = gr('property_id','int');
$send_value = gr('send_value');

for($i=0;$i<$total;$i++) {
    if ($properties[$i]['ID']==$property_id) {
        $this->sendValueToYandex($rec['IOT_ID'],$properties[$i]['TITLE'],$send_value);
        $this->redirect("?id=".$rec['ID']."&view_mode=".$this->view_mode);
    }
    if (in_array($properties[$i]['TITLE'],array('devices.capabilities.on_off'))) {
        if ($this->mode == 'update') {
            global ${'linked_object'.$properties[$i]['ID']};
            $properties[$i]['LINKED_OBJECT']=trim(${'linked_object'.$properties[$i]['ID']});
            global ${'linked_property'.$properties[$i]['ID']};
            $properties[$i]['LINKED_PROPERTY']=trim(${'linked_property'.$properties[$i]['ID']});
            SQLUpdate('yadevices_capabilities', $properties[$i]);
        }
        if ($properties[$i]['LINKED_OBJECT'] && $properties[$i]['LINKED_PROPERTY']) {
            addLinkedProperty($properties[$i]['LINKED_OBJECT'], $properties[$i]['LINKED_PROPERTY'], $this->name);
        }
        if ($properties[$i]['TITLE']=='devices.capabilities.on_off') {
            $properties[$i]['SDEVICE_TYPE'] = 'relay';
        }
        $properties[$i]['CAN_LINK']=1;
    }
}
$out['PROPERTIES'] = $properties;

outHash($rec,$out);
