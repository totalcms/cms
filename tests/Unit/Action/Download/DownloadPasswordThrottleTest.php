<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Action\Download\DownloadAction;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\AuthThrottle;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\LoginUrl;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyFile;
use TotalCMS\Domain\Property\Service\PropertyFileKind;
use TotalCMS\Domain\Property\Service\PropertyFileResolver;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\FileStreamRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

// Download passwords were counted in the session, so a script that dropped
// its cookie could guess without limit — and the counter took every download,
// right or wrong, and never reset, so a real visitor was refused after 25
// downloads in one session. Wrong passwords now count per IP on the server.

beforeEach(function (): void {
	$this->config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$this->config->auth = [];

	$fetcher = $this->createMock(FileFetcher::class);
	$fetcher->method('fileExists')->willReturn(true);

	$this->file = new PropertyFile(
		$fetcher,
		$this->createMock(DepotFileFetcher::class),
		$this->createMock(PropertyFetcher::class),
		$this->createMock(ObjectUpdater::class),
		new ActorContext(),
		PropertyFileKind::File,
		'docs',
		'brochure',
		'file',
	);

	$this->access = $this->createMock(FileAccessManager::class);
	$this->access->method('isProtectedByGroups')->willReturn(false);
	$this->access->method('isPasswordProtected')->willReturn(true);

	$this->twig = $this->createMock(TwigRenderer::class);
	$this->twig->method('template')->willReturnArgument(0);

	$this->translator = $this->createMock(TranslationService::class);
	$this->translator->method('trans')->willReturnArgument(0);

	$this->throttle = testAuthThrottle(['downloadMaxAttempts' => 3, 'deniedTimeout' => 7]);
});

/** One password POST for the protected file, with a fresh session, from $ip. */
function guessDownloadPassword(object $test, string $ip = '203.0.113.5'): ResponseInterface
{
	$action = new class($test->file, $test->twig, $test->access, $test->config, $test->translator, $test->throttle) extends DownloadAction {
		public function __construct(
			private PropertyFile $file,
			TwigRenderer $twig,
			FileAccessManager $access,
			Config $config,
			TranslationService $translator,
			AuthThrottle $throttle,
		) {
			parent::__construct(
				(new ReflectionClass(PropertyFileResolver::class))->newInstanceWithoutConstructor(),
				$twig,
				$access,
				new PhpSession(),
				$config,
				$translator,
				new FileStreamRenderer(new PhpSession()),
				new EventDispatcher(new NullLogger()),
				(new ReflectionClass(LoginUrl::class))->newInstanceWithoutConstructor(),
				$throttle,
			);
		}

		protected function resolve(array $args, array $query): PropertyFile
		{
			return $this->file;
		}
	};

	$request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/download/docs/brochure/file', ['REMOTE_ADDR' => $ip])
		->withParsedBody(['password' => 'guess']);

	return $action($request, (new ResponseFactory())->createResponse(), []);
}

test('a client without cookies stops reaching the password check at the limit', function (): void {
	$this->access->expects($this->exactly(3))->method('verfiyPassword')->willReturn(false);

	$statuses = [];
	for ($i = 0; $i < 5; $i++) {
		$statuses[] = guessDownloadPassword($this)->getStatusCode();
	}

	expect($statuses)->toBe([200, 200, 200, 403, 403]);
});

test('another client is still checked while one is locked out', function (): void {
	$this->access->expects($this->exactly(4))->method('verfiyPassword')->willReturn(false);

	for ($i = 0; $i < 4; $i++) {
		guessDownloadPassword($this);
	}
	guessDownloadPassword($this, '198.51.100.9');
});
