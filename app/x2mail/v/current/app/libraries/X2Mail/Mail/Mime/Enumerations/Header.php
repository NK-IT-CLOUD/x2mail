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
enum Header: string
{
	case DATE = 'Date';
	case RECEIVED = 'Received';

	case SUBJECT = 'Subject';

	case TO = 'To';
	case FROM = 'From';
	case CC = 'Cc';
	case BCC = 'Bcc';
	case REPLY_TO = 'Reply-To';
	case SENDER = 'Sender';
	case RETURN_PATH = 'Return-Path';
	case DELIVERED_TO = 'Delivered-To';

	case MESSAGE_ID = 'Message-ID';
	case IN_REPLY_TO = 'In-Reply-To';
	case REFERENCES = 'References';
	case X_DRAFT_INFO = 'X-Draft-Info';
	case X_ORIGINATING_IP = 'X-Originating-IP';

	case CONTENT_TYPE = 'Content-Type';
	case CONTENT_TRANSFER_ENCODING = 'Content-Transfer-Encoding';
	case CONTENT_DISPOSITION = 'Content-Disposition';
	case CONTENT_DESCRIPTION = 'Content-Description';
	case CONTENT_ID = 'Content-ID';
//	case CONTENT_BASE = 'Content-Base'; // rfc2110
	case CONTENT_LOCATION = 'Content-Location';

	case RECEIVED_SPF = 'Received-SPF';
	case AUTHENTICATION_RESULTS = 'Authentication-Results';
	case X_DKIM_AUTHENTICATION_RESULTS = 'X-DKIM-Authentication-Results';

	case DKIM_SIGNATURE = 'DKIM-Signature';
	case DOMAINKEY_SIGNATURE = 'DomainKey-Signature';

	// SpamAssassin
	case X_SPAM_FLAG     = 'X-Spam-Flag';     // YES/NO
	case X_SPAM_LEVEL    = 'X-Spam-Level';    // *******
	case X_SPAM_STATUS   = 'X-Spam-Status';   // Yes|No
	case X_SPAM_BAR      = 'X-Spam-Bar';      // ++ | --
	case X_SPAM_REPORT   = 'X-Spam-Report';
	case X_SPAM_INFO     = 'X-Spam-Info';     // v4.0.0
	// Rspamd
	case X_SPAMD_RESULT  = 'X-Spamd-Result';  // default: False [7.13 / 9.00],
	case X_SPAMD_BAR     = 'X-Spamd-Bar';     // +++++++
	// Bogofilter
	case X_BOGOSITY      = 'X-Bogosity';
	// Unknown
	case X_SPAM_CATEGORY = 'X-Spam-Category'; // SPAM|LEGIT
	case X_SPAM_SCORE    = 'X-Spam-Score';    // 0
	case X_HAM_REPORT    = 'X-Ham-Report';
	case X_MICROSOFT_ANTISPAM = 'x-microsoft-antispam';

//	case X_QUARANTINE_ID = 'X-Quarantine-ID';
	// Rspamd
	case X_VIRUS = 'X-Virus';
	// ClamAV
	case X_VIRUS_SCANNED = 'X-Virus-Scanned';
	case X_VIRUS_STATUS  = 'X-Virus-Status';  // clean/infected/not-scanned

	case RETURN_RECEIPT_TO = 'Return-Receipt-To';
	case DISPOSITION_NOTIFICATION_TO = 'Disposition-Notification-To';
	case X_CONFIRM_READING_TO = 'X-Confirm-Reading-To';

	case MIME_VERSION = 'MIME-Version';
	case X_MAILER = 'X-Mailer';

	case X_MSMAIL_PRIORITY = 'X-MSMail-Priority';
	case IMPORTANCE = 'Importance';
	case X_PRIORITY = 'X-Priority';

	// https://autocrypt.org/level1.html#the-autocrypt-header
	case AUTOCRYPT = 'Autocrypt';
	// Deprecated https://datatracker.ietf.org/doc/html/draft-josefsson-openpgp-mailnews-header-07
//	case X_PGP_KEY = 'X-PGP-Key';
//	case OPENPGP = 'OpenPGP'; // url="https://www.irf.se/pgp/robert.labudda" id=11FA93ABE6892CA7D58CB0BE6392A597DE44B055

	// https://www.ietf.org/archive/id/draft-brand-indicators-for-message-identification-04.html#bimi-selector
	case BIMI_SELECTOR = 'BIMI-Selector';

	case LIST_UNSUBSCRIBE = 'List-Unsubscribe';
}
