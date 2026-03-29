<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2021 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Imap\Requests;

abstract class Request
{
	protected \X2Mail\Mail\Imap\ImapClient $oImapClient;

	function __construct(\X2Mail\Mail\Imap\ImapClient $oImapClient)
	{
		$this->oImapClient = $oImapClient;
	}

	final public function getName()
	{
		$name = \explode('\\', \get_class($this));
		return \end($name);
	}
}
