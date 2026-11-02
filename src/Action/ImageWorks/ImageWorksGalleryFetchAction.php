<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;

readonly class ImageWorksGalleryFetchAction
{
	use ImageWorksResponder;

	public function __construct(private ImageGenerator $imageGenerator)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The arguments
	 *
	 * @throws HttpNotFoundException
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection  = $args['collection'] ?? 'gallery';
		$id          = $args['id'];
		$property    = $args['property'] ?? 'gallery';
		// `{path:.+}` may carry multiple segments (deck-nested: `itemId/childKey`).
		// Falls back to the legacy single-segment `{name}` arg.
		$path        = $args['path'] ?? $args['name'] ?? '';

		return $this->respond($request, $response, function () use ($request, $collection, $id, $property, $path, $args): ResponseInterface {
			$queryParams = ImageParamValidator::validate($request->getQueryParams());

			// Only set fm from URL format if no preset is used (presets may define their own format)
			if (!isset($queryParams['p'])) {
				$queryParams['fm'] = $args['format'];
			}

			return $this->imageGenerator->forPublicRoute()->generateGalleryImage($collection, $id, $property, $path, $queryParams, $request);
		});
	}
}
