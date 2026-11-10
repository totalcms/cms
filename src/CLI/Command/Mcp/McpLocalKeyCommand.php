<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Mcp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyDeleter;

/**
 * Creates the admin API key that AI tools on this computer use to reach the
 * site's MCP server. Total CMS Studio runs it and writes the key into the
 * project's `.mcp.json` and friends.
 *
 * The key is local-only: it authenticates only on a server that accepts
 * local-only keys (Studio's sets TCMS_LOCAL_KEYS=1), for a request from this
 * computer ({@see \TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator::isLocalRequest()}).
 * The project folder, `tcms-data/` and all, is what gets uploaded to the live
 * site; there the key must not work.
 *
 * Running it again replaces the local-only key of the same name, so a tool
 * that reconnects doesn't pile up keys. Keys made in the admin are never
 * touched, whatever their name.
 */
class McpLocalKeyCommand extends BaseCommand
{
	private const DEFAULT_NAME = 'Local AI tools';

	private const EVERY_METHOD = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('mcp:local-key')
			->setDescription('Create an admin API key for AI tools that works only from this computer, on Total CMS Studio')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name for the key; an earlier local-only key with this name is replaced', self::DEFAULT_NAME);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$name = trim((string)$input->getOption('name'));
		$name = $name !== '' ? $name : self::DEFAULT_NAME;

		$container = $this->totalcms->container();
		$deleter   = $container->get(ApiKeyDeleter::class);
		foreach ($container->get(ApiKeyRepository::class)->getAll() as $existing) {
			if ($existing->localOnly && $existing->name === $name) {
				$deleter->deleteKey($existing->id);
			}
		}

		$key = $container->get(ApiKeyCreator::class)
			->createApiKey($name, ['methods' => self::EVERY_METHOD, 'paths' => ['*']], localOnly: true);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['key' => $key->key, 'id' => $key->id, 'name' => $key->name], JSON_UNESCAPED_SLASHES));
		} else {
			$this->renderHumanKey($output, $key);
		}

		return Command::SUCCESS;
	}

	private function renderHumanKey(OutputInterface $output, ApiKeyData $key): void
	{
		$output->writeln($key->key);
		$output->writeln('This admin key works only from this computer, on a server that accepts local-only keys (Total CMS Studio\'s does). Send it as the X-API-Key header to the site\'s /mcp endpoint.');
	}
}
