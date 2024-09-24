<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksImageFetchAction
{
	public function __construct(private ImageGenerator $imageGenerator)
	{
	}

	/**
	 * Get image by property.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args The arguments
	 *
	 * @throws HttpNotFoundException
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection  = $args['collection'] ?? 'image';
		$id          = $args['id'];
		$property    = $args['property'] ?? 'image';
		$queryParams = $request->getQueryParams();

		$queryParams['fm'] = $args['format'];

		try {
			$image = $this->imageGenerator->generateImage($collection, $id, $property, $queryParams);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}

		return $image;
	}
}
