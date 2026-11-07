<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Media\Service\PublicUploadPolicy;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;
use TotalCMS\Domain\Security\RateLimit\RateLimitResult;
use TotalCMS\Renderer\JsonRenderer;

/**
 * The limit on an anonymous upload to a depot with a `publicUpload` grant: a
 * visitor may add one file to one object that exists, and nothing else.
 *
 * Applies to every caller without an `authMethod`, including on a collection
 * that also has public `update`. DualAuthMiddleware stamps anyone the grant
 * does not cover (see its `uploadsWithOwnRights()`), and they pass straight
 * through to the full upload. A property with no grant passes too.
 *
 * Every check runs on every request, chunks included, and before the file is
 * touched, so a refused chunk stores nothing. A failed bot check answers as a
 * real upload would and is never announced. FileSaveAction reads the grant
 * from {@see self::ATTRIBUTE} and makes the checks that need the whole file.
 *
 * Filenames are judged as sent and as they will be stored. A part of a
 * multi-chunk upload may be no larger than the chunk the form sends, so
 * abandoned parts stay small; a file in one piece may reach the grant's limit.
 *
 * Rate limits, per IP and tunable with `$settings['rateLimits']`:
 * `public-upload-requests` counts every request, `public-upload` counts files
 * on their last chunk, and `public-upload-starts` counts multi-chunk uploads
 * on their first, because those that never finish are never counted as files.
 */
