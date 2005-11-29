<?php
// inter-client communications
// NOTE: Always use BigEndian as defined in BitTorrent protocol

// http://etudiant.univ-mlv.fr/~llelion/inter.html

$GLOBALS['PEERS']=array();

define('STATE_ACTIVE',1);
define('STATE_CHOKE',2);
define('STATE_INTERESTED',4);
define('STATE_I_CHOKE',8);
define('STATE_I_INTERESTED',16);

define('STATE_INITIAL',STATE_CHOKE | STATE_I_CHOKE);

function bt_new_client($sock,&$info) {
	@socket_close($sock);
	return false;
}

function bt_tracker_do_ping($type=null) {
	$query ='info_hash='.urlencode(pack('H*',$GLOBALS['TORRENT']->hash)).'&peer_id='.urlencode(PEER_ID).'&port='.urlencode(BT_PORT);
	$query.='&uploaded='.urlencode($GLOBALS['COUNT']['up']).'&downloaded='.urlencode($GLOBALS['COUNT']['down']);
	$query.='&left='.urlencode($GLOBALS['TORRENT']->bitfield->leftbytes()).'&numwant=100';
	if (!is_null($type)) {
		$query.='&event='.$type;
	}
	$url=$GLOBALS['TORRENT']->data['announce'].'?'.$query;
//	echo 'QUERY: '.$url."\n";
	$res=http_get($url,'bt_peer_trackeranswer',$type);
	return false;
}

function bt_tracker_ping($time,$type=null) {
	$time=$time*1000; // we need millisecs
	Gtk::timeout_add($time,'bt_tracker_do_ping',$type);
}

function bt_tracker_stop() {
	bt_tracker_do_ping('stopped');
	return false;
}

function bt_tracker_run() {
	$GLOBALS['BtWindow']->hide_all();
	$GLOBALS['TORRENT']->CloseAllFP();
	$param=$GLOBALS['TORRENT']->target;
	$param=escapeshellarg($param);
	system($param);
	return true;
}

BtWindow::timeout_add(10000,'roll_choke');
function roll_choke() {
	// We need to set 4 peers "unchoked". we roll one of them every 10 secs 
	// in order to give everyone his chance. It is called "optimistic choking"
	// 
	// ... or something like that.
	// send_packet_0($sockid) <-- choke
	// send_packet_0($sockid) <-- unchoke
	$peers=array();
	$choked=array();
	$unchoked=array();
	$chokeme=array();
	$unchokeme=array();
	foreach($GLOBALS['PEERS'] as $key=>$peer) $peers[]=$key;
	// first, list unchoked peers...
	$k=0;
	foreach($peers as $key) {
		if ($GLOBALS['PEERS'][$key]['state'] & STATE_INTERESTED) {
			if (!($GLOBALS['PEERS'][$key]['state'] & STATE_I_CHOKE)) {
				// this peer isn't choked
				$k++;
				if ($k>=4) {
					$chokeme[]=$key;
				} else {
					$unchoked[]=$key;
				}
			} else {
				$choked[]=$key;
			}
		} else {
			// not interested - should be shoked
			if (!($GLOBALS['PEERS'][$key]['state'] & STATE_I_CHOKE)) $chokeme[]=$key;
		}
	}
	if ( (sizeof($choked)+sizeof($unchoked)) <= 4) {
		// less than 4 leeches, serve them all !
		$unchokeme=$choked;
	} else {
		if (sizeof($unchoked)<4) {
			while (sizeof($unchoked)<4) {
				$n=rand(0,sizeof($choked)-1);
				$unchokeme[]=$choked[$n];
				$unchoked[]=$choked[$n]; // for counter
				unset($choked[$n]);
				sort($choked); // sort will drop keys
			}
		} else {
			$unchokeme[]=$choked[rand(0,sizeof($choked)-1)];
			$chokeme[]=$unchoked[rand(0,sizeof($unchoked)-1)];
		}
	}
	// now, do the work !
	foreach($unchokeme as $key) {
		send_packet_1($GLOBALS['PEERS'][$key]['sockid']);
		$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] & (~STATE_I_CHOKE);
	}
	foreach($chokeme as $key) {
		send_packet_0($GLOBALS['PEERS'][$key]['sockid']);
		$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] | STATE_I_CHOKE;
	}
	return true;
}

