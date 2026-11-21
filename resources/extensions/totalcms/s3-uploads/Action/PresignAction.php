<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Bundled\S3Uploads\Upload\UploadService;

/**
 * POST /admin/ext/totalcms/s3-uploads/presign with JSON
 * `{collection, id, property, filename, size, mime}`.
 *
 * Answers `{url, method, headers, key, token}`: where the browser PUTs the
 * file, and the token it sends back to /commit when the PUT is done.
 */
final readonly class PresignAction
{
	public function __construct(
		private UploadService $uploads,
		private ActionResponder $responder,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$body = $this->responder->body($request);

		$text = [];
		foreach (['collection', 'id', 'property', 'filename'] as $field) {
			$value = $body[$field] ?? null;
			if (!is_string($value) || trim($value) === '') {
				return $this->responder->badRequest($response);
			}
			$text[$field] = $value;
		}

		$size = $body['size'] ?? null;
		$mime = $body['mime'] ?? '';
		if (!is_int($size) && !(is_string($size) && ctype_digit($size))) {
			return $this->responder->badRequest($response);
		}
		if ((int)$size < 0 || !is_string($mime)) {
			return $this->responder->badRequest($response);
		}

		return $this->responder->run($response, fn (): array => $this->uploads->presign(
			$text['collection'],
			$text['id'],
			$text['property'],
			$text['filename'],
			(int)$size,
			$mime,
		));
	}
}
