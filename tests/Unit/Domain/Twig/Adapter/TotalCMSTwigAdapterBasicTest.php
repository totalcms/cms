<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\CacheSizingAdvisor;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\JobQueueRenderer;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Config;

final class TotalCMSTwigAdapterBasicTest extends TestCase
{
	public function testLanguagesReturnsCorrectArray(): void
	{
		$translator = $this->createMock(TranslationService::class);
		// Per CLAUDE.md: mock Config via reflection. This test calls languages()
		// which only reads the static method table; an empty Config is sufficient.
		$config     = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$adapter    = new LocaleTwigAdapter($translator, $config);
		$languages  = $adapter->languages();

		// `languages()` delegates to LocaleRegistry, which uses native-language
		// labels (Deutsch, Español, Français, العربية) and emits one entry per
		// registered code. Bare codes own the simple labels (`English` => `en`);
		// regional variants get country-suffixed labels (`English (US)` => `en_US`).
		expect($languages)->toBeArray();

		// Bare codes — simple labels, no parens.
		expect($languages)->toHaveKey('English');
		expect($languages['English'])->toBe('en');
		expect($languages)->toHaveKey('Deutsch');
		expect($languages['Deutsch'])->toBe('de');
		expect($languages)->toHaveKey('Español');
		expect($languages['Español'])->toBe('es');
		expect($languages)->toHaveKey('Français');
		expect($languages['Français'])->toBe('fr');

		// Regional variants — language + country code in parens.
		expect($languages)->toHaveKey('English (US)');
		expect($languages['English (US)'])->toBe('en_US');
		expect($languages)->toHaveKey('Deutsch (DE)');
		expect($languages['Deutsch (DE)'])->toBe('de_DE');
		expect($languages)->toHaveKey('Português (BR)');
		expect($languages['Português (BR)'])->toBe('pt_BR');

		expect(count($languages))->toBeGreaterThan(40);
		expect(count($languages))->toBeLessThan(80);
	}

	public function testPrettyUrlHandlesBasicPath(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		// Inject domain via config
		$reflection     = new \ReflectionClass(CollectionTwigAdapter::class);
		$configProp     = $reflection->getProperty('config');
		$config         = $this->createMock(Config::class);
		$config->domain = 'example.com';
		$configProp->setValue($adapter, $config);

		$result = $adapter->prettyUrl('/blog/post');

		expect($result)->toBe('/blog/post/');
	}

	public function testPrettyUrlWithDomain(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		$reflection     = new \ReflectionClass(CollectionTwigAdapter::class);
		$configProp     = $reflection->getProperty('config');
		$config         = $this->createMock(Config::class);
		$config->domain = 'example.com';
		$configProp->setValue($adapter, $config);

		$result = $adapter->prettyUrl('/blog/post', true);

		expect($result)->toBe('https://example.com/blog/post/');
	}

	public function testPrettyUrlHandlesPhpExtension(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		$reflection     = new \ReflectionClass(CollectionTwigAdapter::class);
		$configProp     = $reflection->getProperty('config');
		$config         = $this->createMock(Config::class);
		$config->domain = 'example.com';
		$configProp->setValue($adapter, $config);

		$result = $adapter->prettyUrl('/blog/post.php');

		expect($result)->toBe('/blog/');
	}

	public function testPrettyUrlHandlesFullUrl(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		$reflection     = new \ReflectionClass(CollectionTwigAdapter::class);
		$configProp     = $reflection->getProperty('config');
		$config         = $this->createMock(Config::class);
		$config->domain = 'example.com';
		$configProp->setValue($adapter, $config);

		$result = $adapter->prettyUrl('https://example.com/blog/post');

		expect($result)->toBe('https://example.com/blog/post/');
	}

	public function testApacheRuleGeneratesCorrectRewriteRules(): void
	{
		$adapter = $this->createPartialMock(AdminTwigAdapter::class, []);

		$result = $adapter->apacheRule('https://example.com/blog/post.php', 'Blog');

		expect($result)->toContain('# Total CMS Pretty URL Rewrites for Blog');
		expect($result)->toContain('RewriteEngine On');
		expect($result)->toContain('RewriteRule');
		expect($result)->toContain('/blog/post.php');
		expect($result)->toContain('blog');
		// Must match both /blog/my-post and /blog/my-post/ — a missed
		// trailing slash renders the post page without ?id= (mirrors nginxRule).
		expect($result)->toContain('([\w-]+)/?$');
	}

	public function testNginxRuleGeneratesCorrectRewriteRules(): void
	{
		$adapter = $this->createPartialMock(AdminTwigAdapter::class, []);

		$result = $adapter->nginxRule('https://example.com/blog/post.php', 'Blog');

		expect($result)->toContain('# Total CMS Pretty URL Rewrites for Blog');
		expect($result)->toContain('rewrite');
		expect($result)->toContain('/blog/post.php');
		expect($result)->toContain('blog');
	}

