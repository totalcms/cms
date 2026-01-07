<?php

namespace Tests\Unit\Renderer;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Renderer\RawRenderer;

final class RawRendererTest extends TestCase
{
	private RawRenderer $renderer;
	private Psr17Factory $factory;

	protected function setUp(): void
	{
		$this->renderer = new RawRenderer();
		$this->factory  = new Psr17Factory();
	}

	public function testRenderWritesContentToBody(): void
	{
		$response = $this->factory->createResponse();
		$content  = 'Hello, World!';

		$result = $this->renderer->render($response, $content);

		$this->assertSame($content, (string)$result->getBody());
	}

	public function testRenderHandlesEmptyContent(): void
	{
		$response = $this->factory->createResponse();

		$result = $this->renderer->render($response, '');

		$this->assertSame('', (string)$result->getBody());
	}

	public function testRenderHandlesHtmlContent(): void
	{
		$response = $this->factory->createResponse();
		$html     = '<html><body><h1>Test</h1></body></html>';

		$result = $this->renderer->render($response, $html);

		$this->assertSame($html, (string)$result->getBody());
	}

	public function testRenderHandlesLongContent(): void
	{
		$response = $this->factory->createResponse();
		$content  = str_repeat('Lorem ipsum dolor sit amet. ', 1000);

		$result = $this->renderer->render($response, $content);

		$this->assertSame($content, (string)$result->getBody());
	}

	public function testRenderPreservesResponseStatus(): void
	{
		$response = $this->factory->createResponse(201);

		$result = $this->renderer->render($response, 'Created');

		$this->assertSame(201, $result->getStatusCode());
	}

	public function testRenderPreservesExistingHeaders(): void
	{
		$response = $this->factory->createResponse()
			->withHeader('X-Custom-Header', 'custom-value')
			->withHeader('Content-Type', 'text/html');

		$result = $this->renderer->render($response, '<p>Test</p>');

		$this->assertSame('custom-value', $result->getHeaderLine('X-Custom-Header'));
		$this->assertSame('text/html', $result->getHeaderLine('Content-Type'));
	}

	public function testRenderHandlesSpecialCharacters(): void
	{
		$response = $this->factory->createResponse();
		$content  = '<script>alert("XSS")</script> & special chars: é è ñ 中文';

		$result = $this->renderer->render($response, $content);

		$this->assertSame($content, (string)$result->getBody());
	}

	public function testRenderHandlesJsonContent(): void
	{
		$response = $this->factory->createResponse();
		$json     = '{"name":"test","value":123,"nested":{"key":"value"}}';

		$result = $this->renderer->render($response, $json);

		$this->assertSame($json, (string)$result->getBody());
	}

	public function testRenderHandlesBinaryContent(): void
	{
		$response = $this->factory->createResponse();
		$binary   = chr(0) . chr(1) . chr(255) . chr(128);

		$result = $this->renderer->render($response, $binary);

		$this->assertSame($binary, (string)$result->getBody());
	}

	public function testRenderHandlesMultilineContent(): void
	{
		$response  = $this->factory->createResponse();
		$multiline = "Line 1\nLine 2\r\nLine 3\rLine 4";

		$result = $this->renderer->render($response, $multiline);

		$this->assertSame($multiline, (string)$result->getBody());
	}
}
