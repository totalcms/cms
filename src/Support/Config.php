<?php

namespace TotalCMS\Support;

final class Config
{
	public string $template  = '';
	public string $datadir   = '';
	public string $cachedir  = '';
	public string $tmpdir    = '';
	public string $domain    = '';
	public string $api       = '';
	public string $locale    = '';
	/** @var array<string,mixed> */
	public array $logger     = [];
	/** @var array<string,mixed> */
	public array $sentry     = [];
	/** @var array<string,mixed> */
	public array $error      = [];
	/** @var array<string,mixed> */
	public array $imageworks = [];

	/** @param array<string,mixed> $settings */
	public function __construct(array $settings)
	{
		$this->template   = $settings['template'];
		$this->datadir    = $settings['datadir'];
		$this->tmpdir     = $settings['tmpdir'];
		$this->cachedir   = $settings['cachedir'];
		$this->logger     = $settings['logger'];
		$this->sentry     = $settings['sentry'];
		$this->error      = $settings['error'];
		$this->imageworks = $settings['imageworks'];
		$this->domain     = $settings['domain'];
		$this->api        = $settings['api'];
		$this->locale     = $settings['locale'];
	}
}
