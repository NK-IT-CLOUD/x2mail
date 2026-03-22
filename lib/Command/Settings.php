<?php

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Util\SnappyMailHelper;
use OC\Core\Command\Base;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Settings extends Base
{
	protected IUserManager $userManager;
	protected IConfig $config;

	public function __construct(IUserManager $userManager, IConfig $config) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->config = $config;
	}

	protected function configure() {
		$this
			->setName('x2mail:settings')
			->setDescription('Set user mail credentials for auto-login')
			->addArgument(
				'uid',
				InputArgument::REQUIRED,
				'User ID used to login'
			)
			->addArgument(
				'user',
				InputArgument::REQUIRED,
				'The login username (email address)'
			)
			->addArgument(
				'pass',
				InputArgument::OPTIONAL,
				'The login passphrase'
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = $input->getArgument('uid');
		if (!$this->userManager->userExists($uid)) {
			$output->writeln('<error>The user "' . $uid . '" does not exist.</error>');
			return 1;
		}

		$sEmail = $input->getArgument('user');
		$this->config->setUserValue($uid, 'x2mail', 'snappymail-email', $sEmail);

		$sPass = $input->getArgument('pass');
		if (empty($sPass)) {
			if (\is_callable('readline')) {
				$sPass = \readline("password: ");
			} else {
				echo "password: ";
				$sPass = \stream_get_line(STDIN, 1024, PHP_EOL);
			}
		}
		$sPass = ($sEmail && $sPass) ? SnappyMailHelper::encodePassword($sPass, \md5($sEmail)) : '';
		$this->config->setUserValue($uid, 'x2mail', 'passphrase', $sPass);
		return 0;
	}
}
