<?php

namespace TotalCMS\Support;

final class Config
{
	public string $template = '';
	public string $datadir  = '';
	public string $tmpdir   = '';
	public string $domain   = '';
	public string $api      = '';
	public string $locale   = '';
	public string $timezone = '';
	public string $notfound = '';
	public bool $debug      = false;
	/** @var array<string,mixed> */
	public array $cache = [];
	/** @var array<string,mixed> */
	public array $session = [];
	/** @var array<string,mixed> */
	public array $logger = [];
	/** @var array<string,mixed> */
	public array $sentry = [];
	/** @var array<string,mixed> */
	public array $error = [];
	/** @var array<string,mixed> */
	public array $imageworks = [];
	/** @var array<string,mixed> */
	public array $auth = [];
	/** @var array<string,mixed> */
	public array $htmlclean = [];
	/** @var array<string,mixed> */
	public array $dashboard = [];

	/** @param array<string,mixed> $settings */
	public function __construct(array $settings)
	{
		$this->template   = $settings['template'];
		$this->dashboard  = $settings['dashboard'];
		$this->datadir    = $settings['datadir'];
		$this->tmpdir     = $settings['tmpdir'];
		$this->cache      = $settings['cache'];
		$this->logger     = $settings['logger'];
		$this->sentry     = $settings['sentry'];
		$this->error      = $settings['error'];
		$this->imageworks = $settings['imageworks'];
		$this->domain     = $settings['domain'];
		$this->api        = $settings['api'];
		$this->locale     = $settings['locale'];
		$this->session    = $settings['session'];
		$this->auth       = $settings['auth'];
		$this->debug      = $settings['debug'];
		$this->notfound   = $settings['notfound'];
		$this->htmlclean  = $settings['htmlclean'] ?? [];
		$this->timezone   = $settings['timezone'] ?? date_default_timezone_get();

		date_default_timezone_set($this->timezone);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}

	public static function init(): self
	{
		return new Config(require __DIR__ . '/../../config/settings.php');
	}
}
