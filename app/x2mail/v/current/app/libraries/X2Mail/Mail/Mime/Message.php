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
class Message extends Part
{
	private array $aHeadersValue = array(
/*
		Enumerations\Header::BCC->value => '',
		Enumerations\Header::CC->value => '',
		Enumerations\Header::DATE->value => '',
		Enumerations\Header::DISPOSITION_NOTIFICATION_TO->value => '',
		Enumerations\Header::FROM->value => '',
		Enumerations\Header::IN_REPLY_TO->value => '',
		Enumerations\Header::MESSAGE_ID->value => '',
		Enumerations\Header::MIME_VERSION->value => '',
		Enumerations\Header::REFERENCES->value => '',
		Enumerations\Header::REPLY_TO->value => '',
		Enumerations\Header::SENDER->value => '',
		Enumerations\Header::SUBJECT->value => '',
		Enumerations\Header::TO->value => '',
		Enumerations\Header::X_CONFIRM_READING_TO->value => '',
		Enumerations\Header::X_DRAFT_INFO->value => '',
		Enumerations\Header::X_MAILER->value => '',
		Enumerations\Header::X_PRIORITY->value => '',
*/
	);

	private AttachmentCollection $oAttachmentCollection;

	private bool $bAddEmptyTextPart = true;

	private bool $bAddDefaultXMailer = true;

	function __construct()
	{
		parent::__construct();
		$this->oAttachmentCollection = new AttachmentCollection;
	}

	private function getHeaderValue(string $name)
	{
		return isset($this->aHeadersValue[$name])
			? $this->aHeadersValue[$name]
			: null;
	}

	public function DoesNotAddDefaultXMailer() : void
	{
		$this->bAddDefaultXMailer = false;
	}

	public function MessageId() : string
	{
		return $this->getHeaderValue(Enumerations\Header::MESSAGE_ID->value) ?: '';
	}

	public function SetMessageId(string $sMessageId) : void
	{
		$this->aHeadersValue[Enumerations\Header::MESSAGE_ID->value] = $sMessageId;
	}

	public function RegenerateMessageId(string $sHostName = '') : void
	{
		$this->SetMessageId($this->generateNewMessageId($sHostName));
	}

	public function Attachments() : AttachmentCollection
	{
		return $this->oAttachmentCollection;
	}

	public function GetSubject() : string
	{
		return $this->getHeaderValue(Enumerations\Header::SUBJECT->value) ?: '';
	}

	public function GetFrom() : ?Email
	{
		$value = $this->getHeaderValue(Enumerations\Header::FROM->value);
		return ($value instanceof Email) ? $value : null;
	}

	public function GetTo() : ?EmailCollection
	{
		$value = $this->getHeaderValue(Enumerations\Header::TO->value);
		return ($value instanceof EmailCollection) ? $value->Unique() : null;
	}

	public function GetCc() : ?EmailCollection
	{
		$value = $this->getHeaderValue(Enumerations\Header::CC->value);
		return ($value instanceof EmailCollection) ? $value->Unique() : null;
	}

	public function GetBcc() : ?EmailCollection
	{
		$value = $this->getHeaderValue(Enumerations\Header::BCC->value);
		return ($value instanceof EmailCollection) ? $value->Unique() : null;
	}

	public function GetRcpt() : EmailCollection
	{
		$oResult = new EmailCollection;

		$headers = array(Enumerations\Header::TO->value, Enumerations\Header::CC->value, Enumerations\Header::BCC->value);
		foreach ($headers as $header) {
			$value = $this->getHeaderValue($header);
			if ($value instanceof EmailCollection) {
				foreach ($value as $oEmail) {
					$oResult->append($oEmail);
				}
			}
		}

/*
		$aReturn = array();
		$headers = array(Enumerations\Header::TO->value, Enumerations\Header::CC->value, Enumerations\Header::BCC->value);
		foreach ($headers as $header) {
			$value = $this->getHeaderValue($header);
			if ($value instanceof EmailCollection) {
				foreach ($value as $oEmail) {
					$oResult->append($oEmail);
					$sEmail = $oEmail->GetEmail();
					if (!isset($aReturn[$sEmail])) {
						$aReturn[$sEmail] = $oEmail;
					}
				}
			}
		}
		return new EmailCollection($aReturn);
*/

		return $oResult->Unique();
	}

	public function SetCustomHeader(string $sHeaderName, string $sValue) : self
	{
		$sHeaderName = \trim($sHeaderName);
		if (\strlen($sHeaderName)) {
			$this->aHeadersValue[$sHeaderName] = $sValue;
		}

		return $this;
	}

	public function SetAutocrypt(array $aValue) : self
	{
		$this->aHeadersValue['Autocrypt'] = $aValue;
		return $this;
	}

