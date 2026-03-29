<?php

namespace X2Mail\Engine\Imap;

use X2Mail\Mail\Imap\Enumerations\FetchType;
use X2Mail\Mail\Imap\Enumerations\MessageFlag;
use X2Mail\Mail\Mime\Enumerations\Header;

class Sync
{
	public \X2Mail\Mail\Imap\ImapClient $oImapSource;
	public \X2Mail\Mail\Imap\ImapClient $oImapTarget;

	function import(string $sTargetRootFolderName = '')
	{
		$sParent = '';
		$sListPattern = '*';

		$this->oImapSource->TAG_PREFIX = 'S';
		$this->oImapTarget->TAG_PREFIX = 'T';

//		$this->oImapTarget->logWrite('Get oImapTarget->FolderList');
		\X2Mail\Engine\Log::notice('SYNC', 'Get oImapTarget->FolderList');
		$aTargetFolders = $this->oImapTarget->FolderList($sParent, $sListPattern);
		if (!$aTargetFolders) {
			return null;
		}
		$sTargetDelimiter = '';
		$sTargetRoles = [
			'inbox'   => false,
			'sent'    => false,
			'drafts'  => false,
			'junk'    => false,
			'trash'   => false,
			'archive' => false
		];
		foreach ($aTargetFolders as $sFullName => $oImapFolder) {
			$role = $oImapFolder->Role();
			if ($role && empty($sTargetRoles[$role])) {
				$sTargetRoles[$role] = $sFullName;
			}
			if (!$sTargetDelimiter) {
				$sTargetDelimiter = $oImapFolder->Delimiter();
			}
		}

		\X2Mail\Engine\Log::notice('SYNC', 'Get oImapSource->FolderList');
		$bUseListStatus = $this->oImapSource->hasCapability('LIST-EXTENDED');
		$aSourceFolders = $this->oImapSource->FolderList($sParent, $sListPattern, false, $bUseListStatus);
		if (!$aSourceFolders) {
			return null;
		}

		$isCli = false !== \stripos(\php_sapi_name(), 'cli');
		if ($isCli) {
			echo 'folders: ' . \count($aSourceFolders) . "\n";
		} else {
			\X2Mail\Engine\HTTP\Stream::start();
			\X2Mail\Engine\HTTP\Stream::JSON([
				'folders' => \count($aSourceFolders)
			]);
		}

		$fi = 0;
		\ignore_user_abort(true);
		foreach ($aSourceFolders as $sSourceFolderName => $oImapFolder) {
			++$fi;
			if ($oImapFolder->Selectable()) {
				$role = $oImapFolder->Role();
				if ('all' === $role) {
					// Don't duplicate all mail
					continue;
				}
				// Detect mailbox name based on role
				if (!$sTargetRootFolderName && $role && !empty($sTargetRoles[$role])) {
					$sTargetFolderName = $sTargetRoles[$role];
				}
				// Else just do a name match
				else {
					if ($sTargetDelimiter) {
						$sTargetFolderName = \str_replace($oImapFolder->Delimiter(), $sTargetDelimiter, $sSourceFolderName);
						$sTargetRootFolderName = \str_replace($sTargetDelimiter, '-', $sTargetRootFolderName);
					} else {
						$sTargetFolderName = $sSourceFolderName;
					}
					if ($sTargetRootFolderName) {
						$sTargetFolderName = $sTargetRootFolderName . ($sTargetDelimiter?:'-') . $sTargetFolderName;
					}
				}
				if ($isCli) {
					echo \str_pad($fi, 3, ' ', STR_PAD_LEFT) . " folder: {$sSourceFolderName} => {$sTargetFolderName}\n";
				} else {
					\X2Mail\Engine\HTTP\Stream::JSON([
						'index' => $fi,
						'folder' => $sSourceFolderName,
						'target' => $sTargetFolderName
					]);
				}

				// Create mailbox if not exists
				if (!isset($aTargetFolders[$sTargetFolderName])) {
					$this->oImapTarget->FolderCreate(
						$sTargetFolderName,
						!$bUseListStatus || $oImapFolder->IsSubscribed()
					);
				} else if (!$aTargetFolders[$sTargetFolderName]->Selectable()) {
					// Can't copy messages
					continue;
				}

				// Set Source metadata on target
				if ($aMetadata = $oImapFolder->Metadata()) {
					$this->oImapTarget->FolderSetMetadata($sTargetFolderName, $aMetadata);
				}

				$oSourceInfo = $this->oImapSource->FolderSelect($sSourceFolderName);
				if ($oSourceInfo->MESSAGES) {
					if ($isCli) {
						echo \str_pad($fi, 3, ' ', STR_PAD_LEFT)
							. " messages: [" . \str_repeat(' ', 50) . "] 0/{$oSourceInfo->MESSAGES}";
					} else {
						\X2Mail\Engine\HTTP\Stream::JSON([
							'index' => $fi,
							'messages' => $oSourceInfo->MESSAGES
						]);
					}
					// All id's to skip from source
					$aTargetMessageIDs = [];
					$oTargetInfo = $this->oImapTarget->FolderSelect($sTargetFolderName);
					if ($oTargetInfo->MESSAGES) {
						// Get all existing message id's from target to skip
						$aTargetMessageIDs = [];
						$this->oImapTarget->SendRequest('FETCH', [
							'1:*', [FetchType::BuildBodyCustomHeaderRequest([Header::MESSAGE_ID->value], true)]
						]);
						foreach ($this->oImapTarget->yieldUntaggedResponses() as $oResponse) {
							if ('FETCH' === $oResponse->ResponseList[2]) {
								// $oResponse->ResponseList[3][0] == 'BODY[HEADER.FIELDS (MESSAGE-ID)]'
								// 'Message-ID: ...'
								$aTargetMessageIDs[] = $oResponse->ResponseList[3][1];
							}
						}
					}
					// Set all existing id's from source to skip and get all flags
					$aSourceSkipIDs = [];
					$aSourceFlags = [];
					$this->oImapSource->SendRequest('FETCH', [
						'1:*', [FetchType::FLAGS->value, FetchType::BuildBodyCustomHeaderRequest([Header::MESSAGE_ID->value], true)]
					]);
					foreach ($this->oImapSource->yieldUntaggedResponses() as $oResponse) {
						if ('FETCH' === $oResponse->ResponseList[2]
						 && isset($oResponse->ResponseList[3])
						 && \is_array($oResponse->ResponseList[3])
						) {
							$id = $oResponse->ResponseList[1];
							foreach ($oResponse->ResponseList[3] as $i => $mItem) {
								if ('FLAGS' === $mItem) {
									$aSourceFlags[$id] = $oResponse->ResponseList[3][$i+1];
								} else if ('MESSAGE-ID' === $mItem && \in_array($oResponse->ResponseList[3][$i+1], $aTargetMessageIDs)) {
									$aSourceSkipIDs[] = $id;
								}
							}
						}
					}

					$aTargetMessageIDs = [];
					// Now copy each message from source to target
					for ($i = 1; $i <= $oSourceInfo->MESSAGES; ++$i) {
						if (!\in_array($i, $aSourceSkipIDs)) {
							$sPeek = $this->oImapSource->hasCapability('BINARY')
								? FetchType::BINARY_PEEK->value
								: FetchType::BODY_PEEK->value;
							$iAppendUid = 0;
							$aFetchResponse = $this->oImapSource->Fetch(array(
								array(
									$sPeek.'[]',
									function ($sParent, $sLiteralAtomUpperCase, $rLiteralStream, $iLiteralLen)
									use ($sTargetFolderName, &$iAppendUid, $aSourceFlags, $i) {
										if (\strlen($sLiteralAtomUpperCase) && \is_resource($rLiteralStream) && 'FETCH' === $sParent) {
//											$sMessage = \stream_get_contents($rLiteralStream);
											$iAppendUid = $this->oImapTarget->MessageAppendStream(
												$sTargetFolderName,
												$rLiteralStream,
												$iLiteralLen,
												isset($aSourceFlags[$i]) ? $aSourceFlags[$i] : []
											);
										}
									}
								)), $i, false);

/*
							$aFlags = $aFetchResponse[0]->GetFetchValue('FLAGS');
							$iAppendUid = $this->oImapTarget->MessageAppendStream(
								$sTargetFolderName,
								$rLiteralStream,
								$iLiteralLen,
								$aFlags
							);
							if ($iAppendUid && $aFlags) {
								$this->MessageStoreFlag(
									new SequenceSet($iAppendUid),
									$aFlags,
									\X2Mail\Mail\Imap\Enumerations\StoreAction::ADD_FLAGS_SILENT
								);
							}
*/
						}

						if ($isCli) {
							// Clear line
							echo "\x1b[2K\x1b[1G";
							// Echo same line with progress
							$p = \floor(50 * $i / $oSourceInfo->MESSAGES);
							echo \str_pad($fi, 3, ' ', STR_PAD_LEFT)
								. " messages: [" . \str_repeat('=', $p) . \str_repeat(' ', 50 - $p)
								. "] {$i}/{$oSourceInfo->MESSAGES}";
						} else {
							\X2Mail\Engine\HTTP\Stream::JSON([
								'index' => $fi,
								'message' => $i
							]);
						}
					}
					if ($isCli) {
						echo "\n";
					}
				}
			} else if ($isCli) {
				echo \str_pad($fi, 3, ' ', STR_PAD_LEFT) . " folder: {$sSourceFolderName}\n";
			} else {
				\X2Mail\Engine\HTTP\Stream::JSON([
					'index' => $fi,
					'folder' => $sSourceFolderName
				]);
			}
		}
	}

}
