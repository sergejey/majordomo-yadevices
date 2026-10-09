<?php
/*
* @version 0.1 (wizard)
*/

$go_linked_object = gr('go_linked_object');
$go_linked_property = gr('go_linked_property');
if ($go_linked_object && $go_linked_property) {
    $tmp = SQLSelectOne("SELECT ID, YADEVICE_ID FROM yadevices_capabilities WHERE LINKED_OBJECT = '" . DBSafe($go_linked_object) . "' AND LINKED_PROPERTY='" . DBSafe($go_linked_property) . "'");
    if (!empty($tmp['YADEVICE_ID'])) {
        $this->redirect("?view_mode=edit_yadevices&id=" . (int)$tmp['YADEVICE_ID']);
    }
}

 global $session;
  if ($this->owner->name=='panel') {
   $out['CONTROLPANEL']=1;
  }
  $qry="1";
  // search filters
  // QUERY READY
  global $save_qry;
  if (!empty($save_qry) && isset($session->data['yastations_qry'])) {
   $qry=$session->data['yastations_qry'];
  } else {
   $session->data['yastations_qry']=$qry;
  }
  if (!$qry) $qry="1";
  $sortby_yastations="ID DESC";
  $out['SORTBY']=$sortby_yastations;
  // SEARCH RESULTS
  $res=SQLSelect("SELECT * FROM yastations WHERE $qry ORDER BY ".$sortby_yastations);
  if (!empty($res)) {
   //paging($res, 100, $out); // search result paging
   $total=count($res);
   for($i=0;$i<$total;$i++) {
	$res[$i]['TITLE']=htmlspecialchars((string)$res[$i]['TITLE']);
	$res[$i]['ARTIST']=htmlspecialchars((string)$res[$i]['ARTIST']);
	$res[$i]['TRACK']=htmlspecialchars((string)$res[$i]['TRACK']);
   }
   $out['RESULT']=$res;
  }
