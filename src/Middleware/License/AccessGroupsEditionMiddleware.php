<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate access groups routes by edition.
 * Requires Standard or higher edition for access groups feature.
 */
readonly class AccessGroupsEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::ACCESS_GROUPS;
	}
}
