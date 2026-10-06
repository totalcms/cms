<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use TotalCMS\Support\SiteOrigin;

beforeEach(function (): void {
	$this->file = sys_get_temp_dir() . '/tcms-siteorigin-' . uniqid() . '/.siteurl';
	mkdir(dirname($this->file));
});

afterEach(function (): void {
	@unlink($this->file);
	@rmdir(dirname($this->file));
});

it('recalls the domain and scheme a web request remembered', function (): void {
	SiteOrigin::remember($this->file, 'totalcms.co', true);

	expect(SiteOrigin::recall($this->file))->toBe(['domain' => 'totalcms.co', 'https' => true]);
});

it('keeps the port with the domain', function (): void {
	SiteOrigin::remember($this->file, 'totalcms.test:8080', false);

	expect(SiteOrigin::recall($this->file))->toBe(['domain' => 'totalcms.test:8080', 'https' => false]);
});

it('does not remember an unknown or non-routable host', function (): void {
	SiteOrigin::remember($this->file, 'unknown', true);
	SiteOrigin::remember($this->file, '', true);
	SiteOrigin::remember($this->file, '127.0.0.1', true);
	SiteOrigin::remember($this->file, '172.17.0.2:8080', false);

	expect(file_exists($this->file))->toBeFalse();
	expect(SiteOrigin::recall($this->file))->toBeNull();
});

it('follows the site to a new domain', function (): void {
	SiteOrigin::remember($this->file, 'staging.example.com', true);
	SiteOrigin::remember($this->file, 'example.com', true);

	expect(SiteOrigin::recall($this->file)['domain'])->toBe('example.com');
});

it('ignores a file it cannot parse', function (): void {
	file_put_contents($this->file, 'not a url');

	expect(SiteOrigin::recall($this->file))->toBeNull();
});
