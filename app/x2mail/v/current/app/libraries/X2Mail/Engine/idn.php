<?php
/**
 * Internationalized domain names
 */

namespace X2Mail\Engine;

abstract class IDN
{
	/**
	 * Converts domain name to lowercased punycode
	 * When the '@' is in, only the part after is changed
	 * Like: '📧.X2Mail.EU' to 'xn--du8h.x2mail.dev'
	 */
	public static function toAscii(string $value) : string
	{
		$local = \explode('@', $value);
		$domain = self::domain(\array_pop($local), true);
		$local[] = $domain;
		return \implode('@', $local);
	}

	/**
	 * Converts IDN domain part to lowercased punycode
	 * Like: 'Smile😀@📧.X2Mail.eu' to 'Smile😀@xn--du8h.x2mail.dev'
	 * When the '@' is missing, it does nothing
	 */
	public static function emailToAscii(string $address) : string
	{
		return self::emailAddress($address, true);
	}

	/**
	 * Converts IDN domain part to unicode
	 * Like: 'Smile😀@xn--du8h.X2Mail.eu' to 'Smile😀@📧.X2Mail.eu'
	 * When the '@' is missing, it does nothing
	 */
	public static function emailToUtf8(string $address) : string
	{
		return self::emailAddress($address, false);
	}

	private static function domain(string $domain, bool $toAscii) : string
	{
//		if ($toAscii && \preg_match('/[^\x20-\x7E]/', $domain)) {
//		if (!$toAscii && \preg_match('/(^|\\.)xn--/i', $domain)) {
		return $toAscii ? \strtolower(\idn_to_ascii($domain)) : \idn_to_utf8($domain);
/*
		$domain = \explode('.', $domain);
		foreach ($domain as $k => $v) {
			$conv = $toAscii ? \idn_to_ascii($v) : \idn_to_utf8($v);
			if ($conv) $domain[$k] = $conv;
		}
		return \implode('.', $domain);
*/
	}

	private static function emailAddress(string $address, bool $toAscii) : string
	{
		if (!\str_contains($address, '@')) {
//			throw new \RuntimeException("Invalid email address: {$address}");
			return $address;
		}
		$local = \explode('@', $address);
		$domain = self::domain(\array_pop($local), $toAscii);
		return \implode('@', $local) . '@' . $domain;
	}

	private static function uri(string $address, bool $toAscii) : string
	{
		$parsed = \parse_url($address);
		if (isset($parsed['host'])) {
			$parsed['host'] = self::domain($parsed['host'], $toAscii);

			$url = empty($parsed['scheme']) ? '//'
				: $parsed['scheme'] . (\strtolower($parsed['scheme']) === 'mailto' ? ':' : '://');
/*
			if (!empty($parsed['user']) || !empty($parsed['pass'])) {
				$ret_url .= $parts_arr['user'];
				if (!empty($parts_arr['pass'])) {
					$ret_url .= ':' . $parts_arr['pass'];
				}
				$ret_url .= '@';
			}
*/
			if (!empty($parsed['host']))     { $url .= $parsed['host']; }
			if (!empty($parsed['port']))     { $url .= ':'.$parsed['port']; }
			if (!empty($parsed['path']))     { $url .= $parsed['path']; }
			if (!empty($parsed['query']))    { $url .= '?'.$parsed['query']; }
			if (!empty($parsed['fragment'])) { $url .= '#'.$parsed['fragment']; }
			return $url;
		}

		// parse_url seems to have failed, try without it
		return self::domain($address, $toAscii);
	}

}
