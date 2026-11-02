<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Exception\RenderBusyException;
use TotalCMS\Domain\ImageWorks\Service\RenderSlots;

/**
 * Hold every slot file the way another PHP-FPM worker would.
 *
 * @return list<resource>
 */
function renderSlotsHoldAll(string $dir, int $slots): array
{
	if (!is_dir($dir)) {
		mkdir($dir, 0755, true);
	}

	$handles = [];
	for ($slot = 0; $slot < $slots; $slot++) {
		$handle = fopen("{$dir}/slot-{$slot}.lock", 'c');
		expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
		$handles[] = $handle;
	}

	return $handles;
}

describe('RenderSlots', function (): void {
	beforeEach(function (): void {
		$this->dir = tcmsTestTempDir('render-slots') . '/slots';
	});

	afterEach(function (): void {
		foreach (glob($this->dir . '/*.lock') ?: [] as $file) {
			unlink($file);
		}
		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
	});

	it('runs the render and hands back its result', function (): void {
		expect((new RenderSlots($this->dir, 2, 0))->run(fn (): string => 'rendered'))->toBe('rendered');
	});

	it('releases the slot when the render throws', function (): void {
		$slots = new RenderSlots($this->dir, 1, 0);

		expect(fn () => $slots->run(fn () => throw new RuntimeException('decode failed')))->toThrow(RuntimeException::class);
		expect($slots->run(fn (): string => 'next'))->toBe('next');
	});

	it('takes a free slot while others are held', function (): void {
		$held = renderSlotsHoldAll($this->dir, 1);

		expect((new RenderSlots($this->dir, 2, 0))->run(fn (): string => 'rendered'))->toBe('rendered');

		array_map(fclose(...), $held);
	});

	it('throws busy with a Retry-After once every slot stays held for the wait', function (): void {
		$held  = renderSlotsHoldAll($this->dir, 2);
		$start = microtime(true);

		try {
			(new RenderSlots($this->dir, 2, 0.2))->run(fn (): string => 'never');
			$this->fail('expected RenderBusyException');
		} catch (RenderBusyException $e) {
			expect($e->retryAfter)->toBe(1);
			expect(microtime(true) - $start)->toBeGreaterThanOrEqual(0.2);
		}

		array_map(fclose(...), $held);
	});

	it('runs unlimited when the slot folder cannot be made', function (): void {
		$file = tcmsTestTempDir('render-slots-file');
		mkdir($file, 0755, true);
		touch($file . '/not-a-dir');

		expect((new RenderSlots($file . '/not-a-dir/slots', 1, 0))->run(fn (): string => 'rendered'))->toBe('rendered');

		unlink($file . '/not-a-dir');
		rmdir($file);
	});
});
