<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\InfoCommand;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\TotalCMS;

require_once __DIR__ . '/helpers.php';

beforeEach(function (): void {
	$this->totalcms         = $this->createMock(TotalCMS::class);
	$this->totalcms->config = createTestConfig([
		'cache'  => ['apcu' => ['enabled' => true]],
		'domain' => 'example.com',
	]);

	$collectionLister = $this->createMock(CollectionLister::class);
	$collectionLister->method('listAllCollections')->willReturn([new CollectionData(), new CollectionData()]);
	$this->totalcms->method('collectionLister')->willReturn($collectionLister);

	$schemaLister = $this->createMock(SchemaLister::class);
	$schema       = new SchemaData();
	$schema->id   = 'custom';
	$schemaLister->method('listCustomSchemas')->willReturn([$schema]);
	$schemaLister->method('listReservedSchemas')->willReturn([new SchemaData(), new SchemaData()]);
	$this->totalcms->method('schemaLister')->willReturn($schemaLister);

	$license = new LicenseData(
		valid: true,
		trial: false,
		domain: 'example.com',
		edition: 'pro',
		message: '',
		validationToken: null,
		updatesValid: true,
		trialDaysRemaining: null
	);
	$licenseValidator = $this->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willReturn($license);
	$this->totalcms->method('licenseValidator')->willReturn($licenseValidator);

	$app     = new Application();
	$command = new InfoCommand($this->totalcms);
	$app->addCommand($command);
	$this->tester = new CommandTester($command);
});

it('outputs site info in human format', function (): void {
	$this->tester->execute([]);

	$output = $this->tester->getDisplay();
	expect($output)->toContain('Total CMS');
	expect($output)->toContain('example.com');
	expect($output)->toContain('Pro');
	expect($this->tester->getStatusCode())->toBe(0);
});

it('outputs valid JSON with --json flag', function (): void {
	$this->tester->execute(['--json' => true]);

	$output = $this->tester->getDisplay();
	$data   = json_decode($output, true);

	expect($data)->toBeArray();
	expect($data['domain'])->toBe('example.com');
	expect($data['edition'])->toBe('pro');
	expect($data['license']['valid'])->toBeTrue();
	expect($data['collections']['total'])->toBe(2);
	expect($data['schemas']['custom'])->toBe(1);
	expect($data['cache']['backend'])->toBe('apcu');
});

it('handles license validation failure gracefully', function (): void {
	// Need a fresh TotalCMS mock with a failing license validator
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['domain' => 'example.com']);

	$collectionLister = $this->createMock(CollectionLister::class);
	$collectionLister->method('listAllCollections')->willReturn([]);
	$totalcms->method('collectionLister')->willReturn($collectionLister);

	$schemaLister = $this->createMock(SchemaLister::class);
	$schemaLister->method('listCustomSchemas')->willReturn([]);
	$schemaLister->method('listReservedSchemas')->willReturn([]);
	$totalcms->method('schemaLister')->willReturn($schemaLister);

	$licenseValidator = $this->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willThrowException(new \RuntimeException('offline'));
	$totalcms->method('licenseValidator')->willReturn($licenseValidator);

	$app     = new Application();
	$command = new InfoCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--json' => true]);
	$data = json_decode($tester->getDisplay(), true);

	expect($data['edition'])->toBe('unknown');
	expect($data['license']['valid'])->toBeFalse();
});
