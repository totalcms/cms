<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class TotalCMSTwigAdapterCaptionTest extends TestCase
{
	private \ReflectionMethod $method;
	private RenderTwigAdapter $adapter;

	protected function setUp(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->adapter = new RenderTwigAdapter(
			$this->createMock(HtmxRenderer::class),
			$this->createMock(Config::class),
			$this->createMock(DataTwigAdapter::class),
			$this->createMock(MediaTwigAdapter::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaFetcher::class),
			$this->createMock(GridRenderer::class),
			$loggerFactory,
		);

		$reflection   = new \ReflectionClass(RenderTwigAdapter::class);
		$this->method = $reflection->getMethod('renderCaptionTemplate');
	}

	private function render(string $template, array $image): string
	{
		return $this->method->invoke($this->adapter, $template, $image);
	}

	public function testSingleBraceVariableRendersCorrectly(): void
	{
		$result = $this->render('{alt}', ['alt' => 'Sunset photo']);
		expect($result)->toBe('Sunset photo');
	}

	public function testSpacedBracesVariableRendersCorrectly(): void
	{
		$result = $this->render('{ alt }', ['alt' => 'Sunset photo']);
		expect($result)->toBe('Sunset photo');
	}

	public function testHtmlInTemplateIsPreserved(): void
	{
		$result = $this->render('<h4>{alt}</h4>', ['alt' => 'Sunset photo']);
		expect($result)->toBe('<h4>Sunset photo</h4>');
	}

	public function testNestedDotNotationVariables(): void
	{
		$result = $this->render('{exif.camera}', [
			'exif' => ['camera' => 'Canon EOS R5'],
		]);
		expect($result)->toBe('Canon EOS R5');
	}

	public function testMissingVariablesRenderAsEmpty(): void
	{
		$result = $this->render('{alt}', []);
		expect($result)->toBe('');
	}

	public function testAllEmptyVariablesReturnEmptyString(): void
	{
		// With only variables and no HTML/separators, empty values yield empty result
		$result = $this->render('{alt}', ['alt' => '']);
		expect($result)->toBe('');
	}

	public function testHtmlWithEmptyVariablesPreservesSeparators(): void
	{
		// HTML tags with empty variables: strip_tags leaves " - " which is non-empty
		$result = $this->render('<span>{alt}</span> - <span>{title}</span>', [
			'alt'   => '',
			'title' => '',
		]);
		expect($result)->toBe('<span></span> - <span></span>');
	}

	public function testTwigFiltersWork(): void
	{
		$result = $this->render('{alt|upper}', ['alt' => 'hello']);
		expect($result)->toBe('HELLO');
	}

	public function testInvalidTemplateReturnsEmptyString(): void
	{
		// Malformed Twig syntax after brace conversion should trigger catch block
		$result = $this->render('{%invalid%}', []);
		expect($result)->toBe('');
	}
}
