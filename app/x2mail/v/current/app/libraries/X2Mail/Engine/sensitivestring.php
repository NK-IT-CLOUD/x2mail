<?php

namespace X2Mail\Engine;

class SensitiveString /* extends SensitiveParameterValue | SensitiveParameter */ implements \Stringable, \JsonSerializable
{
	private string $value, $nonce;
	private static ?string $key = null;

	public function __construct(
		#[\SensitiveParameter]
		string $value
	)
	{
		$this->setValue($value);
	}

	public function getValue(): string
	{
		if (\is_callable('sodium_crypto_secretbox')) {
			return \sodium_crypto_secretbox_open($this->value, $this->nonce, self::$key);
		}
		return self::xorIt($this->value);
	}

	public function setValue(
		#[\SensitiveParameter]
		string $value
	) : void
	{
		\strlen($value) && \X2Mail\Engine\Api::Actions()->logMask($value);
		if (\is_callable('sodium_crypto_secretbox')) {
			$this->nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			if (!self::$key) {
				self::$key = \sodium_crypto_secretbox_keygen();
			}
			$this->value = \sodium_crypto_secretbox($value, $this->nonce, self::$key);
		} else {
			$this->value = self::xorIt($value);
		}
	}

	private static function xorIt(
		#[\SensitiveParameter]
		string $value
	) : string
	{
		if (!self::$key) {
			self::$key = \random_bytes(32);
		}
		$kl = \strlen(self::$key);
		$i = \strlen($value);
		while ($i--) {
			$value[$i] = $value[$i] ^ self::$key[$i % $kl];
		}
		return $value;
	}

	public function __toString(): string
	{
		return $this->getValue();
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		throw new \Exception("JSON serialization of 'X2Mail\\Engine\\SensitiveString' is not allowed");
	}

	public function __debugInfo(): array
	{
		return [];
	}

	public function __serialize(): array
	{
		throw new \Exception("Serialization of 'X2Mail\\Engine\\SensitiveString' is not allowed");
	}

	public function __unserialize(array $data): void
	{
		throw new \Exception("Unserialization of 'X2Mail\\Engine\\SensitiveString' is not allowed");
	}
}
