<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

readonly class ImageWorksGalleryFetchDynamicAction
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
		$action      = $args['action'];
		$queryParams = $request->getQueryParams();

		try {
			$image = $this->imageGenerator->generateGalleryImage($collection, $id, $property, $action, $queryParams, $request);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}

		return $image;
	}
}
