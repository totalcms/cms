<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\EventListener\ReloadPulseListener;
use TotalCMS\Domain\Builder\Repository\ReloadPulseRepository;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;

/**
 * Tests the listener wires up the right pulses for template + page events,
 * filtering object events to the configured pages collection only.
 */
final class ReloadPulseListenerTest extends TestCase
{
	private ReloadPulseRepository&MockObject $pulse;
	private BuilderConfigService&MockObject $builderConfig;
	private ReloadPulseListener $listener;

	protected function setUp(): void
	{
		$this->pulse         = $this->createMock(ReloadPulseRepository::class);
		$this->builderConfig = $this->createMock(BuilderConfigService::class);
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->listener = new ReloadPulseListener($this->pulse, $this->builderConfig);
	}

	public function testTemplateSavedPulsesWithFullPath(): void
	{
		$this->pulse->expects($this->once())
			->method('pulse')
			->with('pages/about');

		$this->listener->onTemplateSaved([
			'id'     => 'about',
			'folder' => 'pages',
			'path'   => 'pages/about',
		]);
	}

	public function testTemplateSavedFallsBackToIdWhenPathMissing(): void
	{
		$this->pulse->expects($this->once())
			->method('pulse')
			->with('about');

		$this->listener->onTemplateSaved([
			'id' => 'about',
		]);
	}

	public function testObjectChangedPulsesForPagesCollection(): void
	{
		$this->pulse->expects($this->once())
			->method('pulse')
			->with('builder-pages/about');

		$this->listener->onObjectChanged([
			'collection' => 'builder-pages',
			'id'         => 'about',
		]);
	}

	public function testObjectChangedIgnoresOtherCollections(): void
	{
		// Saving a blog post should not bump the pulse — we only care about
		// changes to the pages-collection records.
		$this->pulse->expects($this->never())->method('pulse');

		$this->listener->onObjectChanged([
			'collection' => 'blog',
			'id'         => 'hello-world',
		]);
	}

	public function testObjectChangedRespectsCustomPagesCollection(): void
	{
		// A site that customizes which collection backs Builder pages should
		// have the listener follow that config — not the hard-coded default.
		$customConfig = $this->createMock(BuilderConfigService::class);
		$customConfig->method('getPagesCollectionId')->willReturn('site-pages');
		$listener = new ReloadPulseListener($this->pulse, $customConfig);

		$this->pulse->expects($this->once())
			->method('pulse')
			->with('site-pages/home');

		$listener->onObjectChanged([
			'collection' => 'site-pages',
			'id'         => 'home',
		]);
	}
}
