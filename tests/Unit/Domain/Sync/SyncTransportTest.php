<?php

declare(strict_types=1);

use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Sync\Service\SyncTransport;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

// The HTTP leg of a sync, on its own: API-key headers, the legacy-route
// fallback, and one readable error out of whatever the remote sent back.
beforeEach(function (): void {
	$this->http      = test()->createMock(HttpClientInterface::class);
	$this->transport = new SyncTransport($this->http);
});

test('a push sends the API key header, never a Bearer token, and decodes the answer', function (): void {
	$this->http->expects(test()->once())->method('request')
		->with('POST', 'https://prod.example/api/sync/import', test()->callback(
			fn (array $o): bool => in_array('X-API-Key: k1', $o['headers'], true)
				&& !array_filter($o['headers'], fn (string $h): bool => str_starts_with($h, 'Authorization'))
				&& $o['timeout'] === 60
		))
		->willReturn(new HttpResponse(200, '{"success":true,"errors":[]}'));

	expect($this->transport->post('https://prod.example/', 'k1', '/api/sync/import', new JumpStartData()))
		->toBe(['success' => true, 'errors' => []]);
});

test('a refused push carries the remote message out of its nested error shape', function (): void {
	$this->http->method('request')->willReturn(new HttpResponse(422, '{"error":{"message":"Cannot save collection with a reserved name","code":"reserved"}}'));

	expect(fn () => $this->transport->post('https://prod.example', 'k1', '/api/sync/import', new JumpStartData()))
		->toThrow(RuntimeException::class, 'Push failed (HTTP 422): Cannot save collection with a reserved name');
});

test('a fetch falls back to the legacy export route on a 4xx and fails on a 5xx', function (): void {
	$this->http->expects(test()->exactly(2))->method('request')
		->willReturnCallback(fn (string $m, string $url): HttpResponse => str_ends_with($url, SyncTransport::EXPORT_ROUTE)
			? new HttpResponse(404, '')
			: new HttpResponse(200, '{"schemas":[]}'));

	expect($this->transport->fetchExport('https://old.example', 'k1'))->toBe(['schemas' => []]);

	$http = test()->createMock(HttpClientInterface::class);
	$http->method('request')->willReturn(new HttpResponse(500, 'boom'));
	expect(fn () => (new SyncTransport($http))->fetchExport('https://x', 'k'))->toThrow(RuntimeException::class, 'Pull failed (HTTP 500): boom');
});

test('the verdict treats an absent success flag as success and any error line as failure', function (): void {
	expect(SyncTransport::verdict(null, null))->toBe([true, []])
		->and(SyncTransport::verdict(true, ['bad', 7, ['nested' => 'ignored']]))->toBe([false, ['bad', '7']])
		->and(SyncTransport::verdict(false, []))->toBe([false, []]);
});

test('remote errors read the nested message, flat strings, or a trimmed body', function (): void {
	expect(SyncTransport::remoteError('{"error":{"message":"nested"}}'))->toBe('nested')
		->and(SyncTransport::remoteError('{"error":"flat"}'))->toBe('flat')
		->and(SyncTransport::remoteError('{"message":"top"}'))->toBe('top')
		->and(SyncTransport::remoteError('   '))->toBe('(empty response body)')
		->and(SyncTransport::remoteError(str_repeat('x', 600)))->toHaveLength(501);
});
