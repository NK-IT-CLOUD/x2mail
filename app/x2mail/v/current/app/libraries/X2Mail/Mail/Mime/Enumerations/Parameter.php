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
enum Parameter: string
{
	case CHARSET = 'charset';
	case NAME = 'name';
	case FILENAME = 'filename';
	case FORMAT = 'format';
	case BOUNDARY = 'boundary';
	case PROTOCOL = 'protocol';
}
