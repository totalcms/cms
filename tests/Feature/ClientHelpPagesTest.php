<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function TotalCMS\Slim\Pest\get;

/**
 * The help area over real HTTP, with the bundled extension switched on. The
 * collection is created by the extension at boot, as on a real site.
 */
const CLIENT_HELP_URL = '/admin/ext/totalcms/client-help';

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/client-help' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$this->post = fn (string $id, array $data = []) => $c->get(ObjectSaver::class)->saveObject('client-help', ['id' => $id, 'title' => ucfirst($id)] + $data);

	// A client: reads other collections, and can open the extension or not.
	$this->client = function (array $extensions) use ($c): void {
		$c->get(AccessGroupRepository::class)->save(new AccessGroupData([
			'id'          => 'client',
			'name'        => 'Client',
			'operations'  => ['read'],
			'permissions' => [
				'collections' => ['all' => false, 'allowed' => ['blog']],
				'extensions'  => $extensions,
			],
		]));
		$c->get(ObjectSaver::class)->saveObject('auth', [
			'id' => 'client-user', 'name' => 'Client', 'email' => 'client@example.test', 'password' => 'client-password-1', 'groups' => ['client'],
		]);
		signInAs($this->app, 'client-user', 'auth');
	};
	$this->admin = fn () => signInAs($this->app, 'admin-user-test-com');
	$this->html  = fn (string $path = '/home'): string => (string)get(CLIENT_HELP_URL . $path)->getBody();
	$this->setting = fn (array $settings) => $c->get(ExtensionSettingsManager::class)->saveSettings('totalcms/client-help', $settings);
});

test('a client with the extension but not the collection can read help', function (): void {
	($this->post)('first');
	($this->client)(['all' => false, 'allowed' => ['totalcms/client-help']]);

	expect(get(CLIENT_HELP_URL . '/home')->getStatusCode())->toBe(200);
});

test('a client whose group excludes the extension is refused', function (): void {
	($this->post)('first');
	($this->client)(['all' => false, 'allowed' => []]);

	expect(get(CLIENT_HELP_URL . '/home')->getStatusCode())->toBe(403);
});

test('the home page lists posts in the sidebar and featured posts as cards', function (): void {
	($this->post)('star', ['featured' => true, 'categories' => ['Your Menu']]);
	($this->post)('plain', ['categories' => ['Your Website']]);
	($this->admin)();

	$html = ($this->html)();

	expect($html)->toContain('Your Menu')->toContain('Your Website');
	expect(substr_count($html, 'class="help-card'))->toBe(1);
	expect($html)->toContain('post/plain')->toContain('post/star');
});

test('with no posts an admin is shown how to add the first one', function (): void {
	($this->admin)();

	expect(($this->html)())->toContain('collections/client-help')->not->toContain('Nothing here yet.');
});

test('with no posts a client is told there is nothing yet', function (): void {
	($this->client)(['all' => false, 'allowed' => ['totalcms/client-help']]);

	expect(($this->html)())->toContain('Nothing here yet.');
});

test('with no featured posts no cards render', function (): void {
	($this->post)('plain');
	($this->admin)();

	expect(($this->html)())->not->toContain('class="help-card');
});

test('an article shows its breadcrumb, body and headings', function (): void {
	($this->post)('steps', ['categories' => ['Your Menu'], 'content' => "Intro\n\n## One\n\n## Two"]);
	($this->setting)(['navLabel' => 'Guides']);
	($this->admin)();

	$html = ($this->html)('/post/steps');

	expect($html)->toContain('doc-breadcrumb')->toContain('Guides')->toContain('Your Menu')
		->toContain('On this page')->toContain('<p>Intro</p>');
	expect($html)->toMatch('~<a class="active"\s+href="ext/totalcms/client-help/post/steps"~');
});

test('one heading leaves out the page list', function (): void {
	($this->post)('steps', ['content' => '## One']);
	($this->admin)();

	expect(($this->html)('/post/steps'))->not->toContain('On this page');
});

test('a draft and a missing post are both a 404, for an admin too', function (string $id): void {
	($this->post)('wip', ['draft' => true]);
	($this->admin)();

	expect(get(CLIENT_HELP_URL . '/post/' . $id)->getStatusCode())->toBe(404);
})->with(['wip', 'missing']);

test('a post cannot run a script on the page', function (): void {
	$xss = '<img src=x onerror=alert(1)>';
	($this->post)('bad', [
		'title'      => 'T' . $xss,
		'categories' => ['C' . $xss],
		'featured'   => true,
		'content'    => "<script>alert(1)</script>\n\n## A &lt;img src=x onerror=alert(1)&gt;\n\n## B\n\n[click](javascript:alert(1))",
	]);
	($this->admin)();

	foreach (['/home', '/post/bad'] as $path) {
		$html = ($this->html)($path);
		expect($html)->not->toContain('<img src=x onerror')->not->toContain('<script>alert(1)')->not->toContain('href="javascript:alert');
	}
});

