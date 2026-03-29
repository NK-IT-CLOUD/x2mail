<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Mime\Enumerations;

/**
 * @category MailSo
 * @package Mime
 * @subpackage Enumerations
 */
enum DkimStatus: string
{
	case NONE = 'none';
	case PASS = 'pass';
	case FAIL = 'fail';
	case POLICY = 'policy';
	case NEUTRAL = 'neutral';
	case TEMP_ERROR = 'temperror';
	case PREM_ERROR = 'permerror';

	public static function verifyValue(string $sStatus) : bool
	{
		return self::tryFrom($sStatus) !== null;
	}

	public static function normalizeValue(string $sStatus) : string
	{
		$sStatus = \strtolower(\trim($sStatus));
		return (self::tryFrom($sStatus) ?? self::NONE)->value;
	}
}
