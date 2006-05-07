<?php
// MetaFile manager
// Multifiles torrent compatible
// NOTE: metafiles are .torrent files, they are bencoded !

// FP : array(
//   [x] => array(
//         virtualstart#,
//         virtualend#,
//         fp,
//     ),
// );

class BtMetaFile {
	var $fp=array(); // fp index
	var $data=null; // root of torrent metainfo
	var $info=null; // info part of the torrent metainfo
	var $hash=null; // hash of the info part
	var $bitfield=null; // bitfield
	var $sizeint=0; // int value of the torrent size
	var $size='N/A'; // string for the size (eg. 514.41 MB)
	var $multifile=false; // is that a multifiles torrent - set by UpdateSize()
	var $target=''; // target filename/dirname
	var $current_random_max=-1; // maximum block for random

// DecodeMeta :
// Return bool(true) = success
// Return string() = failed (error in string)
	function DecodeMeta($metadata,$hash=null) {
		$torrent=BDecode($metadata);
		if (!$torrent) return 'Could not parse downloaded torrent !';
		
		// check the hash
		$this->hash=sha1(BEncode($torrent['info']));
		if (!is_null($hash)) if (strtolower($this->hash)!=strtolower($hash)) return 'Downloaded torrent seems to be corrupt. (1)';
		
		// update our class' references
		$this->data=&$torrent;
		$this->info=&$this->data['info'];
		unset($torrent);
		
		// calculate size of torrent...
		if (!$this->UpdateSize()) return 'Could not get size of torrent data!';
		
		// check the length of the checksums part
		$count=ceil($this->sizeint / $this->info['piece length']);
		if ($count!=(strlen($this->info['pieces'])/20)) return 'Downloaded torrent seems to be corrupt. (2)';
		
		// make bitfield
		$this->bitfield=&new bitfield($count);
		
		// verify target path
		$GLOBALS['BtWindow']->label_filename->set_text('Downloading : '.$this->info['name']);
//		$tmp_path=$this->GetTempPath($this->multifile); // GetTempPath(true) returns a path in CWD
		$tmp_path=$this->GetTempPath(true); // GetTempPath(true) returns a path in CWD
		if (!$tmp_path) return 'Could not determine the target directory.';
		$this->target = $tmp_path.'/'.$this->info['name'];
		
		$res = $this->CheckFileStatus();
		if (is_string($res)) return $res;
		$GLOBALS['BtWindow']->gtk_pc_status($GLOBALS['TORRENT']->bitfield->done,$GLOBALS['TORRENT']->bitfield->size);
		return true;
	}
	
	function CloseAllFP() {
		foreach($this->fp as $fpinfo) {
			fclose($fpinfo[2]);
		}
		$this->fp=array();
	}
	
	function VerifyFileSizeAndSum(&$state) {
		$pos=&$state['pos'];
		$fdid=&$state['fd'];
		$blpos=&$state['blpos'];
		$calcbf=$state['calcbf']; // shall we recompute the bitfield?
		if (!isset($this->fp[$fdid])) {
			// always update the status of the bitfield at the end
			$this->current_random_max=($this->bitfield->size-1);
//			echo "BLPOS=$blpos\n";
			if ($blpos >= $this->current_random_max) {
				$this->bitfield->update_status();
				return false; // end of verification loop
			}
		} else {
			$fdinfo=$this->fp[$fdid];
			// FDINFO :
			// 0 = start position
			// 1 = length
			// 2 = fd
			$length=$fdinfo[1];
			$fp=$fdinfo[2];
			$gpos=$fdinfo[0]+$pos;
			// update random_max
			$this->current_random_max=floor($gpos/$this->info['piece length']);
		}
		
		if ($calcbf) {
			while($blpos < $this->current_random_max) {
				$blpos++;
				$j=$this->bt_check_piece($blpos);
				$this->bitfield->has_piece($blpos,$j,false);
				if ($j) {
					foreach($GLOBALS['PEERS'] as $key=>$peer) {
						if ($peer['state'] & STATE_ACTIVE) send_packet_4($peer['sockid'],$pnum);
					}
				}
			}
		}
		if (!isset($this->fp[$fdid])) return true;
		
		// get file size
		$stat=fstat($fp); // according to tests, the fstat function is *not* cached
		if ($stat['size']>$length) {
			// truncate the file and return true, it will give time to the hard drive
			// to sync.
			echo "File too big, truncated!\n";
			ftruncate($fp,$length);
			return true;
		} elseif($stat['size']==$length) {
			// the file have the right size. Just need to check it !
			if ($pos>=$length) {
				$pos=0;
				$fdid++;
				return true;
			}
			// let the system check the missing pieces !
			$pos+=$this->info['piece length'];
			return true;
		}
		// force filesize (should transparently allocate disk space.. or on linux not even allocate at all but
		// say that the file has this size, even if it's not really using it)
		if ($pos < $stat['size']) {
			$pos=min($stat['size'],$pos+65535);
			return true;
		}
		$pos+=65535;
		if ($pos > $length) $pos=$length;
		fseek($fp,$pos);
		ftruncate($fp,$pos);
		$stat=fstat($fp);
		if ($stat['size'] != $pos) {
			$GLOBALS['BtWindow']->gtk_error('Could not allocate required disk space (disk full?).');
			return false;
		}
		return true;
	}

