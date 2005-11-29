<?php

// Stats system

$stats_start=mytime();
$stats_update=0;

Gtk::timeout_add(250,'stats_idle');

function mytime() {
	list($usec, $sec) = explode(' ', microtime()); 
	return ((float)$usec + (float)$sec); 
}

function stats_idle() {
	if(defined('IN_ERROR')) return false;
	global $stats_update;
	$now=mytime();
	if($now==$stats_update) return true;
	$stats_update=$now;
	stats_update();
	return true;
}

function format_speed($count,$time) {
	if (is_null($count)) return 'N/A';
	if ($time==0) return 'N/A';
	$res=0;
	$speed=round((float)$count/(float)$time);
	$funit='B/s';
	$fstr='%01d';
	$unit=array('kB/s'=>1024,'MB/s'=>1024,'GB/s'=>1024);
	foreach($unit as $str=>$uspec) {
		if ($speed>($uspec*1.4)) {
			$fstr='%01.2f';
			$speed=$speed/$uspec;
			$funit=$str;
		}
	}
	return sprintf($fstr.' '.$funit,$speed);
}

function stats_update() {
	global $stats_update,$stats_start;
	$uptime=($stats_update-$stats_start);
	$uptime_str='';
	$time_var=array(86400=>' day',3600=>':',60=>':',1=>' ');
	foreach($time_var as $tspec=>$str) {
		if ($uptime<$tspec) {
			if (strlen($str)!=1) continue;
			$val=0;
		} else {
			$val=floor($uptime/$tspec);
			$uptime-=($val*$tspec);
		}
		if (strlen($str)==1) while(strlen($val)<2) $val='0'.$val;
		$uptime_str.=$val.$str;
		if (strlen($str)!=1) {
			if ($val!=1) $uptime_str.='s';
			$uptime_str.=' ';
		}
	}
	
	if (($stats_update) > ($GLOBALS['COUNTERS']['reset']+1)) {
		$diff=$stats_update-$GLOBALS['COUNTERS']['reset'];
		if ($GLOBALS['COUNTERS_']['val']>10) {
			$GLOBALS['COUNTERS_']=array(
				'down'=>$GLOBALS['COUNTERS_']['down'] / $GLOBALS['COUNTERS_']['val'],
				'up'=>$GLOBALS['COUNTERS_']['up'] / $GLOBALS['COUNTERS_']['val'],
				'val'=>1,
			);
		}
		$GLOBALS['COUNTERS_']=array(
			'down'=>$GLOBALS['COUNTERS_']['down']+$GLOBALS['COUNTERS']['down'],
			'up'=>$GLOBALS['COUNTERS_']['up']+$GLOBALS['COUNTERS']['up'],
			'val'=>$GLOBALS['COUNTERS_']['val']+$diff,
		);
		$GLOBALS['COUNTERS']=array(
			'up'=>0,
			'down'=>0,
			'reset'=>$stats_update,
		);
	}
	$speed_label='Download Speed : '.format_speed($GLOBALS['COUNTERS_']['down'], $GLOBALS['COUNTERS_']['val']).'  ';
	$speed_label.='Upload Speed : '.format_speed($GLOBALS['COUNTERS_']['up'], $GLOBALS['COUNTERS_']['val']);
	if (defined('DOWNLOADING')) $GLOBALS['BtWindow']->label_speed->set_text($speed_label);
	$GLOBALS['BtWindow']->label_size->set_text('Size : '.$GLOBALS['TORRENT']->size.' - '.$uptime_str);
	if (!is_null($GLOBALS['TORRENT']->bitfield)) {
		$GLOBALS['BtWindow']->gtk_pc_status($GLOBALS['TORRENT']->bitfield->done,$GLOBALS['TORRENT']->bitfield->size);
	}
	if (defined('DOWNLOADING')) {
		$num=count($GLOBALS['PEERS']);
		$GLOBALS['BtWindow']->gtk_action('Downloading file from '.$num.' host'.($num==1?'':'s').'.');
	}
}

