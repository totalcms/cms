<?php

namespace TotalCMS\Action\Froala;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

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
		$files = $request->getUploadedFiles();

		$type = null;
		// possible file types: image, video, file
		if (key_exists('image', $files)) {
			$type = 'image';
		} elseif (key_exists('video', $files)) {
			$type = 'video';
		} elseif (key_exists('file', $files)) {
			$type = 'file';
		}

		if ($type === null) {
			throw new \RuntimeException('No file found for upload');
		}
		$file = $files[$type];

		// move the uploaded file to the tmp directory
		// this is because saveFile expects a file path
		$filepath = $this->config->tmpdir . '/' . $file->getClientFilename();
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0777, true);
		}
		$file->moveTo($filepath);

		$path = $this->saver->save(
			$args['collection'],
			$args['id'],
			$args['property'],
			$filepath
		);

		$link = $this->config->api . '/upload/' . $path;

		if ($type === "image") {
			$link = $this->config->api . '/imageworks/upload/' . $path;
		}

		$params = $request->getParsedBody();
		if (!empty($params)) {
			$link .= '?' . http_build_query($params);
		}

		return $this->renderer->json($response, [ "link" => $link ]);
	}
}
