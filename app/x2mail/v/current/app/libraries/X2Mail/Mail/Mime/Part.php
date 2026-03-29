<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Mime;

use X2Mail\Mail\Mime\Enumerations\ContentType;

/**
 * @category MailSo
 * @package Mime
 */
class Part
{
	public HeaderCollection $Headers;

	/**
	 * @var resource
	 */
	public $Body = null;

	/**
	 * @var resource
	 */
	public $Raw = null;

	public PartCollection $SubParts;

	function __construct()
	{
		$this->Headers = new HeaderCollection;
		$this->SubParts = new PartCollection;
	}

	public function HeaderCharset() : string
	{
		return \trim(\strtolower($this->Headers->ParameterValue(Enumerations\Header::CONTENT_TYPE->value, Enumerations\Parameter::CHARSET->value)));
	}

	public function HeaderBoundary() : string
	{
		return \trim($this->Headers->ParameterValue(Enumerations\Header::CONTENT_TYPE->value, Enumerations\Parameter::BOUNDARY->value));
	}

	public function ContentType() : string
	{
		return \trim(\strtolower($this->Headers->ValueByName(Enumerations\Header::CONTENT_TYPE->value)));
	}

	public function ContentTransferEncoding() : string
	{
		return \trim(\strtolower($this->Headers->ValueByName(Enumerations\Header::CONTENT_TRANSFER_ENCODING->value)));
	}

	public function IsFlowedFormat() : bool
	{
		$bResult = 'flowed' === \trim(\strtolower($this->Headers->ParameterValue(
			Enumerations\Header::CONTENT_TYPE->value,
			Enumerations\Parameter::FORMAT->value)));

		if ($bResult && \in_array($this->ContentTransferEncoding(), array('base64', 'quoted-printable'))) {
			$bResult = false;
		}

		return $bResult;
	}

	public function FileName() : string
	{
		$sResult = \trim($this->Headers->ParameterValue(
			Enumerations\Header::CONTENT_DISPOSITION->value,
			Enumerations\Parameter::FILENAME->value));

		if (!\strlen($sResult)) {
			$sResult = \trim($this->Headers->ParameterValue(
				Enumerations\Header::CONTENT_TYPE->value,
				Enumerations\Parameter::NAME->value));
		}

		return $sResult;
	}

	// https://datatracker.ietf.org/doc/html/rfc3156#section-5
	public function isPgpSigned() : bool
	{
		$header = $this->Headers->GetByName(Enumerations\Header::CONTENT_TYPE->value);
		return $header
		 && \preg_match('#multipart/signed.+protocol=["\']?application/pgp-signature#si', $header->FullValue())
		 // The multipart/signed body MUST consist of exactly two parts.
		 && 2 === \count($this->SubParts)
		 && 'application/pgp-signature' === $this->SubParts[1]->ContentType();
	}

	// https://www.rfc-editor.org/rfc/rfc8551.html#section-3.5
	public function isSMimeSigned() : bool
	{
		$header = $this->Headers->GetByName(Enumerations\Header::CONTENT_TYPE->value);
		return ($header
			&& \preg_match('#multipart/signed.+protocol=["\']?application/(x-)?pkcs7-signature#si', $header->FullValue())
			// The multipart/signed body MUST consist of exactly two parts.
			&& 2 === \count($this->SubParts)
			&& ContentType::isPkcs7Signature($this->SubParts[1]->ContentType())
		) || ($header
			&& \preg_match('#application/(x-)?pkcs7-mime.+smime-type=["\']?signed-data#si', $header->FullValue())
		);
	}

	public static function FromFile(string $sFileName) : ?self
	{
		$rStreamHandle = \file_exists($sFileName) ? \fopen($sFileName, 'rb') : false;
		if ($rStreamHandle) {
			try {
				return Parser::parseStream($rStreamHandle);
			} finally {
				\fclose($rStreamHandle);
			}
		}
		return null;
	}

