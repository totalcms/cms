<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/** Wire event listeners from extensions into the EventDispatcher. */
final readonly class EventListenersStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(EventDispatcher::class)) {
			return;
		}

		$eventListeners = $manager->getAllEventListeners();
		if ($eventListeners !== []) {
			/** @var EventDispatcher $dispatcher */
			$dispatcher = $this->container->get(EventDispatcher::class);
			$dispatcher->registerAll($eventListeners);
		}
	}
}
