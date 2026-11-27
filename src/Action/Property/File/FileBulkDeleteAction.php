<?php

declare(strict_types=1);

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST `/{coll}/{id}/{prop}/bulk/delete` with `{"names": ["a.jpg", …]}`:
 * remove several images from a gallery in one save, so the object changes
 * once (one `object.updated` event) however many images go.
 *
 * A POST rather than a DELETE with a body: some proxies drop a DELETE's body.
 * Access is the same as the single file delete: OperationDetector lists this
 * route beside `property-file-delete`.
 */
readonly class FileBulkDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private RemoverFactory $factory,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body     = $request->getParsedBody();
		$rawNames = is_array($body) ? ($body['names'] ?? null) : null;
		if (!is_array($rawNames)) {
			throw new HttpBadRequestException($request, 'A list of file names is required');
		}

		$names = array_values(array_unique(array_filter(
			array_map(static fn ($name): string => is_scalar($name) ? trim((string)$name) : '', $rawNames),
			static fn (string $name): bool => $name !== '',
		)));
		if ($names === []) {
			throw new HttpBadRequestException($request, 'A list of file names is required');
		}

		// As in FileDeleteAction: a property the schema does not have is a 404.
		try {
			$remover = $this->factory->generateRemoverService($args['collection'], $args['property']);
		} catch (\Throwable) {
			throw new HttpNotFoundException($request);
		}

		$result = $remover->deleteFiles($args['collection'], $args['id'], $args['property'], $names);

		return $this->renderer->json($response, $result);
	}
}
