<?php
/**
 * This class encrypts any data into JSON format.
 * Decrypted Objects are returned as Array.
 *
 * Tokens are ['sodium', nonce, ciphertext] (XChaCha20-Poly1305). The algorithm
 * name is part of the token, so it is the only one accepted: any other name,
 * such as the former xxtea (keyed by its own 16 byte salt, so anyone could mint
 * one) or openssl, is refused.
 */

namespace X2Mail\Engine;

abstract class Crypt
{
	/**
	 * Derive encryption passphrase from an explicit key, falling back to a
	 * stable application value when none is given.
	 */
	private static function Passphrase(
		#[\SensitiveParameter]
		?string $key
	) : string
	{
		return \sha1(($key ?: APP_VERSION) . APP_SALT, true);
	}

	public static function Decrypt(array $data,
		#[\SensitiveParameter]
		?string $key = null
	) /* : mixed */
	{
		if (3 !== \count($data) || !isset($data[0], $data[1], $data[2])) {
			Log::warning('Crypt', 'Decrypt() invalid $data');
			return null;
		}
		if ('sodium' !== $data[0]) {
			Log::warning('Crypt', 'Decrypt() refused algorithm ' . \json_encode((string) $data[0]));
			return null;
		}
		if (!\is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			throw new \RuntimeException('Crypt: sodium is required');
		}
		try {
			$result = static::SodiumDecrypt($data[2], $data[1], $key);
			if (\is_string($result)) {
				return self::jsonDecode($result);
			}
			throw new \RuntimeException('invalid $data or $key');
		} catch (\Throwable $e) {
			Log::error('Crypt', "SodiumDecrypt(): {$e->getMessage()}\n{$e->getTraceAsString()}");
		}
		return null;
	}

	public static function DecryptFromJSON(string $data,
		#[\SensitiveParameter]
		?string $key = null
	) /* : mixed */
	{
		$data = self::jsonDecode($data);
		if (!\is_array($data)) {
			Log::notice('Crypt', 'DecryptFromJSON() invalid $data');
			return null;
		}
		return static::Decrypt(\array_map('base64_decode', $data), $key);
	}

	public static function DecryptUrlSafe(string $data,
		#[\SensitiveParameter]
		?string $key = null
	) /* : mixed */
	{
		$data = \explode('.', $data);
		if (!\is_array($data)) {
			Log::notice('Crypt', 'DecryptUrlSafe() invalid $data');
			return null;
		}
		return static::Decrypt(\array_map('X2Mail\Mail\\Base\\Utils::UrlSafeBase64Decode', $data), $key);
	}

	/**
	 * @throws \RuntimeException without sodium or when encryption fails
	 */
	public static function Encrypt(
		#[\SensitiveParameter]
		$data,
		#[\SensitiveParameter]
		?string $key = null
	) : array
	{
		if (!\is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
			throw new \RuntimeException('Crypt: sodium is required');
		}
		$data = \json_encode($data, JSON_THROW_ON_ERROR);
		$nonce = \random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		return ['sodium', $nonce, static::SodiumEncrypt($data, $nonce, $key)];
	}

	public static function EncryptToJSON(
		#[\SensitiveParameter]
		$data,
		#[\SensitiveParameter]
		?string $key = null
	) : string
	{
		return \json_encode(\array_map('base64_encode', static::Encrypt($data, $key)));
	}

	public static function EncryptUrlSafe(
		#[\SensitiveParameter]
		$data,
		#[\SensitiveParameter]
		?string $key = null
	) : string
	{
		return \implode('.', \array_map('X2Mail\Mail\\Base\\Utils::UrlSafeBase64Encode', static::Encrypt($data, $key)));
	}

	public static function SodiumDecrypt(string $data, string $nonce,
		#[\SensitiveParameter]
		?string $key = null
	) /* : string|false */
	{
		if (!\is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			throw new \RuntimeException('Crypt: sodium is required');
		}
		return \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			$data,
			APP_SALT,
			$nonce,
			\str_pad('', \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::Passphrase($key))
		);
	}

	public static function SodiumEncrypt(
		#[\SensitiveParameter]
		string $data,
		string $nonce,
		#[\SensitiveParameter]
		?string $key = null
	) : string
	{
		if (!\is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
			throw new \RuntimeException('Crypt: sodium is required');
		}
		$result = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			$data,
			APP_SALT,
			$nonce,
			\str_pad('', \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::Passphrase($key))
		);
		if (!$result) {
			throw new \RuntimeException('Sodium encryption failed');
		}
		return $result;
	}

	private static function jsonDecode(string $data) /*: mixed*/
	{
		return \json_decode($data, true, 512, JSON_THROW_ON_ERROR);
	}

}
