<?php
// CONFIGURATION

define('BT_URL','http://cdimage.debian.org/debian-cd/3.1_r2/i386/bt-cd/debian-31r2-i386-binary-1.iso.torrent');

// Tweak those values for better performances
define('MAX_WORKING_PIECES',20);
define('MAX_QUERY_PER_PEER',25);

//DEBUG echo 'Remote bootstrap loaded. Now loading DLLs....'."\n";
// You can define PREFIX to an url
if (!defined('PREFIX')) define('PREFIX','./');

if (strpos(strtolower(PHP_OS),'win')!==false) {
	define('IS_WINDOWS',true);
} else {
	define('IS_WINDOWS',false);
}

// load required extentions
#if (IS_WINDOWS) {
#	if (!extension_loaded('Win32 API')) {
#		dl( 'php_w32api.' . PHP_SHLIB_SUFFIX) or die("Could not load php_w32api extention\n");
#	}
#} else {
	if ((!extension_loaded('gtk')) && (!extension_loaded('php-gtk'))) {
		dl( 'php_gtk.' . PHP_SHLIB_SUFFIX) or dl( 'php_gtk2.' . PHP_SHLIB_SUFFIX) or die("Could not load php_gtk extention\n");
	}
#}
if (!extension_loaded('sockets')) {
	dl( 'php_sockets.' . PHP_SHLIB_SUFFIX) or die("Could not load php_sockets extention\n");
}

if (!function_exists('socket_create')) {
	die('Serious bug !');
}
set_time_limit(0);

$GLOBALS['TORRENT']=array(
	'size'=>'UNKNOWN',
);

//DEBUG echo "Downloading bittorrent client ...\n";
require(PREFIX.'bt_funcs.php');
#if (!IS_WINDOWS) {
	if (extension_loaded('php-gtk')) {
		require(PREFIX.'bt_gtk2.php');
	} else {
		require(PREFIX.'bt_gtk.php');
	}
#} else {
#	require(PREFIX.'bt_w32api.php');
#}
require(PREFIX.'bt_tcp.php');
require(PREFIX.'bt_bencoding.php');
require(PREFIX.'bt_bitfield.php');
require(PREFIX.'bt_metafile.php');
require(PREFIX.'bt_stats.php');
require(PREFIX.'bt_init.php');
require(PREFIX.'bt_peer.php');
require(PREFIX.'bt_parts.php');
define('PEER_ID','-KBT-01-'.code(12));

//DEBUG echo "Init done !\n";
Gtk::main();

