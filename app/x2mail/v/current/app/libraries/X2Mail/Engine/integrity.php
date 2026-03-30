<?php

namespace X2Mail\Engine;

abstract class Integrity
{

	/**
	 * Called by https://webmail.tld/?/Test
	 */
	public static function test()
	{
		$result = static::phpVersion();
		if ($result) {
			echo '<p style="color: red">' . $result . '</p>';
			return;
		}

		$result = static::phpExtensions();
		if ($result) {
			echo '<p>The following PHP extensions are not available in your PHP configuration!</p>';
			echo '<ul><li>' . \implode('</li>li><li>', $result) . '</li></ul>';
		}

/*
		echo '<div>'.APP_VERSION_ROOT_PATH.'static directory permissions: ' . substr(sprintf('%o', fileperms(APP_VERSION_ROOT_PATH . 'static')), -4) . '</div>';
		echo '<div>'.APP_VERSION_ROOT_PATH.'themes directory permissions: ' . substr(sprintf('%o', fileperms(APP_VERSION_ROOT_PATH . 'themes')), -4) . '</div>';
*/

		$uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
			. \X2Mail\Engine\Utils::WebVersionPath();
		$HTTP = \X2Mail\Engine\HTTP\Request::factory();
		$files = [
			'static/css/app.css',
			'static/js/libs.js',
			'static/js/app.js',
			'static/js/openpgp.js',
		];
		foreach ($files as $file) {
			echo "<h2>{$uri}{$file}</h2>";
			$response = $HTTP->doRequest('HEAD', $uri . $file);
			echo '<details><summary>Status: ' . $response->status . '</summary><pre>' . \print_r($response->headers, 1) . '</pre></details>';
			$size = \filesize(APP_VERSION_ROOT_PATH.$file);
			if ($size == intval($response->getHeader('content-length'))) {
				echo '<div>content-length matches size ' . $size . '</div>';
			} else {
				echo '<div style="color: red">content-length mismatch, should be: ' . $size . '</div>';
			}
		}
	}

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
