<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;

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
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessMailer($userId);
	}
}