readonly class PublicUploadGuardMiddleware implements MiddlewareInterface
{
	public const ATTRIBUTE = 'publicUpload';

	public const REQUEST_LIMIT = 'public-upload-requests';
	public const MAX_REQUESTS  = 300;
	public const FILE_LIMIT    = 'public-upload';
	public const MAX_FILES     = 20;
	public const START_LIMIT   = 'public-upload-starts';
	public const MAX_STARTS    = 40;
	public const WINDOW        = 3600;

	/** The size of the chunks the depot-drop form sends. */
	private const CHUNK_BYTES = 5 * 1024 * 1024;

	public function __construct(
		private PublicUploadPolicy $policy,
		private BotSubmissionGate $botGate,
		private PublicRateLimiter $limiter,
		private ObjectFetcher $objects,
		private JsonRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$route = $request->getAttribute(RouteContext::ROUTE);
		if ((string)$request->getAttribute('authMethod', '') !== '' || !$route instanceof RouteInterface) {
			return $handler->handle($request);
		}

		$collection = (string)$route->getArgument('collection');
		$property   = (string)$route->getArgument('property');
		$grant      = $this->policy->grantFor($collection, $property);
		if (!$grant instanceof PublicUploadGrant) {
			return $handler->handle($request);
		}

		$limited = $this->refusedBy(self::REQUEST_LIMIT, $request, self::MAX_REQUESTS);
		if ($limited instanceof RateLimitResult) {
			return $this->tooManyRequests($limited);
		}

		/** @var array<string,mixed> $sent */
		$sent            = (array)$request->getParsedBody();
		[$body, $reason] = $this->botGate->screenUpload($request, $sent, $collection);
		$counters        = $this->counters($body);

		if (BotSubmissionGate::expired($reason)) {
			return $this->botGate->expiredResponse($request, $this->responseFactory->createResponse());
		}
		if ($reason !== '') {
			return $this->status($this->isLastChunk($counters) ? 'ok' : 'chunk received');
		}

		if (!$this->objectExists($collection, (string)$route->getArgument('id'))) {
			return $this->renderer->json($this->responseFactory->createResponse()->withStatus(404), ['error' => ['message' => 'Not found']]);
		}

		$file    = $request->getUploadedFiles()[$property] ?? null;
		$refusal = $this->refusal($grant, $file, $body, $counters);
		if ($refusal !== null) {
			return $this->renderer->json($this->responseFactory->createResponse()->withStatus(400), [
				'error'   => 'File upload validation failed',
				'details' => [$refusal],
			]);
		}

		$limited = $this->uploadLimitHit($request, $counters);
		if ($limited instanceof RateLimitResult) {
			return $this->tooManyRequests($limited);
		}

		return $handler->handle($request->withParsedBody($body)->withAttribute(self::ATTRIBUTE, $grant));
	}

	/**
	 * Why this request cannot go on, or null when it can.
	 *
	 * @param array<string,mixed>                         $body
	 * @param array{index:int,total:int,size:int}|null    $counters
	 */
	private function refusal(PublicUploadGrant $grant, mixed $file, array $body, ?array $counters): ?string
	{
		if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
			return 'A file is required.';
		}

		$name = (string)$file->getClientFilename();
		if ($this->policy->nameTooLong($name)) {
			return 'This file name is too long.';
		}

		if (!$this->policy->extensionAllowed($grant, $name)) {
			return 'This type of file is not allowed.';
		}

		// The assembler keys a multi-chunk upload on dzuuid, and without one on
		// the filename: two strangers sending `invoice.pdf` would share parts.
		$uuid = $body['dzuuid'] ?? null;
		if ($counters === null || ($counters['total'] > 1 && (!is_string($uuid) || $uuid === ''))) {
			return 'The upload could not be read.';
		}

		$size     = (int)$file->getSize();
		$tooLarge = $counters['size'] > $grant->maxFileBytes
			|| $counters['total'] > (int)ceil($grant->maxFileBytes / self::CHUNK_BYTES)
			|| $size > $grant->maxFileBytes
			|| ($counters['total'] > 1 && $size > self::CHUNK_BYTES);

		return $tooLarge ? 'The file is larger than the allowed size.' : null;
	}

	/**
	 * Count this chunk as a file or as a start. The refusal when that went over.
	 *
	 * @param array{index:int,total:int,size:int}|null $counters
	 */
	private function uploadLimitHit(ServerRequestInterface $request, ?array $counters): ?RateLimitResult
	{
		if ($counters === null) {
			return null;
		}

		if ($this->isLastChunk($counters)) {
			$refused = $this->refusedBy(self::FILE_LIMIT, $request, self::MAX_FILES);
			if ($refused instanceof RateLimitResult) {
				return $refused;
			}
		}

		return $counters['index'] === 0 && $counters['total'] > 1
			? $this->refusedBy(self::START_LIMIT, $request, self::MAX_STARTS)
			: null;
	}

	/** Count one request against a limit. The result when it went over, null otherwise. */
	private function refusedBy(string $limit, ServerRequestInterface $request, int $max): ?RateLimitResult
	{
		$result = $this->limiter->attempt($limit, $request, $max, self::WINDOW);

		return $result->allowed ? null : $result;
	}

	/**
	 * The chunk counters, read under the same names and in the same order as
	 * ChunkedUploadAssembler, or null unless they are whole numbers that fit
	 * together. A file sent in one piece carries none and is a single chunk.
	 *
	 * @param array<string,mixed> $body
	 *
	 * @return array{index:int,total:int,size:int}|null
	 */
	private function counters(array $body): ?array
	{
		$index = $this->wholeNumber($body['dzchunkindex'] ?? $body['chunkindex'] ?? 0);
		$total = $this->wholeNumber($body['dztotalchunkcount'] ?? $body['totalchunkcount'] ?? 1);
		$size  = $this->wholeNumber($body['dztotalfilesize'] ?? 0);

		if ($index === null || $total === null || $size === null || $index >= $total) {
			return null;
		}

		return ['index' => $index, 'total' => $total, 'size' => $size];
	}

	private function wholeNumber(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value >= 0 ? $value : null;
		}

		return is_string($value) && ctype_digit($value) ? (int)$value : null;
	}

	/**
	 * @param array{index:int,total:int,size:int}|null $counters
	 */
	private function isLastChunk(?array $counters): bool
	{
		return $counters === null || $counters['index'] === $counters['total'] - 1;
	}

	/**
	 * Whether the route's id names an object. An id is one plain name: Slim
	 * decodes the path, so an encoded `..` or backslash arrives here as itself,
	 * and a leading dot would name a hidden file beside the objects.
	 */
	private function objectExists(string $collection, string $id): bool
	{
		if ($id === '' || $id[0] === '.' || strpbrk($id, "/\\\0") !== false) {
			return false;
		}

		return $this->objects->existsObject($collection, $id);
	}

	private function status(string $status): ResponseInterface
	{
		return $this->renderer->json($this->responseFactory->createResponse(), ['status' => $status]);
	}

	private function tooManyRequests(RateLimitResult $limit): ResponseInterface
	{
		return $this->renderer->json(
			$this->responseFactory->createResponse()->withStatus(429)->withHeader('Retry-After', (string)$limit->retryAfter),
			['error' => ['message' => 'Too many uploads. Try again later.']],
		);
	}
}
