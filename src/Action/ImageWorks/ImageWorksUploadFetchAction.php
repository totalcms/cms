<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\RawUploadGate;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

readonly class ImageWorksUploadFetchAction
{
	use ImageWorksResponder;

	public function __construct(
		private ImageGenerator $imageGenerator,
		private UploadFetcher $uploadFetcher,
		private RawUploadGate $gate,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection       = $args['collection'];
		$id               = $args['id'];
		$property         = $args['property'];
		[$name, $subpath] = PathUtils::splitPath($args['path'] ?? $args['name'] ?? '');

		// Short-circuit when the file isn't on disk. ImageGenerator → Glide → Flysystem
		// would `@fopen()` the missing file and emit a warning before the failure
		// surfaces — fileExists() lets us 404 cleanly without that noise.
		if ($name === '' || !$this->uploadFetcher->fileExists($collection, $id, $property, $name, $subpath)) {
			throw new HttpNotFoundException($request, 'Image not found');
		}

		// The same folder can hold a protected file field's or depot's files;
		// those keep their protection here too (GHSA-4852-hjgf-c7q5).
		$denial = $this->gate->denialFor($collection, $id, $property, $subpath, null);
		if ($denial !== null) {
			throw new HttpForbiddenException($request, $denial);
		}

		// ImageWorks serves images. Anything else in the folder (an `.html`
		// an editor uploaded, say) went out inline as itself, on this origin
		// (GHSA-f354-xm96-5p3w); `/download/upload/…` is its URL.
		if (!str_starts_with($this->uploadFetcher->mimeType($collection, $id, $property, $name, $subpath), 'image/')) {
			throw new HttpNotFoundException($request, 'Image not found');
		}

		return $this->respond($request, $response, fn (): ResponseInterface => $this->imageGenerator
			->forPublicRoute()
			->generateUploadImage($collection, $id, $property, $name, ImageParamValidator::validate($request->getQueryParams()), $request, $subpath));
	}
}
