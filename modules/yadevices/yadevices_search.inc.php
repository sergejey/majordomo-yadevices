<?php

$devices = SQLSelect("SELECT * FROM yadevices ORDER BY TITLE");
if ($devices[0]['ID']) {
    $out['RESULT'] = $devices;
}
