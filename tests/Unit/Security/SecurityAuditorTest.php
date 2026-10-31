<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Check\SecurityCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function secAuditConfig(array $ignore = []): Config
{
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->security = ['ignore' => $ignore];

	return $config;
}

function secAuditCheck(array $findings, bool $requests = false): SecurityCheck
{
	return new class($findings, $requests) implements SecurityCheck {
		public function __construct(private array $findings, private bool $requests)
		{
		}

		public function run(SecurityContext $context): array
		{
			return $this->findings;
		}

		public function makesRequests(): bool
		{
			return $this->requests;
		}

		public function id(): string
		{
			return 'stub';
		}

		public function title(): string
		{
			return 'Stub check';
		}
	};
}

function secAuditContext(): SecurityContext
{
	return new SecurityContext('https://example.com', 'example.com', true, true, 'Apache');
}

test('sorts findings critical, warning, info, with ok and skipped after found', function () {
	$auditor = new SecurityAuditor(secAuditConfig(), [
		secAuditCheck([
			SecurityFinding::ok('ok1', 'Fine'),
			SecurityFinding::found('info1', Severity::Info, 't', '', '', ''),
			SecurityFinding::skipped('skip1', 't', 'why'),
			SecurityFinding::found('warn1', Severity::Warning, 't', '', '', ''),
			SecurityFinding::found('crit1', Severity::Critical, 't', '', '', ''),
		]),
	]);

	$ids = array_map(fn ($f) => $f->id, $auditor->audit(secAuditContext())->findings);

	expect($ids)->toBe(['crit1', 'warn1', 'info1', 'ok1', 'skip1']);
});

test('a check that throws becomes skipped under its own id and title, with a generic reason, and is logged', function () {
	$thrower = new class implements SecurityCheck {
		public function run(SecurityContext $context): array
		{
			throw new RuntimeException('boom /secret/path');
		}

		public function makesRequests(): bool
		{
			return false;
		}

		public function id(): string
		{
			return 'thrower';
		}

		public function title(): string
		{
			return 'Thrower check';
		}
	};
	$logger = new class extends AbstractLogger {
		public array $records = [];

		public function log($level, string|Stringable $message, array $context = []): void
		{
			$this->records[] = [$level, (string)$message, $context];
		}
	};
	$auditor = new SecurityAuditor(secAuditConfig(), [$thrower, secAuditCheck([SecurityFinding::ok('next', 'Fine')])], null, $logger);

	$findings = $auditor->audit(secAuditContext())->findings;
	$skipped  = array_values(array_filter($findings, fn ($f) => $f->status === FindingStatus::Skipped));

	expect($skipped)->toHaveCount(1)
		->and($skipped[0]->id)->toBe('thrower')
		->and($skipped[0]->title)->toBe('Thrower check')
		->and($skipped[0]->detail)->toBe(SecurityAuditor::CHECK_FAILED)
		->and($skipped[0]->detail)->not->toContain('boom')
		->and(array_map(fn ($f) => $f->id, $findings))->toContain('next')
		->and($logger->records)->toHaveCount(1)
		->and($logger->records[0][1])->toContain('thrower')
		->and($logger->records[0][2]['exception'] ?? null)->toBeInstanceOf(RuntimeException::class);
});

test('every registered check has a non-empty id and title', function () {
	foreach (SecurityAuditor::CHECKS as $class) {
		$check = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		expect($check->id())->not->toBe('')
			->and($check->title())->not->toBe('');
	}
});

test('audit resets the site probe so a previous audit\'s failure does not carry over', function () {
	$client = new class implements HttpClientInterface {
		public int $calls = 0;

		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$this->calls++;

			throw new RuntimeException('down');
		}
	};
	$probe   = new SiteProbe($client, secAuditConfig());
	$prober  = new class($probe) implements SecurityCheck {
		public function __construct(private SiteProbe $probe)
		{
		}

		public function run(SecurityContext $context): array
		{
			$this->probe->get('https://example.com/a');
			$this->probe->get('https://example.com/b');

			return [SecurityFinding::ok('p', 'P')];
		}

		public function makesRequests(): bool
		{
			return true;
		}

		public function id(): string
		{
			return 'p';
		}

		public function title(): string
		{
			return 'P';
		}
	};
	$auditor = new SecurityAuditor(secAuditConfig(), [$prober], $probe);

	$auditor->audit(secAuditContext());
	$auditor->audit(secAuditContext());

	expect($client->calls)->toBe(2);
});

test('applyIgnores re-applies the current ignore list to a stored report', function () {
	$stored = new SecurityReport([
		SecurityFinding::found('was-ignored', Severity::Critical, 't', '', '', '')->withIgnored(),
		SecurityFinding::found('now-ignored', Severity::Critical, 't', '', '', ''),
	], '2026-10-31T12:00:00+00:00');

	$report = (new SecurityAuditor(secAuditConfig(['now-ignored']), []))->applyIgnores($stored);
	$byId   = [];
	foreach ($report->findings as $f) {
		$byId[$f->id] = $f->ignored;
	}

	expect($byId)->toBe(['was-ignored' => false, 'now-ignored' => true])
		->and($report->criticalCount())->toBe(1)
		->and($report->checkedAt)->toBe('2026-10-31T12:00:00+00:00');
});

test('ignored ids are marked and do not count', function () {
	$auditor = new SecurityAuditor(secAuditConfig(['a']), [
		secAuditCheck([SecurityFinding::found('a', Severity::Critical, 't', '', '', '')]),
	]);

	$report = $auditor->audit(secAuditContext());

	expect($report->findings[0]->ignored)->toBeTrue()
		->and($report->criticalCount())->toBe(0);
});

test('a non-ignored critical counts', function () {
	$auditor = new SecurityAuditor(secAuditConfig(), [
		secAuditCheck([SecurityFinding::found('a', Severity::Critical, 't', '', '', '')]),
	]);

	expect($auditor->audit(secAuditContext())->criticalCount())->toBe(1);
});

test('offlineOnly skips checks that make requests', function () {
	$auditor = new SecurityAuditor(secAuditConfig(), [
		secAuditCheck([SecurityFinding::ok('offline', 't')]),
		secAuditCheck([SecurityFinding::ok('online', 't')], true),
	]);

	$ids = array_map(fn ($f) => $f->id, $auditor->audit(secAuditContext(), true)->findings);

	expect($ids)->toBe(['offline']);
});

test('report round-trips through toArray and fromArray', function () {
	$report = new SecurityReport([
		SecurityFinding::found('a', Severity::Warning, 'Title', 'Detail', 'Fix', 'docs/x')->withIgnored(),
		SecurityFinding::skipped('b', 'Other', 'reason'),
	], '2026-10-31T12:00:00+00:00');

	expect(SecurityReport::fromArray($report->toArray()))->toEqual($report);
});

test('context host helpers', function () {
	expect((new SecurityContext('', 'foo.test', false, false, ''))->isLocalHost())->toBeTrue()
		->and((new SecurityContext('', 'example.com', false, false, ''))->isLocalHost())->toBeTrue()
		->and((new SecurityContext('', 'mysite.com', false, false, ''))->isLocalHost())->toBeFalse()
		->and((new SecurityContext('', 'a', false, false, ''))->isApache())->toBeTrue()
		->and((new SecurityContext('', 'a', false, true, 'nginx/1.25'))->isApache())->toBeFalse()
		->and((new SecurityContext('', 'a', false, true, 'LiteSpeed'))->isApache())->toBeTrue();
});
