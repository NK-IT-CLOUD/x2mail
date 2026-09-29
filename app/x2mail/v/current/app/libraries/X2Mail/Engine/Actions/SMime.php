<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\SMime\OpenSSL;
use X2Mail\Engine\SMime\Certificate;
use X2Mail\Mail\Imap\Enumerations\FetchType;

trait SMime
{
	private $SMIME = null;
	public function SMIME() : ?OpenSSL
	{
		if (!$this->SMIME) {
			$oAccount = $this->getMainAccountFromToken();
			if (!$oAccount) {
				return null;
			}

			$homedir = \rtrim($this->StorageProvider()->GenerateFilePath(
				$oAccount,
				\X2Mail\Engine\Providers\Storage\Enumerations\StorageType::ROOT->value
			), '/') . '/.smime';

			\X2Mail\Mail\Base\Utils::mkdir($homedir);
			if (!\is_writable($homedir)) {
				throw new \Exception("smime homedir '{$homedir}' not writable");
			}

			$this->SMIME = new OpenSSL($homedir);
		}
		return $this->SMIME;
	}

	private function requireSMimeEngine() : OpenSSL
	{
		$o = $this->SMIME();
		if (!$o) {
			throw new \RuntimeException('S/MIME engine unavailable');
		}

		return $o;
	}

	public function DoGetSMimeCertificate() : array
	{
		$result = [
			'key' => '',
			'pkey' => '',
			'cert' => ''
		];
		return $this->DefaultResponse(\array_values(\array_unique($result)));
	}

	// Like DoGnupgGetKeys
	public function DoSMimeGetCertificates() : array
	{
		$oSmime = $this->SMIME();

		return $this->DefaultResponse(
			$oSmime ? $oSmime->certificates() : []
		);
	}

	/**
	 * Can be used by Identity
	 */
	public function DoSMimeCreateCertificate() : array
	{
		$oAccount = $this->getAccountFromToken();

		$oPassphrase = new \X2Mail\Engine\SensitiveString($this->GetActionParam('passphrase', ''));

		$cert = new Certificate();
		$cert->distinguishedName['commonName'] = $this->GetActionParam('name', '') ?: $oAccount->Name();
		$cert->distinguishedName['emailAddress'] = $this->GetActionParam('email', '') ?: $oAccount->Email();
		$result = $cert->createSelfSigned($oPassphrase, $this->GetActionParam('privateKey', ''));
		return $this->DefaultResponse($result ?: false);
	}

	public function DoSMimeExportPrivateKey() : array
	{
		$SMIME = $this->requireSMimeEngine();
		$SMIME->setPrivateKey(
			$this->GetActionParam('privateKey'),
			new \X2Mail\Engine\SensitiveString($this->GetActionParam('oldPassphrase', ''))
		);
		$result = $SMIME->exportPrivateKey(
			new \X2Mail\Engine\SensitiveString($this->GetActionParam('newPassphrase', ''))
		);

		return $this->DefaultResponse($result);
	}

