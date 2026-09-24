<?php

declare(strict_types=1);

use TotalCMS\Bundled\WebMcp\WebMcpFormBuilder;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Object\Service\ObjectSaver;
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

	$property  = $this->schemas->fetchSchemaForCollection('blog')->properties['title'];
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
	$builder = $this->app->getContainer()->get(WebMcpFormBuilder::class);

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
	$c->get(ObjectSaver::class)->saveObject('notes', ['id' => 'n1', 'body' => 'INJECTED body', 'mood' => 'INJECTED mood']);

	$html = ($this->render)("{{ webmcp_form('notes', {id: 'n1'}) }}");
	preg_match_all('/tool(?:name|description|paramtitle|paramdescription)="([^"]*)"/', $html, $m);

	expect($m[1])->not->toBe([])
		->and(implode(' ', $m[1]))->not->toContain('INJECTED')
		->and(webmcpFormTag($html))->toContain('toolname="update_notes"');
});

/**
 * Settings are read when the extension registers, so they must be on disk
 * before the app boots — hence the re-bootstrap in each case.
 */
function bootWebMcpWith(object $test, array $settings): void
{
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	@mkdir(cmsDataDir() . '.system/extension-settings/totalcms', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/webmcp' => ['enabled' => true]]));
	file_put_contents(cmsDataDir() . '.system/extension-settings/totalcms/webmcp.json', json_encode($settings));
	// setUpApp() and $app are protected; called from this global function they
	// need an explicit scope bind rather than the closure-scope trick beforeEach() relies on.
	$app = Closure::bind(function () use ($test) {
		$test->setUpApp(bootstrap());

		return $test->app;
	}, $test, $test::class)();
	$test->render = fn (string $template): string => $app->getContainer()->get(TwigEngine::class)->renderString($template, []);
}

describe('the frontend assets', function (): void {
	test('read tools on: the MCP-client script is on public pages', function (): void {
		bootWebMcpWith($this, ['readTools' => true]);

		expect(($this->render)('{{ cms.assetsBody() }}'))->toContain('ext/totalcms/webmcp/assets/webmcp.js');
	});

	test('read tools off, forms on: only the bridge is on public pages', function (): void {
		bootWebMcpWith($this, ['readTools' => false, 'declarativeForms' => true]);
		$html = ($this->render)('{{ cms.assetsBody() }}');

		expect($html)->toContain('ext/totalcms/webmcp/assets/bridge.js')
			->and($html)->not->toContain('assets/webmcp.js');
	});

	test('both switches off: nothing is registered', function (): void {
		bootWebMcpWith($this, ['readTools' => false, 'declarativeForms' => false, 'originTrialToken' => 'TOKEN123']);

		expect(($this->render)('{{ cms.assetsBody() }}'))->not->toContain('webmcp')
			->and(($this->render)('{{ cms.assetsHead() }}'))->not->toContain('origin-trial');
	});

	test('a token becomes one origin-trial meta tag in the head', function (): void {
		bootWebMcpWith($this, ['originTrialToken' => ' TOKEN123 ']);

		expect(($this->render)('{{ cms.assetsHead() }}'))->toContain('<meta http-equiv="origin-trial" content="TOKEN123"');
	});

	test('no token, no meta tag', function (): void {
		bootWebMcpWith($this, []);

		expect(($this->render)('{{ cms.assetsHead() }}'))->not->toContain('origin-trial');
	});

	test('the old manifest route is gone', function (): void {
		bootWebMcpWith($this, ['readTools' => true]);

		expect(get('/api/ext/totalcms/webmcp/tools.json')->getStatusCode())->toBe(404);
	});

	test('the scripts are served as extension assets', function (): void {
		bootWebMcpWith($this, ['readTools' => true]);

		expect((string)get('/api/ext/totalcms/webmcp/assets/webmcp.js')->getBody())->toContain('tools/list')
			->and(get('/api/ext/totalcms/webmcp/assets/bridge.js')->getStatusCode())->toBe(200);
	});

	test('a settings file that still carries the removed keys loads', function (): void {
		bootWebMcpWith($this, ['readTools' => true, 'readCollections' => ['blog'], 'maxResults' => 5]);

		expect(($this->render)('{{ cms.assetsBody() }}'))->toContain('assets/webmcp.js');
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
		file_put_contents(cmsDataDir() . '.system/extension-settings/totalcms/webmcp.json', json_encode(['adminTools' => true, 'originTrialToken' => 'TOKEN123']));
		$this->setUpApp(bootstrap());
		signInAs($this->app, 'blogger-user-test-com', 'auth');
	});

	test('the script loads on dashboard pages when the admin toggle is on', function (): void {
		$html = (string)get('/admin/collections')->getBody();

		expect($html)->toContain('ext/totalcms/webmcp/assets/webmcp.js')
			->and($html)->toContain('<meta http-equiv="origin-trial" content="TOKEN123"');
	});
});

it('keeps the script off dashboard pages by default', function (): void {
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	expect((string)get('/admin/collections')->getBody())->not->toContain('webmcp/assets/webmcp.js');
});
