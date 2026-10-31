<?php

declare(strict_types=1);

use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Starter\Data\StarterExportOptions;
use TotalCMS\Domain\Starter\Service\StarterExporter;
use TotalCMS\Domain\Starter\Service\StarterProjectWriter;
use TotalCMS\Domain\Starter\Service\StarterSecretScrubber;
use TotalCMS\Support\PathResolver;

/*
 * tcms starter:export, end to end against a small project on disk: what is
 * copied, what is left out, what is scrubbed, and the final secret check.
 */

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-starter-' . uniqid();
	$this->src  = $this->root . '/site';
	$this->dest = $this->root . '/starter';
	$this->key  = str_repeat('k', 64);

	$files = [
		'composer.json'                                 => json_encode(['name' => 'totalcms/totalcms', 'description' => 'Skeleton', 'type' => 'project', 'require' => ['php' => '>=8.2', 'totalcms/cms' => '^3.5']]),
		'composer.lock'                                 => '{}',
		'README.md'                                     => "# Total CMS\n\nThis is the project skeleton for [Total CMS](https://totalcms.co).\n",
		'config/tcms.php'                               => "<?php return ['debug' => true, 'datadir' => '/somewhere'];",
		'builder/pages/index.twig'                      => '<h1>Home</h1>',
		'public/index.php'                              => '<?php',
		'public/assets/theme.css'                       => 'body{}',
		'vendor/autoload.php'                           => '<?php',
		'cache/x.cache'                                 => 'x',
		'logs/app.log'                                  => 'x',
		'frontend/node_modules/pkg/index.js'            => 'x',
		'.git/HEAD'                                     => 'ref',
		'.DS_Store'                                     => 'x',
		'tcms-data/.system/site.key'                    => $this->key,
		'tcms-data/.system/apikeys.json'                => json_encode(['abc' => ['key' => 'tcms_live_0123456789abcdef']]),
		'tcms-data/.system/setup-state.json'            => '{}',
		'tcms-data/.system/sessions/sess_1'             => 'x',
		'tcms-data/.system/settings.json.bak'           => '{}',
		'tcms-data/.system/migrations.json'             => '{}',
		'tcms-data/.system/access-groups.json'          => '{}',
		'tcms-data/.system/settings.json'               => json_encode([
			'siteName' => 'Juniper Table',
			'smtp'     => ['host' => 'smtp.example.com', 'password' => 'smtp-password-123456'],
			'sync'     => ['url' => 'https://prod.example.com', 'key' => 'sync-key-abcdefghijkl'],
		]),
		'tcms-data/auth/.meta.json'                     => json_encode(['id' => 'auth', 'schema' => 'auth', 'count' => 2, 'totalObjects' => 1]),
		'tcms-data/auth/.index.json'                    => json_encode(['objects' => [['id' => '1', 'email' => 'owner@example.com']]]),
		'tcms-data/auth/1.json'                         => json_encode(['id' => '1', 'password' => '$2y$hash']),
		'tcms-data/menu/.meta.json'                     => json_encode(['id' => 'menu', 'schema' => 'menu-item', 'count' => 2, 'totalObjects' => 2]),
		'tcms-data/menu/.index.json'                    => json_encode(['objects' => [['id' => 'burrata'], ['id' => 'cake']]]),
		'tcms-data/menu/burrata.json'                   => json_encode(['id' => 'burrata', 'image' => ['name' => 'photo.jpg', 'mime' => 'image/jpeg', 'credit' => [
			'author' => 'Nadin Sh', 'authorUrl' => 'https://www.pexels.com/@nadin-sh/', 'source' => 'Pexels', 'sourceUrl' => 'https://www.pexels.com/photo/25650514/', 'license' => 'Pexels License',
		]]]),
		'tcms-data/menu/cake.json'                      => json_encode(['id' => 'cake', 'gallery' => [['name' => 'cake.jpg', 'mime' => 'image/jpeg'], ['name' => 'slice.jpg', 'mime' => 'image/jpeg', 'credit' => ['source' => 'Unsplash']]]]),
		'tcms-data/menu/burrata/image/photo.jpg'        => 'jpg',
		'tcms-data/menu/burrata/image/.cache/small.jpg' => 'jpg',
		'tcms-data/builder-pages/.meta.json'            => json_encode(['id' => 'builder-pages', 'schema' => 'builder-page', 'count' => 1]),
		'tcms-data/builder-pages/.index.json'           => json_encode(['objects' => [['id' => 'home']]]),
		'tcms-data/builder-pages/.order.json'           => json_encode(['home']),
		'tcms-data/builder-pages/home.json'             => json_encode(['id' => 'home']),
		'tcms-data/auth/.meta.json.lock'                => '',
		'tcms-data/auth/.1.json.tmp.ab12cd34'           => json_encode(['id' => '1', 'password' => '$2y$hash']),
		'tcms-data/menu/.index.json.lock'               => '',
		'tcms-data/menu/.cake.json.tmp.ab12cd34'        => '{}',
	];

	foreach ($files as $path => $content) {
		$full = $this->src . '/' . $path;
		@mkdir(dirname($full), 0o755, true);
		file_put_contents($full, $content);
	}

	$this->exporter = new StarterExporter(
		new StarterSecretScrubber(new SettingsSchemaFetcher(), PathResolver::packageRoot() . '/resources/extensions'),
		new StarterProjectWriter(PathResolver::packageRoot() . '/resources/starter', '3.6.4'),
	);

	$this->export = fn (array $opts = []) => $this->exporter->export(
		new StarterExportOptions(...array_merge(['destination' => $this->dest, 'name' => 'totalcms/starter-restaurant'], $opts)),
		$this->src,
		$this->src . '/tcms-data',
	);

	$this->json = fn (string $path): array => json_decode((string)file_get_contents($this->dest . '/' . $path), true);
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

