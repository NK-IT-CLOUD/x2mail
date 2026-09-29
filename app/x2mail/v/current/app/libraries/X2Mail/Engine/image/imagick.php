<?php

namespace X2Mail\Engine\Image;

if (!\class_exists('Imagick',false)) { return; }

class IMagick extends \Imagick implements \X2Mail\Engine\Image
{
	function __destruct()
	{
		$this->clear();
	}

	public function valid() : bool
	{
		return 0 < $this->getImageWidth();
	}

	public static function createFromString(string &$data)
	{
		Limits::check($data);
		/** @phpstan-ignore new.static */
		$imagick = new static();
		// ImageMagick decodes every frame (animated GIF/WebP), so sum up the
		// frame sizes without decoding the pixels first.
		if (!$imagick->pingImageBlob($data)) {
			throw new \InvalidArgumentException('Failed to load image');
		}
		$pixels = 0;
		// No foreach: its frame objects would run __destruct() and clear the wand
		for ($i = 0, $n = $imagick->getNumberImages(); $i < $n; ++$i) {
			$imagick->setIteratorIndex($i);
			$pixels += $imagick->getImageWidth() * $imagick->getImageHeight();
		}
		$imagick->clear();
		if ($pixels > Limits::MAX_PIXELS) {
			throw new \InvalidArgumentException('Image dimensions out of range');
		}
		if (!$imagick->readImageBlob($data)) {
			throw new \InvalidArgumentException('Failed to load image');
		}
		$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
		return $imagick;
	}

	public static function createFromStream($fp)
	{
		$data = Limits::read($fp);
		return static::createFromString($data);
/*
		$imagick = new static();
		if (!$imagick->readImageFile($fp)) {
			throw new \InvalidArgumentException('Failed to load image');
		}
		$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
		return $imagick;
*/
	}

	public function getOrientation() : int
	{
		return $this->getImageOrientation();
	}

	public function rotate(float $degrees) : bool
	{
		return $this->rotateImage(new \ImagickPixel(), $degrees);
	}

	public function show(?string $format = null) : void
	{
		$format && $this->setImageFormat($format);
		\header('Content-Type: ' . $this->getImageMimeType());
		echo $this;
	}
}
