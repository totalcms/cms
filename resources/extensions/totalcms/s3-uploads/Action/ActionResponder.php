<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Upload\UploadError;
use TotalCMS\Renderer\JsonRenderer;

/**
 * What the JSON routes share: reading a JSON body, and turning a refusal into
 * `{"error": message, "code": code}` with the right status. The message is the
 * text the field shows the editor.
 */
final readonly class ActionResponder
{
	public function __construct(
		private JsonRenderer $renderer,
		private S3Settings $settings,
	) {
	}

	/**
	 * The request's JSON object, whether or not a body-parsing middleware already decoded it.
	 *
	 * @return array<string,mixed> empty when the body is not a JSON object
	 */
	public function body(ServerRequestInterface $request): array
	{
		$parsed = $request->getParsedBody();
		if (is_array($parsed) && $parsed !== []) {
			return $parsed;
		}

		$decoded = json_decode((string)$request->getBody(), true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Run $work and render what it returns as JSON, or the refusal it throws.
	 *
	 * @param \Closure(): array<string,mixed> $work
	 */
	public function run(ResponseInterface $response, \Closure $work): ResponseInterface
	{
		try {
			return $this->renderer->json($response, $work());
		} catch (UploadError $e) {
			return $this->error($response, $e->status, $e->errorCode, $e->getMessage());
		} catch (S3Exception $e) {
			return $this->error($response, 502, 's3.provider', trim("The bucket refused the request ({$e->s3Code}). " . $e->hint($this->settings)));
		}
	}

	public function error(ResponseInterface $response, int $status, string $code, string $message): ResponseInterface
	{
		return $this->renderer->json($response, ['error' => $message, 'code' => $code], $status);
	}

	public function badRequest(ResponseInterface $response): ResponseInterface
	{
		return $this->error($response, 400, 's3.bad_request', 'The upload request is incomplete.');
	}
}