it('copies the site and leaves out what Composer and the runtime regenerate', function (): void {
	$result = ($this->export)();

	expect($result->passed())->toBeTrue()
		->and($this->dest . '/builder/pages/index.twig')->toBeFile()
		->and($this->dest . '/public/assets/theme.css')->toBeFile()
		->and($this->dest . '/tcms-data/menu/burrata/image/photo.jpg')->toBeFile();

	foreach (['vendor', 'cache', 'logs', 'composer.lock', '.git', '.DS_Store', 'frontend/node_modules', 'tcms-data/menu/burrata/image/.cache'] as $gone) {
		expect(file_exists($this->dest . '/' . $gone))->toBeFalse("{$gone} should not be copied");
	}
});

it('copies .system by allowlist, so credentials and setup state stay behind', function (): void {
	($this->export)();

	$system = $this->dest . '/tcms-data/.system';
	foreach (['site.key', 'apikeys.json', 'setup-state.json', 'sessions', 'settings.json.bak'] as $gone) {
		expect(file_exists("{$system}/{$gone}"))->toBeFalse("{$gone} should not be copied");
	}
	expect("{$system}/migrations.json")->toBeFile()
		->and("{$system}/access-groups.json")->toBeFile();
});

it('blanks sensitive settings and keeps the rest', function (): void {
	$result   = ($this->export)();
	$settings = ($this->json)('tcms-data/.system/settings.json');

	expect($settings['smtp']['password'])->toBe('')
		->and($settings['sync']['key'])->toBe('')
		->and($settings['smtp']['host'])->toBe('smtp.example.com')
		->and($settings['siteName'])->toBe('Juniper Table')
		->and($result->scrubbedSettings)->toContain('settings.json: smtp.password', 'settings.json: sync.key');
});

it('leaves lock files and leftover write temps behind', function (): void {
	// Saves leave a .lock beside .meta.json and .index.json in every
	// collection, and a killed write can leave a .tmp — neither belongs in a
	// starter, and the temp can hold a user record.
	($this->export)();

	expect(glob($this->dest . '/tcms-data/*/.*.lock'))->toBe([])
		->and(glob($this->dest . '/tcms-data/*/.*.tmp.*'))->toBe([])
		->and($this->dest . '/tcms-data/builder-pages/.order.json')->toBeFile();
});

it('empties the auth collection and resets its counters', function (): void {
	$result = ($this->export)();

	expect(file_exists($this->dest . '/tcms-data/auth/1.json'))->toBeFalse()
		->and(($this->json)('tcms-data/auth/.index.json'))->toBe(['objects' => []])
		->and(($this->json)('tcms-data/auth/.meta.json'))->toMatchArray(['count' => 0, 'totalObjects' => 0])
		->and($result->emptiedCollections)->toBe(['auth'])
		->and($this->dest . '/tcms-data/menu/cake.json')->toBeFile();
});

