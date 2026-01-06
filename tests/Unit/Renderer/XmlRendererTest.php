<?php

namespace Tests\Unit\Renderer;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Renderer\XmlRenderer;

final class XmlRendererTest extends TestCase
{
	private XmlRenderer $renderer;
	private Psr17Factory $factory;

	protected function setUp(): void
	{
		$this->renderer = new XmlRenderer();
		$this->factory  = new Psr17Factory();
	}

	public function testXmlSetsCorrectContentType(): void
	{
		$response = $this->factory->createResponse();
		$result   = $this->renderer->xml($response, '<root></root>');

		$this->assertSame('application/xml', $result->getHeaderLine('Content-Type'));
	}

	public function testXmlWritesContentToBody(): void
	{
		$xml      = '<?xml version="1.0"?><root><item>test</item></root>';
		$response = $this->factory->createResponse();
		$result   = $this->renderer->xml($response, $xml);

		$this->assertSame($xml, (string)$result->getBody());
	}

	public function testXmlHandlesEmptyContent(): void
	{
		$response = $this->factory->createResponse();
		$result   = $this->renderer->xml($response, '');

		$this->assertSame('', (string)$result->getBody());
		$this->assertSame('application/xml', $result->getHeaderLine('Content-Type'));
	}

	public function testXmlHandlesComplexXml(): void
	{
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/</loc>
    <lastmod>2025-01-01</lastmod>
  </url>
</urlset>
XML;

		$response = $this->factory->createResponse();
		$result   = $this->renderer->xml($response, $xml);

		$this->assertSame($xml, (string)$result->getBody());
	}

	public function testXmlPreservesExistingHeaders(): void
	{
		$response = $this->factory->createResponse()
			->withHeader('X-Custom-Header', 'custom-value');

		$result = $this->renderer->xml($response, '<root/>');

		$this->assertSame('custom-value', $result->getHeaderLine('X-Custom-Header'));
		$this->assertSame('application/xml', $result->getHeaderLine('Content-Type'));
	}

	public function testXmlHandlesSpecialCharacters(): void
	{
		$xml      = '<root><item>&lt;script&gt;alert("xss")&lt;/script&gt;</item></root>';
		$response = $this->factory->createResponse();
		$result   = $this->renderer->xml($response, $xml);

		$this->assertSame($xml, (string)$result->getBody());
	}
}