function bt_peer_trackeranswer(&$buf,$eof,$event) {
	if (!$eof) return true;
	$reping=true;
	if (!is_null($event)) {
		switch($event) {
			case 'started':
				$GLOBALS['BtWindow']->gtk_shutdown_func('bt_tracker_stop','bt_tracker');
				break;
			case 'stopped':
				$GLOBALS['BtWindow']->bt_quit_cleanup();
				return false;
			case 'completed':
				// once completed, stop the download!
				$GLOBALS['BtWindow']->gtk_shutdown_func('bt_tracker_run','bt_tracker');
				bt_tracker_do_ping('stopped');
				$GLOBALS['BtWindow']->bt_quit_cleanup();
				return false;
			#
		}
	}
	// we have the tracker's answer plus some http headers... parse !
	if (!defined('DOWNLOADING')) define('DOWNLOADING',true);
	$fl=true;
	while(1) {
		$pos=strpos($buf,"\n");
		if ($pos===false) {
			$GLOBALS['BtWindow']->gtk_error('Unknown error on socket while connecting to tacker !');
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
				$GLOBALS['BtWindow']->gtk_error('Bad HTTP reply from tracker : '.$lin);
				return;
			}
		}
	}
	$info=BDecode($buf);
	if (isset($info['failure reason'])) {
		echo 'ERROR FROM TRACKER: '.$info['failure reason']."\n";
		echo 'Re-downloading torrent...'."\n";
		btinit_loadtorrent();
//		bt_tracker_ping(180);
		return;
	}
	$next_ping=rand($info['min interval'],$info['interval']); // choose a time for next ping ...
	if ($reping) bt_tracker_ping($next_ping);
	$pstr='BitTorrent protocol'; // protocol string (identifier)
	$handshake=pack('C',strlen($pstr)).$pstr;
	$handshake.=str_repeat("\0",8); // reserved
	$handshake.=pack('H*',$GLOBALS['TORRENT']->hash); // hash
	$handshake.=PEER_ID; // 20 bytes peerID
	// now, try to contact peers...
	foreach($info['peers'] as $peer) {
		$ip=$peer['ip'];
		$peer_id=$peer['peer id'];
		if ($peer_id==PEER_ID) continue; // ignore ourself !
		$port=$peer['port'];
		unset($key); // make sure key isn't set
		$key=$peer_id; // unique client key
		if (!isset($GLOBALS['PEERS'][$key])) {
			// try to connect !
			$GLOBALS['PEERS'][$key]=array();
//			echo 'Connecting to peer '.$ip.':'.$port.' ['.$peer_id.'] ...'."\n";
			$sock=make_csocket($ip,$port,$handshake,'manage_peer',$key);
			if (is_int($sock)) {
				$GLOBALS['SOCKETS'][$sock]['peer']=$key;
				$GLOBALS['PEERS'][$key]['sockid']=$sock;
				$GLOBALS['PEERS'][$key]['self']=$key;
				$GLOBALS['PEERS'][$key]['dl']=array();
				$GLOBALS['PEERS'][$key]['ping']=3;
				$GLOBALS['PEERS'][$key]['touch']=time(); // we need to send alive packet every 2 minutes
				$GLOBALS['PEERS'][$key]['queries']=0;
				$GLOBALS['PEERS'][$key]['state']=STATE_INITIAL; // state flags
			} else {
				unset($GLOBALS['PEERS'][$key]);
//				echo 'Could not connect to peer ['.$key.'] : '.$sock."\n";
			}
		}
	}
}

