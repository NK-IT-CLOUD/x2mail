<?php

namespace X2Mail\Engine\Image;

if (!\class_exists('Gmagick',false)) { return; }

/**
 * @method int getImageOrientation()
 * @method string getimageblob()
 */
class GMagick extends \Gmagick implements \X2Mail\Engine\Image
{
	private
		$orientation = 0;

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
		$gmagick = new static();
		if (!$gmagick->readimageblob($data)) {
			throw new \InvalidArgumentException('Failed to load image');
		}
		if (\method_exists($gmagick, 'getImageOrientation')) {
			$gmagick->orientation = $gmagick->getImageOrientation();
		} else {
			$gmagick->orientation = Exif::getImageOrientation($data);
		}
		return $gmagick;
	}

	public static function createFromStream($fp)
	{
		// Always through createFromString(), so the limits apply
		$data = Limits::read($fp);
		return static::createFromString($data);
	}

	public function getOrientation() : int
	{
		return $this->orientation;
	}

	public function rotate(float $degrees) : bool
	{
		$this->rotateImage(new \GmagickPixel(), $degrees);

		return true;
	}

	public function show(?string $format = null) : void
	{
		$format && $this->setImageFormat($format);
		\header('Content-Type: ' . $this->getImageMimeType());
		echo $this->getimageblob();
	}

	public function getImageMimeType() : string
	{
		switch (\strtolower(parent::getImageFormat()))
		{
		case 'png':
		case 'png8':
		case 'png24':
		case 'png32':
			return 'image/png';
		case 'jpeg':
			return 'image/jpeg';
		case 'gif':
			return 'image/gif';
		case 'webp':
			return 'image/webp';
		}
		return 'application/octet-stream';
	}
}
