<?php

namespace OCA\X2Mail\AppInfo;

use OCA\X2Mail\Dashboard\UnreadMailWidget;
use OCA\X2Mail\Listeners\AccessTokenUpdatedListener;
use OCA\X2Mail\Listeners\ImpersonateListener;
use OCA\X2Mail\Listeners\LoginBridgeListener;
use OCA\X2Mail\Listeners\LogoutListener;
use OCA\X2Mail\Listeners\PasswordLoginListener;
use OCA\X2Mail\Listeners\TokenBridgeListener;
use OCA\X2Mail\Middleware\TokenRefreshMiddleware;
use OCA\X2Mail\Search\Provider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'x2mail';

    /** @param array<string, mixed> $urlParams */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // NC 28+: Controllers use autowiring — no manual registerService needed.
        // The DI container resolves constructor dependencies automatically.

        $context->registerSearchProvider(Provider::class);

        // OIDCLogin AccessTokenUpdatedEvent — use string class name to avoid autoload interference
        $context->registerEventListener(
            'OCA\\OIDCLogin\\Events\\AccessTokenUpdatedEvent',
            AccessTokenUpdatedListener::class
        );

        // user_oidc TokenObtainedEvent — use string class name to avoid autoload interference
        $context->registerEventListener(
            'OCA\\UserOIDC\\Event\\TokenObtainedEvent',
            TokenBridgeListener::class
        );

        // UserLoggedInEvent — bridge NC login to SM session
        $context->registerEventListener(
            UserLoggedInEvent::class,
            LoginBridgeListener::class
        );

        // PostLoginEvent — store UID + encoded password for IMAP (skips token logins)
        $context->registerEventListener(
            PostLoginEvent::class,
            PasswordLoginListener::class
        );

        // BeforeUserLoggedOutEvent — SM logout
        $context->registerEventListener(
            BeforeUserLoggedOutEvent::class,
            LogoutListener::class
        );

        // Impersonate begin/end — clear passphrase + SM logout
        // Use string class names to avoid hard dependency on the impersonate app
        $context->registerEventListener(
            'OCA\\Impersonate\\Events\\BeginImpersonateEvent',
            ImpersonateListener::class
        );
        $context->registerEventListener(
            'OCA\\Impersonate\\Events\\EndImpersonateEvent',
            ImpersonateListener::class
        );

        // Register middleware for token refresh
        $context->registerMiddleware(TokenRefreshMiddleware::class);

        $context->registerDashboardWidget(UnreadMailWidget::class);
    }

    public function boot(IBootContext $context): void
    {
        $config = $context->getServerContainer()->get(IConfig::class);
        $dataDir = \rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/');
        if (!\is_dir($dataDir . '/appdata_x2mail')) {
            return;
        }

        // APP_PRIVATE_DATA setup happens via SnappyMailHelper::loadApp() on demand
    }
}