	public function SetSubject(string $sSubject) : self
	{
		$this->aHeadersValue[Enumerations\Header::SUBJECT->value] = $sSubject;

		return $this;
	}

	public function SetInReplyTo(string $sInReplyTo) : self
	{
		$sInReplyTo = \trim($sInReplyTo);
		if (\strlen($sInReplyTo)) {
			$this->aHeadersValue[Enumerations\Header::IN_REPLY_TO->value] = $sInReplyTo;
		}
		return $this;
	}

	public function SetReferences(string $sReferences) : self
	{
		$sReferences = \X2Mail\Mail\Base\Utils::StripSpaces($sReferences);
		if (\strlen($sReferences)) {
			$this->aHeadersValue[Enumerations\Header::REFERENCES->value] = $sReferences;
		}
		return $this;
	}

	public function SetReadReceipt(string $sEmail) : self
	{
		$this->aHeadersValue[Enumerations\Header::DISPOSITION_NOTIFICATION_TO->value] = $sEmail;
		$this->aHeadersValue[Enumerations\Header::X_CONFIRM_READING_TO->value] = $sEmail;

		return $this;
	}

	public function SetPriority(int $iValue) : self
	{
		$sResult = '';
		switch ($iValue)
		{
			case Enumerations\MessagePriority::HIGH->value:
				$sResult = Enumerations\MessagePriority::HIGH->value.' (Highest)';
				break;
			case Enumerations\MessagePriority::NORMAL->value:
				$sResult = Enumerations\MessagePriority::NORMAL->value.' (Normal)';
				break;
			case Enumerations\MessagePriority::LOW->value:
				$sResult = Enumerations\MessagePriority::LOW->value.' (Lowest)';
				break;
		}

		if (\strlen($sResult)) {
			$this->aHeadersValue[Enumerations\Header::X_PRIORITY->value] = $sResult;
		}

		return $this;
	}

	public function SetXMailer(string $sXMailer) : self
	{
		$this->aHeadersValue[Enumerations\Header::X_MAILER->value] = $sXMailer;

		return $this;
	}

	public function SetFrom(Email $oEmail) : self
	{
		$this->aHeadersValue[Enumerations\Header::FROM->value] = $oEmail;

		return $this;
	}

	public function SetTo(EmailCollection $oEmails) : self
	{
		if ($oEmails->count()) {
			$this->aHeadersValue[Enumerations\Header::TO->value] = $oEmails;
		}
		return $this;
	}

	public function SetDate(int $iDateTime) : self
	{
		$this->aHeadersValue[Enumerations\Header::DATE->value] = \gmdate('r', $iDateTime);

		return $this;
	}

	public function SetReplyTo(EmailCollection $oEmails) : self
	{
		if ($oEmails->count()) {
			$this->aHeadersValue[Enumerations\Header::REPLY_TO->value] = $oEmails;
		}
		return $this;
	}

	public function SetCc(EmailCollection $oEmails) : self
	{
		if ($oEmails->count()) {
			$this->aHeadersValue[Enumerations\Header::CC->value] = $oEmails;
		}
		return $this;
	}

	public function SetBcc(EmailCollection $oEmails) : self
	{
		if ($oEmails->count()) {
			$this->aHeadersValue[Enumerations\Header::BCC->value] = $oEmails;
		}
		return $this;
	}

	public function SetSender(Email $oEmail) : self
	{
		$this->aHeadersValue[Enumerations\Header::SENDER->value] = $oEmail;

		return $this;
	}

	public function SetDraftInfo(string $sType, int $iUid, string $sFolder) : self
	{
		$this->aHeadersValue[Enumerations\Header::X_DRAFT_INFO->value] = (new ParameterCollection)
			->Add(new Parameter('type', $sType))
			->Add(new Parameter('uid', $iUid))
			->Add(new Parameter('folder', \base64_encode($sFolder)))
		;

		return $this;
	}

	private function generateNewMessageId(string $sHostName = '') : string
	{
		if (!\strlen($sHostName)) {
			$sHostName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
		}

		if (empty($sHostName) && \X2Mail\Mail\Base\Utils::FunctionCallable('php_uname')) {
			$sHostName = \php_uname('n');
		}

		if (empty($sHostName)) {
			$sHostName = 'localhost';
		}

		return '<'.
			\X2Mail\Mail\Base\Utils::Sha1Rand($sHostName.
				(\X2Mail\Mail\Base\Utils::FunctionCallable('getmypid') ? \getmypid() : '')).'@'.$sHostName.'>';
	}

