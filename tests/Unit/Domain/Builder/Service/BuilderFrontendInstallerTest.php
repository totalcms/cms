<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Data\FrontendInstallOptions;
use TotalCMS\Domain\Builder\Service\BuilderFrontendBuild;
use TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/** Answers shell commands from a script, so no test runs npm. */
final class ScriptedFrontendBuild extends BuilderFrontendBuild
{
	/** @var list<string> */
	public array $commands = [];

	/** @param array<string,array{int,list<string>}> $answers */
	public function __construct(CacheManager $cache, private array $answers = [])
	{
		parent::__construct($cache);
	}

	/** @return array{int,list<string>} */
	protected function execute(string $command, string $directory): array
	{
		$this->commands[] = $command;

		return $this->answers[$command] ?? [0, ['v24.0.0']];
	}
}

final class BuilderFrontendInstallerTest extends TestCase
{
	private string $tmpRoot;
	private string $project;
	private ?string $originalPackageRoot;
	private ?string $originalProjectRoot;
	private BuilderFrontendInstaller $installer;
	private ScriptedFrontendBuild $build;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-frontend-test-' . uniqid();
		$this->project = $this->tmpRoot . '/project';
		mkdir($this->project . '/public', 0755, true);
		file_put_contents($this->project . '/composer.json', "{}\n");
		file_put_contents($this->project . '/public/index.php', "<?php\n");

		// The installer reads the scaffold from the real package, so these
		// tests fail if the shipped scaffold stops doing what they describe.
		$pkgProp                   = new \ReflectionProperty(PathResolver::class, 'packageRoot');
		$projProp                  = new \ReflectionProperty(PathResolver::class, 'projectRoot');
		$this->originalPackageRoot = $pkgProp->getValue();
		$this->originalProjectRoot = $projProp->getValue();
		$pkgProp->setValue(null, dirname(__DIR__, 5));
		$projProp->setValue(null, $this->project);

