<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Net\Enumerations;

/**
 * @category MailSo
 * @package Net
 * @subpackage Enumerations
 */
enum ConnectionSecurityType: int
{
	case NONE = 0;
	case SSL = 1;
	case STARTTLS = 2;
	case AUTO_DETECT = 9;

	public const TLS = self::SSL; // alias

	public static function UseSSL(int $iPort, int $iSecurityType) : bool
	{
		$iPort = (int) $iPort;
		$iResult = $iSecurityType;
		if (self::AUTO_DETECT->value === $iSecurityType) {
			switch (true)
			{
				case 993 === $iPort:
				case 995 === $iPort:
				case 465 === $iPort:
					$iResult = self::SSL->value;
					break;
			}
		}

		if (self::SSL->value === $iResult && !\in_array('ssl', \stream_get_transports())) {
			$iResult = self::NONE->value;
		}

		return self::SSL->value === $iResult;
	}
}
