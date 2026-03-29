<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Util\EngineHelper;
use Symfony\Component\Console\Command\Command;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Settings extends Command
{
    protected IUserManager $userManager;
    protected IUserConfig $userConfig;

    public function __construct(IUserManager $userManager, IUserConfig $userConfig)
    {
        parent::__construct();
        $this->userManager = $userManager;
        $this->userConfig = $userConfig;
    }

    protected function configure(): void
    {
        $this
            ->setName('x2mail:settings')
            ->setDescription('Legacy: set manual mail credentials when SSO is unavailable')
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = $input->getArgument('uid');
        if (!$this->userManager->userExists($uid)) {
            $output->writeln('<error>The user "' . $uid . '" does not exist.</error>');
            return 1;
        }

        $sEmail = $input->getArgument('user');
        $this->userConfig->setValueString($uid, 'x2mail', 'email', $sEmail);

        $sPass = $input->getArgument('pass');
        if (empty($sPass)) {
            $sPass = \readline("password: ");
        }
        $sPass = ($sEmail && $sPass) ? EngineHelper::encodePassword($sPass, \md5($sEmail)) : '';
        $this->userConfig->setValueString($uid, 'x2mail', 'passphrase', $sPass);
        return 0;
    }
}
