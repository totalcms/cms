<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Typed result for service operations that can succeed or fail.
 * Replaces untyped ['success' => bool, 'message' => string] arrays.
 */
readonly class OperationResult
{
	/**
	 * @param array<string,mixed> $data Extra result data (token, path, html, etc.)
	 */
	private function __construct(
		public bool $success,
		public string $message = '',
		public ?string $error = null,
		public array $data = [],
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function success(string $message = '', array $data = []): self
	{
		return new self(true, $message, null, $data);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function failure(string $message, ?string $error = null, array $data = []): self
	{
		return new self(false, $message, $error, $data);
	}

	/**
	 * Convert to array for JSON rendering and backwards compatibility.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$result = [
			'success' => $this->success,
			'message' => $this->message,
		];

		if ($this->error !== null) {
			$result['error'] = $this->error;
		}

		return array_merge($result, $this->data);
	}
}
