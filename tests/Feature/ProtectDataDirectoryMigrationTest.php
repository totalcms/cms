<?php

declare(strict_types=1);

use TotalCMS\Domain\Migration\Migration\ProtectDataDirectoryMigration;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Support\Config;

/**
 * Installs whose tcms-data sits in the docroot without a .htaccess — a 3.6.4
 * Composer install, or any install whose folder a request created before the
 * wizard — served every object and .system/ (API keys among them) to anyone.
 * The migration gives the folder its .htaccess, and leaves an operator's own.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();
	$this->htaccess  = rtrim($this->container->get(Config::class)->datadir, '/') . '/.htaccess';
	@unlink($this->htaccess);
});

it('adds the .htaccess to a data folder that has none', function (): void {
	$migration = $this->container->get(ProtectDataDirectoryMigration::class);

	expect($migration->run())->toBe(1)
		->and((string)file_get_contents($this->htaccess))->toContain('Require all denied', 'Deny from all');
});

it('leaves an existing .htaccess alone', function (): void {
	file_put_contents($this->htaccess, "# operator rules\nRequire all denied\n");

	expect($this->container->get(ProtectDataDirectoryMigration::class)->run())->toBe(0)
		->and((string)file_get_contents($this->htaccess))->toBe("# operator rules\nRequire all denied\n");
});

it('runs with the other migrations', function (): void {
	$this->container->get(MigrationRunner::class)->runPending();

	expect(file_exists($this->htaccess))->toBeTrue();
});
