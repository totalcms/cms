<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Gates the automations admin + webhook routes by edition. Requires Pro.
 */
readonly class AutomationsEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::AUTOMATIONS;
	}
}
