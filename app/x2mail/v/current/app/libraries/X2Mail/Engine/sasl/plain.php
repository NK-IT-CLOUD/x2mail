<?php

namespace X2Mail\Engine\SASL;

class Plain extends \X2Mail\Engine\SASL
{

	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $passphrase,
		?string $authzid = null) : string
	{
		return $this->encode("{$authzid}\x00{$username}\x00{$passphrase}");
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}

}
