<?php

namespace X2Mail\Engine\Providers\Suggestions;

interface ISuggestions
{
//	use \X2Mail\Mail\Log\Inherit;
	public function Process(\X2Mail\Engine\Model\Account $oAccount, string $sQuery, int $iLimit = 20) : array;
//	public function SetLogger(\X2Mail\Mail\Log\Logger $oLogger) : void
}
