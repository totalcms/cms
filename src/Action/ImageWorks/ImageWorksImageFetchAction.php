<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;

readonly class ImageWorksImageFetchAction
{
	use ImageWorksResponder;

	public function __construct(private ImageGenerator $imageGenerator)
	{
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection  = $args['collection'] ?? 'image';
		$id          = $args['id'];
		$property    = $args['property'] ?? 'image';

		return $this->respond($request, $response, function () use ($request, $collection, $id, $property, $args): ResponseInterface {
			$queryParams = ImageParamValidator::validate($request->getQueryParams());

			// Only set fm from URL format if no preset is used (presets may define their own format)
			if (!isset($queryParams['p'])) {
				$queryParams['fm'] = $args['format'];
			}

			return $this->imageGenerator->forPublicRoute()->generateImage($collection, $id, $property, $queryParams, $request);
		});
	}
}
