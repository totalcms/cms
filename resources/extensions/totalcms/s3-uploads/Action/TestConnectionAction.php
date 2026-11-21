<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Setup\BucketSnippets;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET|POST /admin/ext/totalcms/s3-uploads/test (super admins)
 *
 * The Bucket Setup page. POST lists the first key under the prefix, which
 * proves the endpoint, region, bucket and both keys in one request. The page
 * also prints what to paste at the provider, built from the saved settings:
 * the access key's policy, the bucket's CORS rules and, for a public bucket,
 * its read policy. CORS itself cannot be checked from here: only a browser is
 * subject to it.
 */
final readonly class TestConnectionAction
{
	public function __construct(
		private S3Client $client,
		private S3Settings $settings,
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$data = [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'configured' => $this->settings->isConfigured(),
			'bucket'     => $this->settings->bucket,
			'prefix'     => $this->settings->prefix,
			'visibility' => $this->settings->visibility,
			'cdn'        => $this->settings->publicBaseUrl !== '',
			'amazon'     => BucketSnippets::isAmazon($this->settings),
			'cors'       => BucketSnippets::cors(self::origin($request)),
			// A policy with no bucket in it is not worth copying.
			'keyPolicy'    => $this->settings->bucket === '' ? null : BucketSnippets::keyPolicy($this->settings),
			'publicPolicy' => $this->settings->bucket === '' ? null : BucketSnippets::publicReadPolicy($this->settings),
		];

		if ($request->getMethod() === 'POST' && $this->settings->isConfigured()) {
			$data['result'] = $this->check();
		}

		return $this->twigRenderer->template($response, '@totalcms-s3-uploads/connection-test.twig', $data);
	}

	/** @return array{success:bool, message:string} */
	public function check(): array
	{
		try {
			// The first page is one request; the listing is not walked any further.
			$this->client->listObjects($this->settings->prefix, 1)->current();
		} catch (S3Exception $e) {
			return ['success' => false, 'message' => trim("The bucket refused the request ({$e->s3Code}). " . $e->hint($this->settings))];
		}

		$where = $this->settings->prefix === '' ? 'the whole bucket' : $this->settings->prefix;

		return ['success' => true, 'message' => "Connected to {$this->settings->bucket}. Listing {$where} works."];
	}

	/** scheme://host[:port] of the admin the request came to. */
	private static function origin(ServerRequestInterface $request): string
	{
		$uri  = $request->getUri();
		$port = $uri->getPort();

		return $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port);
	}
}
