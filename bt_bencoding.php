<?php

/*

	Programming info

All functions output a small array, which we'll call $return for now.

$return[0] is the data expected of the function
$return[1] is the offset over the whole bencoded data of the next
           piece of data.

numberdecode returns [0] as the integer read, and [1]-1 points to the
symbol that was interprented as the end of the interger (either "e" or
":"). 
numberdecode is used for integer decodes both for i11e and 11:hello there
so it is tolerant of the ending symbol.

decodelist returns $return[0] as an integer indexed array like you would use in C
for all the entries. $return[1]-1 is the "e" that ends the list, so [1] is the next
useful byte.

decodeDict returns $return[0] as an array of text-indexed entries. For example,
$return[0]["announce"] = "http://www.whatever.com:6969/announce";
$return[1]-1 again points to the "e" that ends the dictionary.

decodeEntry returns [0] as an integer in the case $offset points to
i12345e or a string if $offset points to 11:hello there style strings.
It also calls decodeDict or decodeList if it encounters a d or an l.


Known bugs:
- The program doesn't pay attention to the string it's working on.
  A zero-sized or truncated data block will cause string offset errors
  before they get rejected by the decoder. This is worked around by
  suppressing errors.

*/


function numberdecode($wholefile, $start)
{
	$ret[0] = 0;
	$offset = $start;

	// Funky handling of negative numbers and zero
	$negative = false;
	if ($wholefile[$offset] == '-')
	{
		$negative = true;
		$offset++;
	}
	if ($wholefile[$offset] == '0')
	{
		$offset++;
		if ($negative)
			return array(false);
		if ($wholefile[$offset] == ':' || $wholefile[$offset] == 'e')
		{
			$offset++;
			$ret[0] = 0;
			$ret[1] = $offset;
			return $ret;
		}
		return array(false);
	}
	while (true)
	{

		if ($wholefile[$offset] >= '0' && $wholefile[$offset] <= '9')
		{
			
			$ret[0] *= 10;
			$ret[0] += ord($wholefile[$offset]) - ord("0");
			$offset++;
		}
		// Tolerate : or e because this is a multiuse function
		else if ($wholefile[$offset] == 'e' || $wholefile[$offset] == ':')
		{
			$ret[1] = $offset+1;
			if ($negative)
			{
				if ($ret[0] == 0)
					return array(false);
				$ret[0] = - $ret[0];
			}
			return $ret;
		}
		else
			return array(false);
	}

}

function decodeEntry($wholefile, $offset=0)
{
	if ($wholefile[$offset] == 'd')
		return decodeDict($wholefile, $offset);
	if ($wholefile[$offset] == 'l')
		return decodelist($wholefile, $offset);
	if ($wholefile[$offset] == "i")
	{
		$offset++;
		return numberdecode($wholefile, $offset);
	}
	// String value: decode number, then grab substring
	$info = numberdecode($wholefile, $offset);
	if ($info[0] === false)
		return array(false);
	$ret[0] = substr($wholefile, $info[1], $info[0]);
	$ret[1] = $info[1]+strlen($ret[0]);
	return $ret;
}

function decodeList($wholefile, $start)
{
	$offset = $start+1;
	$i = 0;
	if ($wholefile[$start] != 'l')
		return array(false);
	$ret = array();
	while (true)
	{
		if ($wholefile[$offset] == 'e')
			break;
		$value = decodeEntry($wholefile, $offset);
		if ($value[0] === false)
			return array(false);
		$ret[$i] = $value[0];
		$offset = $value[1];
		$i ++;
	}

	// The empy list is an empty array. Seems fine.
	$final[0] = $ret;
	$final[1] = $offset+1;
	return $final;



}

// Tries to construct an array
function decodeDict($wholefile, $start=0)
{
	$offset = $start;
	if ($wholefile[$offset] == 'l')
		return decodeList($wholefile, $start);
	if ($wholefile[$offset] != 'd')
		return false;
	$ret = array();
	$offset++;
	while (true)
	{	
		if ($wholefile[$offset] == 'e')
		{
			$offset++;
			break;
		}
		$left = decodeEntry($wholefile, $offset);
		if (!$left[0])
			return false;
		$offset = $left[1];
		if ($wholefile[$offset] == 'd')
		{
			// Recurse
			$value = decodedict($wholefile, $offset);
			if (!$value[0])
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset= $value[1];
			continue;
		}
		else if ($wholefile[$offset] == 'l')
		{
			$value = decodeList($wholefile, $offset);
			if (!$value[0] && is_bool($value[0]))
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset = $value[1];
		}
		else
		{
 			$value = decodeEntry($wholefile, $offset);
			if ($value[0] === false)
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset = $value[1];
		}
	}
	if (empty($ret))
		$final[0] = true;
	else
		$final[0] = $ret;
	$final[1] = $offset;
   	return $final;


}

// Use this function. eg:  BDecode("d8:announce44:http://www. ... e");
function BDecode($wholefile)
{
	$return = @decodeEntry($wholefile);
	return $return[0];
}



// Dictionary keys must be sorted. foreach tends to iterate over the order
// the array was made, so we make a new one in sorted order. :)
function makeSorted($array)
{
	$i = 0;

	// Shouldn't happen!
	if (empty($array))
		return $array;

	foreach($array as $key => $value)
		$keys[$i++] = stripslashes($key);
	sort($keys);
	for ($i=0 ; isset($keys[$i]); $i++)
		$return[addslashes($keys[$i])] = $array[addslashes($keys[$i])];
	return $return;
}

// Encodes strings, integers and empty dictionaries.
// $unstrip is set to true when decoding dictionary keys
function encodeEntry($entry, &$fd, $unstrip = false)
{
	if (is_bool($entry))
	{
		$fd .= "de";
		return;
	}
	if (is_int($entry))
	{
		$fd .= "i".$entry."e";
		return;
	}
	if ($unstrip)
		$myentry = stripslashes($entry);
	else
		$myentry = $entry;
	$length = strlen($myentry);
	$fd .= $length.":".$myentry;
	return;
}

// Encodes lists
function encodeList($array, &$fd)
{
	$fd .= "l";

	// The empty list is defined as array();
	if (empty($array))
	{
		$fd .= "e";
		return;
	}
	for ($i = 0; isset($array[$i]); $i++)
		decideEncode($array[$i], $fd);
	$fd .= "e";
}

// Passes lists and dictionaries accordingly, and has encodeEntry handle
// the strings and integers.
function decideEncode($unknown, &$fd)
{
	if (is_array($unknown))
	{
		if (isset($unknown[0]) || empty($unknown))
			return encodeList($unknown, $fd);
		else
			return encodeDict($unknown, $fd);
	}
	encodeEntry($unknown, $fd);
}

// Encodes dictionaries
function encodeDict($array, &$fd)
{
	$fd .= "d";
	if (is_bool($array))
	{
		$fd .= "e";
		return;
	}
	// NEED TO SORT!
	$newarray = makeSorted($array);
	foreach($newarray as $left => $right)
	{
		encodeEntry($left, $fd, true);
		decideEncode($right, $fd);
	}
	$fd .= "e";
	return;
}

// Use this function in your own code.
function BEncode($array)
{
	$string = "";
	decideEncode($array, $string);
	return $string;
}

