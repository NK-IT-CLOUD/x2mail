<?php

namespace X2Mail\Engine\Actions;

trait Raw
{
	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function RawViewAsPlain() : bool
	{
		$oAccount = $this->getAccountFromToken();
		$sRawKey = $this->GetActionParam('RawKey', '');
		$aValues = $this->decodeRawKey($sRawKey);
		if (!empty($aValues['folder']) && !empty($aValues['uid'])
		 && !empty($aValues['accountHash']) && $aValues['accountHash'] === $oAccount->Hash()
		) {
			$this->verifyCacheByKey($sRawKey);
			$this->initMailClientConnection();
			\header('Content-Type: text/plain');
			return $this->MailClient()->MessageMimeStream(
				function ($rResource) use ($sRawKey) {
					if (\is_resource($rResource)) {
						$this->cacheByKey($sRawKey);
						\X2Mail\Mail\Base\Utils::FpassthruWithTimeLimitReset($rResource);
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}
		return false;
	}

	public function RawDownload() : bool
	{
		return $this->rawSmart(true);
	}

	public function RawView() : bool
	{
		return $this->rawSmart(false);
	}

	public function RawViewThumbnail() : bool
	{
		return $this->rawSmart(false, true);
	}

	public function RawUserBackground() : bool
	{
		$sRawKey = (string) $this->GetActionParam('RawKey', '');
		$this->verifyCacheByKey($sRawKey);

		$oAccount = $this->getAccountFromToken();

		$mData = $this->StorageProvider()->Get($oAccount,
			\X2Mail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG,
			'background'
		);

		if (!empty($mData)) {
			$mData = \json_decode($mData, true);
			if (!empty($mData['ContentType']) && !empty($mData['Raw'])) {
				$this->cacheByKey($sRawKey);

				\header('Content-Type: '.$mData['ContentType']);
				echo \base64_decode($mData['Raw']);
				unset($mData);

				return true;
			}
		}

		return false;
	}

	private function clearFileName(string $sFileName, string $sContentType, string $sMimeIndex, int $iMaxLength = 250): string
	{
		$sFileName = !\strlen($sFileName) ? \preg_replace('/[^a-zA-Z0-9]/', '.', (empty($sMimeIndex) ? '' : $sMimeIndex . '.') . $sContentType) : $sFileName;
		$sClearedFileName = \X2Mail\Mail\Base\Utils::StripSpaces(\preg_replace('/[\.]+/', '.', $sFileName));
		$sExt = \X2Mail\Mail\Base\Utils::GetFileExtension($sClearedFileName);

		if (10 < $iMaxLength && $iMaxLength < \strlen($sClearedFileName) - \strlen($sExt)) {
			$sClearedFileName = \substr($sClearedFileName, 0, $iMaxLength) . (empty($sExt) ? '' : '.' . $sExt);
		}

		return \X2Mail\Mail\Base\Utils::SecureFileName(\X2Mail\Mail\Base\Utils::Utf8Clear($sClearedFileName));
	}

	/**
	 * Message, Message Attachment or Zip
	 */
	private function rawSmart(bool $bDownload, bool $bThumbnail = false) : bool
	{
		$sRawKey = (string) $this->GetActionParam('RawKey', '');

		$oAccount = $this->getAccountFromToken();
		$aValues = $this->decodeRawKey($sRawKey);
		if (empty($aValues['accountHash']) || $aValues['accountHash'] !== $oAccount->Hash()) {
			return false;
		}

		$sRange = \X2Mail\Mail\Base\Http::GetHeader('Range');
		$aMatch = array();
		$sRangeStart = $sRangeEnd = '';
		$bIsRangeRequest = false;
		if (!empty($sRange) && 'bytes=0-' !== \strtolower($sRange)
		 && \preg_match('/^bytes=([0-9]+)-([0-9]*)/i', \trim($sRange), $aMatch))
		{
			$sRangeStart = $aMatch[1];
			$sRangeEnd = $aMatch[2];
			$bIsRangeRequest = true;
		} else {
			$this->verifyCacheByKey($sRawKey);
		}

		$sMimeIndex = isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : '';
		$sContentTypeIn = isset($aValues['mimeType']) ? (string) $aValues['mimeType'] : '';
		$sFileNameIn = isset($aValues['fileName']) ? (string) $aValues['fileName'] : '';

		if (!empty($aValues['fileHash'])) {
			$rResource = $this->FilesProvider()->GetFile($oAccount, (string) $aValues['fileHash']);
			if (!\is_resource($rResource)) {
				return false;
			}
			if ('.pdf' === \substr($sFileNameIn,-4)) {
				$sContentTypeOut = 'application/pdf'; // application/octet-stream
			} else {
				$sContentTypeOut = $sContentTypeIn
					?: \X2Mail\Engine\File\MimeType::fromFilename($sFileNameIn)
					?: 'application/octet-stream';
			}
			$sFileNameOut = $this->clearFileName($sFileNameIn, $sContentTypeIn, $sMimeIndex);
			\header('Content-Type: '.$sContentTypeOut);
			\X2Mail\Mail\Base\Http::setContentDisposition('attachment', ['filename' => $sFileNameOut]);
			\header('Accept-Ranges: none');
			\header('Content-Transfer-Encoding: binary');
			\X2Mail\Mail\Base\Utils::FpassthruWithTimeLimitReset($rResource);
			return true;
		}

		$sFolder = isset($aValues['folder']) ? (string) $aValues['folder'] : '';
		$iUid = isset($aValues['uid']) ? (int) $aValues['uid'] : 0;
		if (empty($sFolder) || 1 > $iUid) {
			return false;
		}

		$this->initMailClientConnection();

		$self = $this;
		return $this->MailClient()->MessageMimeStream(
			function($rResource, $sContentType, $sFileName, $sMimeIndex = '') use (
				$self, $sRawKey, $sContentTypeIn, $sFileNameIn, $bDownload, $bThumbnail,
				$bIsRangeRequest, $sRangeStart, $sRangeEnd
			) {
				if (\is_resource($rResource)) {
					\X2Mail\Mail\Base\Utils::ResetTimeLimit();

					$self->cacheByKey($sRawKey);

					$self->logWrite(\print_r([
						$sFileName,
						$sContentType,
						$sFileNameIn,
						$sContentTypeIn
					],true), \LOG_DEBUG, 'RAW');

					if ($sFileNameIn) {
						$sFileName = $sFileNameIn;
					}
					$sFileName = $self->clearFileName($sFileName, $sContentType, $sMimeIndex);

					if ('.pdf' === \substr($sFileName, -4)) {
						$sContentType = 'application/pdf';
					} else {
						$sContentType = $sContentTypeIn
							?: $sContentType
//							?: \X2Mail\Engine\File\MimeType::fromStream($rResource, $sFileName)
							?: \X2Mail\Engine\File\MimeType::fromFilename($sFileName)
							?: 'application/octet-stream';
					}

					if (!$bDownload) {
						$bDetectImageOrientation = $self->Config()->Get('labs', 'image_exif_auto_rotate', false)
							// Mostly only JPEG has EXIF metadata
							&& 'image/jpeg' == $sContentType;
						try
						{
							if ($bThumbnail) {
								$oImage = static::loadImage($rResource, $bDetectImageOrientation, 48);
								\X2Mail\Mail\Base\Http::setContentDisposition('inline', ['filename' => $sFileName.'_thumb60x60.png']);
								$oImage->show('png');
//								$oImage->show('webp'); // Little Britain: "Safari says NO"
								exit;
							} else if ($bDetectImageOrientation) {
								$oImage = static::loadImage($rResource, $bDetectImageOrientation);
								\X2Mail\Mail\Base\Http::setContentDisposition('inline', ['filename' => $sFileName]);
								$oImage->show();
//								$oImage->show('webp'); // Little Britain: "Safari says NO"
								exit;
							}
						}
						catch (\Throwable $oException)
						{
							$self->Logger()->WriteExceptionShort($oException);
							\X2Mail\Mail\Base\Http::StatusHeader(500);
							exit;
						}
					}

					if (!\headers_sent()) {
						\header('Content-Type: '.$sContentType);
						\X2Mail\Mail\Base\Http::setContentDisposition($bDownload ? 'attachment' : 'inline', ['filename' => $sFileName]);
						\header('Accept-Ranges: bytes');
						\header('Content-Transfer-Encoding: binary');
					}

					$sLoadedData = null;
					if ($bIsRangeRequest || !$bDownload) {
						$sLoadedData = \stream_get_contents($rResource);
					}

					\X2Mail\Mail\Base\Utils::ResetTimeLimit();

					if ($sLoadedData) {
						if ($bIsRangeRequest && (\strlen($sRangeStart) || \strlen($sRangeEnd))) {
							$iFullContentLength = \strlen($sLoadedData);

							\X2Mail\Mail\Base\Http::StatusHeader(206);

							$iRangeStart = \max(0, \intval($sRangeStart));
							$iRangeEnd = \max(0, \intval($sRangeEnd));

							if ($iRangeEnd && $iRangeStart < $iRangeEnd) {
								$sLoadedData = \substr($sLoadedData, $iRangeStart, $iRangeEnd - $iRangeStart);
							} else if ($iRangeStart) {
								$sLoadedData = \substr($sLoadedData, $iRangeStart);
							}

							$iContentLength = \strlen($sLoadedData);

							if (0 < $iContentLength) {
								\header('Content-Length: '.$iContentLength);
								\header('Content-Range: bytes '.$sRangeStart.'-'.($iRangeEnd ?: $iFullContentLength - 1).'/'.$iFullContentLength);
							}
						} else {
							\header('Content-Length: '.\strlen($sLoadedData));
						}

						echo $sLoadedData;

						unset($sLoadedData);
					} else {
						\X2Mail\Mail\Base\Utils::FpassthruWithTimeLimitReset($rResource);
					}
				}
			}, $sFolder, $iUid, $sMimeIndex
		);
	}

	private static function loadImage($resource, bool $bDetectImageOrientation = false, int $iThumbnailBoxSize = 0) : \X2Mail\Engine\Image
	{
		if (\extension_loaded('gmagick'))      { $handler = 'gmagick'; }
		else if (\extension_loaded('imagick')) { $handler = 'imagick'; }
		else if (\extension_loaded('gd'))      { $handler = 'gd2'; }
		else { return null; }
		$handler = 'X2Mail\\Engine\\Image\\'.$handler.'::createFromStream';
		$oImage = $handler($resource);

		if (!$oImage->valid()) {
			throw new \Exception('Loading image failed');
		}

		// rotateImageByOrientation
		if ($bDetectImageOrientation) {
			switch ($oImage->getOrientation())
			{
				case 2: // flip horizontal
					$oImage->flopImage();
					break;

				case 3: // rotate 180
					$oImage->rotate(180);
					break;

				case 4: // flip vertical
					$oImage->flipImage();
					break;

				case 5: // vertical flip + 90 rotate
					$oImage->flipImage();
					$oImage->rotate(90);
					break;

				case 6: // rotate 90
					$oImage->rotate(90);
					break;

				case 7: // horizontal flip + 90 rotate
					$oImage->flopImage();
					$oImage->rotate(90);
					break;

				case 8: // rotate 270
					$oImage->rotate(270);
					break;
			}
		}

		if (0 < $iThumbnailBoxSize) {
			$oImage->cropThumbnailImage($iThumbnailBoxSize, $iThumbnailBoxSize);
		}

		$oImage->stripImage();

		return $oImage;
	}

}
