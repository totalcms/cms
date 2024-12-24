<?php

namespace TotalCMS\Action\Froala;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class FroalaUploadFileAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private UploadSaver $saver,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$query = $request->getQueryParams();
		$files = $request->getUploadedFiles();

		$path = null;
		// possible file paths: image, video, file
		if (key_exists('image', $files)) {
			$path = 'image';
		} elseif (key_exists('video', $files)) {
			$path = 'video';
		} elseif (key_exists('file', $files)) {
			$path = 'file';
		}

		if ($path === null) {
			throw new \RuntimeException('No file found for upload');
		}
		$file = $files[$path];

		// move the uploaded file to the tmp directory
		// this is because saveFile expects a file path
		$filepath = $this->config->tmpdir . '/' . $file->getClientFilename();
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0777, true);
		}
		$file->moveTo($filepath);

		$object = $this->saver->save(
			$args['collection'],
			$args['id'],
			$args['property'],
			$filepath,
			$path
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
