<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;

/** An auditor that returns a canned report and records how it was called. */
function stubSecurityAuditor(SecurityReport $report): SecurityAuditor
{
	return new class($report) extends SecurityAuditor {
		public int $calls = 0;

		public function __construct(private SecurityReport $canned)
		{
		}

		public function audit(SecurityContext $context, bool $offlineOnly = false): SecurityReport
		{
			++$this->calls;

			return $this->canned;
		}
	};
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());

	$this->app->getContainer()->set(HttpClientInterface::class, createMockHttpClient(new HttpResponse(0, '')));

	$session = $this->app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin');
	$session->set(SessionKeys::AUTH_COLLECTION, 'auth');
});

test('server checker page shows the security section', function (): void {
	$auditor = stubSecurityAuditor(new SecurityReport([
		SecurityFinding::found('debug-on', Severity::Warning, 'Debug mode is on', 'detail text', 'Turn debug off', 'operations/security#debug-on'),
		SecurityFinding::skipped('https', 'HTTPS check', 'No origin known'),
	], '2026-10-31T10:00:00+00:00'));
	$this->app->getContainer()->set(SecurityAuditor::class, $auditor);

	$body = (string)get('/admin/utils/server-checker')->getBody();

	expect($body)->toContain('Debug mode is on')
		->and($body)->toContain('Turn debug off')
		->and($body)->toContain('docs/operations/security#debug-on')
		->and($body)->toContain('Not checked')
		->and($body)->toContain('No origin known')
		->and($auditor->calls)->toBe(1);
});

test('critical finding puts the section first', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::found('data-folder-public', Severity::Critical, 'Data folder is public', 'd', 'f', 'operations/security#data-folder-public'),
	], '2026-10-31T10:00:00+00:00')));

	$body = (string)get('/admin/utils/server-checker')->getBody();

	expect(strpos($body, 'Data folder is public'))->toBeLessThan(strpos($body, 'License Information'));
});

test('a clean report puts the section last', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::ok('https', 'HTTPS in use'),
	], '2026-10-31T10:00:00+00:00')));

	$body = (string)get('/admin/utils/server-checker')->getBody();

	expect(strpos($body, 'class="security-section"'))->toBeGreaterThan(strpos($body, 'License Information'));
});

test('other utility pages do not run the audit', function (): void {
	$auditor = stubSecurityAuditor(new SecurityReport([], '2026-10-31T10:00:00+00:00'));
	$this->app->getContainer()->set(SecurityAuditor::class, $auditor);

	get('/admin/utils/logs');

	expect($auditor->calls)->toBe(0);
});

test('a report with no findings but skipped checks says they could not run instead of all clear', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::ok('https', 'HTTPS in use'),
		SecurityFinding::skipped('a', 'A', 'why a'),
		SecurityFinding::skipped('b', 'B', 'why b'),
	], '2026-10-31T10:00:00+00:00')));

	$body = (string)get('/admin/utils/server-checker')->getBody();

	expect($body)->toContain('No problems found, but 2 checks couldn')
		->and($body)->not->toContain('No security problems were found.');
});

test('a clean report with nothing skipped says all clear', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::ok('https', 'HTTPS in use'),
	], '2026-10-31T10:00:00+00:00')));

	expect((string)get('/admin/utils/server-checker')->getBody())->toContain('No security problems were found.');
});

test('detail and fix cells keep their line breaks', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::found('security-headers', Severity::Info, 'Headers missing', 'd', "Add these:\n\nHeader A", 'operations/security#security-headers'),
	], '2026-10-31T10:00:00+00:00')));

	expect((string)get('/admin/utils/server-checker')->getBody())->toContain('<td class="security-text">Add these:');
});

test('finding text is escaped, so a literal tag in a fix shows as text', function (): void {
	$this->app->getContainer()->set(SecurityAuditor::class, stubSecurityAuditor(new SecurityReport([
		SecurityFinding::found('sensitive-files-public', Severity::Critical, 'Sensitive <b>files</b>', '/.env', 'Add a <Files> deny rule to .htaccess.', 'operations/security#sensitive-files-public'),
	], '2026-10-31T10:00:00+00:00')));

	$body = (string)get('/admin/utils/server-checker')->getBody();

	expect($body)->toContain('Add a &lt;Files&gt; deny rule')
		->and($body)->toContain('Sensitive &lt;b&gt;files&lt;/b&gt;');
});
