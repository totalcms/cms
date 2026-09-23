<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * The `{success, message, ...}` envelope the migration importers (Alloy,
 * WordPress, RSS) answer with: 400 for a rejected request, 500 with the
 * importer's message when it throws, 200 otherwise.
 */
abstract readonly class ImportEndpoint
{
	public function __construct(protected JsonRenderer $renderer)
	{
	}

	protected function reject(ResponseInterface $response, string $message): ResponseInterface
	{
		return $this->renderer->json($response, ['success' => false, 'message' => $message], 400);
	}

	/**
	 * Run the importer and wrap its payload; a throw becomes a 500 prefixed
	 * with `$failurePrefix` ("Import failed" / "Analysis failed").
	 *
	 * @param callable(): array<string,mixed> $work
	 */
	protected function attempt(ResponseInterface $response, string $failurePrefix, callable $work): ResponseInterface
	{
		try {
			return $this->renderer->json($response, ['success' => true] + $work());
		} catch (\Exception $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => $failurePrefix . ': ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	protected function analyzed(array $data): array
	{
		return ['message' => 'Analysis completed successfully', 'data' => $data];
	}

	/** @return array<string,mixed> */
	protected function imported(int $count, string $message): array
	{
		return ['message' => sprintf($message, $count), 'import_count' => $count];
	}
}
