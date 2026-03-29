<?php

namespace X2Mail\Engine\Rtf;

class Text implements Entity, \Stringable
{
	public string $text;

	public function __construct(string $text)
	{
		$this->text = $text;
	}

	public function __toString(): string
	{
		return $this->text;
	}
}
