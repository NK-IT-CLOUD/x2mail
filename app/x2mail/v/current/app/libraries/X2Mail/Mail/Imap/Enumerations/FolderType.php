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
enum FolderType: int
{
	case USER = 0;
	case INBOX = 1;
	case SENT = 2;
	case DRAFTS = 3;
	case JUNK = 4;
	case TRASH = 5;
	case ARCHIVE = 6;
	case IMPORTANT = 10;
	case FLAGGED = 11;
	case ALL = 13;

	// TODO: X2Mail
	case TEMPLATES = 19;

	// Kolab
	case CONFIGURATION = 20;
	case CALENDAR = 21;
	case CONTACTS = 22;
	case TASKS    = 23;
	case NOTES    = 24;
	case FILES    = 25;
	case JOURNAL  = 26;
}
