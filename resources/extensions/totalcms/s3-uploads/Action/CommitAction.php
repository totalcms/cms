<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Bundled\S3Uploads\Upload\UploadService;

/**
 * POST /admin/ext/totalcms/s3-uploads/commit with JSON `{token, duration?}`,
 * sent once the browser's PUT to the bucket has finished.
 *
 * Answers `{value, item}`: the property's new stored value, and the pointer
 * that was just added. The pointer is already saved on the object.
 */
final readonly class CommitAction
{
	public function __construct(
		private UploadService $uploads,
		private ActionResponder $responder,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$body  = $this->responder->body($request);
		$token = $body['token'] ?? null;
		if (!is_string($token) || $token === '') {
			return $this->responder->badRequest($response);
		}

		$duration = is_numeric($body['duration'] ?? null) ? (float)$body['duration'] : null;

		return $this->responder->run($response, fn (): array => $this->uploads->commit($token, $duration));
	}
}
