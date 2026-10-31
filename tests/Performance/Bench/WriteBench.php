<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;

/**
 * One editor saving one existing post — the full write path, including the
 * index rebuild the save triggers. It updates rather than creates, so the
 * fixture keeps its size however many times it runs.
 */
#[Bench\ParamProviders('provideSizes')]
#[Bench\BeforeMethods(['boot', 'pickPost'])]
#[Bench\AfterMethods(['assertSizeUnchanged'])]
final class WriteBench extends BenchCase
{
	private const COLLECTION = 'posts';

	private string $id = '';

	/** @var array<string,mixed> */
	private array $post = [];

	public function pickPost(): void
	{
		$index    = $this->container->get(IndexRepository::class)->fetchIndex(self::COLLECTION);
		$this->id = (string)($index?->objects->first()['id'] ?? '');
		if ($this->id === '') {
			throw new \RuntimeException('Fixture has no posts');
		}
		$this->post = $this->container->get(ObjectFetcher::class)->fetchObject(self::COLLECTION, $this->id)->toArray();
		$this->freshRequest();
	}

	public function benchSaveExisting(): void
	{
		$this->post['views'] = random_int(0, 100000);
		$this->container->get(ObjectUpdater::class)->updateObject(self::COLLECTION, $this->id, $this->post);
	}

	public function assertSizeUnchanged(): void
	{
		$manifest = json_decode((string)file_get_contents($this->root . '/fixture.json'), true);
		$files    = count(glob($this->root . '/tcms-data/' . self::COLLECTION . '/*.json') ?: []);
		if ($files !== $manifest['objects']) {
			throw new \RuntimeException(sprintf('Write benchmark changed the fixture size: %d files, expected %d', $files, $manifest['objects']));
		}
	}
}
