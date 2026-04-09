<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
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

		// Cache backend
		$cacheBackend = $this->detectCacheBackend();

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
				'backend' => $cacheBackend,
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

	private function detectCacheBackend(): string
	{
		$cache = $this->totalcms->config->cache;

		if (!empty($cache['apcu']['enabled'])) {
			return 'apcu';
		}
		if (!empty($cache['redis']['enabled'])) {
			return 'redis';
		}
		if (!empty($cache['memcached']['enabled'])) {
			return 'memcached';
		}

		return 'filesystem';
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
			'Edition'     => ucfirst($data['edition']),
			'License'     => $status,
			'Collections' => (string)$data['collections']['total'],
			'Schemas'     => "{$data['schemas']['custom']} custom, {$data['schemas']['reserved']} reserved",
			'Cache'       => $data['cache']['backend'],
		]);

		$output->writeln('');
	}
}
