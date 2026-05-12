<?php

use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;

describe('EventDispatcher', function (): void {
	test('dispatches event to registered listener', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());
		$received   = [];

		$dispatcher->listen('object.created', function (array $payload) use (&$received): void {
			$received[] = $payload;
		});

		$dispatcher->dispatch('object.created', new ObjectEventPayload('blog', 'post-1'));

		expect($received)->toHaveCount(1);
		expect($received[0]['collection'])->toBe('blog');
		expect($received[0]['id'])->toBe('post-1');
	});

	test('dispatches to multiple listeners', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());
		$calls      = [];

		$dispatcher->listen('object.created', function () use (&$calls): void {
			$calls[] = 'listener-1';
		});
		$dispatcher->listen('object.created', function () use (&$calls): void {
			$calls[] = 'listener-2';
		});

		$dispatcher->dispatch('object.created', new ObjectEventPayload('blog', 'test'));

		expect($calls)->toBe(['listener-1', 'listener-2']);
	});

	test('respects priority ordering', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());
		$calls      = [];

		$dispatcher->listen('test', function () use (&$calls): void {
			$calls[] = 'priority-50';
		}, 50);
		$dispatcher->listen('test', function () use (&$calls): void {
			$calls[] = 'priority-10';
		}, 10);
		$dispatcher->listen('test', function () use (&$calls): void {
			$calls[] = 'priority-30';
		}, 30);

		$dispatcher->dispatch('test', new CollectionEventPayload('test'));

		expect($calls)->toBe(['priority-10', 'priority-30', 'priority-50']);
	});

	test('does nothing when no listeners registered', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());

		// Should not throw
		$dispatcher->dispatch('nonexistent.event', new CollectionEventPayload('test'));

		expect(true)->toBeTrue();
	});

	test('catches exceptions from listeners without affecting others', function (): void {
		$dispatcher   = new EventDispatcher(new NullLogger());
		$secondCalled = false;

		$dispatcher->listen('test', function (): void {
			throw new RuntimeException('Listener failed');
		});
		$dispatcher->listen('test', function () use (&$secondCalled): void {
			$secondCalled = true;
		});

		$dispatcher->dispatch('test', new CollectionEventPayload('test'));

		expect($secondCalled)->toBeTrue();
	});

	test('hasListeners returns correct state', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());

		expect($dispatcher->hasListeners('test'))->toBeFalse();

		$dispatcher->listen('test', fn (): null => null);

		expect($dispatcher->hasListeners('test'))->toBeTrue();
		expect($dispatcher->hasListeners('other'))->toBeFalse();
	});

	test('registerAll adds listeners from extensions', function (): void {
		$dispatcher = new EventDispatcher(new NullLogger());
		$calls      = [];

		$dispatcher->registerAll([
			'object.created' => [
				[function () use (&$calls): void {
					$calls[] = 'ext-1';
				}, 0],
				[function () use (&$calls): void {
					$calls[] = 'ext-2';
				}, 10],
			],
			'object.deleted' => [
				[function () use (&$calls): void {
					$calls[] = 'ext-3';
				}, 0],
			],
		]);

		$dispatcher->dispatch('object.created', new ObjectEventPayload('blog', 'test'));
		$dispatcher->dispatch('object.deleted', new ObjectEventPayload('blog', 'test'));

		expect($calls)->toBe(['ext-1', 'ext-2', 'ext-3']);
	});
});