function manage_peer(&$buf,$eof,&$key,$sockid) {
	if ($eof) return false; // nothing to do with closed sockets >.<
	unset($key);
	$key=$GLOBALS['SOCKETS'][$sockid]['peer'];
	// decode packet...
	if ($key!==false) if (!isset($GLOBALS['PEERS'][$key])) {
		echo "Unexpected call with bad key $key !\n";
		return false; // argh!
	}
	$peer=&$GLOBALS['PEERS'][$key];
	if ( ! ($peer['state'] & STATE_ACTIVE)) {
		// we're waiting for handshake...
		// handshake size : 48+1+first byte
		if (strlen($buf)<48) return true;
		$len=ord($buf{0})+49;
		if (strlen($buf)<$len) return true;
		$len=ord($buf{0});
		$protocol=substr($buf,1,$len);
		if ($protocol!='BitTorrent protocol') {
//			echo "BTINIT: bad handshake : \"$protocol\"\n";
			return false; // close link, unknown handshake
		}
		$buf=substr($buf,$len+1+8); // skip start of handshake + 8 reserved bytes
		$hash=substr($buf,0,20);
		$peer_id=substr($buf,20,20);
		$buf=substr($buf,40); // skip hash & peer id
		if ($hash != pack('H*',$GLOBALS['TORRENT']->hash)) {
			echo "WARNING: Got different hash from a peer.\n";
			return false;
		}
		if ($key!==false) {
			if ($key!=$peer_id) {
				echo "WARNING: Peer with different peer id than the one reported by the tracker !\n";
				return false;
			}
		} else {
			// new peer !
			if (isset($GLOBALS['PEERS'][$peer_id])) {
				echo "WARNING: New peer with a known key - ignored !\n";
				return false;
			}
			$GLOBALS['PEERS'][$peer_id]=array(
				'sockid'=>$sockid,
				'touch'=>time(),
				'state'=>STATE_ACTIVE,
			);
			$key=$peer_id; // save new key !
			$GLOBALS['SOCKETS'][$sockid]['peer']=$key;
		}
		send_packet_5($sockid);
		$peer['state']=$peer['state'] | STATE_ACTIVE;
	}
	// while loop : handle all complete pending packets
	while(1) {
		if (strlen($buf)<4) return true; // no data to read!
		$len=substr($buf,0,4);
		$len=unpack('Nlen',$len);
		$len=$len['len'];
		if (strlen($buf)<($len+4)) return true; // incomplete buffer
		$packet=substr($buf,4,$len);
		$buf=substr($buf,4+$len); // remove from buffer
		$peer['touch']=time(); // got touched!
		bt_peer_packet($packet,$key,$peer);
		unset($packet);
	}
}

// ping peers with a keep-alive !
Gtk::timeout_add(10000,'bt_peer_ping');
function bt_peer_ping() {
	$expire=time()-180; // 3 minutes timeout
	$expire2=time()-30; // 30 secs timeout
	foreach($GLOBALS['PEERS'] as $key=>$p) {
		$peer=&$GLOBALS['PEERS'][$key];
		if ($peer['ping']--<1) {
//			echo "PING PEER $key\n";
			if ($peer['state'] & STATE_ACTIVE) send_packet_null($peer['sockid']);
			$peer['ping']=6;
		}
		if ($peer['touch']<$expire) kill_socket($peer['sockid']);
		if ($peer['state'] & STATE_ACTIVE) {
			foreach($peer['dl'] as $dlid=>$start) {
				if ($start<$expire2) {
					unset($peer['dl'][$dlid]);
	//				echo "Download $dlid expired\n";
				}
			}
		}
	}
	return true;
}

// pass packet by reference as it may be big - no need to have
// it twice in memory
function bt_peer_packet(&$packet,$key,&$peer) {
	if (strlen($packet)==0) return; // keep-alive packet
	$packet_id=ord($packet{0});
	$packet=substr($packet,1); // strip packetid
//	echo 'PACKET #'.$packet_id.' from '.$key."!\n";
	switch($packet_id) {
		case 0: bt_peer_choke($key); break;
		case 1: bt_peer_unchoke($key); break;
		case 2: bt_peer_interested($key); break;
		case 3: bt_peer_uninterested($key); break;
		case 4: bt_peer_have($key,$packet); break;
		case 5: bt_peer_scan_bitfield($key,$packet); break;
		case 6: bt_peer_ask_part($key,$packet); break;
		case 7: $GLOBALS['PARTS']->part_done($key,$packet); break;
		default: echo 'Got unknown packet #'.$packet_id.' from '.$key.' !'."\n";
	}
}

// packet0 : choke
function bt_peer_choke($key) {
	$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] | STATE_CHOKE;
//	echo 'CHOKE: By ['.$key."]\n";
}

// packet1 : unchoke
function bt_peer_unchoke($key) {
	$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] & (~STATE_CHOKE);
//	echo 'UNCHOKE: By ['.$key."]\n";
}

// packet2 : interested
function bt_peer_interested($key) {
	$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] | STATE_INTERESTED;
//	echo 'INTERESTED: By ['.$key."]\n";
}

// packet3 : uninterested
function bt_peer_uninterested($key) {
	$GLOBALS['PEERS'][$key]['state'] = $GLOBALS['PEERS'][$key]['state'] & (~STATE_INTERESTED);
//	echo 'UNINTERESTED: By ['.$key."]\n";
}

