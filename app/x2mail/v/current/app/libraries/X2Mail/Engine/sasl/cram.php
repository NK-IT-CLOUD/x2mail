<?php

namespace X2Mail\Engine\SASL;

class Cram extends \X2Mail\Engine\SASL
{
	protected string $algo;

	function __construct(string $algo)
	{
		$algo = \strtolower($algo);
		if (!\in_array($algo, \hash_algos())) {
			throw new \Exception("Unsupported SASL CRAM algorithm: {$algo}");
		}
		$this->algo = $algo;
	}

	public function authenticate(string $authcid,
		#[\SensitiveParameter]
		string $passphrase,
		?string $challenge = null
	) : string
	{
		if (empty($challenge)) {
			throw new \X2Mail\Mail\RuntimeException('Empty CRAM challenge');
		}
		return $this->encode($authcid . ' ' . \hash_hmac($this->algo, $this->decode($challenge), $passphrase));
	}

	public static function isSupported(string $param) : bool
	{
		return \in_array(\strtolower($param), \hash_algos());
	}

}