test('the note runs as Twig on the home page only', function (): void {
	($this->post)('first');
	($this->setting)(['note' => 'Sum {{ 1 + 1 }}']);
	($this->admin)();

	expect(($this->html)())->toContain('Sum 2');
	expect(($this->html)('/post/first'))->not->toContain('Sum 2');
});

test('a broken note keeps the page up and tells only admins', function (string $note): void {
	($this->post)('first');
	($this->setting)(['note' => $note]);

	($this->admin)();
	expect(get(CLIENT_HELP_URL . '/home')->getStatusCode())->toBe(200);
	expect(($this->html)())->toContain('help-note-error');

	($this->client)(['all' => false, 'allowed' => ['totalcms/client-help']]);
	$html = ($this->html)();
	expect($html)->not->toContain('class="help-note')->not->toContain('nothing.call');
})->with(['{% if %}', "{{ include('nothing.call.twig') }}"]);

test('a blank note renders no wrapper', function (): void {
	($this->post)('first');
	($this->admin)();

	expect(($this->html)())->not->toContain('class="help-note');
});

test('custom CSS and HTML appear on help pages only', function (): void {
	($this->post)('first');
	($this->setting)(['customCss' => '.mine{color:red}', 'customHtml' => '<div id="mine"></div>']);
	($this->admin)();

	foreach (['/home', '/post/first'] as $path) {
		expect(($this->html)($path))->toContain('.mine{color:red}')->toContain('<div id="mine"></div>');
	}
	expect((string)get('/admin/docs')->getBody())->not->toContain('.mine{color:red}')->not->toContain('id="mine"');
});

test('the settings page renders its code editors', function (): void {
	($this->admin)();

	$html = (string)get('/admin/extensions/totalcms/client-help/settings')->getBody();

	expect($html)->toContain('data-mode="twig"')->toContain('data-mode="css"')->toContain('data-mode="html"');
});

test('the rail link stays inside the admin', function (): void {
	($this->admin)();

	expect(($this->html)())->toMatch('~class="ext-nav[^"]*" href="ext/totalcms/client-help/home"~');
});

test('the rail uses the stock help icon', function (): void {
	($this->admin)();

	expect(($this->html)())->toContain('--ext-icon: var(--icon-help);');
});

test('an article shows every field a post can have', function (): void {
	($this->post)('full', [
		'author'  => 'Sam Rivera',
		'date'    => '2026-03-09',
		'summary' => 'The short version.',
		'content' => 'The body.',
		'extra'   => 'The extra part.',
		'media'   => 'https://example.com/guide',
		'tags'    => ['beginner', 'menu'],
		'image'   => ['name' => 'hero.jpg', 'alt' => 'The hero', 'size' => 10, 'width' => 10, 'height' => 10],
		'gallery' => [['name' => 'one.jpg', 'alt' => 'First shot', 'size' => 10, 'width' => 10, 'height' => 10]],
	]);
	($this->admin)();

	$html = ($this->html)('/post/full');

	expect($html)->toContain('Sam Rivera')->toContain('March 9, 2026')->toContain('The short version.')
		->toContain('The extra part.')->toContain('href="https://example.com/guide"')
		->toContain('>beginner<')->toContain('>menu<')->toContain('Last updated')
		->toContain('alt="The hero"')->toContain('alt="First shot"')
		->toContain('/client-help/full/image')->toContain('/client-help/full/gallery');
	expect(strpos($html, 'The body.'))->toBeLessThan(strpos($html, 'First shot'));
	expect(strpos($html, 'First shot'))->toBeLessThan(strpos($html, 'The extra part.'));
});

test('a bare post leaves out everything it does not use', function (): void {
	($this->post)('bare', ['content' => 'Only this.']);
	($this->admin)();

	expect(($this->html)('/post/bare'))->not->toContain('class="help-hero')
		->not->toContain('class="help-summary')->not->toContain('class="help-media')
		->not->toContain('class="help-gallery')->not->toContain('class="help-tags');
});

test('post fields cannot run a script on the page', function (): void {
	$xss = '<img src=x onerror=alert(1)>';
	($this->post)('bad', [
		'author'   => 'A' . $xss,
		'summary'  => 'S' . $xss,
		'tags'     => ['T' . $xss],
		'featured' => true,
		'image'    => ['name' => 'a.jpg', 'alt' => '"' . $xss, 'size' => 10, 'width' => 10, 'height' => 10],
		'gallery'  => [['name' => 'b.jpg', 'alt' => '"' . $xss, 'size' => 10, 'width' => 10, 'height' => 10]],
	]);
	($this->admin)();

	foreach (['/home', '/post/bad'] as $path) {
		expect(($this->html)($path))->not->toContain('<img src=x onerror');
	}
});

test('a home card shows the summary', function (): void {
	($this->post)('star', ['featured' => true, 'summary' => 'Fish **and** chips']);
	($this->admin)();

	expect(($this->html)())->toContain('Fish and chips')->not->toContain('**and**');
});