it('keeps only the Site Builder pages in a structure-only export', function (): void {
	$result = ($this->export)(['includeContent' => false]);

	expect($result->emptiedCollections)->toBe(['auth', 'menu'])
		->and(file_exists($this->dest . '/tcms-data/menu/burrata.json'))->toBeFalse()
		->and(file_exists($this->dest . '/tcms-data/menu/burrata'))->toBeFalse()
		->and($this->dest . '/tcms-data/builder-pages/home.json')->toBeFile()
		->and($this->dest . '/tcms-data/builder-pages/.order.json')->toBeFile();
});

it('summarizes the content a structure-only export would leave out', function (): void {
	expect($this->exporter->contentSummary($this->src . '/tcms-data'))->toBe(['menu' => 2]);
});

it('writes the starter project files', function (): void {
	$result   = ($this->export)(['description' => 'A restaurant site']);
	$composer = ($this->json)('composer.json');

	expect($composer['name'])->toBe('totalcms/starter-restaurant')
		->and($composer['description'])->toBe('A restaurant site')
		->and($composer['require']['totalcms/cms'])->toBe('^3.6')
		->and($composer['keywords'])->toContain('totalcms-starter')
		->and($composer['scripts']['post-create-project-cmd'])->toBe(['@php bin/post-install.php'])
		->and((string)file_get_contents($this->dest . '/config/tcms.php'))->not->toContain('debug')
		->and($result->configKeys)->toBe(['debug', 'datadir'])
		->and((string)file_get_contents($this->dest . '/bin/post-install.php'))->toContain('totalcms/starter-restaurant')
		->and((string)file_get_contents($this->dest . '/.gitignore'))->toContain('!/tcms-data/.system/settings.json')
		->and((string)file_get_contents($this->dest . '/README.md'))->toContain('composer create-project totalcms/starter-restaurant')
		->and($this->dest . '/CREDITS.md')->toBeFile();
});

it('writes CREDITS.md from the image credits, grouped by source, and names the images without one', function (): void {
	$result  = ($this->export)();
	$credits = (string)file_get_contents($this->dest . '/CREDITS.md');

	expect($credits)->toContain('## Pexels')
		->and($credits)->toContain('- [photo.jpg](https://www.pexels.com/photo/25650514/) by [Nadin Sh](https://www.pexels.com/@nadin-sh/), Pexels License')
		->and($credits)->toContain("## Unsplash\n\n- slice.jpg")
		->and(strpos($credits, '## Pexels'))->toBeLessThan(strpos($credits, '## Unsplash'))
		->and($result->creditedImages)->toBe(2)
		->and($result->uncreditedImages)->toBe(['menu/cake: cake.jpg']);
});

it('rewrites its own CREDITS.md on a re-export but keeps one the author wrote', function (): void {
	($this->export)();
	($this->export)(['force' => true]);
	expect((string)file_get_contents($this->dest . '/CREDITS.md'))->toContain('## Pexels');

	file_put_contents($this->src . '/CREDITS.md', "# Credits\n\nMy own list.\n");
	($this->export)(['force' => true]);
	expect((string)file_get_contents($this->dest . '/CREDITS.md'))->toBe("# Credits\n\nMy own list.\n");
});

it('keeps a README the author wrote', function (): void {
	file_put_contents($this->src . '/README.md', "# Juniper Table\n\nMy own words.\n");

	($this->export)();

	expect((string)file_get_contents($this->dest . '/README.md'))->toContain('My own words.');
});

it('fails when a known secret reaches the output', function (): void {
	file_put_contents($this->src . '/builder/pages/index.twig', '<h1>' . $this->key . '</h1>');

	$result = ($this->export)();

	expect($result->passed())->toBeFalse()
		->and($result->secretHits)->toBe(['builder/pages/index.twig']);
});

it('refuses a folder that is not empty unless forced', function (): void {
	@mkdir($this->dest, 0o755, true);
	file_put_contents($this->dest . '/old.txt', 'x');

	expect(fn () => ($this->export)())->toThrow(InvalidArgumentException::class);
	expect(($this->export)(['force' => true])->passed())->toBeTrue();
});

it('refuses to export into the project it copies', function (): void {
	expect(fn () => ($this->export)(['destination' => $this->src . '/starter']))->toThrow(InvalidArgumentException::class);
});
