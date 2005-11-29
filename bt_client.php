<?php
// CONFIGURATION

//define('BT_HASH','9909dd3aa0f0e19fbfc54cd0cd38de79adedb505');
//define('BT_URL','http://www.fairyland-europe.com/downloads/Fsetup.exe.torrent');
//define('BT_HASH','33b18734d474a5992f91b8888f1b3379ea5fee6c');
define('BT_URL','http://bt.ff.st/33b18734d474a5992f91b8888f1b3379ea5fee6c.torrent');

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
	if (!extension_loaded('gtk')) {
		dl( 'php_gtk.' . PHP_SHLIB_SUFFIX) or die("Could not load php_gtk extention\n");
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
	require(PREFIX.'bt_gtk.php');
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

