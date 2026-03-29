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
		$imagick = new static();
		if (!$imagick->readImageBlob($data)) {
			throw new \InvalidArgumentException('Failed to load image');
		}
		$geo = $imagick->getImageGeometry();
		if ($geo['width'] * $geo['height'] > 25000000) { // 25 megapixels max
			$imagick->clear();
			return false;
		}
		$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
		return $imagick;
	}

	public static function createFromStream($fp)
	{
		$data = \stream_get_contents($fp);
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