	public function GetRootPart() : Part
	{
		if (!\count($this->SubParts)) {
			if ($this->bAddEmptyTextPart) {
				$oPart = new Part;
				$oPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'text/plain; charset="utf-8"');
				$oPart->Body = '';
				$this->SubParts->append($oPart);
			} else {
				$aAttachments = $this->oAttachmentCollection->getArrayCopy();
				if (1 === \count($aAttachments) && isset($aAttachments[0])) {
					$this->oAttachmentCollection->Clear();

					$oPart = new Part;
					$oParameters = new ParameterCollection;
					$oParameters->append(
						new Parameter(
							Enumerations\Parameter::CHARSET->value,
							\X2Mail\Mail\Base\Enumerations\Charset::UTF_8->value)
					);
					$params = $aAttachments[0]->CustomContentTypeParams();
					if ($params && \is_array($params)) {
						foreach ($params as $sName => $sValue) {
							$oParameters->append(new Parameter($sName, $sValue));
						}
					}
					$oPart->Headers->append(
						new Header(Enumerations\Header::CONTENT_TYPE->value,
							$aAttachments[0]->ContentType().'; '.$oParameters)
					);

					if ($resource = $aAttachments[0]->Resource()) {
						if (\is_resource($resource)) {
							$oPart->Body = $resource;
						} else if (\is_string($resource) && \strlen($resource)) {
							$oPart->Body = \X2Mail\Mail\Base\ResourceRegistry::CreateMemoryResourceFromString($resource);
						}
					}
					if (!\is_resource($oPart->Body)) {
						$oPart->Body = '';
					}

					$this->SubParts->append($oPart);
				}
			}
		}

		$oRootPart = $oRelatedPart = null;
		if (1 == \count($this->SubParts)) {
			$oRootPart = $this->SubParts[0];
			foreach ($this->oAttachmentCollection as $oAttachment) {
				if ($oAttachment->isLinked()) {
					$oRelatedPart = new Part;
					$oRelatedPart->Headers->append(
						new Header(Enumerations\Header::CONTENT_TYPE->value, 'multipart/related')
					);
					$oRelatedPart->SubParts->append($oRootPart);
					$oRootPart = $oRelatedPart;
					break;
				}
			}
		} else {
			$oRootPart = new Part;
			$oRootPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'multipart/mixed');
			$oRootPart->SubParts = $this->SubParts;
		}

		$oMixedPart = null;
		foreach ($this->oAttachmentCollection as $oAttachment) {
			if ($oRelatedPart && $oAttachment->isLinked()) {
				$oRelatedPart->SubParts->append($oAttachment->ToPart());
			} else {
				if (!$oMixedPart) {
					$oMixedPart = new Part;
					$oMixedPart->Headers->AddByName(Enumerations\Header::CONTENT_TYPE->value, 'multipart/mixed');
					$oMixedPart->SubParts->append($oRootPart);
					$oRootPart = $oMixedPart;
				}
				$oMixedPart->SubParts->append($oAttachment->ToPart());
			}
		}

		return $oRootPart;
	}

	/**
	 * @return resource|bool
	 */
	public function ToStream(bool $bWithoutBcc = false)
	{
		$oRootPart = $this->GetRootPart();

		/**
		 * setDefaultHeaders
		 */
		if (!isset($this->aHeadersValue[Enumerations\Header::DATE->value])) {
			$oRootPart->Headers->SetByName(Enumerations\Header::DATE->value, \gmdate('r'), true);
		}

		if (!isset($this->aHeadersValue[Enumerations\Header::MESSAGE_ID->value])) {
			$oRootPart->Headers->SetByName(Enumerations\Header::MESSAGE_ID->value, $this->generateNewMessageId(), true);
		}

		if ($this->bAddDefaultXMailer && !isset($this->aHeadersValue[Enumerations\Header::X_MAILER->value])) {
			$oRootPart->Headers->SetByName(Enumerations\Header::X_MAILER->value, 'X2Mail/'.APP_VERSION, true);
		}

		if (!isset($this->aHeadersValue[Enumerations\Header::MIME_VERSION->value])) {
			$oRootPart->Headers->SetByName(Enumerations\Header::MIME_VERSION->value, '1.0', true);
		}

		foreach ($this->aHeadersValue as $sName => $mValue) {
			if ('autocrypt' === \strtolower($sName)) {
				foreach ($mValue as $key) {
					$oRootPart->Headers->AddByName($sName, $key);
				}
			} else if (!($bWithoutBcc && \strtolower(Enumerations\Header::BCC->value) === \strtolower($sName))) {
				$oRootPart->Headers->SetByName($sName, (string) $mValue);
			}
		}

		$resource = $oRootPart->ToStream();
		\X2Mail\Mail\Base\StreamFilters\LineEndings::appendTo($resource);
		return $resource;
	}
/*
	public function ToString(bool $bWithoutBcc = false) : string
	{
		return \stream_get_contents($this->ToStream($bWithoutBcc));
	}
*/
}
