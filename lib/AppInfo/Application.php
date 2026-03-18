<?php

namespace OCA\X2Mail\AppInfo;

use OCA\X2Mail\Util\SnappyMailHelper;
use OCA\X2Mail\Controller\FetchController;
use OCA\X2Mail\Controller\PageController;
use OCA\X2Mail\Controller\SetupController;
use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Dashboard\UnreadMailWidget;
use OCA\X2Mail\Search\Provider;
use OCA\X2Mail\Listeners\AccessTokenUpdatedListener;
use OCA\X2Mail\Listeners\TokenBridgeListener;
use OCA\X2Mail\Listeners\LoginBridgeListener;
use OCA\X2Mail\Middleware\TokenRefreshMiddleware;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IConfig;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'x2mail';

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

		// Register middleware for token refresh
		$context->registerMiddleware(TokenRefreshMiddleware::class);

		// TODO: Not working yet, needs a Vue UI
//		$context->registerDashboardWidget(UnreadMailWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$config = $context->getServerContainer()->get(IConfig::class);
		$dataDir = \rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/');
		if (!\is_dir($dataDir . '/appdata_x2mail')) {
			return;
		}

		$dispatcher = $context->getServerContainer()->get(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->addListener(PostLoginEvent::class, function (PostLoginEvent $Event) {
			$sUID = $Event->getUser()->getUID();
			\OC::$server->getSession()['snappymail-nc-uid'] = $sUID;
			\OC::$server->getSession()['snappymail-passphrase'] = SnappyMailHelper::encodePassword($Event->getPassword(), $sUID);
		});

		$dispatcher->addListener(BeforeUserLoggedOutEvent::class, function (BeforeUserLoggedOutEvent $Event) {
			SnappyMailHelper::loadApp();
			\RainLoop\Api::Actions()->DoLogout();
		});

		// https://github.com/nextcloud/impersonate/issues/179
		$class = 'OCA\Impersonate\Events\BeginImpersonateEvent';
		if (\class_exists($class)) {
			$dispatcher->addListener($class, function ($Event) {
				\OC::$server->getSession()['snappymail-passphrase'] = '';
				SnappyMailHelper::loadApp();
				\RainLoop\Api::Actions()->Logout(true);
			});
			$dispatcher->addListener('OCA\Impersonate\Events\EndImpersonateEvent', function ($Event) {
				\OC::$server->getSession()['snappymail-passphrase'] = '';
				SnappyMailHelper::loadApp();
				\RainLoop\Api::Actions()->Logout(true);
			});
		}
	}
}
