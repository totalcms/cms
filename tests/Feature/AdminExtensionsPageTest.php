<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

// Admin → Extensions: one row per extension under a search box and a status
// filter, so one extension can be found among many. It used to be a grid of
// tall cards that all carried the same icon.

beforeEach(function (): void {
	$this->fieldTypes = snapshotExtensionFieldTypes();
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/protect' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());
	signInAs($this->app, 'admin-user-test-com');

	$this->page = (string)get('/admin/extensions')->getBody();
	$this->row  = function (string $id): string {
		preg_match('#<li id="ext-' . preg_quote($id, '#') . '".*?</li>\s*(?=<li id="ext-|</ul>)#s', $this->page, $match);

		return $match[0] ?? '';
	};
});

afterEach(fn () => restoreExtensionFieldTypes($this->fieldTypes));

test('the page is a list with a search box and a status filter, not a grid of cards', function (): void {
	expect($this->page)->toContain('<div class="ext-toolbar" hidden>')
		->and($this->page)->toMatch('#<input[^>]*type="search"[^>]*class="ext-search"#')
		->and($this->page)->toMatch('#<button[^>]*data-show="all"[^>]*aria-pressed="true"#')
		->and($this->page)->toMatch('#<button[^>]*data-show="enabled"[^>]*>Enabled <span class="ext-count">\d+</span>#')
		->and($this->page)->toMatch('#<button[^>]*data-show="disabled"[^>]*>Disabled <span class="ext-count">\d+</span>#')
		->and($this->page)->toContain('class="ext-no-match" hidden')
		->and($this->page)->not->toContain('dash-card-grid');
});

test('an enabled extension is one row: its own mark, what it does, and its controls', function (): void {
	$row = ($this->row)('totalcms-protect');

	expect($row)->toContain('data-status="enabled"')
		->and($row)->toContain('data-attention="false"')
		// Found by its name, its id or what it does.
		->and($row)->toMatch('#data-search="[^"]*Protect[^"]*totalcms/protect[^"]*passcode#')
		// Bundled extensions all ship the same picture, so each gets a mark from the admin's own icons.
		->and($row)->toContain('style="--ext-mark: var(--icon-lock);"')
		->and($row)->not->toContain('<img')
		->and($row)->toContain('Bundled')
		->and($row)->toMatch('#<form[^>]*action="[^"]*/extensions/totalcms/protect/disable"#')
		->and($row)->toContain('>Disable</button>');
});

test('a disabled extension offers Enable, and no links into pages it does not serve yet', function (): void {
	$row = ($this->row)('totalcms-s3-uploads');

	expect($row)->toContain('data-status="disabled"')
		->and($row)->toContain('style="--ext-mark: var(--icon-upload);"')
		->and($row)->toMatch('#<form[^>]*action="[^"]*/extensions/totalcms/s3-uploads/enable"#')
		->and($row)->toContain('>Enable</button>')
		->and($row)->toContain('>Documentation</a>')
		->and($row)->not->toContain('Bucket Setup');
});

test('the rows are grouped under Enabled and Disabled, each with its count', function (): void {
	expect($this->page)->toMatch('#<section class="ext-group" data-group="enabled">\s*<h2 class="ext-group-heading">Enabled <span class="ext-count">\d+</span></h2>#')
		->and($this->page)->toMatch('#<section class="ext-group" data-group="disabled">\s*<h2 class="ext-group-heading">Disabled <span class="ext-count">\d+</span></h2>#')
		->and(strpos($this->page, 'id="ext-totalcms-protect"'))->toBeLessThan(strpos($this->page, 'data-group="disabled"'));
});
