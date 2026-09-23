<?php

namespace TotalCMS\Action\Stream;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Property\Service\PropertyFile;
use TotalCMS\Domain\Property\Service\PropertyFileResolver;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Renderer\FileStreamRenderer;

/**
 * Serve a property file inline with byte-range support, behind the file's
 * own protection. Unlike a download there is no page to show a media player,
 * so every refusal is a 403. Subclasses only say which file.
 */
abstract class StreamAction
{
	public function __construct(
		protected PropertyFileResolver $resolver,
		protected FileAccessManager $accessManager,
		protected FileStreamRenderer $streamRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args
	 * @param array<string,mixed>  $query
	 */
	abstract protected function resolve(array $args, array $query): PropertyFile;

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$file = $this->resolve($args, $request->getQueryParams());

		if (!$file->exists()) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$file->load($this->accessManager);

		if ($this->accessManager->isProtectedByGroups()) {
			if ($this->accessManager->sessionHasUser() === false) {
				throw new HttpForbiddenException($request, 'Authentication required');
			}
			if ($this->accessManager->userHasAccess() === false) {
				throw new HttpForbiddenException($request, 'Access denied');
			}
		}

		if ($this->accessManager->isPasswordProtected()) {
			$query    = $request->getQueryParams();
			$password = isset($query['pwd']) ? Cipher::decrypt($query['pwd']) : null;

			if (is_null($password)) {
				throw new HttpForbiddenException($request, 'Password required');
			}
			if ($this->accessManager->verfiyPassword($password) === false) {
				throw new HttpForbiddenException($request, 'Invalid password');
			}
		}

		$record = $file->fetch();
		$file->recordDownload($record);

		$mtime = $record->uploadDate->date !== '' ? (int)strtotime($record->uploadDate->date) : 0;

		return $this->streamRenderer->stream($request, $response, $record->mime, $record->download, $file->size(), fn () => $file->open(), $mtime);
	}
}
