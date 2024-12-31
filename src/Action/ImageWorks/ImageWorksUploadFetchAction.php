<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksUploadFetchAction
{
	public function __construct(
		private ImageGenerator $imageGenerator
	){}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];
		$name       = $args['name'];

		$query = $request->getQueryParams();

		try {
			$image = $this->imageGenerator->generateUploadImage($collection, $id, $property, $name, $query);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}

		return $image;
	}
}
