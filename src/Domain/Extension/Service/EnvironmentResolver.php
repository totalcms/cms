<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Support\Config;

/**
 * Single source of truth for environment-dependent extension safety behavior.
 *
 * Auto-quarantine is destructive (it disables a customer's extension), so it
 * fires ONLY on a true production request: env === 'prod' AND not a Stacks
 * preview. preview is tracked separately from Config->env, so it must be
 * excluded explicitly here.
 */
final readonly class EnvironmentResolver
{
	public function __construct(
		private Config $config,
		private bool $isPreview,
	) {
	}

	/** Repeat-crasher auto-disable is allowed only on true production. */
	public function isQuarantineAllowed(): bool
	{
		return $this->config->env === 'prod' && !$this->isPreview;
	}

	/** Outside prod, surface extension errors loudly so builders see them. */
	public function shouldSurfaceErrors(): bool
	{
		return $this->config->env !== 'prod' || $this->isPreview;
	}
}
