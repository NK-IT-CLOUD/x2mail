<?php

namespace X2Mail\Engine\Providers;

abstract class AbstractProvider
{
	use \X2Mail\Mail\Log\Inherit;

	abstract public function IsActive() : bool;
}