// packet4 : have
function bt_peer_have($key,$packet) {
	if (strlen($packet)!=4) return;
	$packet=unpack('Npiece',$packet);
	$piece=$packet['piece'];
	if (!isset($GLOBALS['PEERS'][$key]['bitfield'])) $GLOBALS['PEERS'][$key]['bitfield']=&new bitfield($GLOBALS['TORRENT']->bitfield->size);
	$GLOBALS['PEERS'][$key]['bitfield']->has_piece($piece,true);
//	echo 'HAS_ANNOUNCE: Peer ['.$key.'] has piece #'.$piece."\n";
}

// packet5 : bitfield
function bt_peer_scan_bitfield($key,&$bitfield) {
	// we received a bitfield !
	$sockid=$GLOBALS['PEERS'][$key]['sockid'];
	// first, save it in memory !
	if (!isset($GLOBALS['PEERS'][$key]['bitfield'])) $GLOBALS['PEERS'][$key]['bitfield']=&new bitfield($GLOBALS['TORRENT']->bitfield->size);
	$GLOBALS['PEERS'][$key]['bitfield']->import_bitfield($bitfield);
	// now, compare it
	$interest=$GLOBALS['TORRENT']->bitfield->compare($GLOBALS['PEERS'][$key]['bitfield']->bitfield);
	// is the parts owned by this peer interesting ?
	if ( ($interest>0) && (!($GLOBALS['PEERS'][$key]['state'] & STATE_I_INTERESTED))) {
		// send packet 2 [state=interested] and set bit "I_INTERESTED"
		send_packet_2($sockid);
		$GLOBALS['PEERS'][$key]['state'] |= STATE_I_INTERESTED;
		// send a query for a part
		$GLOBALS['PARTS']->rarest_part();
	}
}

// packet6 : peer is asking a part...
function bt_peer_ask_part($key,&$packet) {
	if (strlen($packet)!=12) return; // bad request!
	
	$info=unpack('Nindex/Nbegin/Nlength',$packet);
	$piece=$info['index'];
	$begin=$info['begin'];
	$length=$info['length'];
//	echo "REQUEST: Piece #$piece [$begin-$length] for $key\n";
	if ($length>(16*1024*3)) return; // refuse such big packets!
	if ($piece>($GLOBALS['TORRENT']->bitfield->size)) return; // out of file !
	$psize=$GLOBALS['TORRENT']->bt_piece_size($piece);
	if ($begin>$psize) return; // over the piece
	if (($begin+$length)>$psize) $length=$psize-$begin;
	$data=$GLOBALS['TORRENT']->read_piece($piece);
	$data=substr($data,$begin,$length);
	$data=pack('NCNN',9+strlen($data),7,$piece,$begin).$data;
//	echo "SENDING ".strlen($data)." bytes to $key as requested\n";
	return socket_wbuf($GLOBALS['PEERS'][$key]['sockid'],$data);
}

// keep alive
function send_packet_null($sockid) {
	return socket_wbuf($sockid,"\0\0\0\0");
}

// choked !
function send_packet_0($sockid) {
	return socket_wbuf($sockid,pack('NC',1,0));
}

// not choked !
function send_packet_1($sockid) {
	return socket_wbuf($sockid,pack('NC',1,1));
}

// interested !
function send_packet_2($sockid) {
	return socket_wbuf($sockid,pack('NC',1,2));
}

// not interested !
function send_packet_3($sockid) {
	return socket_wbuf($sockid,pack('NC',1,3));
}

// "have" packet
function send_packet_4($sockid,$pnum) {
	return socket_wbuf($sockid,pack('NCN',5,4,$pnum));
}

// build bitfield
function send_packet_5($sockid) {
	$bitfield=&$GLOBALS['TORRENT']->bitfield->bitfield;
	$wbuf=pack('NC',strlen($bitfield)+1,5).$bitfield;
	return socket_wbuf($sockid,$wbuf);
}

// ask download
function send_packet_6($key,$index,$start,$len) {
//	echo 'Query to download: '.$index.'['.$start.'-'.$len.'] from '.$key."\n";
	$peer=&$GLOBALS['PEERS'][$key];
	if (!$peer) return;
	$peer['queries']++;
	$sockid=$peer['sockid'];
	return socket_wbuf($sockid,pack('NCNNN',13,6,$index,$start,$len));
}

