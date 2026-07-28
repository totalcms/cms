<?php

declare(strict_types=1);

use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcRequestParser;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcResponseWriter;

function xmlRpcCall(string $methodName, string $paramsXml = ''): string
{
	return '<?xml version="1.0"?><methodCall><methodName>' . $methodName . '</methodName>'
		. '<params>' . $paramsXml . '</params></methodCall>';
}

describe('XmlRpcRequestParser', function (): void {
	it('parses the method name and typed params', function (): void {
		$xml = xmlRpcCall('metaWeblog.newPost',
			'<param><value><string>blog</string></value></param>'
			. '<param><value><int>42</int></value></param>'
			. '<param><value><boolean>1</boolean></value></param>'
			. '<param><value><double>1.5</double></value></param>'
		);

		$call = (new XmlRpcRequestParser())->parse($xml);

		expect($call['method'])->toBe('metaWeblog.newPost');
		expect($call['params'])->toBe(['blog', 42, true, 1.5]);
	});

	it('treats an untyped value as a string', function (): void {
		$call = (new XmlRpcRequestParser())->parse(xmlRpcCall('demo', '<param><value>bare</value></param>'));

		expect($call['params'][0])->toBe('bare');
	});

	it('parses structs and arrays', function (): void {
		$xml = xmlRpcCall('metaWeblog.newPost', '<param><value><struct>'
			. '<member><name>title</name><value><string>Hello</string></value></member>'
			. '<member><name>categories</name><value><array><data>'
			. '<value><string>Tech</string></value><value><string>PHP</string></value>'
			. '</data></array></value></member>'
			. '</struct></value></param>');

		$call = (new XmlRpcRequestParser())->parse($xml);

		expect($call['params'][0])->toBe(['title' => 'Hello', 'categories' => ['Tech', 'PHP']]);
	});

	it('decodes base64 and dateTime.iso8601', function (): void {
		$xml = xmlRpcCall('demo',
			'<param><value><base64>' . base64_encode('bytes') . '</base64></value></param>'
			. '<param><value><dateTime.iso8601>20260728T14:30:00Z</dateTime.iso8601></value></param>'
		);

		$call = (new XmlRpcRequestParser())->parse($xml);

		expect($call['params'][0])->toBe('bytes');
		expect($call['params'][1])->toBeInstanceOf(DateTimeImmutable::class);
		expect($call['params'][1]->format('Y-m-d H:i:s'))->toBe('2026-07-28 14:30:00');
	});

	it('rejects a DOCTYPE declaration outright', function (): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x "boom">]>'
			. '<methodCall><methodName>demo</methodName><params /></methodCall>';

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'DOCTYPE');
	});

	it('rejects a UTF-16LE body with a BOM before it ever reaches the DOCTYPE check', function (): void {
		// The DOCTYPE regex is byte-level ASCII and never fires on a UTF-16
		// body, so a UTF-16-encoded DOCTYPE would otherwise sail through to
		// libxml. This body carries no DOCTYPE at all — it only has to prove
		// the encoding guard rejects the transfer encoding itself, on sight.
		$xml = "\xFF\xFE" . mb_convert_encoding(
			xmlRpcCall('demo', '<param><value><string>hi</string></value></param>'),
			'UTF-16LE',
			'UTF-8'
		);

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'UTF-8');
	});

	it('rejects an XML declaration naming a non-UTF-8 encoding', function (): void {
		$xml = '<?xml version="1.0" encoding="ISO-8859-1"?><methodCall><methodName>demo</methodName>'
			. '<params /></methodCall>';

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'UTF-8');
	});

	it('accepts a body whose declaration explicitly names UTF-8', function (): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>demo</methodName>'
			. '<params /></methodCall>';

		$call = (new XmlRpcRequestParser())->parse($xml);

		expect($call['method'])->toBe('demo');
	});

	it('rejects a body over the size cap', function (): void {
		$xml = xmlRpcCall('demo', '<param><value><string>'
			. str_repeat('x', XmlRpcRequestParser::MAX_BODY_BYTES) . '</string></value></param>');

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'too large');
	});

	it('rejects nesting deeper than the cap', function (): void {
		$depth = XmlRpcRequestParser::MAX_DEPTH + 2;
		$inner = '<value><string>deep</string></value>';
		for ($i = 0; $i < $depth; $i++) {
			$inner = '<value><array><data>' . $inner . '</data></array></value>';
		}

		expect(fn (): array => (new XmlRpcRequestParser())->parse(xmlRpcCall('demo', '<param>' . $inner . '</param>')))
			->toThrow(XmlRpcFault::class, 'too deep');
	});

	it('rejects a non-methodCall document', function (): void {
		expect(fn (): array => (new XmlRpcRequestParser())->parse('<?xml version="1.0"?><methodResponse />'))
			->toThrow(XmlRpcFault::class, 'methodCall');
	});

	it('rejects malformed XML', function (): void {
		expect(fn (): array => (new XmlRpcRequestParser())->parse('<methodCall><methodName>x'))
			->toThrow(XmlRpcFault::class);
	});

	it('rejects more params than the cap', function (): void {
		$paramsXml = str_repeat(
			'<param><value><string>x</string></value></param>',
			XmlRpcRequestParser::MAX_PARAMS + 1
		);

		expect(fn (): array => (new XmlRpcRequestParser())->parse(xmlRpcCall('demo', $paramsXml)))
			->toThrow(XmlRpcFault::class, 'Too many parameters');
	});

	it('rejects a struct with more members than the cap', function (): void {
		$members = '';
		for ($i = 0; $i < XmlRpcRequestParser::MAX_MEMBERS + 1; $i++) {
			$members .= '<member><name>m' . $i . '</name><value><string>x</string></value></member>';
		}
		$xml = xmlRpcCall('demo', '<param><value><struct>' . $members . '</struct></value></param>');

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'too many members');
	});

	it('rejects an array with more elements than the cap', function (): void {
		$values = str_repeat('<value><string>x</string></value>', XmlRpcRequestParser::MAX_MEMBERS + 1);
		$xml = xmlRpcCall('demo', '<param><value><array><data>' . $values . '</data></array></value></param>');

		expect(fn (): array => (new XmlRpcRequestParser())->parse($xml))
			->toThrow(XmlRpcFault::class, 'too many elements');
	});
});

