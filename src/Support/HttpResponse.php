<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Simple value object for HTTP responses.
 */
readonly class HttpResponse
{
	public function __construct(
		public int $statusCode,
		public string $body,
	) {
	}

	/**
	 * Decode the response body as JSON.
	 *
	 * @return array<string,mixed>|null
	 */
	public function json(): ?array
	{
		$decoded = json_decode($this->body, true);

		return is_array($decoded) ? $decoded : null;
	}

	public function isSuccess(): bool
	{
		return $this->statusCode >= 200 && $this->statusCode < 300;
	}
}
