<?php

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate templates routes by edition.
 * Requires Standard or higher edition for templates feature.
 */
readonly class TemplatesEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::TEMPLATES;
	}
}
