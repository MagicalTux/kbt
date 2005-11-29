<?php
$fil='trackerless.torrent';

require('bt_bencoding.php');
$data=file_get_contents($fil);
$data=BDecode($data);
$data['info']['pieces']='';
ob_start();
ob_implicit_flush(0);
var_dump($data);
$data=ob_get_contents();
ob_end_clean();
$fil=fopen($fil.'.txt','wb');
fwrite($fil,$data);
fclose($fil);
