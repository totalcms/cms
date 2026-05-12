<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate passkey registration and management routes by edition.
 * Requires Standard edition for passkeys feature.
 */
readonly class PasskeyEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::PASSKEYS;
	}
}