	function CheckFileStatus() {
		if (!$this->multifile) {
			// mono-file torrent, easy to check...
			$GLOBALS['BtWindow']->gtk_action('Checking downloaded file...');
			$fp=@fopen($this->target,'r+b');
			if (!$fp) {
				touch($this->target);
				$fp=fopen($this->target,'r+b');
				if (!$fp) return 'Could not create temporary file.';
			}
			$this->fp[0]=array(0,$this->sizeint,$fp); // monofile~
		} else {
			// mutlifile meta file
			if (!is_dir($this->target)) {
				if (!@mkdir($this->target)) return 'Could not create output directory !';
			}
			$pos=0;
			foreach($this->info['files'] as $fid=>$fil) {
				$path=$fil['path'];
				$file=array_pop($path); // last part of path == filename
				$finalpath=$this->target.'/';
				foreach($path as $p) {
					// recursive mkdir
					$finalpath.=$p.'/';
					if (!is_dir($finalpath)) {
						if (!@mkdir($finalpath)) {
							return 'Could not create directory '.$finalpath;
						}
					}	
				}
				@touch($finalpath.$file);
				$fp=fopen($finalpath.$file,'r+b');
				if (!$fp) return 'Could not open '.$finalpath.$file;
				$this->fp[$fid]=array($pos,$fil['length'],$fp);
				$pos+=$fil['length'];
			}
		}
		$ok=false;
		if (!$this->multifile) $ok=$this->bitfield->loadbf($this->target.'.bitfield');
		$this->current_random_max=-1;
		// pass the parameter by reference, with a reference array
		$x=array();
		$y=array('pos'=>0,'fd'=>0,'blpos'=>-1,'calcbf'=>!$ok);
		foreach($y as $i=>$j) {
			$x[$i]=&$j;
			unset($j);
		}
		BtWindow::idle_add(array(&$this,'VerifyFileSizeAndSum'),$x);
	}

	function UpdateSize() {
		// get size of the loaded torrent file (read from metainfo)
		if (!isset($this->info['length'])) {
			if (!isset($this->info['files'])) return false;
			$size=0;
			foreach($this->info['files'] as $f) $size+=$f['length'];
			$this->sizeint=$size;
		} else {
			$this->sizeint=(int)$this->info['length'];
		}
		$this->multifile=(bool)(isset($this->info['files']));
		$this->size = $this->FormatSize($this->sizeint);
		return true;
	}

	function GetTempPath($cws=false) {
		if (IS_WINDOWS) {
			if (isset($_ENV['TEMP'])) {
				$env=$_ENV['TEMP'];
				if (get_magic_quotes_gpc()) $env=stripslashes($env);
				$tmp_path=str_replace('\\','/',$env);
			} elseif(isset($_ENV['TMP'])) {
				$env=$_ENV['TMP'];
				if (get_magic_quotes_gpc()) $env=stripslashes($env);
				$tmp_path=str_replace('\\','/',$env);
			} else {
				$tmp_path=str_replace('\\','/',getcwd());
			}
		} else {
			$tmp_path='/tmp';
		}
		if (($cws) || (!is_writable($tmp_path))) {
			$tmp_path=str_replace('\\','/',getcwd());
			if (!is_writable($tmp_path)) {
				return false;
			}
		}
		return $tmp_path;
	}

