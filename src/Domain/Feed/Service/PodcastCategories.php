<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

/**
 * Apple Podcasts category taxonomy.
 *
 * Apple rejects a feed whose category is not on this list, and it validates
 * the pair, not just the words — "Technology > Entrepreneurship" is not a
 * category even though both halves exist. Keeping the list here lets the
 * writer refuse at authoring time with a suggestion, rather than leaving the
 * author to discover it in Apple's validator.
 *
 * Source: https://podcasters.apple.com/support/1691-apple-podcasts-categories
 * Verified against that page on 2026-09-06 (19 parents, 91 children, 110 entries). Update
 * by hand when Apple changes it; the taxonomy test guards the shape.
 */
final class PodcastCategories
{
	/** @var array<string, list<string>> parent => children */
	public const TAXONOMY = [
		'Arts'                    => ['Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts'],
		'Business'                => ['Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit'],
		'Comedy'                  => ['Comedy Interviews', 'Improv', 'Stand-Up'],
		'Education'               => ['Courses', 'How To', 'Language Learning', 'Self-Improvement'],
		'Fiction'                 => ['Comedy Fiction', 'Drama', 'Science Fiction'],
		'Government'              => [],
		'History'                 => [],
		'Health & Fitness'        => ['Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality'],
		'Kids & Family'           => ['Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids'],
		'Leisure'                 => ['Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games'],
		'Music'                   => ['Music Commentary', 'Music History', 'Music Interviews'],
		'News'                    => ['Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News'],
		'Religion & Spirituality' => ['Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality'],
		'Science'                 => ['Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences'],
		'Society & Culture'       => ['Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships'],
		'Sports'                  => ['Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'American Football', 'Golf', 'Hockey', 'Rugby', 'Running', 'Football (Soccer)', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling'],
		'Technology'              => [],
		'True Crime'              => [],
		'TV & Film'               => ['After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews'],
	];

	/**
	 * Resolve user input to a canonical category string, or null.
	 * Accepts "Parent" or "Parent > Child" in any case and spacing.
	 */
	public static function canonical(string $input): ?string
	{
		[$parent, $child] = self::split($input);

		$canonicalParent = self::match($parent, array_keys(self::TAXONOMY));
		if ($canonicalParent === null) {
			return null;
		}
		if ($child === null) {
			return $canonicalParent;
		}

		$canonicalChild = self::match($child, self::TAXONOMY[$canonicalParent]);

		return $canonicalChild === null ? null : $canonicalParent . ' > ' . $canonicalChild;
	}

	/**
	 * Closest known categories to a miss, for the error message.
	 *
	 * @return list<string>
	 */
	public static function suggest(string $input, int $max = 3): array
	{
		[$parent, $child] = self::split($input);
		$needle           = strtolower($child ?? $parent);

		// A name that contains the input outranks an edit-distance match, so
		// "soccer" finds "Sports > Football (Soccer)" and "football" finds both
		// football categories before anything merely similar.
		$score = static function (string $name) use ($needle): int {
			$name = strtolower($name);

			return str_contains($name, $needle) ? 0 : 1 + levenshtein($needle, $name);
		};

		$scored = [];
		foreach (self::TAXONOMY as $p => $children) {
			$scored[$p] = $score($p);
			foreach ($children as $c) {
				$scored[$p . ' > ' . $c] = $score($c);
			}
		}
		asort($scored);

		return array_slice(array_keys($scored), 0, $max);
	}

	/** @return array{0: string, 1: ?string} */
	private static function split(string $input): array
	{
		$parts = array_map(trim(...), explode('>', $input, 2));

		return [$parts[0], $parts[1] ?? null];
	}

	/** @param list<string> $options */
	private static function match(string $value, array $options): ?string
	{
		$needle = strtolower(trim($value));
		foreach ($options as $option) {
			if (strtolower($option) === $needle) {
				return $option;
			}
		}

		return null;
	}
}
