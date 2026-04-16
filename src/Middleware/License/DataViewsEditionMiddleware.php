<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate data views routes by edition.
 * Requires Pro or higher edition for data views feature.
 */
readonly class DataViewsEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::DATA_VIEWS;
	}
}
