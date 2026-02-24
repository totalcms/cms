<?php

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate RSS import routes by edition.
 * Requires Standard or higher edition for RSS import feature.
 */
readonly class RssImportEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::RSS_IMPORT;
	}
}
