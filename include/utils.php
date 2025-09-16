<?php

/**
 * Some handy utility functions
 */

/**
 * Return a value from an associative array, or the supplied default
 * @param array $arr An array to look within for the supplied key
 * @param string $key The key to search for
 * @param mixed $default What to return if $arr[$key] is not set
 */ 
function get_or_default($arr, $key, $default) {
	if(isset($arr[$key])) {
		return $arr[$key];
	} else {
		return $default;
	}
}
