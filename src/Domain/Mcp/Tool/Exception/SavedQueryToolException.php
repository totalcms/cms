<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Exception;

/**
 * Domain exception for schema-defined MCP tool failures.
 *
 * Three flavours via static factories — validation (a tool definition
 * failed JSON Schema or operator check), collision (a tool name conflicts
 * with another tool), and placeholder (a filter value referenced an
 * undeclared param). Each carries a `recoveryHint` string appended to the
 * message when the tool handler catches this and rethrows it as a
 * `\Mcp\Exception\ToolCallException` (see SavedQueryTool::handle()) — the
 * SDK sets `isError: true` on the MCP result for you, so dead ends become
 * next steps for the AI agent.
 */
final class SavedQueryToolException extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly string $kind,
		public readonly string $recoveryHint,
	) {
		parent::__construct($message);
	}

	public static function forValidation(string $toolName, string $message): self
	{
		return new self(
			message: sprintf('Schema tool "%s" failed validation: %s', $toolName, $message),
			kind: 'validation',
			recoveryHint: 'The site admin needs to fix the tool definition in the collection schema. Other tools on this collection are unaffected.',
		);
	}

	public static function forCollision(string $toolName, string $source, string $owner): self
	{
		return new self(
			message: sprintf('Schema tool "%s" collides with a %s tool (owner: %s); registration skipped.', $toolName, $source, $owner),
			kind: 'collision',
			recoveryHint: 'Rename the tool to something unique across core, extension, and other schema tools. Tool names must be globally unique on this server.',
		);
	}

	public static function forPlaceholder(string $placeholder, string $toolName): self
	{
		return new self(
			message: sprintf('Schema tool "%s": filter references %s but the parameter is not declared in the params block.', $toolName, $placeholder),
			kind: 'placeholder',
			recoveryHint: 'The site admin needs to either declare the parameter in the params block or fix the filter value. The tool will return errors until corrected.',
		);
	}
}
