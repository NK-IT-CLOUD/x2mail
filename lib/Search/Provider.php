<?php

declare(strict_types=1);

namespace OCA\X2Mail\Search;

use OCA\X2Mail\AppInfo\Application;
use OCA\X2Mail\Util\EngineHelper;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/search.html#search-providers
 */
class Provider implements IProvider
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
        private ISession $session,
        private ContainerInterface $container,
    ) {
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

    /**
     * Refresh OIDC token before engine bootstrap.
     * NC Unified Search may run outside x2mail's middleware context.
     */
    private function refreshOidcToken(): void
    {
        if (!$this->session->get('is_oidc')) {
            return;
        }
        try {
            $tokenService = $this->container->get('OCA\UserOIDC\Service\TokenService');
            $token = $tokenService->getToken(true);
            if ($token !== null) {
                $fresh = $token->getAccessToken();
                if ($fresh !== $this->session->get('oidc_access_token')) {
                    $this->session->set('oidc_access_token', $fresh);
                }
            }
        } catch (\Throwable $e) {
            // user_oidc not installed or refresh failed
        }
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        $result = [];
        if (2 > \strlen(\trim($query->getTerm()))) {
            return SearchResult::complete($this->getName(), $result);
        }
        $this->refreshOidcToken();
        $this->engineHelper->startApp();
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
