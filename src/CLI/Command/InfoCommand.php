<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Support\Version;

class InfoCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('info')
			->setDescription('Show site status, version, and configuration');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$config = $this->totalcms->config;

		$version = Version::number();
		$build   = Version::build();

		// License info (may fail in offline mode)
		$licenseInfo = $this->getLicenseInfo();

		// Counts
		$collections     = $this->totalcms->collectionLister()->listAllCollections();
		$customSchemas   = $this->totalcms->schemaLister()->listCustomSchemas();
		$reservedSchemas = $this->totalcms->schemaLister()->listReservedSchemas();

		// Cache backends, as the admin Cache Manager reports them
		$backendStatus = $this->totalcms->container()->get(CacheReporter::class)->getBackendStatus();
		$backendStatus = array_filter($backendStatus, fn (string $status): bool => $status !== 'not_installed');

		$data = [
			'version'     => $version,
			'build'       => $build,
			'edition'     => $licenseInfo['edition'],
			'license'     => [
				'valid'              => $licenseInfo['valid'],
				'trial'              => $licenseInfo['trial'],
				'trialDaysRemaining' => $licenseInfo['trialDaysRemaining'],
			],
			'domain'      => $config->domain,
			'collections' => [
				'total' => count($collections),
			],
			'schemas'     => [
				'reserved' => count($reservedSchemas),
				'custom'   => count($customSchemas),
			],
			'cache'       => [
				'backend'  => $this->primaryBackend($backendStatus),
				'backends' => $backendStatus,
			],
		];

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getLicenseInfo(): array
	{
		try {
			$license = $this->totalcms->licenseValidator()->validateLicense();

			return [
				'valid'              => $license->valid,
				'trial'              => $license->trial,
				'edition'            => $license->edition,
				'trialDaysRemaining' => $license->trialDaysRemaining,
			];
		} catch (\Throwable) {
			return [
				'valid'              => false,
				'trial'              => false,
				'edition'            => 'unknown',
				'trialDaysRemaining' => null,
			];
		}
	}

	/**
	 * The backend a web request stores data in: the first installed one in
	 * CacheManager's order. The CLI process itself may not be able to use it
	 * (APCu is usually off for the CLI), which is why `installed` and not
	 * `active` decides.
	 *
	 * @param array<string,string> $backendStatus
	 */
	private function primaryBackend(array $backendStatus): string
	{
		foreach (['apcu', 'redis', 'memcached', 'filesystem'] as $backend) {
			if (isset($backendStatus[$backend])) {
				return $backend;
			}
		}

		return 'filesystem';
	}

	/**
	 * "APCu (not active in CLI), Redis, Filesystem, OPcache": data backends in
	 * priority order, then OPcache, which caches bytecode rather than data.
	 *
	 * @param array<string,string> $backendStatus
	 */
	private function describeBackends(array $backendStatus): string
	{
		$names = [
			'apcu'       => 'APCu',
			'redis'      => 'Redis',
			'memcached'  => 'Memcached',
			'filesystem' => 'Filesystem',
			'opcache'    => 'OPcache',
		];

		$parts = [];
		foreach ($names as $backend => $name) {
			if (!isset($backendStatus[$backend])) {
				continue;
			}
			$parts[] = $backendStatus[$backend] === 'active' ? $name : "{$name} (not active in CLI)";
		}

		return $parts === [] ? 'filesystem' : implode(', ', $parts);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$license = $data['license'];
		$status  = $license['valid'] ? ($license['trial'] ? 'Trial' : 'Valid') : 'Invalid';
		if ($license['trial'] && $license['trialDaysRemaining'] !== null) {
			$status .= " ({$license['trialDaysRemaining']} days remaining)";
		}

		$output->writeln('');
		$output->writeln("<info>Total CMS {$data['version']}</info> (build: {$data['build']})");
		$output->writeln('');

		TableHelper::renderKeyValue($output, [
			'Domain'      => $data['domain'],
			'Edition'     => ucfirst((string)$data['edition']),
			'License'     => $status,
			'Collections' => (string)$data['collections']['total'],
			'Schemas'     => "{$data['schemas']['custom']} custom, {$data['schemas']['reserved']} reserved",
			'Cache'       => $this->describeBackends($data['cache']['backends']),
		]);

		$output->writeln('');
	}
}
