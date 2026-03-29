<?php

namespace X2Mail\Engine\SASL;

use X2Mail\Engine\SensitiveString;

class Login extends \X2Mail\Engine\SASL
{
	protected
		SensitiveString $passphrase;

	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $passphrase,
		?string $challenge = null) : string
	{
		// $challenge should be 'VXNlcm5hbWU6', but broken on some systems
		// See
		if ($challenge && !\str_starts_with($this->decode($challenge), 'Username:')) {
			throw new \Exception("Invalid response: {$this->decode($challenge)}");
		}
		$this->passphrase = new SensitiveString($passphrase);
		return $this->encode($username);
	}

	public function challenge(string $challenge) : ?string
	{
		// $challenge should be 'UGFzc3dvcmQ6'
		if ($challenge && 'Password:' !== $this->decode($challenge)) {
			throw new \Exception("invalid response: {$challenge}");
		}
		return $this->encode($this->passphrase->getValue());
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}

}
