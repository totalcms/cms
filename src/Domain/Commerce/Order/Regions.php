<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Whether two ways of writing a region mean the same place.
 *
 * A buyer types their state as they please: `CA`, `ca`, `California`. A tax
 * rate or a shipping zone names it once. For the United States and Canada,
 * where a state or province sets the tax, a name and its code are known to
 * be the same. Anywhere else two regions match when they are written the
 * same, whatever the capitals, spaces and dots.
 */
final class Regions
{
	/** @var array<string,array<string,string>> country => [name as compared => code as compared] */
	private const NAMES = [
		'US' => [
			'alabama' => 'al', 'alaska' => 'ak', 'arizona' => 'az', 'arkansas' => 'ar', 'california' => 'ca', 'colorado' => 'co',
			'connecticut' => 'ct', 'delaware' => 'de', 'district of columbia' => 'dc', 'florida' => 'fl', 'georgia' => 'ga',
			'hawaii' => 'hi', 'idaho' => 'id', 'illinois' => 'il', 'indiana' => 'in', 'iowa' => 'ia', 'kansas' => 'ks',
			'kentucky' => 'ky', 'louisiana' => 'la', 'maine' => 'me', 'maryland' => 'md', 'massachusetts' => 'ma',
			'michigan' => 'mi', 'minnesota' => 'mn', 'mississippi' => 'ms', 'missouri' => 'mo', 'montana' => 'mt',
			'nebraska' => 'ne', 'nevada' => 'nv', 'new hampshire' => 'nh', 'new jersey' => 'nj', 'new mexico' => 'nm',
			'new york' => 'ny', 'north carolina' => 'nc', 'north dakota' => 'nd', 'ohio' => 'oh', 'oklahoma' => 'ok',
			'oregon' => 'or', 'pennsylvania' => 'pa', 'rhode island' => 'ri', 'south carolina' => 'sc', 'south dakota' => 'sd',
			'tennessee' => 'tn', 'texas' => 'tx', 'utah' => 'ut', 'vermont' => 'vt', 'virginia' => 'va', 'washington' => 'wa',
			'west virginia' => 'wv', 'wisconsin' => 'wi', 'wyoming' => 'wy', 'puerto rico' => 'pr', 'guam' => 'gu',
			'american samoa' => 'as', 'northern mariana islands' => 'mp', 'us virgin islands' => 'vi',
		],
		'CA' => [
			'alberta' => 'ab', 'british columbia' => 'bc', 'manitoba' => 'mb', 'new brunswick' => 'nb',
			'newfoundland and labrador' => 'nl', 'northwest territories' => 'nt', 'nova scotia' => 'ns', 'nunavut' => 'nu',
			'ontario' => 'on', 'prince edward island' => 'pe', 'quebec' => 'qc', 'québec' => 'qc', 'saskatchewan' => 'sk',
			'yukon' => 'yt',
		],
	];

	/** Whether $a and $b name the same region of $country. An empty region is no region, and matches nothing. */
	public static function same(string $country, string $a, string $b): bool
	{
		$a = self::comparable($country, $a);

		return $a !== '' && $a === self::comparable($country, $b);
	}

	/** A region as it is compared: lower case, without dots or stray spaces, and a known name as its code. */
	private static function comparable(string $country, string $region): string
	{
		$plain = (string)preg_replace('/\s+/', ' ', trim(str_replace('.', '', mb_strtolower($region))));

		return self::NAMES[strtoupper(trim($country))][$plain] ?? $plain;
	}
}
