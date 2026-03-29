<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2021 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Imap\Responses;

/**
 * @category MailSo
 * @package Imap
 */
class ACL implements \JsonSerializable
{
	public bool $mine = false;
	private string
		$identifier,
		$rights;

	function __construct(string $identifier, string $rights)
	{
		$this->identifier = $identifier;
		$this->rights = $rights;
	}

	public function identifier() : string
	{
		return $this->identifier;
	}

	public function hasRight(string|\X2Mail\Mail\Imap\Enumerations\FolderACL $right) : bool
	{
		if ($right instanceof \X2Mail\Mail\Imap\Enumerations\FolderACL) {
			return \str_contains($this->rights, $right->value);
		}
		$enum = \X2Mail\Mail\Imap\Enumerations\FolderACL::tryFrom($right);
		return \str_contains($this->rights, $enum ? $enum->value : $right);
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return [
			'@Object' => 'Object/FolderACLRights',
			'identifier' => $this->identifier,
			'rights' => $this->rights,
			'mine' => $this->mine,
/*
			'mayReadItems'   => ($this->hasRight('l') && $this->hasRight('r')),
			'mayAddItems'    => $this->hasRight('i'),
			'mayRemoveItems' => ($this->hasRight('t') && $this->hasRight('e')),
			'maySetSeen'     => $this->hasRight('s'),
			'maySetKeywords' => $this->hasRight('w'),
			'mayCreateChild' => $this->hasRight('k'),
			'mayRename'      => $this->hasRight('x'),
			'mayDelete'      => $this->hasRight('x'),
			'maySubmit'      => $this->hasRight('p')
*/
		];
	}

}
