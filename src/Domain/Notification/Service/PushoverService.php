<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Notification\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * PushoverService handles sending push notifications via the Pushover API.
 */
readonly class PushoverService
{
	private const MAX_TITLE_LENGTH      = 250;
	private const MAX_MESSAGE_LENGTH    = 1024;
	private const MAX_URL_LENGTH        = 512;
	private const MAX_URL_TITLE_LENGTH  = 100;
	private const MAX_ATTACHMENT_BYTES  = 5242880;

	private LoggerInterface $logger;

	public function __construct(
		private TwigEngine $twigEngine,
		private Config $config,
		private EditionFeatureService $editionFeatures,
		private ImageGenerator $imageGenerator,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('pushover.log')->createLogger('pushover-service');
	}

	/**
	 * Send a push notification via Pushover.
	 *
	 * @param string $title Notification title (supports Twig)
	 * @param string $message Notification message (supports Twig)
	 * @param array<string,mixed> $data Form data for Twig processing ({{ data.field }})
	 * @param array<string,mixed> $user Current user data for Twig processing ({{ user.field }})
	 * @param int $priority Message priority (-2 to 2)
	 * @param string $sound Notification sound name
	 * @param string $link Optional supplementary URL
	 * @param string $linkTitle Optional URL title
	 * @param array<string,string> $image Optional image attachment config (collection, id, property, name)
	 * @param bool $group Send to group key instead of user key
	 *
	 * @return array{success:bool,message:string,error?:string}
	 */
	public function send(
		string $message,
		array $data      = [],
		array $user      = [],
		int $priority  = 0,
		string $title     = '',
		string $sound     = '',
		string $link      = '',
		string $linkTitle = '',
		array $image     = [],
		bool $group      = false,
	): array {
		if (!$this->editionFeatures->can(EditionFeature::PUSHOVER_ACTIONS)) {
			$this->logger->warning('Pushover action blocked by edition', [
				'edition' => $this->editionFeatures->getEdition()->value,
			]);

			return [
				'success' => false,
				'message' => 'Pushover actions require the Pro edition',
			];
		}

		$appToken  = $this->config->pushnotif['pushoverAppToken'] ?? '';
		$userKey   = $this->config->pushnotif['pushoverUserKey'] ?? '';
		$groupKey  = $this->config->pushnotif['pushoverGroupKey'] ?? '';
		$recipient = $group && $groupKey !== '' ? $groupKey : $userKey;

		if ($appToken === '' || $recipient === '') {
			$this->logger->warning('Pushover not configured', [
				'hasToken' => $appToken !== '',
				'hasUser'  => $userKey !== '',
				'hasGroup' => $groupKey !== '',
				'useGroup' => $group,
			]);

			$missing = $group && $groupKey === '' ? 'Group Key' : 'User Key';

			return [
				'success' => false,
				'message' => "Pushover is not configured. Please set your Application Token and {$missing} in Settings.",
			];
		}

		try {
			$twigData = ['data' => $data, 'user' => $user];

			$processedTitle     = $this->processTwig($title, $twigData);
			$processedMessage   = $this->processTwig($message, $twigData);
			$processedLink      = $link !== '' ? $this->processTwig($link, $twigData) : '';
			$processedLinkTitle = $linkTitle !== '' ? $this->processTwig($linkTitle, $twigData) : '';

			$postData = [
				'token'   => $appToken,
				'user'    => $recipient,
				'message' => mb_substr($processedMessage, 0, self::MAX_MESSAGE_LENGTH),
			];

			if ($priority >= -2 && $priority <= 2 && $priority !== 0) {
				$postData['priority'] = $priority;

				if ($priority === 2) {
					$postData['retry']  = 60;
					$postData['expire'] = 3600;
				}
			}

			if ($title !== '') {
				$postData['title'] = mb_substr($processedTitle, 0, self::MAX_TITLE_LENGTH);
			}

			if ($sound !== '') {
				$postData['sound'] = $sound;
			}

			if ($processedLink !== '') {
				$postData['url'] = mb_substr($processedLink, 0, self::MAX_URL_LENGTH);
			}

			if ($processedLinkTitle !== '') {
				$postData['url_title'] = mb_substr($processedLinkTitle, 0, self::MAX_URL_TITLE_LENGTH);
			}

			$postData['html'] = 1;

			$attachment = $this->generateAttachment($image, $twigData);

			$result = $this->sendRequest($postData, $attachment);

			if ($result['success']) {
				$this->logger->info('Pushover notification sent', [
					'title' => $processedTitle,
				]);
			}

			return $result;
		} catch (\Exception $e) {
			$this->logger->error('Pushover service error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return [
				'success' => false,
				'message' => 'Pushover service error',
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Send the HTTP request to Pushover API.
	 *
	 * @param array<string,mixed> $postData
	 *
	 * @return array{success:bool,message:string}
	 */
	private function sendRequest(array $postData, ?string $attachment = null): array
	{
		try {
			$client  = new Client(['timeout' => 10, 'connect_timeout' => 5]);
			$options = [];

			if ($attachment !== null) {
				$multipart = [];
				foreach ($postData as $key => $value) {
					$multipart[] = ['name' => $key, 'contents' => (string)$value];
				}
				$multipart[] = [
					'name'     => 'attachment',
					'contents' => $attachment,
					'filename' => 'image.jpg',
					'headers'  => ['Content-Type' => 'image/jpeg'],
				];
				$options['multipart'] = $multipart;
			} else {
				$options['form_params'] = $postData;
			}

			$response = $client->post('https://api.pushover.net/1/messages.json', $options);

			$body = json_decode($response->getBody()->getContents(), true);

			if (isset($body['status']) && $body['status'] === 1) {
				return [
					'success' => true,
					'message' => 'Notification sent',
				];
			}

			$errors = $body['errors'] ?? ['Unknown error'];
			$this->logger->warning('Pushover API error', ['errors' => $errors]);

			return [
				'success' => false,
				'message' => 'Pushover error: ' . implode(', ', is_array($errors) ? $errors : [$errors]),
			];
		} catch (GuzzleException $e) {
			$this->logger->error('Pushover API connection error', ['error' => $e->getMessage()]);

			return [
				'success' => false,
				'message' => 'Failed to connect to Pushover API',
			];
		}
	}

	/**
	 * Generate image attachment bytes from an image config.
	 *
	 * @param array<string,string> $image Image config with collection, id, property, and optional name
	 * @param array<string,mixed> $twigData Twig context for processing image.id
	 */
	private function generateAttachment(array $image, array $twigData): ?string
	{
		if ($image === []) {
			return null;
		}

		$collection = $this->processTwig($image['collection'] ?? '', $twigData);
		$id         = $this->processTwig($image['id'] ?? '', $twigData);
		$property   = $this->processTwig($image['property'] ?? '', $twigData);
		$name       = $this->processTwig($image['name'] ?? '', $twigData);

		if ($collection === '' || $id === '' || $property === '') {
			$this->logger->warning('Pushover image config incomplete', ['image' => $image]);

			return null;
		}

		$params = ['w' => 1920, 'h' => 1920, 'fm' => 'jpg'];

		try {
			if ($name !== '') {
				$response = $this->imageGenerator->generateGalleryImage($collection, $id, $property, $name, $params);
			} else {
				$response = $this->imageGenerator->generateImage($collection, $id, $property, $params);
			}

			$bytes = $response->getBody()->getContents();

			if (strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
				$this->logger->warning('Pushover image exceeds 5MB limit', [
					'size'       => strlen($bytes),
					'collection' => $collection,
					'id'         => $id,
					'property'   => $property,
				]);

				return null;
			}

			return $bytes;
		} catch (\Exception $e) {
			$this->logger->warning('Pushover image generation failed', [
				'error'      => $e->getMessage(),
				'collection' => $collection,
				'id'         => $id,
				'property'   => $property,
			]);

			return null;
		}
	}

	/**
	 * Process a string through Twig.
	 *
	 * @param array<string,mixed> $data
	 */
	private function processTwig(string $template, array $data): string
	{
		if ($template === '') {
			return '';
		}

		try {
			return $this->twigEngine->renderString($template, $data);
		} catch (\Exception $e) {
			$this->logger->error('Twig processing error', [
				'template' => $template,
				'error'    => $e->getMessage(),
			]);

			return $template;
		}
	}
}
