<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Transport;

/**
 * Minimal XML-RPC request parser.
 *
 * Hand-rolled rather than pulling in a library: PHP's `xmlrpc` extension was
 * removed in 8.0 and the only maintained userland option is large and legacy.
 * More importantly, the two features behind WordPress's XML-RPC reputation are
 * absent by construction here — there is no `system.multicall` batching and no
 * `pingback.*` — and the caps below bound every allocation an anonymous caller
 * can trigger, since this is the one piece of pre-auth code in the feature.
 */
readonly class XmlRpcRequestParser
{
	public const MAX_BODY_BYTES = 1048576;
	public const MAX_DEPTH      = 20;
	public const MAX_PARAMS     = 32;
	public const MAX_MEMBERS    = 256;

	/**
	 * @return array{method: string, params: array<int,mixed>}
	 */
	public function parse(string $body): array
	{
		if (trim($body) === '') {
			throw XmlRpcFault::malformed('Empty request body.');
		}

		if (strlen($body) > self::MAX_BODY_BYTES) {
			throw XmlRpcFault::malformed('Request body is too large.');
		}

		// Reject DOCTYPE before parsing rather than trusting libxml defaults.
		// This closes XXE and entity-expansion (billion laughs) categorically:
		// no DTD means no entities to expand or resolve.
		if (preg_match('/<!DOCTYPE/i', $body) === 1) {
			throw XmlRpcFault::malformed('DOCTYPE declarations are not accepted.');
		}

		$useErrors = libxml_use_internal_errors(true);

		try {
			$doc = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($useErrors);
		}

		if (!$doc instanceof \SimpleXMLElement || $doc->getName() !== 'methodCall') {
			throw XmlRpcFault::malformed('Request is not a valid XML-RPC methodCall.');
		}

		$method = trim((string)$doc->methodName);
		if ($method === '') {
			throw XmlRpcFault::malformed('methodCall is missing a methodName.');
		}

		$params = [];
		if (isset($doc->params)) {
			foreach ($doc->params->param as $param) {
				if (count($params) >= self::MAX_PARAMS) {
					throw XmlRpcFault::malformed('Too many parameters.');
				}
				if (!isset($param->value)) {
					throw XmlRpcFault::malformed('A param is missing its value.');
				}
				$params[] = $this->parseValue($param->value, 1);
			}
		}

		return ['method' => $method, 'params' => $params];
	}

	private function parseValue(\SimpleXMLElement $value, int $depth): mixed
	{
		if ($depth > self::MAX_DEPTH) {
			throw XmlRpcFault::malformed('Value nesting is too deep.');
		}

		$children = $value->children();
		if (count($children) === 0) {
			// <value>text</value> with no type element is a string per spec.
			return (string)$value;
		}

		$node = $children[0];
		if (!$node instanceof \SimpleXMLElement) {
			return (string)$value;
		}

		return match ($node->getName()) {
			'string'           => (string)$node,
			'int', 'i4'        => (int)(string)$node,
			'boolean'          => (string)$node === '1' || strtolower((string)$node) === 'true',
			'double'           => (float)(string)$node,
			'base64'           => $this->parseBase64((string)$node),
			'dateTime.iso8601' => $this->parseDate((string)$node),
			'struct'           => $this->parseStruct($node, $depth),
			'array'            => $this->parseArray($node, $depth),
			'nil'              => null,
			default            => throw XmlRpcFault::malformed('Unsupported value type: ' . $node->getName()),
		};
	}

	private function parseBase64(string $raw): string
	{
		$decoded = base64_decode(trim($raw), true);
		if ($decoded === false) {
			throw XmlRpcFault::malformed('Invalid base64 value.');
		}

		return $decoded;
	}

	/**
	 * XML-RPC dates use ISO 8601 *basic* format (`20260728T14:30:00`), which
	 * PHP's parser handles inconsistently, so normalise to extended format
	 * first. Any trailing zone designator (`Z` or `±HH:MM`) is preserved —
	 * PostMapper depends on knowing whether a zone was supplied.
	 */
	private function parseDate(string $raw): \DateTimeImmutable
	{
		$raw = trim($raw);
		$normalized = preg_replace(
			'/^(\d{4})(\d{2})(\d{2})T/',
			'$1-$2-$3T',
			$raw
		) ?? $raw;

		try {
			return new \DateTimeImmutable($normalized);
		} catch (\Exception) {
			throw XmlRpcFault::malformed('Invalid dateTime.iso8601 value: ' . $raw);
		}
	}

	/** @return array<string,mixed> */
	private function parseStruct(\SimpleXMLElement $struct, int $depth): array
	{
		$result = [];

		foreach ($struct->member as $member) {
			if (count($result) >= self::MAX_MEMBERS) {
				throw XmlRpcFault::malformed('Struct has too many members.');
			}

			$name = trim((string)$member->name);
			if ($name === '') {
				continue;
			}

			$result[$name] = isset($member->value)
				? $this->parseValue($member->value, $depth + 1)
				: '';
		}

		return $result;
	}

	/** @return array<int,mixed> */
	private function parseArray(\SimpleXMLElement $array, int $depth): array
	{
		$result = [];

		if (!isset($array->data)) {
			return $result;
		}

		foreach ($array->data->value as $value) {
			if (count($result) >= self::MAX_MEMBERS) {
				throw XmlRpcFault::malformed('Array has too many elements.');
			}
			$result[] = $this->parseValue($value, $depth + 1);
		}

		return $result;
	}
}
