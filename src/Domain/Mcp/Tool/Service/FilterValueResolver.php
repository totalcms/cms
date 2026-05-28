<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

/**
 * Substitutes `{{params.X}}` placeholders in schema-tool filter values
 * with the caller's argument values, applying the declared param's
 * type coercion. Non-string filter values are returned unchanged.
 *
 * Only the `params.X` prefix is recognized; any other `{{...}}` form is
 * preserved as a literal so future macro syntax can land additively
 * without colliding with v1 customer data.
 *
 * Strict on unknown param references (throws `forPlaceholder`) — a
 * definition that references `{{params.unknown}}` is misconfigured and
 * surfacing the failure beats silent value drift.
 */
final class FilterValueResolver
{
	/**
	 * @param  mixed                             $value      Filter value from the definition
	 * @param  array<string,mixed>               $args       Caller args (already validated by SDK against inputSchema)
	 * @param  array<string,array<string,mixed>> $paramsSpec Param spec from the definition
	 * @param  string                            $toolName   For error messages
	 *
	 * @return mixed                                         Literal value or substituted value with type coercion
	 */
	public function resolve(mixed $value, array $args, array $paramsSpec, string $toolName): mixed
	{
		if (!is_string($value)) {
			return $value;
		}

		if (!str_contains($value, '{{params.')) {
			return $value;
		}

		// Identify whether the value is a "pure placeholder" — exactly one {{params.X}} with no surrounding text.
		if (preg_match('/^\{\{params\.([a-z][a-z0-9_]*)\}\}$/', $value, $match) === 1) {
			$paramName = $match[1];
			$this->assertDeclared($paramName, $paramsSpec, $toolName, "{{params.{$paramName}}}");

			if (!array_key_exists($paramName, $args)) {
				// Optional param not supplied — pass null so QueryPipeline can decide what to do.
				return null;
			}

			return $this->coerce($args[$paramName], $paramsSpec[$paramName]['type'] ?? 'string');
		}

		// Mixed string — substitute each {{params.X}} segment and assemble.
		return preg_replace_callback(
			'/\{\{params\.([a-z][a-z0-9_]*)\}\}/',
			function (array $match) use ($args, $paramsSpec, $toolName): string {
				$paramName = $match[1];
				$this->assertDeclared($paramName, $paramsSpec, $toolName, "{{params.{$paramName}}}");

				if (!array_key_exists($paramName, $args)) {
					return '';
				}

				$coerced = $this->coerce($args[$paramName], $paramsSpec[$paramName]['type'] ?? 'string');

				return is_scalar($coerced) ? (string)$coerced : '';
			},
			$value,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $paramsSpec
	 */
	private function assertDeclared(string $paramName, array $paramsSpec, string $toolName, string $placeholder): void
	{
		if (!array_key_exists($paramName, $paramsSpec)) {
			throw SavedQueryToolException::forPlaceholder($placeholder, $toolName);
		}
	}

	private function coerce(mixed $value, string $type): mixed
	{
		return match ($type) {
			'string'  => is_scalar($value) ? (string)$value : null,
			'integer' => is_numeric($value) ? (int)$value : null,
			'number'  => is_numeric($value) ? (float)$value : null,
			// NOTE: PHP-cast (bool) treats the string "false" as true (non-empty).
			// SDK delivers proper booleans from JSON so this is defensive, but
			// callers constructing FilterValueResolver directly with string "false"
			// will be surprised.
			'boolean' => is_bool($value) ? $value : (bool)$value,
			default   => $value,
		};
	}
}
