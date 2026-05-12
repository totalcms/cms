<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

readonly class ImageWorksUploadFetchAction
{
	public function __construct(
		private ImageGenerator $imageGenerator,
		private UploadFetcher $uploadFetcher,
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

		$query = $request->getQueryParams();

		try {
			$image = $this->imageGenerator->generateUploadImage($collection, $id, $property, $name, $query, $request, $subpath);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}

		return $image;
	}
}
