<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * GET /admin/ext/totalcms/s3-uploads/stats?collection=&id=.
 *
 * Answers `{"counts": {"brochure": 12}, "totals": {"brochure": 40}}`: the
 * download counts of one object's current files, and each property's all-time
 * total, for the field to show beside each file.
 */
final readonly class StatsAction
{
	public function __construct(
		private DownloadCounter $counter,
		private S3Access $access,
		private JsonRenderer $renderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$query      = $request->getQueryParams();
		$collection = is_string($query['collection'] ?? null) ? $query['collection'] : '';
		$id         = is_string($query['id'] ?? null) ? $query['id'] : '';

		if (!$this->access->can($collection, 'read')) {
			throw new HttpForbiddenException($request);
		}

		// An empty object is `{}`, not `[]`, so the field can always read it by key.
		return $this->renderer->json($response, [
			'counts' => (object)$this->counter->forObject($collection, $id),
			'totals' => (object)$this->counter->totalsForObject($collection, $id),
		]);
	}
}
