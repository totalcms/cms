<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * EmailService handles email template processing and sending.
 */
readonly class EmailService
{
	private LoggerInterface $logger;

	public function __construct(
		private MailerFetcher $mailerFetcher,
		private EmailSender $emailSender,
		private TwigEngine $twigEngine,
		private Config $config,
		private EditionFeatureService $editionFeatures,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('email.log')->createLogger('email-service');
	}

	/**
	 * Send an email using a mailer template.
	 *
	 * @param string $mailerId Mailer object ID
	 * @param array<string,mixed> $data Data for Twig processing
	 * @param string|null $overrideTo Override recipient email address (for bulk testing)
	 * @param array<string,mixed> $user Current user data for Twig processing ({{ user.field }})
	 *
	 * @return array{success:bool,message:string,error?:string}
	 */
	public function sendEmail(string $mailerId, array $data = [], ?string $overrideTo = null, array $user = []): array
	{
		// Mailer actions require Standard edition or higher
		if (!$this->editionFeatures->can(EditionFeature::MAILER_ACTIONS)) {
			$this->logger->warning('Mailer action blocked by edition', [
				'mailerId' => $mailerId,
				'edition'  => $this->editionFeatures->getEdition()->value,
			]);

			return [
				'success' => false,
				'message' => 'Mailer actions require the Standard edition or higher',
			];
		}

		try {
			// Fetch mailer object
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);

			// Check if mailer is active
			if (!$mailer->active) {
				$this->logger->warning('Attempted to send email with inactive mailer', [
					'mailerId' => $mailerId,
				]);

				return [
					'success' => false,
					'message' => 'Email template is not active',
				];
			}

			// Process all Twig fields
			$twigData = ['data' => $data, 'user' => $user];

			$processedTo = $overrideTo !== null && $overrideTo !== ''
				? $overrideTo
				: $this->processTwig($mailer->to, $twigData);

			$processedHtml = $this->processTwig($mailer->bodyHtml, $twigData);
			$processedHtml = $this->processInky($processedHtml);

			$processedEmail = [
				'to'        => $processedTo,
				'toName'    => $this->processTwig($mailer->toName, $twigData),
				'from'      => $mailer->from !== '' ? $this->processTwig($mailer->from, $twigData) : '',
				'fromName'  => $mailer->fromName !== '' ? $this->processTwig($mailer->fromName, $twigData) : '',
				'replyTo'   => $mailer->replyTo !== '' ? $this->processTwig($mailer->replyTo, $twigData) : '',
				'subject'   => $this->processTwig($mailer->subject, $twigData),
				'bodyHtml'  => $processedHtml,
				'bodyText'  => $mailer->bodyText !== '' ? $this->processTwig($mailer->bodyText, $twigData) : '',
				'cc'        => $this->processEmailList($mailer->cc, $twigData),
				'bcc'       => $this->processEmailList($mailer->bcc, $twigData),
			];

			// Validate email whitelist if enabled
			$whitelistResult = $this->validateEmailWhitelist($processedEmail['to']);
			if (!$whitelistResult['success']) {
				return $whitelistResult;
			}

			// Send email
			$result = $this->emailSender->send($processedEmail);

			if ($result['success']) {
				$this->logger->info('Email sent successfully', [
					'mailerId' => $mailerId,
					'to'       => $processedEmail['to'],
					'subject'  => $processedEmail['subject'],
				]);
			}

			return $result;
		} catch (\Exception $e) {
			$this->logger->error('Email service error', [
				'mailerId' => $mailerId,
				'error'    => $e->getMessage(),
				'trace'    => $e->getTraceAsString(),
			]);

			return [
				'success' => false,
				'message' => 'Email service error',
				'error'   => $e->getMessage(),
			];
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

			// Return original template if Twig processing fails
			return $template;
		}
	}

	/**
	 * Process email list (CC/BCC) - split by newlines and process each through Twig.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<int,string>
	 */
	private function processEmailList(string $emailList, array $data): array
	{
		if ($emailList === '') {
			return [];
		}

		$emails    = array_map(trim(...), explode("\n", $emailList));
		$processed = [];

		foreach ($emails as $email) {
			if ($email !== '') {
				$processed[] = trim($this->processTwig($email, $data));
			}
		}

		return array_filter($processed);
	}

	/**
	 * Process HTML through Inky for responsive email markup.
	 * Plain HTML without Inky tags passes through unchanged.
	 */
	private function processInky(string $html): string
	{
		if ($html === '') {
			return '';
		}

		try {
			$doc = \Pinky\transformString($html);

			return (string)$doc->saveHTML();
		} catch (\Throwable $e) {
			$this->logger->warning('Inky processing failed, returning original HTML', [
				'error' => $e->getMessage(),
			]);

			return $html;
		}
	}

	/**
	 * Validate email against whitelist if enabled.
	 *
	 * @return array{success:bool,message:string}
	 */
	private function validateEmailWhitelist(string $email): array
	{
		$allowedDomains = $this->config->mailer['whitelist'] ?? [];

		// Normalize to array (config may provide a single string value)
		if (is_string($allowedDomains)) {
			$allowedDomains = $allowedDomains !== '' ? [$allowedDomains] : [];
		}

		// If whitelist is empty, it's disabled
		if ($allowedDomains === []) {
			return ['success' => true, 'message' => 'Whitelist not enabled'];
		}

		// Extract domain from email
		$emailDomain = '@' . substr((string)strrchr($email, '@'), 1);

		// Check if email domain matches any whitelisted domain
		foreach ($allowedDomains as $allowedDomain) {
			if (str_ends_with($emailDomain, (string)$allowedDomain)) {
				return ['success' => true, 'message' => 'Email domain allowed'];
			}
		}

		$this->logger->warning('Email blocked by whitelist', [
			'email'  => $email,
			'domain' => $emailDomain,
		]);

		return [
			'success' => false,
			'message' => 'Email domain not in whitelist',
		];
	}
}
