<?php

namespace TotalCMS\Domain\Twig\Extension;

/**
 * Twig Adapter with Total CMS.
 *
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
class TotalCMSTwigPatterns
{
	public string $alphaNumeric             = '[a-zA-Z0-9]+';
	public string $notBlank                 = '\S+';
	public string $passwordUpperLowerNumber = '(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?!.*\s).*';
	public string $date                     = '\d{4}-\d{2}-\d{2}';
	public string $time                     = '\d{2}:\d{2}(:\d{2})?';
	public string $dateTime                 = '\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?';
	public string $integer                  = '-?\d+';
	public string $decimal                  = '-?\d+(\.\d+)?';
	public string $hex                      = '#?([a-f0-9]{6}|[a-f0-9]{3})';
	public string $ipv4                     = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
	public string $ipv6                     = '([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}';
	public string $domain                   = '([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,6}';
	public string $slug                     = '[a-z0-9-]+';
	public string $uuid                     = '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}';
	public string $macAddress               = '([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})';
	public string $isbn                     = '(97(8|9))?\d{9}(\d|X)';
	public string $currency                 = '\d+([\.\,]\d{1,2})?';
	public string $latitudeLongitude        = '(\-?\d+(\.\d+)?),\s*(\-?\d+(\.\d+)?)';
	public string $html                     = '<([a-z]+)([^<]+)*(?:>(.*)<\/\1>|\s+/>)';

	/** @var array<string> */
	public array $postCode = [
		'australia'   => '[0-9]{4}',
		'austria'     => '[0-9]{4}',
		'belgium'     => '[0-9]{4}',
		'brazil'      => '[0-9]{5}[\-]?[0-9]{3}',
		'canada'      => '[A-Za-z]\d[A-Za-z] \d[A-Za-z]\d',
		'germany'     => '[0-9]{5}',
		'hungary'     => '[0-9]{4}',
		'italy'       => '[0-9]{5}',
		'japan'       => '\d{3}-\d{4}',
		'luxembourg'  => '(L\s*(-|—|–))\s*?[\d]{4}',
		'netherlands' => '[1-9][0-9]{3}\s?[a-zA-Z]{2}',
		'poland'      => '[0-9]{2}\-[0-9]{3}',
		'spain'       => '\d{3}\s?\d{2}',
		'sweden'      => '\d{3}\s?\d{2}',
		'uk'          => '[A-Za-z]{1,2}[0-9Rr][0-9A-Za-z]? [0-9][ABD-HJLNP-UW-Zabd-hjlnp-uw-z]{2}',
		'usa'         => '\d{5}(-\d{4})?',
	];

	/** @var array<string> */
	public array $phone = [
		'usa'           => '\d{3}[\-]\d{3}[\-]\d{4}',
		'uk'            => '\s*\(?(020[7,8]{1}\)?[ ]?[1-9]{1}[0-9{2}[ ]?[0-9]{4})|(0[1-8]{1}[0-9]{3}\)?[ ]?[1-9]{1}[0-9]{2}[ ]?[0-9]{3})\s*',
		'france'        => '(?:0|\(?\+33\)?\s?|0033\s?)[1-79](?:[\.\-\s]?\d\d){4}',
		'international' => '[\+]\d{2}[\(]\d{2}[\)]\d{4}[\-]\d{4}', // +99(99)9999-9999
	];

	public function passwordMinLength(int $minLength = 8): string
	{
		return sprintf('(?=^.{%d,}$)((?=.*\d)|(?=.*\W+))(?![.\n])(?=.*[A-Z])(?=.*[a-z]).*', $minLength);
	}
}
