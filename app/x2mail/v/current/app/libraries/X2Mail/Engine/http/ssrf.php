<?php

namespace X2Mail\Engine\HTTP;

/**
 * SSRF guard for the outbound HTTP layer (security review S3, 2026-05-31).
 *
 * Rejects requests whose host resolves to a private, loopback, link-local or
 * otherwise reserved address — including the cloud metadata endpoint
 * 169.254.169.254. Fails closed on anything that does not resolve to a
 * verifiably public address.
 */
class Ssrf
{
	/** IPv4 ranges that must never be a fetch target (CIDR). */
	private const BLOCKED_V4 = [
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
		'169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
		'192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
		'203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
	];

	/**
	 * Classify a literal IP address as unsafe to fetch from.
	 */
	public static function isBlockedIp(string $ip) : bool
	{
		$packed = \inet_pton($ip);
		if (false === $packed) {
			return true; // not a valid IP — fail closed
		}

		// IPv4-mapped IPv6 (::ffff:a.b.c.d) — classify as the embedded IPv4.
		if (16 === \strlen($packed)
		 && \str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
			return self::isBlockedIpv4(\inet_ntop(\substr($packed, 12)) ?: '');
		}

		return 4 === \strlen($packed)
			? self::isBlockedIpv4($ip)
			: self::isBlockedIpv6($packed);
	}

	private static function isBlockedIpv4(string $ip) : bool
	{
		$long = \ip2long($ip);
		if (false === $long) {
			return true;
		}
		$long &= 0xFFFFFFFF;
		foreach (self::BLOCKED_V4 as $cidr) {
			[$net, $bits] = \explode('/', $cidr);
			$mask = (0xFFFFFFFF << (32 - (int) $bits)) & 0xFFFFFFFF;
			if ((\ip2long($net) & $mask) === ($long & $mask)) {
				return true;
			}
		}
		return false;
	}

	private static function isBlockedIpv6(string $packed) : bool
	{
		// :: (unspecified) and ::1 (loopback)
		if ($packed === \str_repeat("\x00", 16)
		 || $packed === \str_repeat("\x00", 15) . "\x01") {
			return true;
		}
		$b0 = \ord($packed[0]);
		// fc00::/7 unique-local
		if (0xfc === ($b0 & 0xfe)) {
			return true;
		}
		// fe80::/10 link-local
		if (0xfe === $b0 && 0x80 === (\ord($packed[1]) & 0xc0)) {
			return true;
		}
		// fec0::/10 site-local (deprecated, still routed internally)
		if (0xfe === $b0 && 0xc0 === (\ord($packed[1]) & 0xc0)) {
			return true;
		}
		// ff00::/8 multicast
		if (0xff === $b0) {
			return true;
		}
		return false;
	}

	/**
	 * Throw unless every address $url resolves to is a public destination.
	 *
	 * @throws \RuntimeException
	 */
	public static function assertPublicUrl(string $url) : void
	{
		$host = \parse_url($url, \PHP_URL_HOST);
		if (!\is_string($host) || '' === $host) {
			throw new \RuntimeException('SSRF guard: URL has no host');
		}
		$host = \trim($host, '[]'); // strip IPv6 brackets

		$ips = self::resolveHost($host);
		if (!$ips) {
			throw new \RuntimeException("SSRF guard: cannot resolve host '{$host}'");
		}
		foreach ($ips as $ip) {
			if (self::isBlockedIp($ip)) {
				throw new \RuntimeException("SSRF guard: '{$host}' resolves to blocked address {$ip}");
			}
		}
	}

	/**
	 * Build a CURLOPT_RESOLVE entry ("host:port:ip") pinning $url to a vetted
	 * public IP, closing the DNS-rebinding window between assertPublicUrl() and
	 * the actual connect. Returns null for literal IPs (nothing to rebind) or
	 * when no public address is found.
	 */
	public static function pin(string $url) : ?string
	{
		$host = \parse_url($url, \PHP_URL_HOST);
		if (!\is_string($host) || '' === $host) {
			return null;
		}
		$host = \trim($host, '[]');
		if (\filter_var($host, \FILTER_VALIDATE_IP)) {
			return null; // literal IP — no DNS, no rebind
		}
		$scheme = \parse_url($url, \PHP_URL_SCHEME) ?: 'http';
		$port = \parse_url($url, \PHP_URL_PORT) ?: ('https' === $scheme ? 443 : 80);
		foreach (self::resolveHost($host) as $ip) {
			if (!self::isBlockedIp($ip)) {
				return "{$host}:{$port}:{$ip}";
			}
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function resolveHost(string $host) : array
	{
		if (\filter_var($host, \FILTER_VALIDATE_IP)) {
			return [$host];
		}
		$ips = [];
		$v4 = \gethostbynamel($host);
		if (\is_array($v4)) {
			$ips = $v4;
		}
		$v6 = @\dns_get_record($host, \DNS_AAAA);
		if (\is_array($v6)) {
			foreach ($v6 as $rec) {
				if (!empty($rec['ipv6'])) {
					$ips[] = $rec['ipv6'];
				}
			}
		}
		return $ips;
	}
}