	public function testLoginUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(AuthTwigAdapter::class, []);

		// Inject config with api
		$reflection  = new \ReflectionClass(AuthTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		// login() falls back to $_SERVER['REQUEST_URI'] when no redirect is
		// given, so these assertions only hold when that global is unset.
		// Pin it rather than depending on whatever ran earlier in the same
		// process — under `pest --parallel` that differs per worker.
		$previousUri = $_SERVER['REQUEST_URI'] ?? null;
		unset($_SERVER['REQUEST_URI']);

		try {
			$result = $adapter->login();
			expect($result)->toBe('/admin/login');

			$result = $adapter->login('admin');
			expect($result)->toBe('/admin/login/admin');
		} finally {
			if ($previousUri !== null) {
				$_SERVER['REQUEST_URI'] = $previousUri;
			}
		}
	}

	public function testJobQueuePendingInfoReturnsEmptyStringForNoPendingJobs(): void
	{
		$config = new Config([
			'env'        => 'test',
			'template'   => sys_get_temp_dir(),
			'dashboard'  => [],
			'datadir'    => sys_get_temp_dir() . '/totalcms-test',
			'tmpdir'     => sys_get_temp_dir(),
			'cachedir'   => sys_get_temp_dir() . '/cache',
			'cache'      => [],
			'logger'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'imageworks' => [],
			'smtp'       => [],
			'mailer'     => [],
		]);
		$jobRepository = new JobRepository($config);
		$jobManager    = new JobManager($jobRepository);
		$jobManager->clearQueue();

		$adapter = $this->createPartialMock(JobQueueRenderer::class, []);

		$reflection = new \ReflectionClass(JobQueueRenderer::class);
		$property   = $reflection->getProperty('jobManager');
		$property->setValue($adapter, $jobManager);

		$result = $adapter->jobQueuePendingInfo();

		expect($result)->toBe('');
	}

	public function testJobQueueFailedInfoReturnsEmptyStringForNoFailedJobs(): void
	{
		$config = new Config([
			'env'        => 'test',
			'template'   => sys_get_temp_dir(),
			'dashboard'  => [],
			'datadir'    => sys_get_temp_dir() . '/totalcms-test',
			'tmpdir'     => sys_get_temp_dir(),
			'cachedir'   => sys_get_temp_dir() . '/cache',
			'cache'      => [],
			'logger'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'imageworks' => [],
			'smtp'       => [],
			'mailer'     => [],
		]);
		$jobRepository = new JobRepository($config);
		$jobManager    = new JobManager($jobRepository);
		$jobManager->clearQueue();

		$adapter = $this->createPartialMock(JobQueueRenderer::class, []);

		$reflection = new \ReflectionClass(JobQueueRenderer::class);
		$property   = $reflection->getProperty('jobManager');
		$property->setValue($adapter, $jobManager);

		$result = $adapter->jobQueueFailedInfo();

		expect($result)->toBe('');
	}

	public function testDownloadUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		// Inject config with api
		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		// Test basic download URL
		$result = $adapter->download('test-id');
		expect($result)->toBe('/download/file/test-id/file');

		// Test download URL with custom options
		$result = $adapter->download('test-id', [
			'collection' => 'documents',
			'property'   => 'attachment',
		]);
		expect($result)->toBe('/download/documents/test-id/attachment');

