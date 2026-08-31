<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\AlloyImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

/**
 * AlloyImporter tests with proper mocking now that final keywords have been removed.
 */
class AlloyImporterTest extends TestCase
{
	private AlloyImporter $alloyImporter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFactory;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;
	private \PHPUnit\Framework\MockObject\MockObject $logger;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = \tcmsTestTempDir('alloy-test');
		mkdir($this->tempDir, 0755, true);

		// Create mock dependencies (now possible since final was removed)
		$this->collectionFetcher    = $this->createMock(CollectionFetcher::class);
		$this->collectionFactory    = $this->createMock(CollectionFactory::class);
		$this->collectionRepository = $this->createMock(CollectionRepository::class);
		$this->jobQueuer            = $this->createMock(JobQueuer::class);
		$this->logger               = $this->createMock(LoggerInterface::class);
		$this->loggerFactory        = $this->createMock(LoggerFactory::class);

		// Setup logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn($this->logger);

		// Configure collection mocks to support creation verification
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->alloyImporter = new AlloyImporter(
			$this->collectionFetcher,
			$this->collectionFactory,
			$this->collectionRepository,
			$this->jobQueuer,
			$this->loggerFactory
		);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->tempDir)) {
			$this->removeDirectory($this->tempDir);
		}
	}

	private function removeDirectory(string $dir): void
	{
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$filePath = $dir . '/' . $file;
			is_dir($filePath) ? $this->removeDirectory($filePath) : unlink($filePath);
		}
		rmdir($dir);
	}

	public function testAnalyzeWithNonexistentDirectories(): void
	{
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$folders = [
			'blog'          => 'nonexistent-blog',
			'image_uploads' => 'nonexistent-uploads',
			'embeds'        => 'nonexistent-embeds',
			'droplets'      => 'nonexistent-droplets',
		];

		$result = $this->alloyImporter->analyze($folders);

		$this->assertArrayHasKey('blogs', $result);
		$this->assertArrayHasKey('embeds', $result);
		$this->assertArrayHasKey('droplets', $result);
		$this->assertEmpty($result['blogs']);
		$this->assertEmpty($result['embeds']);
		$this->assertEmpty($result['droplets']);
	}

	public function testImportWithEmptyDirectories(): void
	{
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		// Create empty directories
		mkdir($this->tempDir . '/blog');
		mkdir($this->tempDir . '/embeds');
		mkdir($this->tempDir . '/droplets');

		$folders = [
			'blog'          => 'blog',
			'image_uploads' => 'uploads',
			'embeds'        => 'embeds',
			'droplets'      => 'droplets',
		];

		$importCount = $this->alloyImporter->import($folders);
		$this->assertEquals(0, $importCount);
	}

	// ── Migrating a blog post ────────────────────────────────────────────────

	/** Write an Alloy markdown post into a blog folder under the document root. */
	private function writePost(string $filename, string $frontMatter, string $body = 'Body text.'): void
	{
		$dir = $this->tempDir . '/blog';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($dir . '/' . $filename, "---\n" . $frontMatter . "\n---\n" . $body);
	}

	/** @return array<string,mixed> the data queued for import */
	private function importAndCaptureQueued(): array
	{
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$queued = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queued): \TotalCMS\Domain\JobQueue\Data\JobData {
				$queued[] = ['collection' => $collection, 'data' => $data];

				return new \TotalCMS\Domain\JobQueue\Data\JobData();
			});

		$this->alloyImporter->import([
			'blog'          => 'blog',
			'image_uploads' => 'uploads',
			'embeds'        => 'embeds',
			'droplets'      => 'droplets',
		]);

		return $queued;
	}

	public function testTakesTheDateAndIdFromTheFilename(): void
	{
		// Alloy names posts `YYYY-MM-DD_slug.md`, and that date is the only
		// publication date many posts have. Losing it silently republishes an
		// entire archive as undated.
		$this->writePost('2021-03-01_the-website-is-live.md', 'title: The website is live');

		$queued = $this->importAndCaptureQueued();

		$this->assertCount(1, $queued);
		$this->assertSame('blog', $queued[0]['collection']);
		$this->assertSame('the-website-is-live', $queued[0]['data']['id']);
		$this->assertSame('2021-03-01T00:00:00+00:00', $queued[0]['data']['date']);
	}

	public function testAFilenameWithoutADateBecomesTheIdWhole(): void
	{
		$this->writePost('about-us.md', 'title: About');

		$queued = $this->importAndCaptureQueued();

		$this->assertSame('about-us', $queued[0]['data']['id']);
		$this->assertArrayNotHasKey('date', $queued[0]['data']);
	}

	public function testCarriesTheFrontMatterFieldsAcross(): void
	{
		$this->writePost('2021-03-01_post.md', "title: Real Title\nauthor: Jane\ncategory: News\ntags:\n  - one\n  - two\ndraft: true");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame('Real Title', $data['title']);
		$this->assertSame('Jane', $data['author']);
		$this->assertSame('News', $data['category']);
		$this->assertSame(['one', 'two'], $data['tags']);
		// A draft that imports as published is the migration mistake that gets
		// noticed by the public rather than by the operator.
		$this->assertTrue($data['draft']);
	}

	public function testFallsBackToTheIdWhenAPostHasNoTitle(): void
	{
		$this->writePost('2021-03-01_no-title-here.md', 'author: Jane');

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame('no-title-here', $data['title']);
		$this->assertFalse($data['draft']);
	}

	public function testConvertsTheMarkdownBodyToHtml(): void
	{
		$this->writePost('2021-03-01_post.md', 'title: T', "# Heading\n\nSome **bold** text.");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertStringContainsString('<h1>Heading</h1>', $data['content']);
		$this->assertStringContainsString('<strong>bold</strong>', $data['content']);
	}

	public function testWrapsTheSummaryInAParagraph(): void
	{
		$this->writePost('2021-03-01_post.md', "title: T\nsummary: A short summary");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame('<p>A short summary</p>', $data['summary']);
	}

	// ── The topper image ─────────────────────────────────────────────────────

	public function testResolvesTheTopperImageAgainstTheUploadsFolder(): void
	{
		mkdir($this->tempDir . '/uploads', 0755, true);
		file_put_contents($this->tempDir . '/uploads/hero.jpg', 'not-really-a-jpeg');
		$this->writePost('2021-03-01_post.md', "title: T\ntopper: /uploads/hero.jpg\ntopperalt: A hero");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame($this->tempDir . '/uploads/hero.jpg', $data['image']);
		$this->assertSame('A hero', $data['imageAlt']);
	}

	public function testStillImportsAPostWhoseImageIsMissing(): void
	{
		// Alloy sites routinely reference images that did not survive the copy.
		// Dropping the whole post over a missing image would lose the writing
		// as well as the picture.
		$this->writePost('2021-03-01_post.md', "title: T\ntopper: /uploads/gone.jpg\ntopperalt: Alt");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame('T', $data['title']);
		$this->assertArrayNotHasKey('image', $data);
		$this->assertArrayNotHasKey('imageAlt', $data);
	}

	public function testHandlesATopperGivenAsAFullUrl(): void
	{
		mkdir($this->tempDir . '/uploads', 0755, true);
		file_put_contents($this->tempDir . '/uploads/hero.jpg', 'x');
		$this->writePost('2021-03-01_post.md', "title: T\ntopper: https://example.com/uploads/hero.jpg");

		$data = $this->importAndCaptureQueued()[0]['data'];

		$this->assertSame($this->tempDir . '/uploads/hero.jpg', $data['image']);
	}

	// ── Robustness across a whole archive ────────────────────────────────────

	public function testImportsEveryPostInTheFolder(): void
	{
		$this->writePost('2021-03-01_one.md', 'title: One');
		$this->writePost('2021-03-02_two.md', 'title: Two');
		$this->writePost('2021-03-03_three.md', 'title: Three');

		$queued = $this->importAndCaptureQueued();

		$this->assertCount(3, $queued);
		// glob() returns them sorted by filename, which for Alloy's naming is
		// chronological.
		$this->assertSame(['one', 'two', 'three'], array_column(array_column($queued, 'data'), 'id'));
	}

	public function testCountsWhatItImported(): void
	{
		$this->writePost('2021-03-01_one.md', 'title: One');
		$this->writePost('2021-03-02_two.md', 'title: Two');
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$count = $this->alloyImporter->import([
			'blog' => 'blog', 'image_uploads' => 'uploads', 'embeds' => 'embeds', 'droplets' => 'droplets',
		]);

		$this->assertSame(2, $count);
	}

	public function testCreatesTheBlogCollectionWhenItIsMissing(): void
	{
		$this->writePost('2021-03-01_one.md', 'title: One');

		// exists() false on the first check so it creates, then true so the
		// post-create verification inside createCollection() passes.
		$fetcher = $this->createMock(CollectionFetcher::class);
		$fetcher->method('collectionExists')->willReturnOnConsecutiveCalls(false, true, true, true, true);

		$this->collectionFactory->expects($this->atLeastOnce())->method('generateCollection')
			->willReturn(new \TotalCMS\Domain\Collection\Data\CollectionData());
		$this->collectionRepository->expects($this->atLeastOnce())->method('saveCollection');

		$importer = new AlloyImporter(
			$fetcher,
			$this->collectionFactory,
			$this->collectionRepository,
			$this->jobQueuer,
			$this->loggerFactory,
		);

		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;
		$importer->import([
			'blog' => 'blog', 'image_uploads' => 'uploads', 'embeds' => 'embeds', 'droplets' => 'droplets',
		]);
	}

	// ── analyze(): the preview an operator decides from ──────────────────────

	public function testAnalyzeReportsWhatWouldBeImportedWithoutImporting(): void
	{
		// This is the screen the operator reads before committing to a
		// migration, so it has to describe the same posts the import will
		// create — and queue nothing itself.
		$this->writePost('2021-03-01_the-post.md', "title: The Post\nauthor: Jane\ndraft: true\ntopper: /uploads/hero.jpg");
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$this->jobQueuer->expects($this->never())->method('queueImport');

		$result = $this->alloyImporter->analyze([
			'blog' => 'blog', 'image_uploads' => 'uploads', 'embeds' => 'embeds', 'droplets' => 'droplets',
		]);

		$this->assertCount(1, $result['blogs']);
		$blog = $result['blogs'][0];
		$this->assertSame('the-post', $blog['id']);
		$this->assertSame('2021-03-01', $blog['date']);
		$this->assertSame('The Post', $blog['title']);
		$this->assertSame('Jane', $blog['author']);
		$this->assertTrue($blog['draft']);
		$this->assertTrue($blog['has_image']);
	}

	// ── Embeds and droplets ──────────────────────────────────────────────────

	private function writeInto(string $folder, string $filename, string $contents): void
	{
		$dir = $this->tempDir . '/' . $folder;
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($dir . '/' . $filename, $contents);
	}

	public function testAnEmbedBecomesStyledTextWithItsFilenameAsTheId(): void
	{
		$this->writeInto('embeds', 'newsletter-signup.md', "---\ntitle: Ignored\n---\nSome **markdown**.");

		$queued = $this->importAndCaptureQueued();

		$this->assertCount(1, $queued);
		$this->assertSame('styledtext', $queued[0]['collection']);
		$this->assertSame('newsletter-signup', $queued[0]['data']['id']);
		$this->assertStringContainsString('<strong>markdown</strong>', $queued[0]['data']['styledtext']);
	}

	public function testATextDropletBecomesATextObject(): void
	{
		$this->writeInto('droplets', 'tagline.md', "---\ntype: text\ndata: Hello world\n---\n");

		$queued = $this->importAndCaptureQueued();

		$this->assertSame('text', $queued[0]['collection']);
		$this->assertSame(['id' => 'tagline', 'text' => 'Hello world'], $queued[0]['data']);
	}

	public function testADropletWithNoTypeIsTreatedAsText(): void
	{
		$this->writeInto('droplets', 'untyped.md', "---\ndata: Some value\n---\n");

		$queued = $this->importAndCaptureQueued();

		$this->assertSame('text', $queued[0]['collection']);
		$this->assertSame('Some value', $queued[0]['data']['text']);
	}

	public function testAnImageDropletResolvesAgainstTheUploadsFolder(): void
	{
		mkdir($this->tempDir . '/uploads', 0755, true);
		file_put_contents($this->tempDir . '/uploads/logo.png', 'x');
		$this->writeInto('droplets', 'logo.md', "---\ntype: image\ndata: /uploads/logo.png\n---\n");

		$queued = $this->importAndCaptureQueued();

		$this->assertSame('image', $queued[0]['collection']);
		$this->assertSame($this->tempDir . '/uploads/logo.png', $queued[0]['data']['image']);
	}

	public function testAnImageDropletWithNoFileQueuesNothing(): void
	{
		// Queuing an image import for a file that is not there would create an
		// object pointing at nothing.
		$this->writeInto('droplets', 'missing.md', "---\ntype: image\ndata: /uploads/gone.png\n---\n");

		$this->assertSame([], $this->importAndCaptureQueued());
	}

	public function testAnUnknownDropletTypeIsSkippedRatherThanGuessed(): void
	{
		$this->writeInto('droplets', 'odd.md', "---\ntype: carousel\ndata: something\n---\n");

		$this->assertSame([], $this->importAndCaptureQueued());
	}

	public function testCountsEveryKindTogether(): void
	{
		// import() returns one total across blogs, embeds and droplets, which
		// is the number the operator is shown at the end of a migration.
		$this->writePost('2021-03-01_post.md', 'title: A post');
		$this->writeInto('embeds', 'embed.md', "---\n---\nText");
		$this->writeInto('droplets', 'droplet.md', "---\ntype: text\ndata: v\n---\n");

		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;
		$count                    = $this->alloyImporter->import([
			'blog' => 'blog', 'image_uploads' => 'uploads', 'embeds' => 'embeds', 'droplets' => 'droplets',
		]);

		$this->assertSame(3, $count);
	}

	public function testAnalyzeDescribesEmbedsAndDropletsToo(): void
	{
		$this->writeInto('embeds', 'embed-one.md', "---\n---\nText");
		$this->writeInto('droplets', 'droplet-one.md', "---\ntype: image\ndata: /uploads/x.png\n---\n");
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$result = $this->alloyImporter->analyze([
			'blog' => 'blog', 'image_uploads' => 'uploads', 'embeds' => 'embeds', 'droplets' => 'droplets',
		]);

		$this->assertCount(1, $result['embeds']);
		$this->assertSame('embed-one', $result['embeds'][0]['id']);
		$this->assertCount(1, $result['droplets']);
		$this->assertSame('image', $result['droplets'][0]['type']);
	}
}
