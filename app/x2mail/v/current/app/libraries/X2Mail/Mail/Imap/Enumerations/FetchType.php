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
 *
 * https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.5
 */
enum FetchType: string
{
	// Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE)
	case FAST = 'FAST';
	// Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)
	case ALL = 'ALL';
	// Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)
	case FULL = 'FULL';

	case HEADER = 'HEADER'; // ([RFC-2822] header of the message)
	case TEXT   = 'TEXT';   // ([RFC-2822] text body of the message)
	case MIME   = 'MIME';   // ([MIME-IMB] header)

	// Non-extensible form of BODYSTRUCTURE
	case BODY = 'BODY';
	// An alternate form of BODY[<section>] that does not implicitly set the \Seen flag.
	case BODY_PEEK = 'BODY.PEEK';
	// The text of a particular body section.
	case BODY_HEADER = 'BODY[HEADER]';
	case BODY_HEADER_PEEK = 'BODY.PEEK[HEADER]';
	case BODYSTRUCTURE = 'BODYSTRUCTURE';
	case ENVELOPE = 'ENVELOPE';
	case FLAGS = 'FLAGS';
	case INTERNALDATE = 'INTERNALDATE';
//	case RFC822 = 'RFC822'; // Functionally equivalent to BODY[]
//	case RFC822_HEADER = 'RFC822.HEADER'; // Functionally equivalent to BODY.PEEK[HEADER]
	case RFC822_SIZE = 'RFC822.SIZE';
//	case RFC822_TEXT = 'RFC822.TEXT'; // Functionally equivalent to BODY[TEXT]
	case UID = 'UID';
	// RFC 3516
	case BINARY = 'BINARY';
	case BINARY_PEEK = 'BINARY.PEEK';
	case BINARY_SIZE = 'BINARY.SIZE';
	// RFC 4551
	case MODSEQ = 'MODSEQ';
	// RFC 8474
	case EMAILID = 'EMAILID';
	case THREADID = 'THREADID';
	// RFC 8970
	case PREVIEW = 'PREVIEW';

	public static function BuildBodyCustomHeaderRequest(array $aHeaders, bool $bPeek = true): string
	{
		if (\count($aHeaders)) {
			$aHeaders = \array_map(fn($sHeader) => \strtoupper(\trim($sHeader)), $aHeaders);
			return ($bPeek ? self::BODY_PEEK->value : self::BODY->value)
				. '[HEADER.FIELDS (' . \implode(' ', $aHeaders) . ')]';
		}
		return '';
	}
}
