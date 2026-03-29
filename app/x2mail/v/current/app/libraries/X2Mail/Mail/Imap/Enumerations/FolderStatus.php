<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Imap\Enumerations;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Enumerations
 */
enum FolderStatus: string
{
	// RFC 3501
	case MESSAGES = 'MESSAGES';
//	case RECENT = 'RECENT'; // IMAP4rev2 deprecated
	case UIDNEXT = 'UIDNEXT';
	case UIDVALIDITY = 'UIDVALIDITY';
	case UNSEEN = 'UNSEEN';
	// RFC 4551
	case HIGHESTMODSEQ = 'HIGHESTMODSEQ';
	// RFC 7889
	case APPENDLIMIT = 'APPENDLIMIT';
	// RFC 8438
	case SIZE = 'SIZE';
	// RFC 8474
	case MAILBOXID = 'MAILBOXID';
}
