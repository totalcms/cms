<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

readonly class ImageWorksGalleryFetchAction
{
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
		$name        = $args['name'];
		$queryParams = $request->getQueryParams();

		// Only set fm from URL format if no preset is used (presets may define their own format)
		if (!isset($queryParams['p'])) {
			$queryParams['fm'] = $args['format'];
		}

		try {
			$image = $this->imageGenerator->generateGalleryImage($collection, $id, $property, $name, $queryParams, $request);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}

		return $image;
	}
}
