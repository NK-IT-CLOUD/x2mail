<?php

namespace X2Mail\Engine;

abstract class Integrity
{

	public static function phpVersion()
	{
		if (PHP_VERSION_ID < 80300) {
			return 'Your PHP version ('.PHP_VERSION.') is lower than the minimal required 8.3.0!';
		}
	}

	public static function phpExtensions()
	{
		$aRequirements = array(
			'openssl'  => extension_loaded('openssl'),
			'sodium'   => extension_loaded('sodium'),
			'mbstring' => extension_loaded('mbstring'),
			'Zlib'     => extension_loaded('zlib'),
			// enabled by default:
			'json'     => function_exists('json_decode'),
			'libxml'   => function_exists('libxml_use_internal_errors'),
			'dom'      => class_exists('DOMDocument'),
			'fileinfo' => extension_loaded('fileinfo')
		//	'phar'     => class_exists('PharData')
		);

		$aMissing = [];
		if (in_array(false, $aRequirements)) {
			foreach ($aRequirements as $sKey => $bValue) {
				if (!$bValue) {
					$aMissing[] = $sKey;
				}
			}
		}
		return $aMissing;
	}

}
