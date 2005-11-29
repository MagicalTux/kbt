<?php
// management of parts download

$GLOBALS['PARTS']=&new bt_parts;

BtWindow::timeout_add(5000,array(&$GLOBALS['PARTS'],'download_check'));
BtWindow::timeout_add(5000,array(&$GLOBALS['PARTS'],'rarest_part'));

/*

PART STORAGE :

ARRAY()
	PARTPIECESIZE = 16*1024
	ARRAY(PART)

*/

class bt_parts {
	var $parts;
	
	function bt_parts() {
		$t=array();
		$this->parts=&$t;
	}
	
	function part_done($key,&$packet) {
		// packet has *arrived* !!
		$basesize=16*1024;
		$partsize=$GLOBALS['TORRENT']->info['piece length'];
		$numpart=ceil($partsize/$basesize);
		
		$peer=&$GLOBALS['PEERS'][$key];
		
		$peer['queries']--;
		$info=substr($packet,0,8);
		$data=substr($packet,8);
		$packet='';
		if (strlen($data)>$basesize) return; // I didn't ask for a size larger than $basesize !
		$info=unpack('Nindex/Nbegin',$info);
		$index=$info['index'];
		$pidx=$info['begin']/$basesize;
		if ($pidx != round($pidx)) return; // bad offset >.<
		if (!isset($this->parts[$index])) return; // didn't ask that
		if (!isset($this->parts[$index][$pidx])) return; // didn't ask that
		if (is_string($this->parts[$index][$pidx])) return; // already have that
		$this->parts[$index][$pidx]=&$data; // save data in safe place
		$this->download_check(); // will download next part...
		return;
	}
	
	function rarest_part() {
		// try to find out which part of the ones we don't have is the rarest
		// first : find a part we don't have !
		if (!defined('DOWNLOADING')) return true;
		if (count($this->parts)>MAX_WORKING_PIECES) return true; // do not download more than 10 parts at the same time
		$p=array();
		for($i=0;$i<10;$i++) {
			$piece=$GLOBALS['TORRENT']->bitfield->random_unset_piece($GLOBALS['TORRENT']->current_random_max);
			if (is_null($piece)) continue;
			if (isset($p[$piece])) continue;
			$note=0;
			foreach($GLOBALS['PEERS'] as $peer) {
				// try to give a note to this part. Note is number of peers, lowest note is better
				// but zero note is bad
				if (!isset($peer['bitfield'])) continue;
				if ($peer['bitfield']->has_piece($piece)) $note++;
			}
			$p[$piece]=$note;
		}
		if (!$p) return true;
		asort($p); // associative sort
		reset($p);
		$piece=key($p);
		// we really should download this part as it's the rarest and may become somewhat unavailable
		$this->download_part($piece);
		return true;
	}
	
	function download_part($piece) {
		if (isset($this->parts[$piece])) {
			$this->download_check();
			return;
		}
//		echo 'Starting download of piece '.$piece."...\n";
		$this->parts[$piece]=array();
		$this->download_check();
	}
	
	function download_check() {
		// function called every 5secs (or when I get a new download)
		if (!defined('DOWNLOADING')) return true;
		if (defined('DOWNLOADED')) return false;
		$basesize=16*1024;
		$partsize=$GLOBALS['TORRENT']->info['piece length'];
		$numpart=ceil($partsize/$basesize);
		reset($this->parts);
		while(list($pnum)=each($this->parts)) {
			unset($part);
			$part=&$this->parts[$pnum];
			$complete=true;
			$numpart2=$numpart;
			$finalbasesize=null;
			if ($pnum==($GLOBALS['TORRENT']->bitfield->size-1)) {
				// this is the LAST part !
				$size=$GLOBALS['TORRENT']->bt_piece_size($pnum);
				$numpart2=ceil($size/$basesize);
				$finalbasesize=($size % $basesize);
			}
			for($pindex=0;$pindex < $numpart2;$pindex++) {
				if (is_null($finalbasesize)) {
					$basesize2=$basesize;
				} else {
					if ($pindex<($numpart2)-1) {
						$basesize2=$basesize;
					} else {
						$basesize2=$finalbasesize;
					}
				}
				if (!isset($part[$pindex])) $part[$pindex]=false;
				unset($pdata);
				$pdata=&$part[$pindex];
				// pdata = string --> part complete
				// pdata = array --> reference to queried peer
				// pdata = bool --> nothing
				if (is_string($pdata)) continue;
				$complete=false;
				if (is_array($pdata)) {
					if (isset($GLOBALS['PEERS'][$pdata['self']])) {
						if (isset($GLOBALS['PEERS'][$pdata['self']]['dl'][$pnum.'-'.$pindex])) continue;
					}
//					$GLOBALS['PEERS'][$part[$pindex]['self']]['queries']--;
					$pdata=false;
				}
				foreach($GLOBALS['PEERS'] as $key=>$peer) {
					if ($peer['queries']>=MAX_QUERY_PER_PEER) continue;
					if (!isset($peer['bitfield'])) continue;
					if ($peer['state'] & STATE_CHOKE) continue; // peer is choked
					if (!($peer['bitfield']->has_piece($pnum))) continue;
					// send query...
					$GLOBALS['PEERS'][$key]['dl'][$pnum.'-'.$pindex]=time();
					$this->parts[$pnum][$pindex]=&$GLOBALS['PEERS'][$key];
					send_packet_6($key,$pnum,$pindex*$basesize,$basesize2);
					break; // do not download from someone else !
				}
			}
			// check $complete
			if ($complete) {
				unset($this->parts[$pnum]);
				$data='';
				for($pindex=0;$pindex<$numpart;$pindex++) $data.=$part[$pindex];
				$res=$GLOBALS['TORRENT']->write_piece($pnum,$data);
				if ($res) {
					echo 'PIECE #'.$pnum.' IS NOW COMPLETE !'."\n";
					$GLOBALS['TORRENT']->bitfield->has_piece($pnum,true);
					$GLOBALS['BtWindow']->gtk_pc_status($GLOBALS['TORRENT']->bitfield->done,$GLOBALS['TORRENT']->bitfield->size);
					foreach($GLOBALS['PEERS'] as $key=>$peer) {
						if ($peer['state'] & STATE_ACTIVE) send_packet_4($peer['sockid'],$pnum);
					}
				} else {
					echo 'BAD CHECKSUM'." on piece $pnum\n";
				}
				$this->rarest_part();
			}
		}
		if ($GLOBALS['TORRENT']->bitfield->full) {
			echo 'DOWNLOAD COMPLETED'."\n";
			define('DOWNLOADED',true);
			bt_tracker_do_ping('completed');
		}
		return true;
	}
				
}
