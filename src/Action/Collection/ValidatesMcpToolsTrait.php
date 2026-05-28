<?php

declare(strict_types=1);

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * Shared mcp.tools validation logic for collection save/update/patch actions.
 *
 * Each action passes its authoritative $collectionId:
 *  - POST (Save)  → $data['id']          (body carries the ID; no route param)
 *  - PUT (Update) → $args['collection']  (route param; body id may be absent)
 *  - PATCH        → $args['collection']  (route param; body is a partial patch)
 *
 * On validation error a HttpBadRequestException is thrown (consistent with the
 * existing action behaviour). On success a meta array is returned — empty when
 * there are no warnings, or with a 'warnings' key when tool-name collisions
 * were detected.
 */
trait ValidatesMcpToolsTrait
{
	/**
	 * Validate mcp.tools in the incoming data.
	 * Returns a meta array (possibly with warnings) on success, or throws on failure.
	 *
	 * Exits early (returns []) when mcp is absent, not an array, or when the
	 * 'tools' key is missing — so PATCH bodies that only touch non-mcp fields
	 * are never penalised.
	 *
	 * @param  array<string,mixed>  $data         Full (or partial) request body
	 * @param  string               $collectionId Authoritative collection ID
	 *
	 * @throws HttpBadRequestException
	 *
	 * @return array<string,mixed>                Meta array for the success response
	 */
	private function validateMcpTools(
		ServerRequestInterface $request,
		string $collectionId,
		array $data,
		McpToolsValidator $mcpToolsValidator,
		TranslationService $translator,
	): array {
		$mcp = $data['mcp'] ?? null;
		if (!is_array($mcp) || !array_key_exists('tools', $mcp)) {
			return [];
		}

		$rawTools = $mcp['tools'];
		$access   = (string)($mcp['access'] ?? 'admin');

		$result = $mcpToolsValidator->validate($collectionId, $access, $rawTools);

		if ($result->isInvalidJson()) {
			throw new HttpBadRequestException(
				$request,
				$translator->trans('schema.mcp.tools_invalid_json'),
			);
		}

		if ($result->isValidationFailed()) {
			throw new HttpBadRequestException(
				$request,
				$translator->trans('schema.mcp.tools_validation_failed', [
					'{index}'   => (string)($result->failedIndex + 1),
					'{message}' => $result->failedMessage,
				]),
			);
		}

		$meta = [];
		if ($result->warnings() !== []) {
			$meta['warnings'] = array_map(
				function (array $w) use ($translator): string {
					if ($w['type'] === 'placeholder') {
						return (string)($w['message'] ?? 'Tool has an unrecognized {{...}} placeholder in a filter value.');
					}

					// Default: collision warning.
					return $translator->trans('schema.mcp.tools_collision_warning', [
						'{name}'   => (string)($w['name'] ?? ''),
						'{source}' => (string)($w['source'] ?? ''),
					]);
				},
				$result->warnings(),
			);
		}

		return $meta;
	}
}
