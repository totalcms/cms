<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;

use function TotalCMS\Slim\Pest\get;

/**
 * The bundled WebMCP extension: webmcp_form() renders a form carrying the
 * declarative WebMCP attributes, built from schema metadata only, with the
 * four security rules from docs/planning/3.6/webmcp.md enforced here —
 * descriptions never come from object data, autosubmit is opt-in, a
 * registration form is refused, and a switched-off extension setting
 * renders a plain form so templates keep working.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	@mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/webmcp' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog', 'labelSingular' => 'Post', 'labelPlural' => 'Posts', 'description' => 'The blog', 'publicOperations' => ['read']]);
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'note', 'type' => 'object', 'properties' => [
		'id'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'body' => ['type' => 'string', 'field' => 'textarea', 'label' => 'Body', 'help' => 'Say <b>something</b> nice'],
		'mood' => ['type' => 'string', 'field' => 'text', 'label' => 'Mood'],
	]]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'note']);

	$this->settings = $c->get(ExtensionSettingsManager::class);
	$this->schemas  = $c->get(SchemaFetcher::class);
	$this->render   = fn (string $template): string => $c->get(TwigEngine::class)->renderString($template, []);
});

function webmcpFormTag(string $html): string
{
	preg_match('/<form[^>]*>/', $html, $m);

	return $m[0] ?? '';
}

function webmcpInput(string $html, string $name): string
{
	preg_match('/<(?:input|textarea)[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m);

	return $m[0] ?? '';
}

it('renders a form annotated with the WebMCP tool attributes', function (): void {
	$html = ($this->render)("{{ webmcp_form('blog', {name: 'Create Post', description: 'Create a blog post'}) }}");
	$tag  = webmcpFormTag($html);

	expect($tag)->toContain('toolname="create_post"')
		->and($tag)->toContain('tooldescription="Create a blog post"')
		->and($tag)->not->toContain('toolautosubmit')
		->and($tag)->toContain('class="totalform');
});

it('titles each parameter from the schema label and describes it from the help', function (): void {
	$html = ($this->render)("{{ webmcp_form('blog') }}");

	$property = $this->schemas->fetchSchemaForCollection('blog')->properties['title'];
	$attribute = fn (string $name, string $value): string => $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';

	expect(webmcpInput($html, 'title'))->toContain($attribute('toolparamdescription', (string)$property['help']))
		->and(webmcpInput($html, 'title'))->toContain($attribute('toolparamtitle', (string)$property['label']));
});

it('derives the tool name and description from the collection when none is given', function (): void {
	$tag = webmcpFormTag(($this->render)("{{ webmcp_form('blog') }}"));

	expect($tag)->toContain('toolname="create_blog"')
		->and($tag)->toContain('tooldescription="Create a new Post in the Blog collection"');
});

it('strips markup from a help text before it becomes a parameter description', function (): void {
	$html = ($this->render)("{{ webmcp_form('notes') }}");

	expect(webmcpInput($html, 'body'))->toContain('toolparamdescription="Say something nice"')
		->and(webmcpInput($html, 'body'))->toContain('toolparamtitle="Body"');
});

it('leaves a parameter with only a label titled but undescribed', function (): void {
	// The label is the title; repeating it as the description would tell an
	// agent the same thing twice.
	$html = ($this->render)("{{ webmcp_form('notes') }}");

	expect(webmcpInput($html, 'mood'))->toContain('toolparamtitle="Mood"')
		->and(webmcpInput($html, 'mood'))->not->toContain('toolparamdescription');
});

it('lets the call override a parameter description', function (): void {
	$html = ($this->render)("{{ webmcp_form('notes', {params: {mood: 'One word for how you feel'}}) }}");

	expect(webmcpInput($html, 'mood'))->toContain('toolparamdescription="One word for how you feel"')
		->and(webmcpInput($html, 'mood'))->toContain('toolparamtitle="Mood"');
});

it('lets the call override a parameter title and description together', function (): void {
	$html = ($this->render)("{{ webmcp_form('notes', {params: {mood: {title: 'Feeling', description: 'One word'}}}) }}");

	expect(webmcpInput($html, 'mood'))->toContain('toolparamtitle="Feeling"')
		->and(webmcpInput($html, 'mood'))->toContain('toolparamdescription="One word"');
});

it('adds autosubmit only when the call asks for it', function (): void {
	$tag = webmcpFormTag(($this->render)("{{ webmcp_form('notes', {autosubmit: true}) }}"));

	expect($tag)->toContain('toolautosubmit');
});

it('refuses to annotate a registration form', function (): void {
	// Extension Twig functions are fault-isolated: a throw renders nothing.
	// The builder itself must refuse, so the test reaches it directly.
	$builder = $this->app->getContainer()->get(TotalCMS\Bundled\WebMcp\WebMcpFormBuilder::class);

	expect(fn () => $builder->build('blog', ['register' => true]))->toThrow(DomainException::class, 'registration');
});

it('renders a plain form when the declarative forms setting is off', function (): void {
	$this->settings->saveSettings('totalcms/webmcp', ['declarativeForms' => false]);

	$html = ($this->render)("{{ webmcp_form('blog', {name: 'Create Post'}) }}");

	expect($html)->toContain('<form')
		->and($html)->not->toContain('toolname')
		->and($html)->not->toContain('toolparamtitle')
		->and($html)->not->toContain('toolparamdescription');
});

it('never puts object data into a tool string', function (): void {
	// An edit form loads the object; its values must stay in value=""
	// attributes and never reach toolname / tooldescription / toolparamtitle
	// / toolparamdescription.
	$c = $this->app->getContainer();
	$c->get(TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('notes', ['id' => 'n1', 'body' => 'INJECTED body', 'mood' => 'INJECTED mood']);

	$html = ($this->render)("{{ webmcp_form('notes', {id: 'n1'}) }}");
	preg_match_all('/tool(?:name|description|paramtitle|paramdescription)="([^"]*)"/', $html, $m);

	expect($m[1])->not->toBe([])
		->and(implode(' ', $m[1]))->not->toContain('INJECTED')
		->and(webmcpFormTag($html))->toContain('toolname="update_notes"');
});

describe('the tool manifest', function (): void {
	test('lists only the configured collections that allow public read', function (): void {
		// blog allows public read (beforeEach); notes does not; missing does not exist.
		$this->settings->saveSettings('totalcms/webmcp', ['readCollections' => ['blog', 'notes', 'missing'], 'maxResults' => 5, 'originTrialToken' => 'TOKEN123']);

		$response = get('/api/ext/totalcms/webmcp/tools.json');
		$json     = json_decode((string)$response->getBody(), true);

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
			->and($json['originTrialToken'])->toBe('TOKEN123')
			->and($json['maxResults'])->toBe(5)
			->and($json['api'])->toEndWith('/api')
			->and(array_column($json['tools'], 'collection'))->toBe(['blog'])
			->and($json['tools'][0]['label'])->toBe('Posts')
			->and($json['tools'][0]['description'])->toBe('The blog');
	});

	test('lists nothing when read tools are switched off', function (): void {
		$this->settings->saveSettings('totalcms/webmcp', ['readTools' => false, 'readCollections' => ['blog']]);

		$json = json_decode((string)get('/api/ext/totalcms/webmcp/tools.json')->getBody(), true);

		expect($json['tools'])->toBe([]);
	});

	test('the frontend script is served as an extension asset', function (): void {
		$response = get('/api/ext/totalcms/webmcp/assets/webmcp.js');

		expect($response->getStatusCode())->toBe(200)
			->and((string)$response->getBody())->toContain('modelContext');
	});
});

describe('for a signed-in operator', function (): void {
	test('the manifest lists every configured collection, on any page, uncached', function (): void {
		// notes has no public read: hidden from visitors, listed for the operator,
		// whose session the tools read with — on a public page as much as in the admin.
		$this->settings->saveSettings('totalcms/webmcp', ['readCollections' => ['blog', 'notes', 'missing']]);
		signInAs($this->app, 'blogger-user-test-com', 'auth');

		$response = get('/api/ext/totalcms/webmcp/tools.json');
		$json     = json_decode((string)$response->getBody(), true);

		expect(array_column($json['tools'], 'collection'))->toBe(['blog', 'notes'])
			->and($response->getHeaderLine('Cache-Control'))->toContain('no-store');
	});

	test('a visitor\'s manifest is cacheable but varies on the cookie, so signing in never reuses it', function (): void {
		$this->settings->saveSettings('totalcms/webmcp', ['readCollections' => ['blog', 'notes']]);

		$response = get('/api/ext/totalcms/webmcp/tools.json');

		expect(array_column(json_decode((string)$response->getBody(), true)['tools'], 'collection'))->toBe(['blog'])
			->and($response->getHeaderLine('Vary'))->toBe('Cookie')
			->and($response->getHeaderLine('Cache-Control'))->toContain('max-age');
	});
});

describe('the admin asset', function (): void {
	beforeEach(function (): void {
		// The toggle is read when the extension registers, so it has to be on
		// disk before the app boots.
		recursiveDelete(cmsDataDir());
		restoreFixtures();
		@mkdir(cmsDataDir() . '.system/extension-settings/totalcms', 0755, true);
		file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/webmcp' => ['enabled' => true]]));
		file_put_contents(cmsDataDir() . '.system/extension-settings/totalcms/webmcp.json', json_encode(['adminTools' => true]));
		$this->setUpApp(bootstrap());
		signInAs($this->app, 'blogger-user-test-com', 'auth');
	});

	test('the script loads on dashboard pages when the admin toggle is on', function (): void {
		$html = (string)get('/admin/collections')->getBody();

		expect($html)->toContain('ext/totalcms/webmcp/assets/webmcp.js');
	});
});

it('keeps the script off dashboard pages by default', function (): void {
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	expect((string)get('/admin/collections')->getBody())->not->toContain('webmcp/assets/webmcp.js');
});
