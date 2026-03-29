<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Mime;

/**
 * @category MailSo
 * @package Mime
 */
class AttachmentCollection extends \X2Mail\Mail\Base\Collection
{
	public function append($oAttachment, bool $bToTop = false) : void
	{
		assert($oAttachment instanceof Attachment);
		parent::append($oAttachment, $bToTop);
	}
}
