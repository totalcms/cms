<?php

namespace TotalCMS\Action\Stream;

use Nyholm\Psr7\Stream;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Security\Encryption\Cipher;

abstract class StreamAction
{
	protected FileAccessManager $accessManager;
	protected ObjectUpdater $objectUpdater;
	protected PhpSession $session;

	protected string $collection;
	protected string $id;
	protected string $property;
	protected string $name;
	protected ?string $subpath = null;

	abstract protected function fileExists(): bool;

	abstract protected function loadFile(): void;

	abstract protected function fetchFile(): FileData;

	abstract protected function incrementCount(FileData $file): void;

	abstract protected function actualFileSize(): int;

	/** @return resource */
	abstract protected function streamFile();

	/**
	 * Decode filename from URL, supporting both + and %20 encoding.
	 */
	private function decodeFilename(string $filename): string
	{
		// Handle both + and %20 style encoding for maximum compatibility
		// urldecode handles %20, then we handle + for form-style encoding
		return str_replace('+', ' ', urldecode($filename));
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$this->collection = $args['collection'];
		$this->id         = $args['id'];
		$this->property   = $args['property'];
		$this->name       = $this->decodeFilename($args['name'] ?? '');

		$query          = $request->getQueryParams();
		$this->subpath  = $query['path'] ?? null;

		if (!$this->fileExists()) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$this->loadFile();

		if ($this->accessManager->isProtectedByGroups()) {
			// check if the user is logged in and has access to the file
			if ($this->accessManager->sessionHasUser() === false) {
				throw new HttpForbiddenException($request, 'Authentication required');
			}
			if ($this->accessManager->userHasAccess() === false) {
				throw new HttpForbiddenException($request, 'Access denied');
			}
		}

		if ($this->accessManager->isPasswordProtected()) {
			$password = $this->passwordFromRequest($request);

			if (is_null($password)) {
				throw new HttpForbiddenException($request, 'Password required');
			}
			if ($this->accessManager->verfiyPassword($password) === false) {
				throw new HttpForbiddenException($request, 'Invalid password');
			}
		}

		$file = $this->fetchFile();
		$this->incrementCount($file);

		return $this->buildStreamResponse($request, $response, $file);
	}

	private function buildStreamResponse(ServerRequestInterface $request, ResponseInterface $response, FileData $file): ResponseInterface
	{
		$fileSize    = $this->actualFileSize();
		$rangeHeader = $request->getHeaderLine('Range');

		// Close session before streaming to release file locks
		// This prevents shared hosts from killing long-running locked processes
		$this->session->save();

		// Clean any output buffers to prevent memory bloat on shared hosting
		// PHP output buffering can silently truncate large binary streams
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		// Cache validators — Safari uses Last-Modified/ETag to confirm chunks
		// belong to the same file revision while seeking through a non-faststart
		// MP4. Without them Safari abandons playback partway through.
		$mtime   = $file->uploadDate->date !== '' ? (int)strtotime($file->uploadDate->date) : 0;
		$etag    = '"' . dechex($mtime) . '-' . dechex($fileSize) . '"';
		$lastMod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

		$response = $response
			->withHeader('Content-Type', $file->mime)
			->withHeader('Content-Disposition', "inline; filename=\"{$file->download}\"")
			->withHeader('Accept-Ranges', 'bytes')
			->withHeader('Cache-Control', 'no-cache')
			->withHeader('Last-Modified', $lastMod)
			->withHeader('ETag', $etag);

		if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
			$start = (int)$matches[1];
			$end   = empty($matches[2]) ? $fileSize - 1 : (int)$matches[2];

			if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
				return $response->withStatus(416)
					->withHeader('Content-Range', "bytes */{$fileSize}");
			}

			$contentLength = $end - $start + 1;

			$fileStream   = $this->streamFile();
			$rangeContent = '';
			if (is_resource($fileStream) && $contentLength > 0) {
				fseek($fileStream, $start);
				$readResult   = fread($fileStream, $contentLength);
				$rangeContent = $readResult !== false ? $readResult : '';
				fclose($fileStream);
			}

			// No X-Accel-Buffering on 206 responses — range content is already
			// fully in memory, and setting it caused nginx/PHP-FPM to switch
			// to chunked transfer encoding, which strips Content-Length.
			// Safari refuses to play 206 responses without Content-Length.
			return $response
				->withStatus(206)
				->withHeader('Content-Length', (string)$contentLength)
				->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
				->withBody(Stream::create($rangeContent));
		}

		// Full file path keeps X-Accel-Buffering off so nginx streams the
		// resource without loading the whole file into memory.
		return $response
			->withHeader('X-Accel-Buffering', 'no')
			->withHeader('Content-Length', (string)$fileSize)
			->withBody(Stream::create($this->streamFile()));
	}

	private function passwordFromRequest(ServerRequestInterface $request): ?string
	{
		$queryParams = $request->getQueryParams();

		if (isset($queryParams['pwd'])) {
			return Cipher::decrypt($queryParams['pwd']);
		}

		return null;
	}
}
