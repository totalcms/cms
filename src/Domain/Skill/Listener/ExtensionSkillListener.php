<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Skill\Listener;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Skill\Service\ExtensionSkillSync;
use TotalCMS\Support\PathResolver;

/**
 * Enabling or disabling an extension in the admin or CLI syncs its agent
 * skill at once, so the operator does not wait for the next `composer
 * update` (which runs `skill:install`) to see the change. Best effort: a
 * project root the web process cannot write to is logged, never fatal.
 */
final readonly class ExtensionSkillListener
{
	public function __construct(
		private ExtensionSkillSync $sync,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onExtensionToggled(array $payload): void
	{
		try {
			$this->sync->sync(PathResolver::projectRoot() . '/.claude/skills', PathResolver::isComposerInstall());
		} catch (\Throwable $e) {
			$this->logger->warning('Extension skill sync failed after toggle: ' . $e->getMessage(), ['extension' => $payload['id'] ?? null]);
		}
	}
}
