<?php

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate API keys routes by edition.
 * Requires Pro edition for API keys feature.
 */
readonly class ApiKeysEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::API_KEYS;
	}
}
