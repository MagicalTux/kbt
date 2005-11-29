<?php
$GLOBALS['BtWindow'] = &new BtWindow;

class BtWindow {
	var $window;
	var $mainvbox;
	var $label_action;
	var $label_filename;
	var $label_size;
	var $label_speed;
	var $label_tot;
	var $bar;
	var $cancel_button;
	var $gtk_shutdown=array();
	
	function BtWindow() {
		// window
		$this->window = &new GtkWindow;
		$this->window->set_border_width(5);
		$this->window->set_usize(450,220);
		$this->window->set_title("Cyberjoueurs Bittorrent Downloader");
		$this->window->connect('delete_event', array(&$this,'bt_quit_cleanup'));
		
		// main vertical box
		$this->mainvbox = &new GtkVBox(false, 10);
		$this->mainvbox->set_border_width(3);
		
		// action label
		$this->label_action = &new GtkLabel('Please wait while the client is loading...');
		$this->label_action->set_alignment(0,0);
		
		// filename label
		$this->label_filename = &new GtkLabel('Downloading : -');
		$this->label_filename->set_alignment(0,0);
		
		// size label
		$this->label_size = &new GtkLabel('Size: UNKNOWN');
		$this->label_size->set_alignment(0,0);
		
		// speed label
		$this->label_speed = &new GtkLabel('Download Speed : N/A  Upload Speed : N/A');
		$this->label_speed->set_alignment(0,0);
		
		// tot label
		$this->label_tot = &new GtkLabel('Download total : 0 MB  Upload total : 0 MB  Share Ratio : N/A');
		$this->label_tot->set_alignment(0,0);
		
		// empty label
		$empty = &new GtkLabel('');
		
		// progress bar
		$adj=&New GtkAdjustment(0, 0, 100, 1, 1, 1);
		$this->bar=&New GtkProgressBar($adj);
		$this->bar->Set_Activity_Mode(false);
		$this->bar->Show();
		
		// cancel button
		$this->cancel_button=&new GtkButton('Cancel');
		$this->cancel_button->connect('clicked',array(&$this,'bt_quit_cleanup'));
		
		$this->mainvbox->pack_start($this->label_action,false,false);
		$this->mainvbox->pack_start($this->bar,false,false);
		$this->mainvbox->pack_start($this->label_filename,false,false);
		$this->mainvbox->pack_start($this->label_size,false,false);
		$this->mainvbox->pack_start($this->label_speed,false,false);
		
		$this->mainvbox->pack_start($empty,true,true);
		
		$this->mainvbox->pack_start($this->cancel_button,false,false);
		
		$this->window->add($this->mainvbox);
		
		$this->window->show_all();
	}
	
	function hide_all() {
		$this->window->hide_all();
		$k=0; // this will prevent an infinite loop
		while ((($k++)<6) and (gtk::events_pending())) gtk::main_iteration();
	}
	
	function gtk_action($str) {
		$this->label_action->set_text($str);
		// invoke events (well... invoke pending events ... it means the redraw() event)
		$k=0; // this will prevent an infinite loop
		while ((($k++)<6) and (gtk::events_pending())) gtk::main_iteration();
	}
	
	function gtk_pc_status($cur,$max) {
		if($max==0) {
			$cur=0; $max=1;
		}
		$this->bar->set_percentage($cur/$max);
		// invoke events (well... invoke pending events ... it means the redraw() event)
		$k=0; // this will prevent an infinite loop
		while ((($k++)<6) and (gtk::events_pending())) gtk::main_iteration();
	}
	
	function gtk_error($str) {
		// workaround to change label color
		// http://aspn.activestate.com/ASPN/Mail/Message/php-gtk-general/2253469
		$red=&new GdkColor('#FF0000');
		$style=$this->label_filename->style;
		$style=$style->copy();
		$style->fg[0] = $red;
		$this->label_filename->set_style($style);
		// change text
		$this->label_filename->set_text($str);
		echo 'Fatal error : '.$str."\n";
		// define IN_ERROR so all events will stop
		define('IN_ERROR',true);
	}
	
	function timeout_add($time,$func,$data=null) {
		return Gtk::timeout_add($time,$func,$data);
	}
	
	function idle_add($func,$data=null) {
		return Gtk::idle_add($func,$data);
	}
	
	// Gtk shutdown function
	function bt_quit_cleanup() {
		$todo=array();
		foreach($this->gtk_shutdown as $name=>$func) $todo[]=$name;
		foreach($todo as $name) {
			$func=$this->gtk_shutdown[$name];
			unset($this->gtk_shutdown[$name]);
			if (function_exists($func)) $res=$func();
			if (!$res) return true;
		}
		Gtk::main_quit();
		exit;
		return true;
	}
	
	function gtk_shutdown_func($func,$name) {
		$this->gtk_shutdown[$name]=$func;
	}
}

