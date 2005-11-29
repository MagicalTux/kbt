<?php

// various functions for various usages

function code($nc,$str='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') {
	$res='';
	while($nc-->0) $res.=$str{rand(0,strlen($str)-1)};
	return $res;
}

