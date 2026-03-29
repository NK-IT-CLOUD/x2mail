<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2026 NK-IT Dev
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
enum ContentType: string
{
	case SIGNED = 'multipart/signed';
	case ENCRYPTED = 'multipart/encrypted';
	case PGP_ENCRYPTED = 'application/pgp-encrypted';
	case PGP_SIGNATURE = 'application/pgp-signature';
	case PKCS7_SIGNATURE = 'application/pkcs7-signature';
	case PKCS7_MIME = 'application/pkcs7-mime';
	// RFC 3462
	case REPORT = 'multipart/report'; // ; report-type=delivery-status;

	public static function isPkcs7Mime(string $data) : bool
	{
		return 'application/pkcs7-mime' === $data
			|| 'application/x-pkcs7-mime' === $data;
	}

	public static function isPkcs7Signature(string $data) : bool
	{
		return 'application/pkcs7-signature' === $data
			|| 'application/x-pkcs7-signature' === $data;
	}
}
