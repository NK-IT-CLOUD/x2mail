<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Base\Enumerations;

/**
 * @category MailSo
 * @package Base
 * @subpackage Enumerations
 */
enum Charset: string
{
	case UTF_8 = 'utf-8';
	case ISO_8859_1 = 'iso-8859-1';
	case ISO_2022_JP = 'iso-2022-jp';
}
