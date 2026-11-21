<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\Serving\PointerLocator;

/**
 * GET /admin/ext/totalcms/s3-uploads/open?collection=&id=&property=[&file=]
 *
 * The field's Open button: a five-minute signed link to the file, so an
 * editor can check what they uploaded whether the bucket is public or
 * private. Not counted as a download.
 */
final readonly class OpenAction
{
	private const EXPIRES = 300;

	public function __construct(
		private PointerLocator $locator,
		private S3Client $client,
		private S3Access $access,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$query      = $request->getQueryParams();
		$text       = fn (string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
		$collection = $text('collection');

		if (!$this->access->can($collection, 'read')) {
			throw new HttpForbiddenException($request);
		}

		$found = $this->locator->find($collection, $text('id'), $text('property'), $text('file'));
		if ($found === null) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		return $response
			->withStatus(302)
			->withHeader('Location', $this->client->presignGet($found['pointer']->key, self::EXPIRES))
			->withHeader('Cache-Control', 'no-store')
			->withHeader('Referrer-Policy', 'no-referrer');
	}
}
