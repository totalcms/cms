<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
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
		$config         = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config         = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config         = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config         = $this->createMock(\TotalCMS\Support\Config::class);
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
		$adapter = $this->createPartialMock(\TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter::class, []);

		// Inject config with api
		$reflection  = new \ReflectionClass(\TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '';
		$configProp->setValue($adapter, $config);

		$result = $adapter->login();
		expect($result)->toBe('/admin/login');

		$result = $adapter->login('admin');
		expect($result)->toBe('/admin/login/admin');
	}

	public function testJobQueuePendingInfoReturnsEmptyStringForNoPendingJobs(): void
	{
		$config = new \TotalCMS\Support\Config([
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
		$jobRepository = new \TotalCMS\Domain\JobQueue\Repository\JobRepository($config);
		$jobManager    = new \TotalCMS\Domain\JobQueue\Service\JobManager($jobRepository);
		$jobManager->clearQueue();

		$adapter = $this->createPartialMock(AdminTwigAdapter::class, []);

		$reflection = new \ReflectionClass(AdminTwigAdapter::class);
		$property   = $reflection->getProperty('jobManager');
		$property->setValue($adapter, $jobManager);

		$result = $adapter->jobQueuePendingInfo();

		expect($result)->toBe('');
	}

	public function testJobQueueFailedInfoReturnsEmptyStringForNoFailedJobs(): void
	{
		$config = new \TotalCMS\Support\Config([
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
		$jobRepository = new \TotalCMS\Domain\JobQueue\Repository\JobRepository($config);
		$jobManager    = new \TotalCMS\Domain\JobQueue\Service\JobManager($jobRepository);
		$jobManager->clearQueue();

		$adapter = $this->createPartialMock(AdminTwigAdapter::class, []);

		$reflection = new \ReflectionClass(AdminTwigAdapter::class);
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
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
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
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->env = 'prod';

		$adapter = new AdminTwigAdapter(
			$config,
			$this->createMock(\TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionLister::class),
			$this->createMock(\TotalCMS\Domain\Schema\Service\SchemaLister::class),
			$this->createMock(\TotalCMS\Domain\Template\Service\TemplateLister::class),
			$this->createMock(\TotalCMS\Domain\JobQueue\Service\JobManager::class),
			$this->createMock(\TotalCMS\Domain\Cache\Service\DevModeManager::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionEditionService::class),
			$this->createMock(\TotalCMS\Domain\Cache\CacheReporter::class),
			$this->createMock(\TotalCMS\Domain\License\Service\LicenseStatus::class),
			$this->createMock(\TotalCMS\Domain\Index\Service\IndexReader::class),
			$this->createMock(\TotalCMS\Infrastructure\Diagnostics\ServerChecker::class),
			$this->createMock(\TotalCMS\Infrastructure\Diagnostics\LogAnalyzer::class),
			$this->createMock(\TotalCMS\Domain\ImageWorks\Service\ImageCacheService::class),
			$this->createMock(\TotalCMS\Domain\Cache\CacheSizingAdvisor::class),
			$this->createMock(\TotalCMS\Domain\Update\Service\UpdateChecker::class),
			$this->createMock(\TotalCMS\Domain\Builder\Service\BuilderConfigService::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class),
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
		$mockObject = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'    => 'test-id',
			'depot' => [['name' => 'file.pdf']],
		]);

		$objectFetcher = $this->createMock(\TotalCMS\Domain\Object\Service\ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->willReturn($mockObject);

		$loggerFactory = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '';

		$adapter = new MediaTwigAdapter($objectFetcher, $config, $loggerFactory);

		$result = $adapter->depot('test-id');
		expect($result)->toBe([['name' => 'file.pdf']]);
	}

	public function testPaginationMethods(): void
	{
		$loggerFactory = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		$adapter = new RenderTwigAdapter(
			$this->createMock(\TotalCMS\Domain\Twig\Service\HtmxRenderer::class),
			$this->createMock(\TotalCMS\Support\Config::class),
			$this->createMock(DataTwigAdapter::class),
			$this->createMock(MediaTwigAdapter::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionLister::class),
			$this->createMock(\TotalCMS\Domain\Schema\Service\SchemaFetcher::class),
			$this->createMock(\TotalCMS\Domain\Twig\Service\GridRenderer::class),
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
