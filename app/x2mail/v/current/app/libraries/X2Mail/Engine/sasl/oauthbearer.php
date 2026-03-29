<?php
/**
 * https://datatracker.ietf.org/doc/html/rfc7628
 * https://developers.google.com/gmail/imap/xoauth2-protocol
 */

namespace X2Mail\Engine\SASL;

class OAuthBearer extends \X2Mail\Engine\SASL
{
	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $accessToken,
		?string $authzid = null
	) : string
	{
		// add host and port?
		//return $this->encode("n,a={$username},\x01host={$host}\x01port={$port}\x01auth=Bearer {$accessToken}\x01\x01");
		return $this->encode("n,a={$username},\x01auth=Bearer {$accessToken}\x01\x01");
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}
}
