<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Exception\InvalidFilterException;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Every filter `filt` can name. Filled in order: site config, then core
 * (greyscale and the built-ins), then extensions. The first
 * registration of a name wins; a refused one is logged, never shown.
 */
class ImageFilterRegistry
{
	public const NAME_PATTERN = '/^[a-z0-9-]{1,48}$/';
	/**
	 * Keeps Glide's exact output and old cache files, so it cannot be redefined.
	 * sepia was here too until 3.6.10: Glide's version blew out on Imagick and
	 * crushed shadows on GD, so it became a built-in recipe.
	 */
	public const LEGACY = ['greyscale'];
	public const GROUPS = FilterRecipe::GROUPS;
	/**
	 * Folded into every `filtv`. Change it when the renderer math changes (a
	 * matrix, a lookup table, the strength blend), so files rendered by the
	 * old code are no longer served.
	 */
	public const ENGINE = '1';

	/** @var array<string,RegisteredFilter> */
	private array $filters = [];

	/** @var list<string> Vendors of every extension registering filters in this drain */
	private array $vendors = [];

	private LoggerInterface $logger;

	public function __construct(Config $config, LoggerFactory $loggerFactory)
	{
		$this->logger = $loggerFactory->channelLogger(LogChannel::ImageGenerator);

		$configured = $config->imageworks['filters'] ?? [];
		if (is_array($configured)) {
			foreach ($configured as $name => $definition) {
				$this->registerConfig((string) $name, $definition);
			}
		}

		foreach (self::LEGACY as $name) {
			$this->add(new RegisteredFilter($name, new LegacyGlideFilter($name), 'look', 'core', true, false));
		}
		foreach (BuiltInFilters::all() as $name => $definition) {
			if (isset($this->filters[$name])) {
				// Debug: the registry is built on every request that renders an image.
				$this->logger->debug(sprintf('ImageWorks filter "%s" from the site config hides the built-in of the same name', $name));
				continue;
			}
			$this->register('core', $name, $definition, $definition instanceof DuotoneFilter ? 'duotone' : 'look');
		}
	}

	public function get(string $name): ?RegisteredFilter
	{
		return $this->filters[$name] ?? null;
	}

	/**
	 * @return array<string,RegisteredFilter> in registration order
	 */
	public function all(): array
	{
		return $this->filters;
	}

	/**
	 * Every extension's filters at once, as the boot step drains them. Knowing
	 * all the vendors first lets a longer vendor keep its own prefix: `acme`
	 * cannot take `acme-labs-noir` when `acme-labs` also registers filters.
	 *
	 * @param array<string,list<array{name:string, filter:array<string,mixed>|ImageFilter, group:string}>> $byExtension
	 */
	public function registerExtensionFilters(array $byExtension): void
	{
		$this->vendors = array_values(array_unique(array_map(
			static fn (int|string $id): string => explode('/', (string) $id)[0],
			array_keys($byExtension),
		)));

		foreach ($byExtension as $extensionId => $filters) {
			foreach ($filters as $filter) {
				$this->registerExtensionFilter((string) $extensionId, $filter['name'], $filter['filter'], $filter['group']);
			}
		}
	}

	/**
	 * @param array<string,mixed>|ImageFilter $definition a recipe or a filter class
	 *
	 * @return bool False when the registration was refused (and logged)
	 */
	public function registerExtensionFilter(string $extensionId, string $name, array|ImageFilter $definition, string $group = 'look'): bool
	{
		$vendor = explode('/', $extensionId)[0];
		if (!str_starts_with($name, $vendor . '-')) {
			$this->refuse($extensionId, $name, sprintf('name must start with "%s-"', $vendor));

			return false;
		}
		foreach ($this->vendors as $other) {
			if (strlen($other) > strlen($vendor) && str_starts_with($name, $other . '-')) {
				$this->refuse($extensionId, $name, sprintf('the "%s-" prefix belongs to the "%s" vendor', $other, $other));

				return false;
			}
		}

		return $this->register($extensionId, $name, $definition, $group);
	}

	/**
	 * Cache-key fragment for a chain: the first 8 hex characters of a hash of
	 * the engine version and each active filter's name and version, in order. Null when the chain has
	 * no versioned filter (only greyscale, or unknown names).
	 *
	 * @param list<string> $names
	 */
	public function chainVersion(array $names): ?string
	{
		$pairs = [];
		foreach ($names as $name) {
			$entry = $this->filters[$name] ?? null;
			if ($entry !== null && !$entry->legacy) {
				$pairs[] = $name . ':' . $entry->filter->version();
			}
		}

		return $pairs === [] ? null : substr(sha1(implode('|', ['engine:' . self::ENGINE, ...$pairs])), 0, 8);
	}

	private function registerConfig(string $name, mixed $definition): void
	{
		if (in_array($name, self::LEGACY, true)) {
			$this->refuse('config', $name, 'greyscale keeps Glide\'s output and cannot be redefined');

			return;
		}
		if (!is_array($definition)) {
			$this->refuse('config', $name, 'a filter must be a recipe array');

			return;
		}

		$this->register('config', $name, $definition, 'look');
	}

	/**
	 * @param array<string,mixed>|ImageFilter $definition
	 */
	private function register(string $owner, string $name, array|ImageFilter $definition, string $group): bool
	{
		if (preg_match(self::NAME_PATTERN, $name) !== 1) {
			$this->refuse($owner, $name, 'name must match ' . self::NAME_PATTERN);

			return false;
		}
		if (isset($this->filters[$name])) {
			$this->refuse($owner, $name, sprintf('name already registered by "%s"', $this->filters[$name]->owner));

			return false;
		}

		if ($definition instanceof ImageFilter) {
			if (!in_array($group, self::GROUPS, true)) {
				$this->refuse($owner, $name, 'unknown group "' . $group . '"');

				return false;
			}
			$this->add(new RegisteredFilter($name, $definition, $group, $owner, false, $owner === 'core'));

			return true;
		}

		try {
			// The group argument stands in for a recipe that does not name one.
			$recipe = FilterRecipe::fromDefinition($name, $definition + ['group' => $group]);
		} catch (InvalidFilterException $e) {
			$this->refuse($owner, $name, $e->getMessage());

			return false;
		}
		$this->add(new RegisteredFilter($name, $recipe, $recipe->group(), $owner, false, true));

		return true;
	}

	private function add(RegisteredFilter $filter): void
	{
		$this->filters[$filter->name] = $filter;
	}

	private function refuse(string $owner, string $name, string $reason): void
	{
		$this->logger->warning(sprintf('ImageWorks filter "%s" from %s refused: %s', $name, $owner, $reason));
	}
}