describe('XmlRpcResponseWriter', function (): void {
	it('writes a scalar methodResponse', function (): void {
		$xml = (new XmlRpcResponseWriter())->methodResponse('post-1');

		expect($xml)->toContain('<methodResponse><params><param><value><string>post-1</string></value></param></params></methodResponse>');
	});

	it('writes structs, lists, booleans and dates', function (): void {
		$xml = (new XmlRpcResponseWriter())->methodResponse([
			'postid'      => 'p1',
			'sticky'      => true,
			'categories'  => ['Tech'],
			'dateCreated' => new DateTimeImmutable('2026-07-28 14:30:00', new DateTimeZone('UTC')),
		]);

		expect($xml)->toContain('<name>postid</name><value><string>p1</string></value>');
		expect($xml)->toContain('<name>sticky</name><value><boolean>1</boolean></value>');
		expect($xml)->toContain('<array><data><value><string>Tech</string></value></data></array>');
		expect($xml)->toContain('<dateTime.iso8601>20260728T143000</dateTime.iso8601>');
	});

	it('escapes XML-significant characters in strings', function (): void {
		$xml = (new XmlRpcResponseWriter())->methodResponse('5 < 6 & "quoted"');

		expect($xml)->toContain('5 &lt; 6 &amp;');
		expect($xml)->not->toContain('<value><string>5 < 6');
	});

	it('writes a fault', function (): void {
		$xml = (new XmlRpcResponseWriter())->fault(403, 'Bad login/pass combination.');

		expect($xml)->toContain('<fault>');
		expect($xml)->toContain('<name>faultCode</name><value><int>403</int></value>');
		expect($xml)->toContain('Bad login/pass combination.');
	});
});

describe('XmlRpcFault', function (): void {
	it('builds unknownMethod with the method name and the standard code', function (): void {
		$fault = XmlRpcFault::unknownMethod('wp.foo');

		expect($fault->getCode())->toBe(-32601);
		expect($fault->getMessage())->toContain('wp.foo');
		expect($fault->getMessage())->toContain('does not exist');
	});

	it('builds badCredentials with the standard code and message', function (): void {
		$fault = XmlRpcFault::badCredentials();

		expect($fault->getCode())->toBe(403);
		expect($fault->getMessage())->toBe('Bad login/pass combination.');
	});

	it('builds forbidden with the given message and the standard code', function (): void {
		$fault = XmlRpcFault::forbidden('nope');

		expect($fault->getCode())->toBe(401);
		expect($fault->getMessage())->toBe('nope');
	});

	it('builds notFound with the given message and the standard code', function (): void {
		$fault = XmlRpcFault::notFound('gone');

		expect($fault->getCode())->toBe(404);
		expect($fault->getMessage())->toBe('gone');
	});

	it('builds malformed with the given message and the standard code', function (): void {
		$fault = XmlRpcFault::malformed('bad xml');

		expect($fault->getCode())->toBe(-32700);
		expect($fault->getMessage())->toBe('bad xml');
	});
});
