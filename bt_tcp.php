<?php
// TCP functions
$GLOBALS['SOCKETS']=array();
$GLOBALS['COUNTERS']=array(
	'up'=>0,
	'down'=>0,
	'reset'=>time(),
);
$GLOBALS['COUNTERS_']=array(
	'up'=>NULL,
	'down'=>NULL,
	'val'=>0,
);
$GLOBALS['COUNT']=array(
	'up'=>NULL,
	'down'=>NULL,
);

function http_get($url,$callback,&$callback_param) {
	$url=parse_url($url);
	$port=(isset($url['port'])?$url['port']:80);
	if (!$port) $port=80;
	if ($url['scheme']!='http') return 'Error : This is not an HTTP protocol !';
	$buf ='GET '.$url['path'].($url['query']?'?'.$url['query']:'').' HTTP/1.0'."\r\n";
	$buf.='Host: '.$url['host']."\r\n";
	if (isset($url['user'])) {
		$buf.='Authorization: Basic '.base64_encode($url['user'].':'.$url['pass'])."\r\n";
	}
	$buf.='User-Agent: BtClient/1.0 (compatible; '.PHP_OS.'; PHP/'.PHP_VERSION.')'."\r\n";
	$buf.='Accept: */*'."\r\n";
	$buf.='Connection: close'."\r\n";
	$buf.="\r\n";
	$res=make_csocket($url['host'],$port,$buf,$callback,$callback_param);
	if (is_string($res)) return $res;
	return true;
}

function socket_wbuf($sockid,$data) {
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return false;
	$GLOBALS['SOCKETS'][$sockid]['wbuf'].=$data;
	return true;
}

function make_lsocket($callback,&$callback_param,$port=0,$ip='0.0.0.0') {
	// make listening socket
	$sock=socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	if (!$sock) return socket_strerror(socket_last_error()).' on socket_create (l)';
	if ($port==0) {
		$port=15000;
		while(!socket_bind($sock,$ip,$port)) {
			$port++;
			if ($port>15500) return socket_strerror(socket_last_error($sock)).' on socket_bind (l)';
		}
	} else {
		if (!socket_bind($sock,$ip,$port)) return socket_strerror(socket_last_error($sock)).' on socket_bind (l)';
	}
	if (!socket_listen($sock,5)) return socket_strerror(socket_last_error($sock)).' on socket_listen (l)';
	socket_set_nonblock($sock);
	$x=1;
	while(isset($GLOBALS['SOCKETS'][$x])) $x++;
	$GLOBALS['SOCKETS'][$x]=array();
	$s=&$GLOBALS['SOCKETS'][$x];
	$s['sock']=$sock;
	$s['ip']=$ip;
	$s['port']=$port;
	// tcp_accept : accept incoming connections (150ms)
	Gtk::timeout_add(150,'tcp_accept',array('sock'=>$sock,'sockid'=>$x,'callback'=>$callback,'callback_param'=>&$callback_param));
	return $x;
}

function make_csocket($host,$port,$buf,$callback,&$callback_param) {
	$sock=socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	if (!$sock) return socket_strerror(socket_last_error()).' on socket_create';
	$target=gethostbyname($host);
	socket_set_nonblock($sock);
	if (@socket_connect($sock, $target, $port)<0) {
		$errno=socket_last_error($sock);
		if ($errno!=10035) { // Windows: connect delayed (not important)
			return socket_strerror($errno).' on socket_connect';
		}
	}
	$x=1;
	while(isset($GLOBALS['SOCKETS'][$x])) $x++;
	$GLOBALS['SOCKETS'][$x]=array();
	$s=&$GLOBALS['SOCKETS'][$x];
	$s['sock']=$sock;
	$s['wbuf']=&$buf;
	$s['rbuf']='';
	$s['link']=time();
	// tcp_wait_ack : manage socket errors (connection lost)
//	Gtk::timeout_add(250,'tcp_wait_ack',array('sock'=>$sock,'sockid'=>$x));
	Gtk::idle_add('tcp_wait_ack',array('sock'=>$sock,'sockid'=>$x));
	// tcp_write : send data once it's possible
//	Gtk::timeout_add(100,'tcp_write',array('sock'=>$sock,'buf'=>&$buf,'sockid'=>$x));
	Gtk::idle_add('tcp_write',array('sock'=>$sock,'buf'=>&$buf,'sockid'=>$x));
	// tcp_read : read data after we sent the query
//	Gtk::timeout_add(50,'tcp_read',array('sock'=>$sock,'sockid'=>$x,'buf'=>&$s['rbuf'],'callback'=>$callback,'callback_param'=>&$callback_param));
	Gtk::idle_add('tcp_read',array('sock'=>$sock,'sockid'=>$x,'buf'=>&$s['rbuf'],'callback'=>$callback,'callback_param'=>&$callback_param));
	return $x;
}

