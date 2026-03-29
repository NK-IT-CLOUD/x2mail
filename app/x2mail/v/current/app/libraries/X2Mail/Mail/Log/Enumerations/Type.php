<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Log\Enumerations;

/**
 * @category MailSo
 * @package Log
 * @subpackage Enumerations
 */
enum Type: int
{
	case INFO = 6;       // \LOG_INFO
	case NOTICE = 5;     // \LOG_NOTICE
	case WARNING = 4;    // \LOG_WARNING
	case ERROR = 3;      // \LOG_ERR
	case DEBUG = 7;      // \LOG_DEBUG

	public const SECURE = self::INFO;
	public const NOTE = self::INFO;
	public const TIME = self::DEBUG;
	public const MEMORY = self::INFO;
	public const TIME_DELTA = self::INFO;
}
