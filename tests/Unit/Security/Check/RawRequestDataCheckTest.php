<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Security\Check\RawRequestDataCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\Severity;

function rawCheckRun(array $layers): array
{
	$paths = test()->createMock(BuilderTemplatePaths::class);
	$paths->method('readLayersLabeled')->willReturn($layers);

	return (new RawRequestDataCheck($paths))->run(new SecurityContext('https://mysite.com', 'mysite.com', true, true, 'Apache/2.4'));
}

beforeEach(function (): void {
	$this->rawDir = sys_get_temp_dir() . '/tcms-raw-' . bin2hex(random_bytes(4));
	mkdir($this->rawDir . '/pages', 0777, true);
});

afterEach(function (): void {
	foreach (glob($this->rawDir . '/pages/*') ?: [] as $f) {
		unlink($f);
	}
	rmdir($this->rawDir . '/pages');
	rmdir($this->rawDir);
});

test('raw over getData is flagged with file and line', function (): void {
	file_put_contents($this->rawDir . '/pages/search.twig', "<h1>Hi</h1>\n<p>{{ cms.request.getData('q') | raw }}</p>\n");
	$findings = rawCheckRun([['layer' => BuilderTemplatePaths::LAYER_DATA, 'dir' => $this->rawDir]]);

	expect($findings)->toHaveCount(1)
		->and($findings[0]->status)->toBe(FindingStatus::Found)
		->and($findings[0]->severity)->toBe(Severity::Warning)
		->and($findings[0]->detail)->toContain('pages/search.twig:2')
		->and($findings[0]->detail)->not->toContain($this->rawDir);
});

test('escaped getData is not flagged', function (): void {
	file_put_contents($this->rawDir . '/pages/ok.twig', "{{ cms.request.getData('q') }}\n");
	$findings = rawCheckRun([['layer' => BuilderTemplatePaths::LAYER_DATA, 'dir' => $this->rawDir]]);

	expect($findings)->toHaveCount(1)->and($findings[0]->status)->toBe(FindingStatus::Ok);
});

test('raw over some other variable is not flagged', function (): void {
	file_put_contents($this->rawDir . '/pages/other.twig', "{{ page.body | raw }}\n");
	$findings = rawCheckRun([['layer' => BuilderTemplatePaths::LAYER_PROJECT, 'dir' => $this->rawDir]]);

	expect($findings[0]->status)->toBe(FindingStatus::Ok);
});

test('builtin templates are not scanned', function (): void {
	file_put_contents($this->rawDir . '/pages/bad.twig', "{{ postData('x') | raw }}\n");
	$findings = rawCheckRun([['layer' => BuilderTemplatePaths::LAYER_BUILTIN, 'dir' => $this->rawDir]]);

	expect($findings[0]->status)->toBe(FindingStatus::Ok);
});
