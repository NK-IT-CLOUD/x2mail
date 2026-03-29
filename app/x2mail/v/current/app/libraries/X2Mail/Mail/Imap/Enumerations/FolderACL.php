<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2021 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * https://datatracker.ietf.org/doc/html/rfc2086
 * https://datatracker.ietf.org/doc/html/rfc4314#section-4
 */

namespace X2Mail\Mail\Imap\Enumerations;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Enumerations
 */
enum FolderACL: string
{
	/** RFC 2086 */
	// perform SETACL/DELETEACL/GETACL/LISTRIGHTS
	case ADMINISTER = 'a';
	// mailbox is visible to LIST/LSUB commands, SUBSCRIBE mailbox
	case LOOKUP = 'l';
	// SELECT the mailbox, perform STATUS
	case READ = 'r';
	// set or clear \SEEN flag via STORE, also set \SEEN during APPEND/COPY/FETCH BODY[...]
	case SEEN = 's';
	// set or clear flags other than \SEEN and \DELETED via STORE, also set them during APPEND/COPY
	case WRITE = 'w';
	// perform APPEND, COPY into mailbox
	case INSERT = 'i';
	// send mail to submission address for mailbox, not enforced by IMAP4 itself
	case POST = 'p';
	// CREATE new sub-mailboxes in any implementation-defined hierarchy
//	case CREATE_OLD = 'c';
	// STORE DELETED flag, perform EXPUNGE
//	case DELETED_OLD = 'd';
	/** RFC 4314 */
	// CREATE new sub-mailboxes in any implementation-defined hierarchy, parent mailbox for the new mailbox name in RENAME
	case CREATE = 'k';
	// DELETE mailbox, old mailbox name in RENAME
	case DELETE = 'x';
	// set or clear \DELETED flag via STORE, set \DELETED flag during APPEND/COPY
	case DELETED = 't';
	// perform EXPUNGE and expunge as a part of CLOSE
	case EXPUNGE = 'e';
}
