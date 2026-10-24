<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Support\Config;

/**
 * Who may open the ImageWorks and file-links tools for an object.
 *
 * Both tools are opened in a dialog from an image or file field, which can
 * sit on a front-end form a member of any auth collection is editing. So
 * they are gated by the object they describe, not by the admin area: the
 * signed-in user must be able to read the object named in the query, by the
 * same rule the collection routes apply (groups, then ownership). Sessions
 * only, as before: an API key or a token has no use for an admin page.
 */
final readonly class MediaToolAccess
{
	public function __construct(
		private Config $config,
		private ActorResolver $actorResolver,
		private AccessGuard $guard,
	) {
	}

	/**
	 * @param string $defaultCollection the collection the tool's template falls back to
	 *
	 * @throws HttpForbiddenException when the caller may not read the object
	 */
	public function assertCanRead(ServerRequestInterface $request, string $defaultCollection): void
	{
		if (!$this->config->authEnabled()) {
			return;
		}

		$query      = $request->getQueryParams();
		$collection = is_string($query['collection'] ?? null) && $query['collection'] !== '' ? $query['collection'] : $defaultCollection;
		$id         = is_string($query['id'] ?? null) ? $query['id'] : '';

		$allowed = $request->getAttribute('authMethod') === 'session'
			&& $this->guard->can($this->actorResolver->fromSession(), $collection, Operation::Read, $id)->passes();

		if (!$allowed) {
			throw new HttpForbiddenException($request, 'Access denied');
		}
	}
}
