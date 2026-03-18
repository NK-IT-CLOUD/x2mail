<?php

declare(strict_types=1);

namespace OCA\X2Mail\Search;

use OCA\X2Mail\AppInfo\Application;
use OCA\X2Mail\Util\SnappyMailHelper;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/search.html#search-providers
 */
class Provider implements IProvider
{
	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(IL10N $l10n, IURLGenerator $urlGenerator)
	{
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}

	public function getId(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return 'X2Mail';
	}

	public function getOrder(string $route, array $routeParameters): int
	{
		if (0 === \strpos($route, Application::APP_ID . '.')) {
			// Active app, prefer Mail results
			return -1;
		}
		return 20;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult
	{
		$result = [];
		if (2 > \strlen(\trim($query->getTerm()))) {
			return SearchResult::complete($this->getName(), $result);
		}
		SnappyMailHelper::startApp();
		$oActions = \RainLoop\Api::Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		$iCursor = (int) $query->getCursor();
		$iLimit = $query->getLimit();
		if ($oAccount) {
			$oConfig = $oActions->Config();

			$oParams = new \MailSo\Mail\MessageListParams;
			$oParams->sFolderName = 'INBOX';
			$oParams->sSearch = $query->getTerm();
			$oParams->oCacher = ($oConfig->Get('cache', 'enable', true) && $oConfig->Get('cache', 'server_uids', false))
				? $oActions->Cacher($oAccount) : null;
			$oParams->bUseSortIfSupported = !!$oConfig->Get('labs', 'use_imap_sort', true);
			$oParams->iOffset = $iCursor;
			$oParams->iLimit = $iLimit;

			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oConfig);
			}

			$MessageCollection = $oMailClient->MessageList($oParams);

			$baseURL = $this->urlGenerator->linkToRoute('x2mail.page.index');
			$baseURL .= '#';
			$search = \rawurlencode($oParams->sSearch);

			foreach ($MessageCollection as $Message) {
				$result[] = new SearchResultEntry(
					'',
					$Message->Subject(),
					$Message->From()->ToString(),
					$baseURL . '/mailbox/INBOX/m' . $Message->Uid() . '/' . $search,
					'icon-mail',
					false
				);
			}
		} else {
			\error_log('X2Mail not logged in to use unified search');
		}

		if ($iLimit > \count($result)) {
			return SearchResult::complete($this->getName(), $result);
		}
		return SearchResult::paginated($this->getName(), $result, $iCursor + $iLimit);
	}
}