	public static function FromString(string $sRawMessage) : ?self
	{
		$rStreamHandle = \strlen($sRawMessage) ?
			\X2Mail\Mail\Base\ResourceRegistry::CreateMemoryResource() : false;
		if ($rStreamHandle) {
			\fwrite($rStreamHandle, $sRawMessage);
			unset($sRawMessage);
			\fseek($rStreamHandle, 0);

			try {
				return Parser::parseStream($rStreamHandle);
			} finally {
				\X2Mail\Mail\Base\ResourceRegistry::CloseMemoryResource($rStreamHandle);
			}
		}
		return null;
	}

	/**
	 * @param resource $rStreamHandle
	 */
	public static function FromStream($rStreamHandle) : ?Part
	{
		return Parser::parseStream($rStreamHandle);
	}

	/**
	 * @return resource|bool
	 */
	public function ToStream()
	{
		if ($this->Raw) {
			$aSubStreams = array(
				$this->Raw
			);
		} else {
			if ($this->SubParts->count()) {
				$sBoundary = $this->HeaderBoundary();
				if (!\strlen($sBoundary)) {
					$this->Headers->GetByName(Enumerations\Header::CONTENT_TYPE->value)->setParameter(
						Enumerations\Parameter::BOUNDARY->value,
						$this->SubParts->Boundary()
					);
				} else {
					$this->SubParts->SetBoundary($sBoundary);
				}
			}

			$aSubStreams = array(
				$this->Headers . "\r\n"
			);

			if ($this->Body) {
				$aSubStreams[0] .= "\r\n";
				if (\is_resource($this->Body)) {
					$aMeta = \stream_get_meta_data($this->Body);
					if (!empty($aMeta['seekable'])) {
						\rewind($this->Body);
					}
				}
				$aSubStreams[] = $this->Body;
			}

			if ($this->SubParts->count()) {
				$rSubPartsStream = $this->SubParts->ToStream();
				if (\is_resource($rSubPartsStream)) {
					$aSubStreams[] = $rSubPartsStream;
				}
			}
		}

		return \X2Mail\Mail\Base\StreamWrappers\SubStreams::CreateStream($aSubStreams);
	}

	public function addPgpEncrypted(string $sEncrypted)
	{
		$oPart = new self;
		$oPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'multipart/encrypted; protocol="application/pgp-encrypted"');
		$this->SubParts->append($oPart);

		$oSubPart = new self;
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'application/pgp-encrypted');
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_DISPOSITION->value, 'attachment');
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_TRANSFER_ENCODING->value, '7Bit');
		$oSubPart->Body = \X2Mail\Mail\Base\ResourceRegistry::CreateMemoryResourceFromString('Version: 1');
		$oPart->SubParts->append($oSubPart);

		$oSubPart = new self;
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'application/octet-stream');
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_DISPOSITION->value, 'inline; filename="msg.asc"');
		$oSubPart->Headers->AddByName(Enumerations\Header::CONTENT_TRANSFER_ENCODING->value, '7Bit');
		$oSubPart->Body = \X2Mail\Mail\Base\ResourceRegistry::CreateMemoryResourceFromString($sEncrypted);
		$oPart->SubParts->append($oSubPart);
	}

	public function addPlain(string $sPlain)
	{
		$oPart = new self;
		$oPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'text/plain; charset=utf-8');
		$oPart->Headers->AddByName(Enumerations\Header::CONTENT_TRANSFER_ENCODING->value, 'quoted-printable');
		$oPart->Body = \X2Mail\Mail\Base\StreamWrappers\Binary::CreateStream(
			\X2Mail\Mail\Base\ResourceRegistry::CreateMemoryResourceFromString(\preg_replace('/\\r?\\n/su', "\r\n", \trim($sPlain))),
			'convert.quoted-printable-encode'
		);
		$this->SubParts->append($oPart);
	}

}
