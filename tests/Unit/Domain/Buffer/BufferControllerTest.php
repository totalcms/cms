<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Buffer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Buffer\BufferController;

final class BufferControllerTest extends TestCase
{
	public function testStartBuffering(): void
	{
		$buffer = new BufferController();

		$result = $buffer->start();

		$this->assertTrue($result);
		$buffer->end(); // Clean up
	}

	public function testStartBufferingReturnsTrueIfAlreadyStarted(): void
	{
		$buffer = new BufferController();
		$buffer->start();

		$result = $buffer->start(); // Start again without force

		$this->assertTrue($result);
		$buffer->end(); // Clean up
	}

	public function testStartWithForce(): void
	{
		$buffer = new BufferController();
		$buffer->start();

		$result = $buffer->start(true); // Force new buffer

		$this->assertTrue($result);

		// Clean up the forced buffer
		if (ob_get_level() > 0) {
			ob_end_clean();
		}
		$buffer->end(); // Clean up the original buffer
	}

	public function testEndReturnsBufferContent(): void
	{
		$buffer = new BufferController();
		$buffer->start();
		echo 'Test content';

		$content = $buffer->end();

		$this->assertSame('Test content', $content);
	}

	public function testEndReturnsEmptyStringIfNotStarted(): void
	{
		$buffer = new BufferController();

		$content = $buffer->end();

		$this->assertSame('', $content);
	}

	public function testGetReturnsBufferAndRestartsBuffering(): void
	{
		$buffer = new BufferController();
		$buffer->start();
		echo 'First content';

		$content = $buffer->get();

		$this->assertSame('First content', $content);
		$this->assertTrue($buffer->isBuffering());

		$buffer->end(); // Clean up
	}

	public function testIsBufferingReturnsTrueWhenBuffering(): void
	{
		$buffer = new BufferController();
		$buffer->start();

		$this->assertTrue($buffer->isBuffering());

		$buffer->end(); // Clean up
	}

	public function testIsBufferingReturnsFalseWhenNotBuffering(): void
	{
		$buffer = new BufferController();

		$this->assertFalse($buffer->isBuffering());
	}

	public function testIsBufferingReturnsFalseAfterEnd(): void
	{
		$buffer = new BufferController();
		$buffer->start();
		$buffer->end();

		$this->assertFalse($buffer->isBuffering());
	}

	public function testMultipleBufferCycles(): void
	{
		$buffer = new BufferController();

		// First cycle
		$buffer->start();
		echo 'Content 1';
		$content1 = $buffer->end();

		// Second cycle
		$buffer->start();
		echo 'Content 2';
		$content2 = $buffer->end();

		$this->assertSame('Content 1', $content1);
		$this->assertSame('Content 2', $content2);
	}

	public function testEmptyBuffer(): void
	{
		$buffer = new BufferController();
		$buffer->start();

		$content = $buffer->end();

		$this->assertSame('', $content);
	}

	public function testBufferWithMultipleEchoes(): void
	{
		$buffer = new BufferController();
		$buffer->start();
		echo 'Line 1';
		echo "\n";
		echo 'Line 2';

		$content = $buffer->end();

		$this->assertSame("Line 1\nLine 2", $content);
	}
}
