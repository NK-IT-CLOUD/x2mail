<?php

declare(strict_types=1);

namespace OCA\X2Mail\Search;

use OCA\X2Mail\AppInfo\Application;
use OCA\X2Mail\Util\EngineHelper;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/search.html#search-providers
 */
class Provider implements IProvider
{
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;

    public function __construct(IURLGenerator $urlGenerator, LoggerInterface $logger)
    {
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    public function getId(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return 'X2Mail';
    }

    /** @param array<string, string> $routeParameters */
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
        EngineHelper::startApp();
        $oActions = \X2Mail\Engine\Api::Actions();
        $oAccount = $oActions->getAccountFromToken(false);
        $iCursor = (int) $query->getCursor();
        $iLimit = $query->getLimit();
        if ($oAccount) {
            $oConfig = $oActions->Config();

            $oParams = new \X2Mail\Mail\Client\MessageListParams();
            $oParams->sFolderName = 'INBOX';
            $oParams->sSearch = $query->getTerm();
            $oParams->oCacher = ($oConfig->Get('cache', 'enable', true) && $oConfig->Get('cache', 'server_uids', false))
                ? $oActions->Cacher($oAccount) : null;
            $oParams->bUseSort = !!$oConfig->Get('labs', 'use_imap_sort', true);
            $oParams->iOffset = $iCursor;
            $oParams->iLimit = $iLimit;

            $oMailClient = $oActions->MailClient();
            if (!$oMailClient->ImapClient()->IsLoggined()) {
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
            $this->logger->debug('X2Mail not logged in to use unified search');
        }

        if ($iLimit > \count($result)) {
            return SearchResult::complete($this->getName(), $result);
        }
        return SearchResult::paginated($this->getName(), $result, $iCursor + $iLimit);
    }
}
