<?php

namespace TotalCMS\Action\Download;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Property\Service\FileFetcher;

final class DownloadFileAction
{
	public function __construct(private FileFetcher $fileFetcher) {}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection  = $args['collection'];
		$id          = $args['id'];
		$property    = $args['property'];
		$queryParams = $request->getQueryParams();
		// $queryParams['password']

		if (!$this->fileFetcher->fileExists($collection, $id, $property)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$file   = $this->fileFetcher->fetchFile($collection, $id, $property);
		$stream = $this->fileFetcher->streamFile($collection, $id, $property);

		$response = $response
			->withHeader('Content-Type',$file->mime)
			->withHeader('Content-Disposition', "attachment; filename='{$file->name}'");

		return $response->withBody(Stream::create($stream));
	}
}
