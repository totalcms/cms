<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Data;

/**
 * Result object returned by McpToolsValidator::validate().
 *
 * Three states:
 *  - ok            : validation passed; $warnings may contain non-blocking notices.
 *  - invalid_json  : mcp.tools was a non-parseable JSON string.
 *  - failed        : one entry failed SavedQueryToolDefinition::fromArray().
 */
final readonly class McpToolsValidationResult
{
	private const STATE_OK           = 'ok';
	private const STATE_INVALID_JSON = 'invalid_json';
	private const STATE_FAILED       = 'failed';

	/**
	 * @param list<array{type:string,name?:string,source?:string,tool?:string,message?:string}> $warnings non-blocking notices
	 */
	private function __construct(
		public string $state,
		public int $failedIndex,
		public string $failedMessage,
		public array $warnings,
	) {
	}

	/**
	 * @param list<array{type:string,name?:string,source?:string,tool?:string,message?:string}> $warnings
	 */
	public static function ok(array $warnings = []): self
	{
		return new self(self::STATE_OK, -1, '', $warnings);
	}

	public static function invalidJson(): self
	{
		return new self(self::STATE_INVALID_JSON, -1, '', []);
	}

	public static function validationFailed(int $index, string $message): self
	{
		return new self(self::STATE_FAILED, $index, $message, []);
	}

	public function isOk(): bool
	{
		return $this->state === self::STATE_OK;
	}

	public function isInvalidJson(): bool
	{
		return $this->state === self::STATE_INVALID_JSON;
	}

	public function isValidationFailed(): bool
	{
		return $this->state === self::STATE_FAILED;
	}

	/**
	 * Returns all non-blocking warnings.
	 *
	 * Two shapes are present in the list:
	 *  - Collision warning: `{type: 'collision', name: string, source: string}`
	 *  - Placeholder warning: `{type: 'placeholder', tool: string, message: string}`
	 *
	 * @return list<array{type:string,name?:string,source?:string,tool?:string,message?:string}>
	 */
	public function warnings(): array
	{
		return $this->warnings;
	}
}
