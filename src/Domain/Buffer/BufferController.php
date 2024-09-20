<?php

namespace TotalCMS\Domain\Buffer;

// ---------------------------------------------------------------------------------
// Buffer Controller
// ---------------------------------------------------------------------------------
class BufferController
{
	public function start(): bool
	{
		// Don't start a new buffer if one is already started
		if ($this->isBuffering()) {
			return true;
		}

		return ob_start();
	}

	public function end(): string
	{
		$buffer = $this->isBuffering() ? ob_get_clean() : '';

		return $buffer ? $buffer : '';
	}

	public function get(): string
	{
		$buffer = $this->end();
		$this->start();

		return $buffer;
	}

	public function isBuffering(): bool
	{
		return ob_get_level() > 1;
	}
}
