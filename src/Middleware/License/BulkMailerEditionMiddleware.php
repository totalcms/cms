<?php

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate bulk mailer routes by edition.
 * Requires Pro edition for bulk mailer feature.
 */
readonly class BulkMailerEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::BULK_MAILER;
	}
}
