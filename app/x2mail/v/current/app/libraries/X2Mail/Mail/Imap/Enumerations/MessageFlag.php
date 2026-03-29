<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * https://www.iana.org/assignments/imap-jmap-keywords/imap-jmap-keywords.xhtml
 */

namespace X2Mail\Mail\Imap\Enumerations;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Enumerations
 */
enum MessageFlag: string
{
//	case RECENT = '\\Recent'; // IMAP4rev2 deprecated
	case SEEN = '\\Seen';
	case DELETED = '\\Deleted';
	case FLAGGED = '\\Flagged';
	case ANSWERED = '\\Answered';
	case DRAFT = '\\Draft';
	// https://datatracker.ietf.org/doc/html/rfc3503
	case MDNSENT = '$MDNSent';
	// https://datatracker.ietf.org/doc/html/rfc8457
	case IMPORTANT = '$Important';
	// https://datatracker.ietf.org/doc/html/rfc5788
	case FORWARDED = '$Forwarded';
	// https://datatracker.ietf.org/doc/html/rfc9051#section-2.3.2
	case JUNK = '$Junk';
	case NOTJUNK = '$NotJunk';
	case PHISHING = '$Phishing';
}
