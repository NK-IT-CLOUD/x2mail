<?php

namespace X2Mail\Engine\PGP;

// Signature summary bits, values from gpgme (src/gpgme.h.in, gpgme_sigsum_t).
// The PECL gnupg extension defines them; the gpg binary backend needs them too.
// Defined here because every PGP backend implements this interface.
defined('GNUPG_SIGSUM_VALID') || define('GNUPG_SIGSUM_VALID', 0x0001);
defined('GNUPG_SIGSUM_GREEN') || define('GNUPG_SIGSUM_GREEN', 0x0002);
defined('GNUPG_SIGSUM_RED') || define('GNUPG_SIGSUM_RED', 0x0004);
defined('GNUPG_SIGSUM_KEY_REVOKED') || define('GNUPG_SIGSUM_KEY_REVOKED', 0x0010);
defined('GNUPG_SIGSUM_KEY_EXPIRED') || define('GNUPG_SIGSUM_KEY_EXPIRED', 0x0020);
defined('GNUPG_SIGSUM_SIG_EXPIRED') || define('GNUPG_SIGSUM_SIG_EXPIRED', 0x0040);
defined('GNUPG_SIGSUM_KEY_MISSING') || define('GNUPG_SIGSUM_KEY_MISSING', 0x0080);
defined('GNUPG_SIGSUM_CRL_MISSING') || define('GNUPG_SIGSUM_CRL_MISSING', 0x0100);
defined('GNUPG_SIGSUM_CRL_TOO_OLD') || define('GNUPG_SIGSUM_CRL_TOO_OLD', 0x0200);
defined('GNUPG_SIGSUM_BAD_POLICY') || define('GNUPG_SIGSUM_BAD_POLICY', 0x0400);
defined('GNUPG_SIGSUM_SYS_ERROR') || define('GNUPG_SIGSUM_SYS_ERROR', 0x0800);

use X2Mail\Engine\SensitiveString;

interface PGPInterface
{
	public static function isSupported() : bool;
//	public function addPinentry(string $keyId, SensitiveString $passphrase);
//	public function clearPinentries() : bool
	public function addDecryptKey(string $fingerprint, SensitiveString $passphrase) : bool;
	public function addEncryptKey(string $fingerprint) : bool;
	public function addSignKey(string $fingerprint, SensitiveString $passphrase) : bool;
	public function clearDecryptKeys() : bool;
	public function clearEncryptKeys() : bool;
	public function clearSignKeys() : bool;
	public function decrypt(string $text) /*: string|false */;
	public function decryptFile(string $filename) /*: string|false */;
	public function decryptStream(/*resource*/ $fp, /*string|resource*/ $output = null) /*: string|false */;
	public function decryptVerify(string $text, string &$plaintext) /*: array|false*/;
	public function decryptVerifyFile(string $filename, string &$plaintext) /*: array|false*/;
	public function deleteKey(string $keyId, bool $private) : bool;
	public function encrypt(string $plaintext) /*: string|false*/;
	public function encryptFile(string $filename) /*: string|false*/;
	public function encryptStream(/*resource*/ $fp, /*string|resource*/ $output = null) /*: string|false*/;
	public function export(string $fingerprint, ?SensitiveString $passphrase = null) /*: string|false*/;
	public function getEngineInfo() : array;
	public function getError() /*: string|false*/;
	public function getErrorInfo() : array;
	public function getProtocol() : int;
	public function generateKey(string $uid, SensitiveString $passphrase) /*: string|false*/;
	public function import(string $keydata) /*: array|false*/;
	public function importFile(string $filename) /*: array|false*/;
	public function allKeysInfo(string $pattern) : array;
	public function setArmor(bool $armor = true) : bool;
	public function setErrorMode(int $errormode) : void;
	public function setSignMode(int $signmode) : bool;
	public function sign(string $plaintext) /*: string|false*/;
	public function signFile(string $filename) /*: string|false*/;
	public function signStream($fp, /*string|resource*/ $output = null) /*: string|false*/;
	public function verify(string $signed_text, string $signature, ?string &$plaintext = null) /*: array|false*/;
	public function verifyFile(string $filename, string $signature, ?string &$plaintext = null) /*: array|false*/;
	public function verifyStream(/*resource*/ $fp, string $signature, ?string &$plaintext = null) /*: array|false */;
}
