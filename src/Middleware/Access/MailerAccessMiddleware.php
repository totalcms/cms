<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

/**
 * Mailer Access Middleware.
 *
 * Enforces access group permissions for mailer access.
 */
readonly class MailerAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'mailer';

	/**
	 * Check if the user has permission to access mailer.
	 * Mailer doesn't have operation-based permissions, just boolean access.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return $actor->authority()->canMailer();
	}
}
