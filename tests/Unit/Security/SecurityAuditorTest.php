<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Check\SecurityCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use TotalCMS\Support\Config;

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

test('a check that throws becomes skipped with the exception message', function () {
	$thrower = new class implements SecurityCheck {
		public function run(SecurityContext $context): array
		{
			throw new RuntimeException('boom');
		}

		public function makesRequests(): bool
		{
			return false;
		}
	};
	$auditor = new SecurityAuditor(secAuditConfig(), [$thrower, secAuditCheck([SecurityFinding::ok('next', 'Fine')])]);

	$findings = $auditor->audit(secAuditContext())->findings;
	$byStatus = array_values(array_filter($findings, fn ($f) => $f->status === FindingStatus::Skipped));

	expect($byStatus)->toHaveCount(1)
		->and($byStatus[0]->detail)->toContain('boom')
		->and(array_map(fn ($f) => $f->id, $findings))->toContain('next');
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
