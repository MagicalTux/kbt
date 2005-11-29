<?php

// BitField object

class bitfield {
	var $bitfield; // public
	var $size;
	var $size8;
	var $empty;
	var $full;
	var $done;
	var $bf;
	
	function bitfield($size=null) {
		if (is_null($size)) return;
		$this->size=$size;
		$this->size8=ceil($size/8);
		$this->bf=null;
		$this->bitfield=str_repeat("\0",$this->size8); // initialize an empty bitfield
	}
	
	function import_bitfield($data) {
		$this->bitfield=substr($data,0,$this->size8);
		// if imported bitfield is too small, complete it with NUL(0x00)
		if (strlen($this->bitfield) < $this->size8) $this->bitfield .= str_repeat("\0",$this->size8 - strlen($this->bitfield));
		$this->update_status();
		return true;
	}
	
	function leftbytes() {
		// calculate left bytes...
		$psize=$GLOBALS['TORRENT']->info['piece length'];
		if (is_null($this->done)) $this->update_status();
		$t=$this->size/8;
		if ($t==$this->size8) {
			// all parts of the file are same size !
			return $GLOBALS['TORRENT']->info['length']-($psize*$this->done);
		}
		if (!$this->has_piece($this->size-1)) {
			// we dont have the last piece...
			// so all pieces we have are same size.
			return ($GLOBALS['TORRENT']->sizeint)-($psize*$this->done);
		}
		$lpsiz=$GLOBALS['TORRENT']->info['length']-(($this->size-1)*$psize);
		return ($psize*($this->done-1))+$lpsiz;
	}
	
	function has_piece($num,$state=null,$update=true) {
		// first, find out position of this piece
		if ($num>$this->size) return null;
		$tmp=floor($num/8);
		$bit=chr(128>>($num % 8));
		$cur_state = (bool) ((($this->bitfield{$tmp}) & $bit)==$bit); // is this bit set ?
		if (!is_null($state)) {
			if ($state) {
				// set that bit
				$this->bitfield{$tmp} = $this->bitfield{$tmp} | $bit;
			} else {
				// unset that bit
				$this->bitfield{$tmp} = $this->bitfield{$tmp} & ~$bit;
			}
			if ($update) $this->update_status();
		}
		return $cur_state;
	}
	
	function random_unset_piece($max=null) {
		// return a random piece that we don't have
		if ($this->full) return null; // no piece available to download as we already have them all
		if (!is_null($max)) if ($max<0) return null;
		$val=rand(0,$this->size8-1); // start at a random point
		$val2=$val;
		$c=0;
		while(1) {
			$val++;
			$c++;
			if ($c>$this->size8) return null; // we did a loop (or more if $max is set), and nothing was found - return null
			if ($val>=$this->size8) $val=0;
			if ($this->bitfield{$val}=="\xff") continue;
			for($i=$val*8;$i<$val*8+8;$i++) {
				if (!is_null($max)) {
					if ($i>$max) {
						$val=0; break;
					}
				}
				$state=$this->has_piece($i);
				if (is_null($state)) break; // out of last byte (probably)
				if (!$state) return $i; // yeah! That's a free block !
			}
		}
	}
	
	function update_status() {
		// check again if the bitfield is complete.. or not
		$tmp1=floor($this->size / 8);
		$tmp2=chr((0xff<<(8-($this->size % 8))) & 0xff); // eg. 0b11111100
		// make sure other bits on last byte aren't set - if we have a lastbit 
		if ($tmp1!=$this->size8) $this->bitfield{$this->size8-1}=$this->bitfield{$this->size8-1} & $tmp2;
		$set=0;
		for($i=0;$i<=$this->size8;$i++) {
			$t=ord($this->bitfield{$i});
			if ($t & 0x01) $set++;
			if ($t & 0x02) $set++;
			if ($t & 0x04) $set++;
			if ($t & 0x08) $set++;
			if ($t & 0x10) $set++;
			if ($t & 0x20) $set++;
			if ($t & 0x40) $set++;
			if ($t & 0x80) $set++;
		}
		
		if($lb==$tmp2) {
			$set+=$this->size % 8;
		} elseif ($lb == "\0") {
			$set++;
		}
		if ($set) {
			// not empty
			$this->empty=false;
			$this->full=(bool)($set==$this->size);
		} else {
			$this->empty=true;
			$this->full=false;
		}
		$this->done=$set;
		if (!is_null($this->bf)) {
			$fp=@fopen($this->bf,'wb');
			if ($fp) {
				fwrite($fp,$this->bitfield);
				fclose($fp);
			}
		}
	}
	
	function compare(&$bitfield) {
		// will answer by :
		// 0 = bitfields isn't interesting
		// >0= this bitfield has n pieces I don't have
		
		if ($this->full) return 0;
		// compute mask
		$bfm=~($this->bitfield) & $bitfield; // thanks PHP for allowing such things :D
		// HeDontHave ~WeHave : 0 0 -> 0
		// HeDontHave ~WeDontHave : 0 1 -> 0
		// HeHave ~WeHave : 1 0 -> 0
		// HeHave ~WeDontHave : 1 1 -> 1
		// so bits which are set are only packets we don't have and he have
		
		// now, count set bytes :)
		$tmp=new bitfield($this->size);
		$tmp->import_bitfield($bfm);
		return $tmp->done;
	}
	
	function loadbf($fil) {
		$this->bf=$fil;
		$fp=@fopen($fil,'rb');
		if (!$fp) return false;
		$dat=fread($fp,$this->size8);
		fclose($fp);
		if (strlen($dat)!=$this->size8) return false;
		return $this->import_bitfield($dat);
	}
}
