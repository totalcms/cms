<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate Pushover routes by edition.
 * Requires Pro edition for Pushover notifications.
 */
readonly class PushoverEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::PUSHOVER_ACTIONS;
	}
}
