<?php

// init...
btinit_loadtorrent();

function btinit_loadtorrent() {
	$GLOBALS['BtWindow']->gtk_action('Downloading torrent definition file...');
	$res=http_get(BT_URL,'btinit_get_torrent',$w=null);
	if (is_string($res)) {
		$GLOBALS['BtWindow']->gtk_error($res);
		return;
	}
}

function btinit_get_torrent(&$buf,$eof) {
	if (!$eof) return true;
	// we have our torrent in $buf !
	// first we have to parse the HTTP headers...
	$fl=true;
	while(1) {
		$pos=strpos($buf,"\n");
		if ($pos===false) {
			$GLOBALS['BtWindow']->gtk_error('Unknown error on socket !');
			return;
		}
		$lin=substr($buf,0,$pos);
		$buf=substr($buf,$pos+1);
		$lin=rtrim($lin);
		if (!$lin) break;
		if ($fl) {
			// first line : HTTP/1.x 2xx OK
			$fl=false;
			$code=substr($lin,9,3);
			if ( ($code<200) or ($code>299)) {
				$GLOBALS['BtWindow']->gtk_error('Bad HTTP reply : '.$lin);
				return;
			}
		}
	}
	// now we only have our torrent in the variable !
	$GLOBALS['TORRENT']=&new BtMetaFile;
	$res=$GLOBALS['TORRENT']->DecodeMeta($buf);
	if (is_string($res)) {
		$GLOBALS['BtWindow']->gtk_error($res);
		return;
	}
	$GLOBALS['BtWindow']->gtk_action('Connecting to tracker...');
	// first, let's open a listening port for incoming peers...
	$ls=$GLOBALS['listensock']=make_lsocket('bt_new_client',$x=null); unset($x);
	$port=$GLOBALS['SOCKETS'][$ls]['port'];
	define('BT_PORT',$port);
	bt_tracker_do_ping('started');
}
