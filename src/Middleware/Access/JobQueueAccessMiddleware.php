<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

/**
 * Job Queue Access Middleware.
 *
 * Enforces the `utils` / 'jobqueue' or 'import' access-group permission on
 * the routes that clear the job queue. They carried only AuthMiddleware, so
 * any admin login could wipe queued imports and automation runs whatever its
 * access group: the authorization matrix (2026-10-31) cleared all three as a
 * user in the read-only `viewer` group. The two places that offer the
 * buttons are the Job Queue utility and a collection's import page, so
 * either permission will do. The stats routes stay as they are: they only
 * report counts.
 */
readonly class JobQueueAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'jobqueue';

	/**
	 * A single boolean permission per util, so $operation is unused.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		$authority = $actor->authority();

		return $authority->canUtil('jobqueue') || $authority->canUtil('import');
	}

	/**
	 * These routes are not in OperationDetector's route-name lists, so the
	 * default would deny on detection failure before checkPermission() runs.
	 * Mirrors ImportAccessMiddleware's override.
	 */
	protected function detectOperation(ServerRequestInterface $request): ?string
	{
		return 'delete';
	}
}
