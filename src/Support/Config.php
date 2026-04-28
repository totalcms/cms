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
	public array $presets  = [];
	public string $docroot = '';
	/** @var array<string,mixed> */
	public array $builder = [];

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,mixed> $settings
	 */
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
		$this->timezone        = $settings['timezone'] ?? date_default_timezone_get();
		$this->docroot         = $settings['docroot'] ?? $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->htmlclean       = is_array($settings['htmlclean'] ?? null) ? $settings['htmlclean'] : [];
		$this->smtp            = is_array($settings['smtp'] ?? null) ? $settings['smtp'] : [];
		$this->mailer          = is_array($settings['mailer'] ?? null) ? $settings['mailer'] : [];
		$this->pushnotif       = is_array($settings['pushnotif'] ?? null) ? $settings['pushnotif'] : [];
		$this->builder         = is_array($settings['builder'] ?? null) ? $settings['builder'] : [];

		$presets               = $settings['presets'] ?? [];
		$this->presets         = is_array($presets['presetsettings'] ?? null) ? $presets['presetsettings'] : [];

		date_default_timezone_set($this->timezone);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}

	public static function init(): self
	{
		return new Config(require PathResolver::packageRoot() . '/config/settings.php');
	}
}
