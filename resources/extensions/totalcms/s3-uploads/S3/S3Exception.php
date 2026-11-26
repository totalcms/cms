<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\S3;

/**
 * The bucket refused a request, or could not be reached.
 *
 * `$s3Code` is the provider's error code (`AccessDenied`, `NoSuchBucket`, …),
 * or `NetworkError` when no answer came back. hint() turns the common ones
 * into what the operator should change.
 */
final class S3Exception extends \RuntimeException
{
	/** @param string $bucketRegion the region the provider says the bucket is in, when it said so */
	public function __construct(
		public readonly string $s3Code,
		public readonly int $status,
		string $message,
		public readonly string $bucketRegion = '',
	) {
		parent::__construct($message);
	}

	/** What to fix, for the codes a wrong setting causes; '' for anything else. */
	public function hint(S3Settings $settings): string
	{
		return match ($this->s3Code) {
			'SignatureDoesNotMatch'                             => 'Check the secret key.',
			'InvalidAccessKeyId'                                => 'Check the access key.',
			'AccessDenied'                                      => 'The key lacks permission for this bucket.',
			'NoSuchBucket'                                      => 'Check the bucket name.',
			'PermanentRedirect', 'AuthorizationHeaderMalformed' => $this->bucketRegion !== ''
				? "The bucket is in {$this->bucketRegion}; set Region to that."
				: 'Check the region.',
			'RequestTimeTooSkewed'  => 'The server clock is off; fix its time.',
			'NetworkError'          => 'Could not reach ' . $settings->bucketBaseUrl() . '.',
			default                 => '',
		};
	}
}
