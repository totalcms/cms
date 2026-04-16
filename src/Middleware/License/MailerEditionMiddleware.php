<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate mailer routes by edition.
 * Requires Standard or higher edition for mailer actions feature.
 */
readonly class MailerEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::MAILER_ACTIONS;
	}
}
