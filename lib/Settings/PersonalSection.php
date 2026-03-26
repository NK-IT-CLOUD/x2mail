<?php

declare(strict_types=1);

namespace OCA\X2Mail\Settings;

use OCA\X2Mail\Util\SnappyMailHelper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {
    }

    public function getID(): string
    {
        return 'x2mail';
    }

    public function getName(): string
    {
        try {
            SnappyMailHelper::loadApp();
            return \RainLoop\Api::Config()->Get('webmail', 'title', 'X2Mail') . ' ' . $this->l->t('Settings');
        } catch (\Throwable) {
            return 'X2Mail ' . $this->l->t('Settings');
        }
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('x2mail', 'logo-64x64.png');
    }
}