		// Test download URL with password (should be encrypted)
		$result = $adapter->download('test-id', ['pwd' => 'secret123']);
		expect($result)->toContain('/download/file/test-id/file?pwd=');
		expect($result)->not->toContain('secret123'); // Should be encrypted
	}

	public function testStreamUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		// Test basic stream URL
		$result = $adapter->stream('test-id');
		expect($result)->toBe('/stream/file/test-id/file');

		// Test stream URL with custom options
		$result = $adapter->stream('test-id', [
			'collection' => 'videos',
			'property'   => 'video',
		]);
		expect($result)->toBe('/stream/videos/test-id/video');
	}

	public function testDepotDownloadUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		// Test basic depot download
		$result = $adapter->depotDownload('depot-id', 'file.pdf');
		expect($result)->toBe('/download/depot/depot-id/depot/file.pdf');

		// Test depot download with path in filename
		$result = $adapter->depotDownload('depot-id', 'subfolder/file.pdf');
		expect($result)->toContain('/download/depot/depot-id/depot/file.pdf');
		expect($result)->toContain('path=subfolder');
	}

	public function testDepotStreamUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		// Test basic depot stream
		$result = $adapter->depotStream('depot-id', 'file.mp4');
		expect($result)->toBe('/stream/depot/depot-id/depot/file.mp4');

		// Test depot stream with path in filename
		$result = $adapter->depotStream('depot-id', 'videos/file.mp4');
		expect($result)->toContain('/stream/depot/depot-id/depot/file.mp4');
		expect($result)->toContain('path=videos');
	}

	public function testRedirectIfNotFoundDoesNothingForNonEmptyObject(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		// This should not trigger any redirect (no exception expected)
		$adapter->redirectIfNotFound(['id' => '123', 'title' => 'Test']);

		// If we reach this point, no redirect occurred
		expect(true)->toBeTrue();
	}

	public function testProcessJobQueueCommandGeneratesCorrectCommand(): void
	{
		$config      = $this->createMock(Config::class);
		$config->env = 'prod';

		$adapter = buildAdminTwigAdapter(
			$config,
			$this->createMock(AuthTwigAdapter::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaLister::class),
			$this->createMock(TemplateLister::class),
			$this->createMock(JobManager::class),
			$this->createMock(DevModeManager::class),
			$this->createMock(CollectionEditionService::class),
			$this->createMock(CacheReporter::class),
			$this->createMock(LicenseStatus::class),
			$this->createMock(IndexReader::class),
			$this->createMock(ServerChecker::class),
			$this->createMock(LogAnalyzer::class),
			$this->createMock(ImageCacheService::class),
			$this->createMock(CacheSizingAdvisor::class),
			$this->createMock(UpdateChecker::class),
			$this->createMock(BuilderConfigService::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(BuilderTemplatePaths::class),
			$this->createMock(JobQueueHealth::class),
			$this->createMock(TranslationService::class),
			$this->createMock(EditionFeatureService::class),
			(new \ReflectionClass(AutomationLoader::class))->newInstanceWithoutConstructor(),
			(new \ReflectionClass(AutomationRunReader::class))->newInstanceWithoutConstructor(),
			(new \ReflectionClass(ExtensionStateRepository::class))->newInstanceWithoutConstructor(),
			// final readonly, so it cannot be doubled — this adapter only needs it
			// for cronUrl(), which these tests do not exercise.
			(new \ReflectionClass(CronTokenProvider::class))->newInstanceWithoutConstructor(),
		);

		// Mock $_SERVER for test
		$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';

		$command = $adapter->processJobQueueCommand();

		expect($command)->toBeString();
		expect($command)->toContain('tcms');
		expect($command)->toContain('jobs:process');

		// Clean up
		unset($_SERVER['DOCUMENT_ROOT']);
	}

	public function testSimpleDataAccessors(): void
	{
		$adapter = $this->createPartialMock(DataTwigAdapter::class, ['raw']);

		// Mock the raw method to return test values
		$adapter->method('raw')
			->willReturnMap([
				['text', 'test-id', 'text', 'Sample text'],
				['number', 'test-id', 'number', '42'],
				['url', 'test-id', 'url', 'https://example.com'],
				['styledtext', 'test-id', 'styledtext', '<p>Rich text</p>'],
				['toggle', 'test-id', 'status', true],
				['date', 'test-id', 'date', '2024-01-15'],
				['color', 'test-id', 'color', ['hex' => '#ff0000']],
			]);

		// Test text accessor
		$result = $adapter->text('test-id');
		expect($result)->toBe('Sample text');

		// Test number accessor
		$result = $adapter->number('test-id');
		expect($result)->toBe('42');

		// Test URL accessor
		$result = $adapter->url('test-id');
		expect($result)->toBe('https://example.com');

		// Test styled text accessor
		$result = $adapter->styledtext('test-id');
		expect($result)->toBe('<p>Rich text</p>');

		// Test toggle accessor
		$result = $adapter->toggle('test-id');
		expect($result)->toBeTrue();

		// Test date accessor
		$result = $adapter->date('test-id');
		expect($result)->toBe('2024-01-15');

		// Test color accessor
		$result = $adapter->color('test-id');
		expect($result)->toBe(['hex' => '#ff0000']);

		// Test colour accessor (alias)
		$result = $adapter->colour('test-id');
		expect($result)->toBe(['hex' => '#ff0000']);
	}

	public function testDepotAccessor(): void
	{
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'    => 'test-id',
			'depot' => [['name' => 'file.pdf']],
		]);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->willReturn($mockObject);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$config      = $this->createMock(Config::class);
		$config->api = '';

		$adapter = new MediaTwigAdapter($objectFetcher, $config, $loggerFactory);

		$result = $adapter->depot('test-id');
		expect($result)->toBe([['name' => 'file.pdf']]);
	}

	public function testPaginationMethods(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$adapter = buildRenderTwigAdapter(
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

		// Test simple pagination
		$result = $adapter->paginationSimple(100, 2, 10);
		expect($result)->toBeString();
		expect($result)->toContain('Previous');
		expect($result)->toContain('Next');

		// Test full pagination
		$result = $adapter->paginationFull(100, 2, 10);
		expect($result)->toBeString();
		expect($result)->toContain('Previous');
		expect($result)->toContain('Next');
	}
}
