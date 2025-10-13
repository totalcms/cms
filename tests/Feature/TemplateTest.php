<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\head;
use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		// tests with assertBadRequest do not seem to clean up the session
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

function templateTestData(): string
{
	return file_get_contents(testData('new-template.twig'));
}

it('saves a new template', function (): void {
	$template = templateTestData();
	$id       = 'new-template';
	$verify   = 'Test Template';

	postJson("/templates", ['id' => $id, 'template' => $template])
		->assertOk()
		->assertSee($verify);

	$this->assertFileExists(templatePath($id));
});

it('cannot save a built-in template', function (): void {
	$id = 'admin-layout';

	postJson("/templates", ['id' => $id, 'template' => 'dummy data'])->assertBadRequest();

	$this->assertFileDoesNotExist(templatePath($id));
});

it('fetches a template', function (): void {
	$id     = 'new-template';
	$verify = 'Test Template';

	get("/templates/{$id}")
		->assertOk()
		->assertSee($verify);
});

it('fetches a built-in template', function (): void {
	$files = glob(reservedTemplatePath() . '*.twig');

	foreach ($files as $file) {
		$id     = basename($file, '.twig');
		$verify = file_get_contents($file);
		get("/templates/{$id}")
			->assertOk()
			->assertSee($verify);
	}
});

it('checks if a template exists', function (): void {
	head('/templates/new-template')->assertOk();
	head('/templates/does-not-exist')->assertNotFound();
});

it('fetches a list of all templates', function (): void {
	$test = get('/templates')
		->assertOk()
		->assertJson()
		->assertSee('new-template');

	$files = glob(__DIR__ . '/../../templates/*.twig');
	foreach ($files as $file) {
		$test->assertSee(basename($file, '.twig'));
	}
});

it('fetches a list of reserved templates', function (): void {
	$test = get('/templates?filter=reserved')
		->assertOk()
		->assertJson()
		->assertDontSee('new-template');

	$files = glob(__DIR__ . '/../../templates/*.twig');
	foreach ($files as $file) {
		$test->assertSee(basename($file, '.twig'));
	}
});

it('fetches a list of custom templates', function (): void {
	$test = get('/templates?filter=custom')
		->assertOk()
		->assertJson()
		->assertSee('new-template');

	$files = glob(__DIR__ . '/../../templates/*.twig');
	foreach ($files as $file) {
		$test->assertDontSee(basename($file, '.twig'));
	}
});

it('fetches a recursive list of all templates including folders', function (): void {
	// First create some templates in folders
	$template = templateTestData();
	postJson("/templates", ['id' => 'custom-grids/grid-template', 'template' => $template])->assertOk();
	postJson("/templates", ['id' => 'level1/level2/nested-template', 'template' => $template])->assertOk();

	// Get recursive list
	$response = get('/templates?filter=custom')
		->assertOk()
		->assertJson();

	// Decode JSON to check array contents
	$body      = (string)$response->getBody();
	$templates = json_decode($body, true);

	// Should see root level template
	expect($templates)->toContain('new-template');

	// Should see folder templates with their full paths
	expect($templates)->toContain('custom-grids/grid-template');
	expect($templates)->toContain('level1/level2/nested-template');

	// Clean up
	delete("/templates/custom-grids/grid-template")->assertOk();
	delete("/templates/level1/level2/nested-template")->assertOk();
});

it('can delete a template', function (): void {
	$id = 'new-template';

	$this->assertFileExists(templatePath($id));

	delete("/templates/{$id}")->assertOk();

	$this->assertFileDoesNotExist(templatePath($id));
});

// Folder-based template tests
it('saves a new template to a folder', function (): void {
	$template = templateTestData();
	$id       = 'folder-template';
	$folder   = 'custom-grids';
	$verify   = 'Test Template';

	postJson("/templates", ['id' => "{$folder}/{$id}", 'template' => $template])
		->assertOk()
		->assertSee($verify);

	$this->assertFileExists(templatePath($id, $folder));
});

it('fetches a template from a folder', function (): void {
	$id     = 'folder-template';
	$folder = 'custom-grids';
	$verify = 'Test Template';

	get("/templates/{$folder}/{$id}")
		->assertOk()
		->assertSee($verify);
});

it('checks if a template exists in a folder', function (): void {
	$folder = 'custom-grids';

	head("/templates/{$folder}/folder-template")->assertOk();
	head("/templates/{$folder}/does-not-exist")->assertNotFound();
});

it('fetches a list of templates in a folder', function (): void {
	$folder = 'custom-grids';

	get("/templates/_list/{$folder}?filter=custom")
		->assertOk()
		->assertJson()
		->assertSee('folder-template');
});

it('can delete a template from a folder', function (): void {
	$id     = 'folder-template';
	$folder = 'custom-grids';

	$this->assertFileExists(templatePath($id, $folder));

	delete("/templates/{$folder}/{$id}")->assertOk();

	$this->assertFileDoesNotExist(templatePath($id, $folder));
});

it('saves templates to nested folders', function (): void {
	$template = templateTestData();
	$id       = 'nested-template';
	$folder   = 'level1/level2';
	$verify   = 'Test Template';

	postJson("/templates", ['id' => "{$folder}/{$id}", 'template' => $template])
		->assertOk()
		->assertSee($verify);

	$this->assertFileExists(templatePath($id, $folder));

	// Clean up
	delete("/templates/{$folder}/{$id}")->assertOk();
});