	function FormatSize($size) {
		$size_str='';
		$size_unit=array(
			'kB'=>1024,
			'MB'=>1024,
			'GB'=>1024,
		);
		$dunit='b';
		foreach($size_unit as $str=>$uspec) {
			if (($uspec*1.4)<$size) {
				$size=$size/$uspec;
				$dunit=$str;
			}
		}
		return sprintf('%01.2f '.$dunit,$size);
	}
	
	function read_piece($piece) {
		if ($piece>$this->current_random_max) return '';
		$len=$this->info['piece length'];
		$start=$len*$piece;
		// find in which file this part starts...
		$data='';
		foreach($this->fp as $fpid=>$fpinfo) {
			if (($fpinfo[0]+$fpinfo[1]) < $start) continue; // not the good fp
			$fpstart=$start-$fpinfo[0];
			if ($fpstart<0) $fpstart=0;
			fseek($fpinfo[2],$fpstart);
			$readlen=min($fpinfo[1]-$fpstart,$len);
			$rdata=fread($fpinfo[2],$readlen);
			$len-=strlen($rdata);
			$data.=$rdata;
			if ($len<=0) {
				return $data;
			}
		}
		return $data;
	}
	
	function write_piece($piece,$data) {
		if (!$this->bt_check_piece($piece,$data)) {
			echo "BAD CHECKSUM FOR #$piece!\n";
			var_dump($this->bt_check_piece($piece,$data));
			var_dump($this->bt_piece_size(200));
			return false; // checksum is somewhat invalid
		}
		// ok, we have $data to write as piece $piece..
		if ($piece>$this->current_random_max) return false; // can't write : disk space not available
		$len=strlen($data); // if last block, data may be smaller than "piece size"
		$start=($this->info['piece length'])*$piece;
		$num=0;
		foreach($this->fp as $fpid=>$fpinfo) {
			if (($fpinfo[0]+$fpinfo[1]) < $start) continue; // not the good fp
			$num++;
			$fpstart=$start-$fpinfo[0]; // $fpstart = begin of data in current file
			if ($fpstart<0) $fpstart=0; // if negative, then adjust
			fseek($fpinfo[2],$fpstart); // seek to $fpstart
			$writelen=min($fpinfo[1]-$fpstart,$len); // $writelen is which is lower : $len or $size of file minus seek position
			fwrite($fpinfo[2],substr($data,0,$writelen)); // write only $writelen bytes...
			$len-=$writelen; // ajust $len
			$data=substr($data,$writelen); // update write buffer
			if ($len<=0) {
				echo "Wrote part in $num files !\n";
				return $this->bt_check_piece($piece); // force on-disk recheck
			}
		}
		echo "WRITE FAILED!";
		return false;
	}

	function bt_check_piece($pieceid,$data=null) {
		if ($pieceid>=$this->bitfield->size) {
			echo "BT_CHECK_PIECE : $pieceid is invalid piece id\n";
			return false;
		}
		$psize=$this->info['piece length'];
		$sum=substr($this->info['pieces'],$pieceid*20,20);
		if (is_null($data)) {
			$data=$this->read_piece($pieceid);
		}
		if($data=='') return null;
		$data=sha1($data);
		if (strlen($data)!=20) $data=pack('H*',$data); // pack is slow but there's no other way
		return (bool)($sum==$data);
	}
	
	function bt_piece_size($pieceid) {
		if ($pieceid>=$this->bitfield->size) return false;
		$psize=$this->info['piece length'];
		if ($pieceid<($this->bitfield->size -1)) return $psize;
		// last piece is smaller... find size...
		$size=$psize*($this->bitfield->size -1);
		return ($this->sizeint-$size);
	}

}
