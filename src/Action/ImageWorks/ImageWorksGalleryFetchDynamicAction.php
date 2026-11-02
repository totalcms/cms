<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;

readonly class ImageWorksGalleryFetchDynamicAction
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
		$action      = $args['action'];

		return $this->respond($request, $response, fn (): ResponseInterface => $this->imageGenerator
			->forPublicRoute()
			->generateGalleryImage($collection, $id, $property, $action, ImageParamValidator::validate($request->getQueryParams()), $request));
	}
}
