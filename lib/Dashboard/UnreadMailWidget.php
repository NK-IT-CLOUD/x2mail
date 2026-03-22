<?php

namespace OCA\X2Mail\Dashboard;

use OCA\X2Mail\Util\SnappyMailHelper;

use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;

class UnreadMailWidget implements IAPIWidget, IIconWidget
{
	protected IL10N $l10n;
	protected IURLGenerator $urlGenerator;
	protected IInitialState $initialState;
	protected ?string $userId;

	public function __construct(IL10N $l10n, IURLGenerator $urlGenerator,
		IInitialState $initialState,
		?string $userId)
	{
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->initialState = $initialState;
		$this->userId = $userId;
	}

	public function getId(): string
	{
		return 'x2mail-unread';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('Unread mail');
	}

	public function getOrder(): int
	{
		return 3;
	}

	public function getIconClass(): string
	{
		return 'icon-mail';
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('x2mail.page.index'));
	}

	public function load(): void
	{
		if ($this->userId !== null) {
			$this->initialState->provideInitialState('dashboard-widget-items', $this->getItems($this->userId));
		}
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array
	{
		$result = [];
		SnappyMailHelper::startApp();
		$oActions = \RainLoop\Api::Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		if ($oAccount) {
			$oConfig = $oActions->Config();

			$oParams = new \MailSo\Mail\MessageListParams;
			$oParams->sFolderName = 'INBOX';
			$oParams->sSearch = 'unseen';
			$oParams->oCacher = ($oConfig->Get('cache', 'enable', true) && $oConfig->Get('cache', 'server_uids', false))
				? $oActions->Cacher($oAccount) : null;
			$oParams->bUseSort = !!$oConfig->Get('labs', 'use_imap_sort', true);
			$oParams->iLimit = $limit;

			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->ImapClient()->IsLoggined()) {
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oConfig);
			}

			$MessageCollection = $oMailClient->MessageList($oParams);

			$baseURL = $this->urlGenerator->linkToRoute('x2mail.page.index') . '#';

			foreach ($MessageCollection as $Message) {
				$result[] = new WidgetItem(
					$Message->From()->ToString(),
					$Message->Subject(),
					$baseURL . '/mailbox/INBOX/m' . $Message->Uid(),
					'',
					$Message->ETag('')
				);
			}
		}

		return $result;
	}

	public function getIconUrl(): string
	{
		SnappyMailHelper::loadApp();
		return \RainLoop\Utils::WebStaticPath('images/snappymail-logo.png');
	}

	public function getWidgetOptions(): WidgetOptions
	{
		return new WidgetOptions(true);
	}
}
