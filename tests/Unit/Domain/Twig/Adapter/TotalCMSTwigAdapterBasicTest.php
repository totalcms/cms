<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

final class TotalCMSTwigAdapterBasicTest extends TestCase
{
	public function testLanguagesReturnsCorrectArray(): void
	{
		// Test the static languages method without dependencies
		$reflection = new \ReflectionClass(TotalCMSTwigAdapter::class);
		$method     = $reflection->getMethod('languages');

		// Create a minimal instance for method testing
		$languages = $method->invoke($this->createPartialMock(TotalCMSTwigAdapter::class, []));

		expect($languages)->toBeArray();
		expect($languages)->toHaveKey('English');
		expect($languages['English'])->toBe('en_US');
		expect($languages)->toHaveKey('Spanish');
		expect($languages['Spanish'])->toBe('es_ES');
		expect($languages)->toHaveKey('French');
		expect($languages['French'])->toBe('fr_FR');
		expect($languages)->toHaveKey('German');
		expect($languages['German'])->toBe('de_DE');
		expect(count($languages))->toBeGreaterThan(20);
		expect(count($languages))->toBeLessThan(50);
	}

	public function testPrettyUrlHandlesBasicPath(): void
	{
		$adapter         = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->domain = 'example.com';

		$result = $adapter->prettyUrl('/blog/post');

		expect($result)->toBe('/blog/post/');
	}

	public function testPrettyUrlWithDomain(): void
	{
		$adapter         = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->domain = 'example.com';

		$result = $adapter->prettyUrl('/blog/post', true);

		expect($result)->toBe('https://example.com/blog/post/');
	}

	public function testPrettyUrlHandlesPhpExtension(): void
	{
		$adapter         = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->domain = 'example.com';

		$result = $adapter->prettyUrl('/blog/post.php');

		expect($result)->toBe('/blog/');
	}

	public function testPrettyUrlHandlesFullUrl(): void
	{
		$adapter         = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->domain = 'example.com';

		$result = $adapter->prettyUrl('https://example.com/blog/post');

		expect($result)->toBe('https://example.com/blog/post/');
	}

	public function testApacheRuleGeneratesCorrectRewriteRules(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$result = $adapter->apacheRule('https://example.com/blog/post.php', 'Blog');

		expect($result)->toContain('# Total CMS Pretty URL Rewrites for Blog');
		expect($result)->toContain('RewriteEngine On');
		expect($result)->toContain('RewriteRule');
		expect($result)->toContain('/blog/post.php');
		expect($result)->toContain('blog');
	}

	public function testNginxRuleGeneratesCorrectRewriteRules(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$result = $adapter->nginxRule('https://example.com/blog/post.php', 'Blog');

		expect($result)->toContain('# Total CMS Pretty URL Rewrites for Blog');
		expect($result)->toContain('rewrite');
		expect($result)->toContain('/blog/post.php');
		expect($result)->toContain('blog');
	}

	public function testLoginUrlGeneration(): void
	{
		$adapter      = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->api = '/api';

		$result = $adapter->login();
		expect($result)->toBe('/api/login');

		$result = $adapter->login('admin');
		expect($result)->toBe('/api/login/admin');
	}

	public function testJobQueuePendingInfoReturnsEmptyStringForNoPendingJobs(): void
	{
		// Clear any existing jobs from previous tests
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

		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// Use reflection to inject the jobManager into the adapter
		$reflection = new \ReflectionClass(TotalCMSTwigAdapter::class);
		$property   = $reflection->getProperty('jobManager');
		$property->setValue($adapter, $jobManager);

		$result = $adapter->jobQueuePendingInfo();

		expect($result)->toBe('');
	}

	public function testJobQueueFailedInfoReturnsEmptyStringForNoFailedJobs(): void
	{
		// Clear any existing jobs from previous tests
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

		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// Use reflection to inject the jobManager into the adapter
		$reflection = new \ReflectionClass(TotalCMSTwigAdapter::class);
		$property   = $reflection->getProperty('jobManager');
		$property->setValue($adapter, $jobManager);

		$result = $adapter->jobQueueFailedInfo();

		expect($result)->toBe('');
	}

