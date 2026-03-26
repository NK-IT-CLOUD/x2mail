<?php

declare(strict_types=1);

namespace OCA\X2Mail\Dashboard;

use OCA\X2Mail\Util\SnappyMailHelper;

use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

class UnreadMailWidget implements IAPIWidgetV2, IIconWidget, IReloadableWidget
{
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
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
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		try {
			SnappyMailHelper::startApp();
			$oActions = \RainLoop\Api::Actions();
			$oAccount = $oActions->getMainAccountFromToken(false);
			if (!$oAccount) {
				$oAccount = $oActions->getAccountFromToken(false);
			}
			if (!$oAccount) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->info(
					'X2Mail widget: no SM session — showing fallback',
					['app' => 'x2mail']
				);
				return new WidgetItems([], $this->l10n->t('Open X2Mail to connect'));
			}

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

			$items = [];
			$baseURL = $this->urlGenerator->linkToRoute('x2mail.page.index') . '#';

			foreach ($MessageCollection as $Message) {
				$items[] = new WidgetItem(
					$Message->From()->ToString(),
					$Message->Subject(),
					$baseURL . '/mailbox/INBOX/m' . $Message->Uid(),
					$this->urlGenerator->imagePath('x2mail', 'logo-64x64.png'),
					$Message->ETag('')
				);
			}

			if (empty($items)) {
				return new WidgetItems([], '', $this->l10n->t('No unread mail'));
			}

			return new WidgetItems($items);
		} catch (\Throwable $e) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
				'X2Mail widget error: ' . $e->getMessage(),
				['app' => 'x2mail', 'exception' => $e]
			);
			return new WidgetItems([], $this->l10n->t('Open X2Mail to connect'));
		}
	}

	public function getReloadInterval(): int
	{
		return 120;
	}

	public function getIconUrl(): string
	{
		return $this->urlGenerator->imagePath('x2mail', 'logo-64x64.png');
	}
}
