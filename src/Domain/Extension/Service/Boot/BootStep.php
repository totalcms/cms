<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared constructor for every ExtensionManager::BOOT_STEPS class.
 *
 * bootAll() instantiates each step dynamically with the container and the
 * manager's logger, so the signature has to be one every step shares — hence
 * a final constructor here rather than a convention repeated ten times. The
 * properties are protected on purpose: a step that never reads the logger
 * is fine, and neither PHPStan nor Rector's dead-code pass will strip a
 * protected property the way they did the private write-only copies each
 * step used to declare (which is how Rector once broke boot).
 */
abstract readonly class BootStep implements ExtensionBootStep
{
	final public function __construct(
		protected ContainerInterface $container,
		protected LoggerInterface $logger,
	) {
	}
}