	public function DoSMimeDecryptMessage() : array
	{
		$sFolderName = $this->GetActionParam('folder', '');
		$iUid = (int) $this->GetActionParam('uid', 0);
		$sPartId = $this->GetActionParam('partId', '');
		$sCertificate = $this->GetActionParam('certificate', '');
		$sPrivateKey = $this->GetActionParam('privateKey', '');
		$oPassphrase = new \X2Mail\Engine\SensitiveString($this->GetActionParam('passphrase', ''));

		$this->initMailClientConnection();
		$oImapClient = $this->ImapClient();
		$oImapClient->FolderExamine($sFolderName);

		if ('TEXT' === $sPartId) {
			$oFetchResponse = $oImapClient->Fetch([
				FetchType::BODY_PEEK->value.'['.$sPartId.']',
				// An empty section specification refers to the entire message, including the header.
				// But Dovecot does not return it with BODY.PEEK[1], so we also use BODY.PEEK[1.MIME].
				FetchType::BODY_HEADER_PEEK->value
			], $iUid, true)[0];
			$sBody = $oFetchResponse->GetFetchValue(FetchType::BODY_HEADER->value);
		} else {
			$oFetchResponse = $oImapClient->Fetch([
				FetchType::BODY_PEEK->value.'['.$sPartId.']',
				// An empty section specification refers to the entire message, including the header.
				// But Dovecot does not return it with BODY.PEEK[1], so we also use BODY.PEEK[1.MIME].
				FetchType::BODY_PEEK->value.'['.$sPartId.'.MIME]'
			], $iUid, true)[0];
			$sBody = $oFetchResponse->GetFetchValue(FetchType::BODY->value.'['.$sPartId.'.MIME]');
		}
		$sBody .= $oFetchResponse->GetFetchValue(FetchType::BODY->value.'['.$sPartId.']');

		$SMIME = $this->requireSMimeEngine();
		$SMIME->setCertificate($sCertificate);
		$SMIME->setPrivateKey($sPrivateKey, $oPassphrase);
		$result = $SMIME->decrypt($sBody);
		if ($result) {
			$result = ['data' => $result];
			if (\str_contains($result['data'], 'multipart/signed')
			  || \preg_match('/smime-type=["\']?signed-data/', $result['data'])
			) {
				$signed = $SMIME->verify($result['data'], null, true);
				$result['signed'] = [
					'success' => !empty($signed['success'])
				];
				if (!empty($signed['body'])) {
					$result['data'] = $signed['body'];
				}
			}
		}

		return $this->DefaultResponse($result ?: false);
	}

	public function DoSMimeVerifyMessage() : array
	{
		$sBody = $this->GetActionParam('bodyPart', '');
		$sPartId = $this->GetActionParam('partId', '');
		$bDetached = !empty($this->GetActionParam('detached', 0));
		$oImapClient = null;
		$iUid = 0;
		if (!$sBody && $sPartId) {
			$iUid = (int) $this->GetActionParam('uid', 0);
//			$sMicAlg = $this->GetActionParam('micAlg', '');
			$this->initMailClientConnection();
			$oImapClient = $this->ImapClient();
			$oImapClient->FolderExamine($this->GetActionParam('folder', ''));
			$sBody = $oImapClient->FetchMessagePart($iUid, $sPartId);
		}

		$SMIME = $this->SMIME();
		if (!$SMIME) {
			return $this->FalseResponse();
		}
		// Certificates of the signature are not imported: an unknown sender
		// must not become trusted, nor a recipient for encryption, just by
		// sending a signed message. See DoSMimeImportCertificatesFromMessage.
		$result = $SMIME->verify($sBody, null, !$bDetached);

		return $this->DefaultResponse($result);
	}

	public function DoSMimeImportCertificate() : array
	{
		return $this->DefaultResponse(
			$this->requireSMimeEngine()->storeCertificate(
				$this->GetActionParam('pem', '')
			)
		);
	}

	/**
	 * Explicit user action: trust the signer certificate of a message.
	 * The message is read from the server, not from the request. Only signer
	 * certificates of a valid signature whose address matches the From header
	 * are imported (RFC 8550 section 3).
	 */
	public function DoSMimeImportCertificatesFromMessage() : array
	{
		$sFolderName = $this->GetActionParam('folder', '');
		$iUid = (int) $this->GetActionParam('uid', 0);

		$this->initMailClientConnection();
		$oMessage = $this->MailClient()->Message($sFolderName, $iUid);
		if (!$oMessage || !$oMessage->smimeSigned) {
			return $this->FalseResponse();
		}
		$sBody = $this->ImapClient()->FetchMessagePart($iUid, $oMessage->smimeSigned['partId']);

		$SMIME = $this->requireSMimeEngine();
		$result = $SMIME->verify($sBody, null, !$oMessage->smimeSigned['detached']);
		if (empty($result['success'])) {
			return $this->FalseResponse();
		}

		$aFrom = [];
		foreach ($oMessage->From() ?: [] as $oEmail) {
			$aFrom[] = \mb_strtolower($oEmail->GetEmail());
		}

		return $this->DefaultResponse($SMIME->importSigners($aFrom) ?: false);
	}
}
