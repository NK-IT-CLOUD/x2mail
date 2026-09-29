<?php

namespace X2Mail\Engine\Image;

/**
 * Attachment images come from external senders. Check the size and the
 * dimensions from the image header before any backend decodes the pixels.
 */
abstract class Limits
{
	const MAX_BYTES = 33554432; // 32 MiB
	const MAX_PIXELS = 25000000; // 25 megapixels

	/**
	 * Reads at most MAX_BYTES from the stream.
	 *
	 * @param resource $fp
	 */
	public static function read($fp) : string
	{
		$data = \stream_get_contents($fp, static::MAX_BYTES + 1);
		if (false === $data || \strlen($data) > static::MAX_BYTES) {
			throw new \InvalidArgumentException('Image too large');
		}
		return $data;
	}

	/**
	 * @return array the getimagesizefromstring() result
	 */
	public static function check(string $data) : array
	{
		if (\strlen($data) > static::MAX_BYTES) {
			throw new \InvalidArgumentException('Image too large');
		}
		$imginfo = \getimagesizefromstring($data);
		if (!$imginfo) {
			throw new \InvalidArgumentException('Invalid image');
		}
		if (!\in_array($imginfo[2], [\IMAGETYPE_GIF, \IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
			throw new \InvalidArgumentException('Unsupported fileformat: ' . $imginfo['mime']);
		}
		if (1 > $imginfo[0] || 1 > $imginfo[1] || $imginfo[0] * $imginfo[1] > static::MAX_PIXELS) {
			throw new \InvalidArgumentException('Image dimensions out of range');
		}
		return $imginfo;
	}
}
