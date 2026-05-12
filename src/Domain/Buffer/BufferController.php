<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Buffer;

// ---------------------------------------------------------------------------------
// Buffer Controller
// ---------------------------------------------------------------------------------
class BufferController
{
	private int $initialBufferLevel = 0;
	private bool $bufferStarted     = false;

	public function __construct()
	{
		// Remember the buffer level when we start
		// This allows us to work correctly even if PHP has automatic buffering
		$this->initialBufferLevel = ob_get_level();
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function start(bool $force = false): bool
	{
		// Always start our own buffer if we haven't already
		// This ensures we can capture content even when PHP has automatic buffering
		if (!$force && $this->bufferStarted) {
			return true;
		}

		$this->bufferStarted = ob_start();

		return $this->bufferStarted;
	}

	public function end(): string
	{
		// Only end our buffer if we started one
		if (!$this->bufferStarted) {
			return '';
		}

		$buffer              = ob_get_clean() ?: '';
		$this->bufferStarted = false;

		return $buffer;
	}

	public function get(): string
	{
		$buffer = $this->end();
		$this->start();

		return $buffer;
	}

	public function isBuffering(): bool
	{
		// Return true if WE started a buffer, not just if any buffer exists
		return $this->bufferStarted && ob_get_level() > $this->initialBufferLevel;
	}
}
