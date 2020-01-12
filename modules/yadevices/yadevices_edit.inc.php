<?php

$rec = SQLSelectOne("SELECT * FROM yadevices WHERE ID=".(int)$id);


$capabilities = SQLSelect("SELECT * FROM yadevices_capabilities WHERE YADEVICE_ID=".$rec['ID']." ORDER BY TITLE");
$out['CAPABILITIES'] = $capabilities;

outHash($rec,$out);