function kill_socket($sockid) {
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return;
	@socket_close($GLOBALS['SOCKETS'][$sockid]['sock']);
	if (isset($GLOBALS['SOCKETS'][$sockid]['peer'])) {
		$key=$GLOBALS['SOCKETS'][$sockid]['peer'];
//		var_dump(debug_backtrace());
//		echo "Closing socket for $key \n";
		unset($GLOBALS['PEERS'][$key]);
	}
	unset($GLOBALS['SOCKETS'][$sockid]);
	return true;
}

// This function will wait for a TCP connection to be established
function tcp_wait_ack($info) {
	$sock=$info['sock'];
	$sockid=$info['sockid'];
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return false; // shutdown this socket !
	if (isset($info['callback'])) {
		$callback=$info['callback'];
		$callback_param=$info['callback_param'];
	} else {
		$callback=null;
	}
	if ($sock<=0) return false;
	if (defined('IN_ERROR')) {
		kill_socket($sockid);
		return false;
	}
	$w=$e=array($sock);
	$ret=socket_select($r=null,$w,$e,0);
	if ($ret===false) {
		// error !
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_select [wait_ack]');
		return;
	}
	if ($ret<=0) {
		$dur=time()-$GLOBALS['SOCKETS'][$sockid]['link'];
		if ($dur>60) {
			echo 'Socket #'.$sockid.' has timed out while trying to connect !'."\n";
			kill_socket($sockid);
			return false;
		}
		return true; // need to wait more...
	}
	// yay we got connected !
	if ($w) {
		// call the callback !
		if (!is_null($callback)) $callback($sock,$callback_param);
		return false;
	} elseif($e) {
		kill_socket($sockid);
		return;
	} else {
		return true;
	}
}

// tcp_write: write data to a socket
function tcp_write(&$info) {
	$sock=$info['sock'];
	$buf=&$info['buf'];
	$sockid=$info['sockid'];
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return false; // shutdown this socket !
	if (!$buf) return true;
	if (defined('IN_ERROR')) {
		kill_socket($sockid);
		return false;
	}
	$w=array($sock);
	$ret=socket_select($r=null,$w,$e=null,0);
	if ($ret===false) {
		// error !
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_select [write]');
		return;
	}
	if ($ret<=0) return true; // can't write yet !
	// ok, we can send data !
	$ret=socket_write($sock,$buf);
	if ($ret===false) {
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_write');
		return;
	}
	$GLOBALS['COUNTERS']['up']+=$ret;
	$GLOBALS['COUNT']['up']+=$ret;
	$buf=substr($buf,$ret);
	if ($buf===false) $buf='';
	return true;
}

function tcp_read(&$info) {
	$sock=$info['sock'];
	$buf=&$info['buf'];
	$sockid=$info['sockid'];
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return false; // shutdown this socket !
	if (isset($info['callback'])) {
		$callback=$info['callback'];
		$callback_param=$info['callback_param'];
	} else {
		$callback=null;
	}
	if (defined('IN_ERROR')) {
		kill_socket($sockid);
		return false;
	}
	$r=array($sock);
	$ret=socket_select($r,$w=null,$e=null,0);
	if ($ret===false) {
		// error !
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_select [read]');
		return;
	}
	if ($ret<=0) return true; // nothing to read
	// ok, data is waiting to be picked up !
	$eof=false;
	$res=@socket_read($sock, 256*1024, PHP_BINARY_READ);
	if ($res===false) {
		echo socket_strerror(socket_last_error($sock)).' on socket_read';
		kill_socket($sockid);
		return false;
	}
	if ($res==='') $eof=true;
	$GLOBALS['COUNTERS']['down']+=strlen($res);
	$GLOBALS['COUNT']['down']+=strlen($res);
	$buf.=$res;
	$ret=true;
	if (!is_null($callback)) {
		if ($eof) kill_socket($sockid);
		$ret=$callback($buf,$eof,$callback_param,$sockid);
	} else {
		$buf='';
	}
	if (!$ret) {
		kill_socket($sockid);
		return false;
	}
	return $ret;
}

//Gtk::timeout_add(150,'tcp_accept',array('sock'=>$sock,'sockid'=>$x,'callback'=>$callback,'callback_param'=>&$callback_param));
//socket_set_nonblock($sock);
function tcp_accept(&$info) {
	$sock=$info['sock'];
	$sockid=$info['sockid'];
	if (!isset($GLOBALS['SOCKETS'][$sockid])) return false; // shutdown this socket !
	if (isset($info['callback'])) {
		$callback=$info['callback'];
		$callback_param=$info['callback_param'];
	} else {
		$callback=null;
	}
	if (defined('IN_ERROR')) {
		kill_socket($sockid);
		return false;
	}
	$r=array($sock);
	$ret=socket_select($r,$w=null,$e=null,0);
	if ($ret===false) {
		// error !
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_select [accept]');
		return;
	}
	if ($ret<=0) return true; // no new connection
	// ok, let's accept now ! :D
	$sock2=socket_accept($sock);
	if ($sock2===false) {
		$GLOBALS['BtWindow']->gtk_error(socket_strerror(socket_last_error($sock)).' on socket_accept');
		return;
	}
	if (!$callback) {
		// no callback to accept this connection
		@socket_close($sock2);
		return true;
	}
	$callback($sock2,$callback_param);
	return true;
}
