<?php

declare(strict_types=1);

namespace TotalCMS\Action\Seo;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

/**
 * Serves /{key}.txt — the file the IndexNow engines fetch to confirm a
 * submission came from this site. Its body is the key itself.
 *
 * The route only matches a key-shaped path, and this answers only when the
 * path IS the configured key with IndexNow on; any other key-shaped .txt is
 * a 404, the same as a site without the feature. So nothing about the key
 * leaks, and no other root-level .txt is shadowed.
 */
final readonly class IndexNowKeyAction
{
	public function __construct(
		private SeoSettingsLoader $settings,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$settings = $this->settings->load();
		$key      = (string)($args['key'] ?? '');

		if (!$settings->indexNowEnabled || $settings->indexNowKey === '' || !hash_equals($settings->indexNowKey, $key)) {
			throw new HttpNotFoundException($request);
		}

		return $response
			->withHeader('Content-Type', 'text/plain; charset=utf-8')
			->withHeader('Cache-Control', 'public, max-age=86400')
			->withBody(Stream::create($key));
	}
}
