<?php

namespace X2Mail\Engine\HTTP\Request;

use X2Mail\Engine\HTTP\Response;

class CURL extends \X2Mail\Engine\HTTP\Request
{
	private
		$response_headers = array(),
		$response_body = '',
		$streamed_bytes = 0;

	public function supportsSSL() : bool
	{
		$v = \curl_version();
		if (\is_array($v)) {
			return \in_array('https', $v['protocols']);
		}
		return \is_string($v) ? !!\preg_match('/OpenSSL/i', $v) : false;
	}

	protected function __doRequest(string &$method, string &$request_url, &$body, array $extra_headers) : Response
	{
		$c = \curl_init();
		if (false === $c) {
			throw new \RuntimeException("Could not initialize CURL for URL '{$request_url}'");
		}

		$cv = \curl_version();
		// php.net/curl_setopt
		\curl_setopt_array($c, array(
			CURLOPT_USERAGENT      => $this->user_agent,
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_URL            => $request_url,
			CURLOPT_HEADERFUNCTION => array($this, 'fetchHeader'),
			CURLOPT_WRITEFUNCTION  => array($this, \is_resource($this->stream) ? 'streamData' : 'fetchData'),
			CURLOPT_SSL_VERIFYPEER => ($this->verify_peer || $this->ca_bundle),
			CURLOPT_SSL_VERIFYHOST => $this->verify_peer ? 2 : 0,
//			CURLOPT_FOLLOWLOCATION => false,       // follow redirects
//			CURLOPT_MAXREDIRS      => 0,           // stop after 0 redirects
		));
//		\curl_setopt($c, CURLOPT_ENCODING , 'gzip');
		if (\defined('CURLOPT_NOSIGNAL')) {
			\curl_setopt($c, CURLOPT_NOSIGNAL, true);
		}
		// SSRF: pin the connection to the vetted public IP so curl cannot
		// re-resolve the host to an internal address (DNS rebinding, S3).
		if ($this->block_private && \defined('CURLOPT_RESOLVE')) {
			$pin = \X2Mail\Engine\HTTP\Ssrf::pin($request_url);
			if ($pin) {
				\curl_setopt($c, CURLOPT_RESOLVE, array($pin));
			}
		}
		if ($this->ca_bundle) {
			\curl_setopt($c, CURLOPT_CAINFO, $this->ca_bundle);
		}
		if ($extra_headers) {
			\curl_setopt($c, CURLOPT_HTTPHEADER, $extra_headers);
		}
		if ($this->auth['user'] && $this->auth['type']) {
			if ($this->auth['type'] & self::AUTH_BEARER ) {
				\curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
				\curl_setopt($c, CURLOPT_XOAUTH2_BEARER, $this->auth['pass']);
			} else {
				$auth = 0;
				if ($this->auth['type'] & self::AUTH_BASIC) {
					$auth |= CURLAUTH_BASIC;
				}
				if ($this->auth['type'] & self::AUTH_DIGEST) {
					$auth |= CURLAUTH_DIGEST;
				}
				\curl_setopt($c, CURLOPT_HTTPAUTH, $auth);
				\curl_setopt($c, CURLOPT_USERPWD,  $this->auth['user'] . ':' . $this->auth['pass']);
			}
		}
		if ($this->proxy) {
			\curl_setopt($c, CURLOPT_PROXY, $this->proxy);
			if ($this->proxy_auth) {
				\curl_setopt($c, CURLOPT_PROXYUSERPWD, $this->proxy_auth);
			}
		}
		if ('HEAD' === $method) {
			\curl_setopt($c, CURLOPT_NOBODY, true);
		} else if ('GET' !== $method) {
			if ('POST' === $method) {
				\curl_setopt($c, CURLOPT_POST, true);
			} else {
				\curl_setopt($c, CURLOPT_CUSTOMREQUEST, $method);
			}
			if (!\is_null($body)) {
				\curl_setopt($c, CURLOPT_POSTFIELDS, $body);
			}
		}

		\curl_exec($c);

		try {
			$code = \curl_getinfo($c, CURLINFO_RESPONSE_CODE);
			if (!$code) {
				throw new \RuntimeException("Error " . \curl_errno($c) . ": " . \curl_error($c) . " for {$request_url}");
			}
			return new Response($request_url, $code, $this->response_headers, $this->response_body);
		} finally {
			unset($c);
			$this->response_headers = array();
			$this->response_body = '';
			$this->streamed_bytes = 0;
		}
	}

	protected function fetchHeader($ch, $header)
	{
		static $headers = [];
		if (!\strlen(\rtrim($header))) {
			$this->response_headers = $headers;
			$headers = [];
		} else {
			$headers[] = \rtrim($header);
		}
		return \strlen($header);
	}

	protected function fetchData($ch, $data)
	{
		if ($this->max_response_kb) {
			$data = \substr($data, 0, \min(\strlen($data), ($this->max_response_kb*1024) - \strlen($this->response_body)));
		}
		$this->response_body .= $data;
		return \strlen($data);
	}

	protected function streamData($ch, $data)
	{
		// Enforce max_response_kb on the streamed path too (security review S4).
		// Returning fewer bytes than received makes curl abort the transfer.
		if ($this->max_response_kb) {
			$remaining = ($this->max_response_kb * 1024) - $this->streamed_bytes;
			if ($remaining <= 0) {
				return 0;
			}
			if (\strlen($data) > $remaining) {
				$data = \substr($data, 0, $remaining);
			}
		}
		$written = \fwrite($this->stream, $data);
		$this->streamed_bytes += (int) $written;
		return $written;
	}

}
