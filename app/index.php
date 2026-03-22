<?php

if (!defined('APP_VERSION'))
{
	// Read version from NC app manifest (single source of truth)
	$infoXml = \dirname(__DIR__) . '/appinfo/info.xml';
	$version = 'current';
	if (\is_file($infoXml)) {
		\preg_match('/<version>([^<]+)</', \file_get_contents($infoXml), $m);
		$version = $m[1] ?? 'current';
	}
	define('APP_VERSION', $version);
	define('APP_INDEX_ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
}

// Use static 'current' path — no rename needed on version bumps
$includePath = APP_INDEX_ROOT_PATH . 'snappymail/v/current/include.php';
if (\file_exists($includePath))
{
	include $includePath;
}
else
{
	echo '[105] Missing snappymail/v/current/include.php';
	\is_callable('opcache_invalidate') && \opcache_invalidate(__FILE__, true);
	exit(105);
}
