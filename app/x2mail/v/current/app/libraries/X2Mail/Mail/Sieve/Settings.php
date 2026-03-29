<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2022 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Sieve;

/**
 * @category MailSo
 * @package Net
 */
class Settings extends \X2Mail\Mail\Net\ConnectSettings
{
	public int $port = 4190;

	public bool $enabled = false;

	public bool $authLiteral = true;

	public static function fromArray(array $aSettings) : self
	{
		/** @var self $object */
		$object = parent::fromArray($aSettings);
		$object->enabled = !empty($aSettings['enabled']);
		$object->authLiteral = !isset($aSettings['authLiteral']) || !empty($aSettings['authLiteral']);
		return $object;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return \array_merge(
			parent::jsonSerialize(),
			[
//				'@Object' => 'Object/SmtpSettings',
				'enabled' => $this->enabled,
				'authLiteral' => $this->authLiteral
			]
		);
	}

}