		$this->installer = $this->installerWith(new ScriptedFrontendBuild($this->createStub(CacheManager::class)));
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(PathResolver::class, 'packageRoot'))
			->setValue(null, $this->originalPackageRoot);
		(new \ReflectionProperty(PathResolver::class, 'projectRoot'))
			->setValue(null, $this->originalProjectRoot);

		$this->rrmdir($this->tmpRoot);
	}

	public function testInstallsEverythingAtTheProjectRoot(): void
	{
		$result = $this->install();

		$this->assertTrue($result->success, $result->message);
		foreach (['package.json', 'vite.config.js', 'css/style.css', 'js/app.js', 'FRONTEND.md'] as $file) {
			$this->assertFileExists($this->project . '/' . $file);
		}
		$this->assertDirectoryDoesNotExist($this->project . '/frontend');
		$this->assertDirectoryDoesNotExist($this->project . '/fonts');
	}

	public function testScaffoldInstallsOnlyVite(): void
	{
		$this->install();

		$package = $this->package();
		$this->assertSame(['vite'], array_keys($package['devDependencies']));
		$this->assertArrayNotHasKey('dependencies', $package);
	}

	public function testScaffoldKeepsTheThreeSettingsThatPreventKnownFailures(): void
	{
		$this->install();

		$config = (string)file_get_contents($this->project . '/vite.config.js');
		$this->assertStringContainsString('publicDir: false', $config);
		$this->assertStringContainsString("base: './'", $config);
		$this->assertStringContainsString("cssTarget: 'esnext'", $config);
	}

	public function testRefusesAProjectWithAPackageJsonAndChangesNothing(): void
	{
		file_put_contents($this->project . '/package.json', '{"name":"mine"}');
		$before = $this->snapshot();

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('already has a package.json', $result->message);
		$this->assertSame($before, $this->snapshot());
		$this->assertSame([], $this->build->commands);
	}

	public function testSecondRunStopsAndChangesNothing(): void
	{
		$this->writeLayout("<head>\n\t{% block head %}{% endblock %}\n</head>\n");
		file_put_contents($this->project . '/AGENTS.md', "# Site\n\n## Project layout\n");
		$this->install();
		$before = $this->snapshot();

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertSame($before, $this->snapshot());
	}

	public function testRefusesFilesInPublicAssetsAndChangesNothing(): void
	{
		mkdir($this->project . '/public/assets/fonts', 0755, true);
		file_put_contents($this->project . '/public/assets/theme.css', 'body {}');
		file_put_contents($this->project . '/public/assets/fonts/a.woff2', 'x');
		$before = $this->snapshot();

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('public/assets/theme.css', $result->message);
		$this->assertSame(['public/assets/fonts/a.woff2', 'public/assets/theme.css'], $result->data['blocked']);
		$this->assertSame($before, $this->snapshot());
	}

	/** @dataProvider manifestLocations */
	public function testBuildOutputInPublicAssetsIsNotARefusal(string $manifest): void
	{
		mkdir(dirname($this->project . '/public/assets/' . $manifest), 0755, true);
		file_put_contents($this->project . '/public/assets/' . $manifest, '{}');
		file_put_contents($this->project . '/public/assets/style-abc.css', 'body {}');

		$this->assertTrue($this->install()->success);
	}

	/** @return array<string,array{string}> */
	public static function manifestLocations(): array
	{
		return ['root' => ['manifest.json'], 'vite' => ['.vite/manifest.json']];
	}

	public function testDotFilesAloneDoNotBlock(): void
	{
		mkdir($this->project . '/public/assets', 0755, true);
		file_put_contents($this->project . '/public/assets/.DS_Store', 'x');

		$this->assertTrue($this->install()->success);
	}

	public function testRefusesAnOlderFrontendProject(): void
	{
		mkdir($this->project . '/frontend');
		file_put_contents($this->project . '/frontend/vite.config.js', 'x');
		$before = $this->snapshot();

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Moving from frontend/', $result->message);
		$this->assertSame($before, $this->snapshot());
	}

	public function testRefusesASubpathInstall(): void
	{
		unlink($this->project . '/public/index.php');
		$before = $this->snapshot();

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('subpath', $result->message);
		$this->assertSame($before, $this->snapshot());
	}

	public function testRefusesAProjectWithoutComposerJson(): void
	{
		unlink($this->project . '/composer.json');

		$this->assertFalse($this->install()->success);
		$this->assertFileDoesNotExist($this->project . '/package.json');
	}

	public function testFailsWhenTheScaffoldIsMissing(): void
	{
		(new \ReflectionProperty(PathResolver::class, 'packageRoot'))->setValue(null, $this->tmpRoot);

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('scaffold missing', $result->message);
	}

	public function testKeepsExistingFilesAndWarnsAboutAConfigMissingSettings(): void
	{
		mkdir($this->project . '/css');
		file_put_contents($this->project . '/css/style.css', '/* mine */');
		file_put_contents($this->project . '/vite.config.js', 'export default {}');

		$result = $this->install();

		$this->assertSame('/* mine */', file_get_contents($this->project . '/css/style.css'));
		$this->assertSame('export default {}', file_get_contents($this->project . '/vite.config.js'));
		$this->assertContains('css/style.css', $result->data['skipped']);
		$notes = implode(' ', $result->data['notes']);
		$this->assertStringContainsString('publicDir', $notes);
		$this->assertStringContainsString('manifest', $notes);
	}

	public function testAddsBothGitignoreEntriesOnce(): void
	{
		file_put_contents($this->project . '/.gitignore', "/vendor/\n/node_modules/\n");

		$result = $this->install();

		$lines = file($this->project . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];
		$this->assertSame(['/node_modules/' => 'present', '/public/assets/' => 'added'], $result->data['gitignore']);
		$this->assertContains('/vendor/', $lines);
		$this->assertCount(1, array_keys($lines, '/public/assets/', true));
		$this->assertCount(1, array_keys($lines, '/node_modules/', true));
	}

	public function testCreatesGitignoreWhenMissing(): void
	{
		$this->install();

		$contents = (string)file_get_contents($this->project . '/.gitignore');
		$this->assertStringStartsWith('# Node packages', $contents);
		$this->assertStringContainsString("\n/public/assets/\n", $contents);
	}

	public function testAddsTheStylesheetToTheDefaultLayout(): void
	{
		$this->writeLayout("<head>\n\t{% block head %}{% endblock %}\n</head>\n");

		$result = $this->install();

		$this->assertSame('added', $result->data['layout']);
		$this->assertStringContainsString(
			"\t{{ cms.builder.css('css/style.css') }}\n\t{% block head %}",
			(string)file_get_contents($this->project . '/builder/layouts/default.twig'),
		);
	}

	public function testUsesTheLayoutInTheDatadirWhenTemplatesAreNotGitManaged(): void
	{
		$layout = $this->tmpRoot . '/project/tcms-data/builder/layouts/default.twig';
		mkdir(dirname($layout), 0755, true);
		file_put_contents($layout, "<head>\n</head>\n");

		$result = $this->install();

		$this->assertSame('added', $result->data['layout']);
		$this->assertContains('tcms-data/builder/layouts/default.twig', $result->data['written']);
		$this->assertStringContainsString("cms.builder.css('css/style.css')", (string)file_get_contents($layout));
		$this->assertDirectoryDoesNotExist($this->project . '/builder', 'writing must not flip the project to git-managed');
	}

	public function testLeavesALayoutThatLoadsAStylesheet(): void
	{
		$this->writeLayout("<head>\n<link rel=\"stylesheet\" href=\"/x.css\">\n</head>\n");

		$result = $this->install();

		$this->assertSame('kept', $result->data['layout']);
		$this->assertStringContainsString('left alone', implode(' ', $result->data['notes']));
		$this->assertStringNotContainsString('cms.builder', (string)file_get_contents($this->project . '/builder/layouts/default.twig'));
	}

	public function testExplainsWhenThereIsNoLayout(): void
	{
		$result = $this->install();

		$this->assertSame('missing', $result->data['layout']);
		$this->assertStringContainsString('Add {{ cms.builder.css', implode(' ', $result->data['notes']));
	}

	public function testKeepsOneMarkedBlockInAgentsMd(): void
	{
		file_put_contents($this->project . '/AGENTS.md', "# Site\n\n## Project layout\n\nstuff\n");
		$this->install();

		$first = (string)file_get_contents($this->project . '/AGENTS.md');
		$this->assertStringContainsString('<!-- tcms:frontend -->', $first);
		$this->assertLessThan(strpos($first, '## Project layout'), strpos($first, '<!-- tcms:frontend -->'));

		// An edited block is rewritten, not added to.
		file_put_contents($this->project . '/AGENTS.md', str_replace('Edit `css/`', 'Changed', $first));
		unlink($this->project . '/package.json');
		$this->install();

		$this->assertSame($first, file_get_contents($this->project . '/AGENTS.md'));
	}

	public function testDryRunListsTheFilesAndWritesNothing(): void
	{
		file_put_contents($this->project . '/AGENTS.md', "# Site\n");
		$before = $this->snapshot();

		$result = $this->install(dryRun: true);

		$this->assertTrue($result->success);
		$this->assertStringContainsString('package.json', $result->message);
		$this->assertContains('AGENTS.md', $result->data['written']);
		$this->assertSame($before, $this->snapshot());
		$this->assertSame([], $this->build->commands);
	}

	public function testNoBuildRunsNoCommands(): void
	{
		$result = $this->install(build: false);

		$this->assertTrue($result->success);
		$this->assertFalse($result->data['built']);
		$this->assertSame([], $this->build->commands);
		$this->assertStringContainsString('npm run build', $result->message);
	}

	public function testBuildsAndClearsTheCache(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->once())->method('clearAllCaches');
		$this->installer = $this->installerWith(new ScriptedFrontendBuild($cache));

		$result = $this->install();

		$this->assertTrue($result->data['built']);
		$this->assertSame(['node -v', 'npm install --no-audit --no-fund --silent', 'npm run build --silent'], $this->build->commands);
	}

	public function testTellsTheCallerEachStep(): void
	{
		$steps = [];
		$this->installer->install(new FrontendInstallOptions(onStep: static function (string $command) use (&$steps): void {
			$steps[] = $command;
		}));

		$this->assertCount(2, $steps);
	}

	public function testNoNodeStillInstallsAndExplains(): void
	{
		$this->installer = $this->installerWith(new ScriptedFrontendBuild(
			$this->createStub(CacheManager::class),
			['node -v' => [127, ['sh: node: command not found']]],
		));

		$result = $this->install();

		$this->assertTrue($result->success);
		$this->assertFalse($result->data['built']);
		$this->assertFileExists($this->project . '/package.json');
		$this->assertStringContainsString("Node isn't installed", implode(' ', $result->data['notes']));
		$this->assertSame(['node -v'], $this->build->commands);
	}

	/** @dataProvider rejectedNodeVersions */
	public function testUnsupportedNodeInstallsButDoesNotBuild(string $version): void
	{
		$this->installer = $this->installerWith(new ScriptedFrontendBuild(
			$this->createStub(CacheManager::class),
			['node -v' => [0, [$version]]],
		));

		$result = $this->install();

		$this->assertTrue($result->success);
		$this->assertStringContainsString('Nothing was built', implode(' ', $result->data['notes']));
		$this->assertSame(['node -v'], $this->build->commands);
	}

	/** @return array<string,array{string}> */
	public static function rejectedNodeVersions(): array
	{
		return ['18' => ['v18.20.0'], '20.18' => ['v20.18.3'], '21' => ['v21.7.0'], '22.11' => ['v22.11.0']];
	}

	/** @dataProvider nodeVersions */
	public function testSupportedNodeVersions(string $version, bool $supported): void
	{
		$this->assertSame($supported, BuilderFrontendBuild::isSupported($version));
	}

	/** @return array<string,array{string,bool}> */
	public static function nodeVersions(): array
	{
		return [
			'20.19' => ['v20.19.0', true],
			'20.18' => ['v20.18.9', false],
			'21'    => ['v21.7.0', false],
			'22.12' => ['v22.12.0', true],
			'22.11' => ['v22.11.0', false],
			'24'    => ['v24.1.0', true],
			'junk'  => ['nope', false],
		];
	}

	public function testAFailedBuildIsAnErrorAndLeavesTheFiles(): void
	{
		$this->installer = $this->installerWith(new ScriptedFrontendBuild(
			$this->createStub(CacheManager::class),
			['npm run build --silent' => [1, ['Could not resolve nope']]],
		));

		$result = $this->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('failed', $result->message);
		$this->assertStringContainsString('Could not resolve nope', $result->message);
		$this->assertFileExists($this->project . '/package.json');
	}

	private function install(bool $dryRun = false, bool $build = true): \TotalCMS\Support\OperationResult
	{
		return $this->installer->install(new FrontendInstallOptions($dryRun, $build));
	}

	private function installerWith(ScriptedFrontendBuild $build): BuilderFrontendInstaller
	{
		$this->build = $build;

		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'root'))->setValue($config, $this->project);
		(new \ReflectionProperty($config, 'datadir'))->setValue($config, $this->project . '/tcms-data');

		return new BuilderFrontendInstaller(new BuilderTemplatePaths($config), $build);
	}

	private function writeLayout(string $contents): void
	{
		mkdir($this->project . '/builder/layouts', 0755, true);
		file_put_contents($this->project . '/builder/layouts/default.twig', $contents);
	}

	/** @return array<string,mixed> */
	private function package(): array
	{
		return json_decode((string)file_get_contents($this->project . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return array<string,string> Every file in the project, with its contents hashed */
	private function snapshot(): array
	{
		$files = [];
		$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS));
		foreach ($items as $item) {
			if ($item->isFile()) {
				$files[$item->getPathname()] = (string)hash_file('sha256', $item->getPathname());
			}
		}
		ksort($files);

		return $files;
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
