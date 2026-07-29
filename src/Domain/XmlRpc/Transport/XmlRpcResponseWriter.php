<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Transport;

/**
 * Serializes PHP values into XML-RPC response documents.
 *
 * Lists become `<array>`, associative arrays become `<struct>`, and
 * `DateTimeInterface` becomes `dateTime.iso8601` in the basic format clients
 * expect. Null is written as an empty string rather than `<nil>`, which is an
 * extension many clients do not implement.
 */
readonly class XmlRpcResponseWriter
{
	private const DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';

	public function methodResponse(mixed $value): string
	{
		return self::DECLARATION . "\n"
			. '<methodResponse><params><param>' . $this->value($value) . '</param></params></methodResponse>';
	}

	public function fault(int $code, string $message): string
	{
		return self::DECLARATION . "\n"
			. '<methodResponse><fault>'
			. $this->value(['faultCode' => $code, 'faultString' => $message])
			. '</fault></methodResponse>';
	}

	private function value(mixed $value): string
	{
		if (is_bool($value)) {
			return '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
		}

		if (is_int($value)) {
			return '<value><int>' . $value . '</int></value>';
		}

		if (is_float($value)) {
			return '<value><double>' . rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') . '</double></value>';
		}

		if ($value instanceof \DateTimeInterface) {
			return '<value><dateTime.iso8601>' . $value->format('Ymd\THis') . '</dateTime.iso8601></value>';
		}

		if ($value === null) {
			return '<value><string></string></value>';
		}

		if (is_array($value)) {
			return array_is_list($value) ? $this->arrayValue($value) : $this->structValue($value);
		}

		return '<value><string>' . $this->escape((string)$value) . '</string></value>';
	}

	/** @param array<int,mixed> $items */
	private function arrayValue(array $items): string
	{
		$xml = '<value><array><data>';
		foreach ($items as $item) {
			$xml .= $this->value($item);
		}

		return $xml . '</data></array></value>';
	}

	/** @param array<string,mixed> $members */
	private function structValue(array $members): string
	{
		$xml = '<value><struct>';
		foreach ($members as $name => $member) {
			$xml .= '<member><name>' . $this->escape((string)$name) . '</name>' . $this->value($member) . '</member>';
		}

		return $xml . '</struct></value>';
	}

	private function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
	}
}