	public function testDownloadUrlGeneration(): void
	{
		$adapter      = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->api = '/api';

		// Test basic download URL
		$result = $adapter->download('test-id');
		expect($result)->toBe('/api/download/file/test-id/file');

		// Test download URL with custom options
		$result = $adapter->download('test-id', [
			'collection' => 'documents',
			'property'   => 'attachment',
		]);
		expect($result)->toBe('/api/download/documents/test-id/attachment');

		// Test download URL with password (should be encrypted)
		$result = $adapter->download('test-id', ['pwd' => 'secret123']);
		expect($result)->toContain('/api/download/file/test-id/file?pwd=');
		expect($result)->not->toContain('secret123'); // Should be encrypted
	}

	public function testStreamUrlGeneration(): void
	{
		$adapter      = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->api = '/api';

		// Test basic stream URL
		$result = $adapter->stream('test-id');
		expect($result)->toBe('/api/stream/file/test-id/file');

		// Test stream URL with custom options
		$result = $adapter->stream('test-id', [
			'collection' => 'videos',
			'property'   => 'video',
		]);
		expect($result)->toBe('/api/stream/videos/test-id/video');
	}

	public function testDepotDownloadUrlGeneration(): void
	{
		$adapter      = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->api = '/api';

		// Test basic depot download
		$result = $adapter->depotDownload('depot-id', 'file.pdf');
		expect($result)->toBe('/api/download/depot/depot-id/depot/file.pdf');

		// Test depot download with path in filename
		$result = $adapter->depotDownload('depot-id', 'subfolder/file.pdf');
		expect($result)->toContain('/api/download/depot/depot-id/depot/file.pdf');
		expect($result)->toContain('path=subfolder');
	}

	public function testDepotStreamUrlGeneration(): void
	{
		$adapter      = $this->createPartialMock(TotalCMSTwigAdapter::class, []);
		$adapter->api = '/api';

		// Test basic depot stream
		$result = $adapter->depotStream('depot-id', 'file.mp4');
		expect($result)->toBe('/api/stream/depot/depot-id/depot/file.mp4');

		// Test depot stream with path in filename
		$result = $adapter->depotStream('depot-id', 'videos/file.mp4');
		expect($result)->toContain('/api/stream/depot/depot-id/depot/file.mp4');
		expect($result)->toContain('path=videos');
	}

	public function testRedirectIfNotFoundDoesNothingForNonEmptyObject(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// This should not trigger any redirect (no exception expected)
		$adapter->redirectIfNotFound(['id' => '123', 'title' => 'Test']);

		// If we reach this point, no redirect occurred
		expect(true)->toBeTrue();
	}

	public function testProcessJobQueueCommandGeneratesCorrectCommand(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// Mock $_SERVER for test
		$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';

		$command = $adapter->processJobQueueCommand();

		expect($command)->toContain('processJobs.php');
		expect($command)->toContain('--docroot=');
		expect($command)->toBeString();

		// Clean up
		unset($_SERVER['DOCUMENT_ROOT']);
	}

	public function testSimpleDataAccessors(): void
	{
		// Test methods that use default options patterns
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		// Mock the data method to return test values
		$adapter->method('data')
			->willReturnMap([
				['text', 'test-id', 'text', 'Sample text'],
				['number', 'test-id', 'number', '42'],
				['url', 'test-id', 'url', 'https://example.com'],
				['styledtext', 'test-id', 'styledtext', '<p>Rich text</p>'],
				['depot', 'test-id', 'depot', [['name' => 'file.pdf']]],
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

		// Test depot accessor
		$result = $adapter->depot('test-id');
		expect($result)->toBe([['name' => 'file.pdf']]);

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

	public function testPaginationMethods(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

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
