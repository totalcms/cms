<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Setup;

use TotalCMS\Bundled\S3Uploads\S3\S3Settings;

/**
 * The JSON an operator pastes at the storage provider, built from the saved
 * settings so there is nothing to edit by hand: the bucket name and prefix are
 * the ones the extension will really use.
 */
final class BucketSnippets
{
	/** Whether the settings point at Amazon S3 itself, the one provider that takes IAM and bucket policies. */
	public static function isAmazon(S3Settings $settings): bool
	{
		return $settings->endpoint === '' || str_ends_with((string)parse_url($settings->endpoint, PHP_URL_HOST), '.amazonaws.com');
	}

	/**
	 * The IAM policy for the user the access key belongs to: everything the
	 * extension does, and nothing outside the prefix.
	 */
	public static function keyPolicy(S3Settings $settings): string
	{
		$bucket = 'arn:aws:s3:::' . $settings->bucket;
		$list   = ['Effect' => 'Allow', 'Action' => 's3:ListBucket', 'Resource' => $bucket];
		if ($settings->prefix !== '') {
			$list['Condition'] = ['StringLike' => ['s3:prefix' => $settings->prefix . '*']];
		}

		return self::json([
			'Version'   => '2012-10-17',
			'Statement' => [
				['Effect' => 'Allow', 'Action' => ['s3:PutObject', 's3:GetObject', 's3:DeleteObject'], 'Resource' => $bucket . '/' . $settings->prefix . '*'],
				$list,
			],
		]);
	}

	/** The bucket policy that lets anyone read the files under the prefix, for Public visibility. */
	public static function publicReadPolicy(S3Settings $settings): string
	{
		return self::json([
			'Version'   => '2012-10-17',
			'Statement' => [[
				'Sid'       => 'PublicRead',
				'Effect'    => 'Allow',
				'Principal' => '*',
				'Action'    => 's3:GetObject',
				'Resource'  => 'arn:aws:s3:::' . $settings->bucket . '/' . $settings->prefix . '*',
			]],
		]);
	}

	/** The bucket CORS rules that let a browser on $origin upload and read. */
	public static function cors(string $origin): string
	{
		return self::json([[
			'AllowedOrigins' => [$origin],
			'AllowedMethods' => ['PUT', 'GET', 'HEAD'],
			'AllowedHeaders' => ['content-type'],
			'ExposeHeaders'  => ['ETag'],
			'MaxAgeSeconds'  => 3600,
		]]);
	}

	/** @param array<int|string,mixed> $value */
	private static function json(array $value): string
	{
		return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}
