<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

use function Nekofar\Slim\Pest\get;
use TotalCMS\Action\Admin\AdminAutomationsAction;
use TotalCMS\Action\Automation\AutomationReenableAction;
use TotalCMS\Action\Automation\AutomationRunNowAction;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);
	$fetcher->fetchOrCreateReserved('automations');
	// The admin chrome (quick-nav) enumerates data views + the editor form's
	// errorMailerId references the mailer collection — create both so a full
	// page render doesn't trip on a missing reserved collection.
	$fetcher->fetchOrCreateReserved('dataviews');
	$fetcher->fetchOrCreateReserved('mailer');
});

function saveAdminAutomation(object $container, string $id, string $handler, bool $enabled = true): void
{
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => $id,
		'name'     => ucfirst($id),
		'enabled'  => $enabled,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '0 1 * * *']],
		'handler'  => $handler,
	]);
}

function adminRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
{
	return (new ServerRequestFactory())->createServerRequest($method, $path);
}

it('wires the /admin/automations route + middleware stack (no 500/404)', function (): void {
	// Goes through the real route + AutomationsEditionMiddleware + AdminOnlyMiddleware
	// stack (unauthenticated → redirect/403), confirming all DI resolves.
	$response = get('/admin/automations');

	expect($response->getStatusCode())->toBeIn([200, 302, 403]);
});

it('renders the automations list', function (): void {
	$container = $this->app->getContainer();
	saveAdminAutomation($container, 'daily', "<?php\n\nreturn function (\$ctx) { return 1; };\n");

	$result = $container->get(AdminAutomationsAction::class)(adminRequest('GET', '/admin/automations'), new Response(), []);

	$body = (string)$result->getBody();
	expect($result->getStatusCode())->toBe(200);
	expect($body)->toContain('Daily');
	// The copy-the-cron-command helper renders the process command + run guidance.
	expect($body)->toContain('automations:process');
	expect($body)->toContain('every minute');
});

it('renders the editor with a handler advisory for a risky handler', function (): void {
	$container = $this->app->getContainer();
	saveAdminAutomation($container, 'risky', "<?php\n\nreturn function (\$ctx) {\n    shell_exec('whoami');\n    return 1;\n};\n");

	$result = $container->get(AdminAutomationsAction::class)(adminRequest('GET', '/admin/automations/risky'), new Response(), ['id' => 'risky']);

	$body = (string)$result->getBody();
	expect($result->getStatusCode())->toBe(200);
	expect($body)->toContain('shell_exec');          // advisory finding rendered
	expect($body)->toContain('Handler patterns to review');
	// The form must load the existing object's data + show Save/Delete (the
	// builder needs id/save/delete options — regression guard).
	expect($body)->toContain('Risky');               // the object's name value
	expect($body)->toContain('>Save<');
	expect($body)->toContain('>Delete<');
});

it('shows sidebar run-status badges in editor mode too', function (): void {
	$container = $this->app->getContainer();
	saveAdminAutomation($container, 'beat', "<?php\n\nreturn function (\$ctx) { return 1; };\n");
	saveAdminAutomation($container, 'other', "<?php\n\nreturn function (\$ctx) { return 1; };\n");

	// Give 'beat' a successful run; 'other' has none.
	$container->get(AutomationRunner::class)->run('beat', ['type' => 'manual'], []);

	// Editing 'other' must still render the sidebar — including beat's badge
	// (lastRuns is needed in editor mode, not just list mode).
	$result = $container->get(AdminAutomationsAction::class)(adminRequest('GET', '/admin/automations/other'), new Response(), ['id' => 'other']);

	expect((string)$result->getBody())->toContain('dash-badge success');
});

it('run-now executes the automation and returns its run record as JSON', function (): void {
	$container = $this->app->getContainer();
	saveAdminAutomation($container, 'now', "<?php\n\nreturn function (\$ctx) { return ['ok' => true]; };\n");

	$result = $container->get(AutomationRunNowAction::class)(adminRequest('POST', '/admin/automations/now/run'), new Response(), ['id' => 'now']);

	expect($result->getStatusCode())->toBe(200);
	expect($result->getHeaderLine('Content-Type'))->toContain('application/json');
	$json = json_decode((string)$result->getBody(), true);
	expect($json['status'])->toBe('success');
	expect($json['return'])->toBe(['ok' => true]);
	expect($json['trigger']['type'])->toBe('manual');
});

it('re-enable flips enabled back on and redirects to the editor', function (): void {
	$container = $this->app->getContainer();
	saveAdminAutomation($container, 'off', "<?php\n\nreturn function (\$ctx) { return 1; };\n", enabled: false);

	$result = $container->get(AutomationReenableAction::class)(adminRequest('POST', '/admin/automations/off/enable'), new Response(), ['id' => 'off']);

	expect($result->getStatusCode())->toBe(302);
	expect($result->getHeaderLine('Location'))->toBe('/admin/automations/off');
	expect($container->get(ObjectFetcher::class)->fetchObject('automations', 'off')->toArray()['enabled'])->toBeTrue();
	expect($container->get(AutomationStateStore::class)->failures('off'))->toBe(0);
});
