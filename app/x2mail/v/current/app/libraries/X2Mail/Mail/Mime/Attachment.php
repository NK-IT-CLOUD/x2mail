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

/**
 * @category MailSo
 * @package Mime
 */
class Attachment
{
	/**
	 * @var resource
	 */
	private $rResource;

	private string $sFileName;

//	private int $iFileSize;

	private string $sContentID;

	private bool $bIsInline;

	private bool $bIsLinked;

	private array $aCustomContentTypeParams;

	private string $sContentLocation;

	private string $sContentType;

	/**
	 * @param resource $rResource
	 */
	function __construct($rResource, string $sFileName, int $iFileSize, bool $bIsInline, // @phpstan-ignore constructor.unusedParameter
		bool $bIsLinked, string $sContentID, array $aCustomContentTypeParams = [],
		string $sContentLocation = '', string $sContentType = '')
	{
		$this->rResource = $rResource;
		$this->sFileName = $sFileName;
//		$this->iFileSize = $iFileSize;
		$this->bIsInline = $bIsInline;
		$this->bIsLinked = $bIsLinked;
		$this->sContentID = $sContentID;
		$this->aCustomContentTypeParams = $aCustomContentTypeParams;
		$this->sContentLocation = $sContentLocation;
		$this->sContentType = $sContentType
			?: \X2Mail\Engine\File\MimeType::fromStream($rResource, $sFileName)
			?: \X2Mail\Engine\File\MimeType::fromFilename($sFileName)
			?: 'application/octet-stream';
	}

	/**
	 * @return resource
	 */
	public function Resource()
	{
		return $this->rResource;
	}

	public function ContentType() : string
	{
		return $this->sContentType;
	}

	public function CustomContentTypeParams() : array
	{
		return $this->aCustomContentTypeParams;
	}

	public function FileName() : string
	{
		return $this->sFileName;
	}

	public function isInline() : bool
	{
		return $this->bIsInline;
	}

	public function isLinked() : bool
	{
		return $this->bIsLinked && \strlen($this->sContentID);
	}

	public function ToPart() : Part
	{
		$oAttachmentPart = new Part;

		$sFileName = \trim($this->sFileName);
		$sContentID = $this->sContentID;
		$sContentLocation = $this->sContentLocation;

		$oContentTypeParameters = null;
		$oContentDispositionParameters = null;

		if (\strlen($sFileName)) {
			$oContentTypeParameters =
				(new ParameterCollection)->Add(new Parameter(
					Enumerations\Parameter::NAME->value, $sFileName));

			$oContentDispositionParameters =
				(new ParameterCollection)->Add(new Parameter(
					Enumerations\Parameter::FILENAME->value, $sFileName));
		}

		$oAttachmentPart->Headers->append(
			new Header(Enumerations\Header::CONTENT_TYPE->value,
				$this->ContentType().
				($oContentTypeParameters instanceof ParameterCollection ? '; '.$oContentTypeParameters->ToString() : '')
			)
		);

		$oAttachmentPart->Headers->append(
			new Header(Enumerations\Header::CONTENT_DISPOSITION->value,
				($this->isInline() ? 'inline' : 'attachment').
				($oContentDispositionParameters instanceof ParameterCollection ? '; '.$oContentDispositionParameters->ToString() : '')
			)
		);

		if (\strlen($sContentID)) {
			$oAttachmentPart->Headers->append(
				new Header(Enumerations\Header::CONTENT_ID->value, $sContentID)
			);
		}

		if (\strlen($sContentLocation)) {
			$oAttachmentPart->Headers->append(
				new Header(Enumerations\Header::CONTENT_LOCATION->value, $sContentLocation)
			);
		}

		$oAttachmentPart->Body = $this->Resource();

		if ('message/rfc822' !== \strtolower($this->ContentType())) {
			$oAttachmentPart->Headers->append(
				new Header(
					Enumerations\Header::CONTENT_TRANSFER_ENCODING->value,
					\X2Mail\Mail\Base\Enumerations\Encoding::BASE64_LOWER->value
				)
			);

			if (\is_resource($oAttachmentPart->Body) && !\X2Mail\Mail\Base\StreamWrappers\Binary::IsStreamRemembed($oAttachmentPart->Body)) {
				$oAttachmentPart->Body =
					\X2Mail\Mail\Base\StreamWrappers\Binary::CreateStream($oAttachmentPart->Body,
						\X2Mail\Mail\Base\StreamWrappers\Binary::GetInlineDecodeOrEncodeFunctionName(
							\X2Mail\Mail\Base\Enumerations\Encoding::BASE64_LOWER->value, false));

				\X2Mail\Mail\Base\StreamWrappers\Binary::RememberStream($oAttachmentPart->Body);
			}
		}

		return $oAttachmentPart;
	}
}
