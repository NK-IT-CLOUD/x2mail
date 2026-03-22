<?php

declare(strict_types=1);

namespace OCA\X2Mail\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {}

	public function getID(): string {
		return 'x2mail';
	}

	public function getName(): string {
		return 'X2Mail';
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('x2mail', 'logo-64x64.png');
	}
}
