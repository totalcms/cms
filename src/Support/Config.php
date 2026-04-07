<?php

namespace TotalCMS\Support;

class Config
{
	public const LICENSE_API_URL = 'https://license.totalcms.co';

	public string $env          = 'prod';
	public string $template     = '';
	public string $datadir      = '';
	public string $tmpdir       = '';
	public string $cachedir     = '';
	public string $domain       = '';
	public string $url          = '';
	public string $api          = '';
	public string $locale       = '';
	public string $timezone     = '';
	public string $notfound     = '';
	public int $maxDownloadSize = 2048;
	public bool $debug          = false;
	public bool $sentry         = true;
	/** @var array<string,mixed> */
	public array $cache = [];
	/** @var array<string,mixed> */
	public array $session = [];
	/** @var array<string,mixed> */
	public array $logger = [];
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
	/** @var array<string,mixed> */
	public array $smtp = [];
	/** @var array<string,mixed> */
	public array $mailer = [];
	/** @var array<string,mixed> */
	public array $pushnotif = [];
	/** @var array<string,mixed> */
	public array $presets = [];

	/** @param array<string,mixed> $settings */
	public function __construct(array $settings)
	{
		$this->env             = $settings['env'] ?? 'prod';
		$this->template        = $settings['template'];
		$this->dashboard       = $settings['dashboard'];
		$this->datadir         = $settings['datadir'];
		$this->tmpdir          = $settings['tmpdir'];
		$this->cachedir        = $settings['cachedir'];
		$this->cache           = $settings['cache'];
		$this->logger          = $settings['logger'];
		$this->sentry          = (bool)($settings['sentry'] ?? true);
		$this->error           = $settings['error'];
		$this->imageworks      = $settings['imageworks'];
		$this->domain          = $settings['domain'];
		$this->url             = $settings['url'];
		$this->api             = $settings['api'];
		$this->locale          = $settings['locale'];
		$this->session         = $settings['session'];
		$this->auth            = $settings['auth'];
		$this->debug           = $settings['debug'];
		$this->notfound        = $settings['notfound'];
		$this->maxDownloadSize = (int)($settings['maxDownloadSize'] ?? 2048);
		$this->htmlclean       = $settings['htmlclean'] ?? [];
		$this->smtp            = is_array($settings['smtp'] ?? []) ? $settings['smtp'] : [];
		$this->mailer          = is_array($settings['mailer'] ?? []) ? $settings['mailer'] : [];
		$pushnotif             = $settings['pushnotif'] ?? [];
		$this->pushnotif       = is_array($pushnotif) ? $pushnotif : [];
		$this->presets         = is_array($settings['presets']['presetsettings'] ?? null) ? $settings['presets']['presetsettings'] : [];
		$this->timezone        = $settings['timezone'] ?? date_default_timezone_get();

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
