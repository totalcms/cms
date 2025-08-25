<?php

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Media\Generator\QRGenerator;

/**
 * Twig Adapter with QR Code Generation.
 */
readonly class QRCodeTwigAdapter
{
	public function __construct(private QRGenerator $generator)
	{
	}

	public function url(string $url): string
	{
		return $this->generator->url($url);
	}

	public function text(string $text): string
	{
		return $this->generator->text($text);
	}

	public function tel(string $tel): string
	{
		return $this->generator->tel($tel);
	}

	public function gps(string $latitude, string $longitude): string
	{
		return $this->generator->gps($latitude, $longitude);
	}

	public function sms(string $telephone, string $message): string
	{
		return $this->generator->sms($telephone, $message);
	}

	public function wifi(string $auth, string $ssid, string $password, string $hidden): string
	{
		return $this->generator->wifi($auth, $ssid, $password, $hidden);
	}

	public function mailto(string $email, string $subject = '', string $body = ''): string
	{
		return $this->generator->mailto($email, $subject, $body);
	}

	/** @param array<string,string> $data */
	public function event(array $data): string
	{
		return $this->generator->event($data);
	}

	/** @param array<string,string> $data */
	public function vcf(array $data): string
	{
		return $this->generator->vcf($data);
	}
}
