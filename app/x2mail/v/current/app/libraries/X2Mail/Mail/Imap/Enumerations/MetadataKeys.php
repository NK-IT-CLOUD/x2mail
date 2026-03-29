<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2021 DJMaze
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
enum MetadataKeys: string
{
	// RFC 5464
	case ADMIN_SHARED   = '/shared/admin'; // Server
	case COMMENT        = '/private/comment'; // Mailbox
	case COMMENT_SHARED = '/shared/comment'; // Server & Mailbox

	// RFC 6154
	case SPECIALUSE = '/private/specialuse';

	// Kolab
	case KOLAB_CTYPE        = '/private/vendor/kolab/folder-type';
	case KOLAB_CTYPE_SHARED = '/shared/vendor/kolab/folder-type';
	case KOLAB_COLOR        = '/private/vendor/kolab/color';
	case KOLAB_COLOR_SHARED = '/shared/vendor/kolab/color';
	case KOLAB_NAME         = '/private/vendor/kolab/displayname';
	case KOLAB_NAME_SHARED  = '/shared/vendor/kolab/displayname';
	case KOLAB_UID_SHARED   = '/shared/vendor/kolab/uniqueid';
	case CYRUS_UID_SHARED   = '/shared/vendor/cmu/cyrus-imapd/uniqueid';
}
