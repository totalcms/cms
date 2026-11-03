<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Activity\Service\MailActivity;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

use function Pinky\transformString;

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
		private MailActivity $activity,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Email);
	}

	/**
	 * Send an email using a mailer template.
	 *
	 * @param string $mailerId Mailer object ID
	 * @param array<string,mixed> $data Data for Twig processing
	 * @param string|null $overrideTo Override recipient email address (for bulk testing)
	 * @param array<string,mixed> $user Current user data for Twig processing ({{ user.field }})
	 * @param bool $recordActivity false for a bulk send, whose Send History records each email
	 * @param array<string,mixed> $vars extra Twig variables beside data and user (Bulk Send's unsubscribeUrl)
	 * @param array<string,string> $headers extra mail headers (see MailHeaders)
	 */
	public function sendEmail(string $mailerId, array $data = [], ?string $overrideTo = null, array $user = [], bool $recordActivity = true, array $vars = [], array $headers = []): OperationResult
	{
		$email  = [];
		$result = $this->deliver($mailerId, ['data' => $data, 'user' => $user, 'vars' => $vars, 'headers' => $headers], $overrideTo, $email);
		if ($recordActivity) {
			$this->activity->sent($email, $result, $mailerId);
		}

		return $result;
	}

	/**
	 * Send one message of a rendered bulk send: render the message's Twig
	 * source with $vars (the recipient as `contact`, plus the caller's
	 * variables) and send it. Unlike a template send, a Twig error fails the
	 * send instead of mailing the raw source.
	 *
	 * @param array<string,mixed>  $vars
	 * @param array<string,string> $headers extra mail headers (see MailHeaders)
	 */
	public function sendRendered(RenderedBulkMessage $message, array $vars, ?string $overrideTo = null, array $headers = []): OperationResult
	{
		$refused = $this->editionRefusal($message->sourceId);
		if ($refused instanceof OperationResult) {
			return $refused;
		}

		try {
			$render = fn (string $template): string => $template === '' ? '' : $this->twigEngine->renderString($template, $vars);
			$email  = [
				'to'       => $overrideTo !== null && $overrideTo !== '' ? $overrideTo : trim($render($message->toTemplate)),
				'toName'   => trim($render($message->toNameTemplate)),
				'from'     => trim($render($message->from)),
				'fromName' => trim($render($message->fromName)),
				'replyTo'  => trim($render($message->replyTo)),
				'subject'  => trim($render($message->subjectTemplate)),
				'bodyHtml' => $this->processInky($render($message->bodyHtmlTemplate)),
				'bodyText' => $render($message->bodyTextTemplate),
				'cc'       => [],
				'bcc'      => [],
				'headers'  => $headers,
			];
		} catch (\Throwable $e) {
			$this->logger->error('Rendered bulk message failed to render', ['sourceId' => $message->sourceId, 'error' => $e->getMessage()]);

			return OperationResult::failure('Template error', $e->getMessage());
		}

		return $this->transmit($email, $message->sourceId);
	}

	/**
	 * Build the email from the template and send it. $email is the message as
	 * sent, or stays [] when it is refused before it is built.
	 *
	 * @param array{data: array<string,mixed>, user: array<string,mixed>, vars: array<string,mixed>, headers: array<string,string>} $input
	 * @param array<string,mixed> $email
	 */
	private function deliver(string $mailerId, array $input, ?string $overrideTo, array &$email): OperationResult
	{
		$refused = $this->editionRefusal($mailerId);
		if ($refused instanceof OperationResult) {
			return $refused;
		}

		try {
			// Fetch mailer object
			$mailer = $this->mailerFetcher->fetchMailer($mailerId);

			// Check if mailer is active
			if (!$mailer->active) {
				$this->logger->warning('Attempted to send email with inactive mailer', [
					'mailerId' => $mailerId,
				]);

				return OperationResult::failure('Email template is not active');
			}

			// Process all Twig fields
			// Extra variables (Bulk Send's unsubscribeUrl) never replace data or user.
			$twigData = array_merge($input['vars'], ['data' => $input['data'], 'user' => $input['user']]);

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
				'headers'   => $input['headers'],
			];

			$email = $processedEmail;

			return $this->transmit($processedEmail, $mailerId);
		} catch (\Exception $e) {
			$this->logger->error('Email service error', [
				'mailerId' => $mailerId,
				'error'    => $e->getMessage(),
				'trace'    => $e->getTraceAsString(),
			]);

			return OperationResult::failure('Email service error', $e->getMessage());
		}
	}

	/**
	 * Mailer sends need the Standard edition or higher.
	 */
	private function editionRefusal(string $source): ?OperationResult
	{
		if ($this->editionFeatures->can(EditionFeature::MAILER_ACTIONS)) {
			return null;
		}

		$this->logger->warning('Mailer action blocked by edition', [
			'mailerId' => $source,
			'edition'  => $this->editionFeatures->getEdition()->value,
		]);

		return OperationResult::failure('Mailer actions require the Standard edition or higher');
	}

	/**
	 * Check the whitelist and send a built message.
	 *
	 * @param array<string,mixed> $email
	 */
	private function transmit(array $email, string $source): OperationResult
	{
		$whitelistResult = $this->validateEmailWhitelist((string)$email['to']);
		if (!$whitelistResult->success) {
			return $whitelistResult;
		}

		$result = $this->emailSender->send($email, recordActivity: false);

		if ($result->success) {
			$this->logger->info('Email sent successfully', [
				'mailerId' => $source,
				'to'       => $email['to'],
				'subject'  => $email['subject'],
			]);
		}

		return $result;
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
			$doc = transformString($html);

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
	 */
	private function validateEmailWhitelist(string $email): OperationResult
	{
		$allowedDomains = $this->config->mailer['whitelist'] ?? [];

		// Normalize to array (config may provide a single string value)
		if (is_string($allowedDomains)) {
			$allowedDomains = $allowedDomains !== '' ? [$allowedDomains] : [];
		}

		// If whitelist is empty, it's disabled
		if ($allowedDomains === []) {
			return OperationResult::success('Whitelist not enabled');
		}

		// Extract domain from email. Domains are case-insensitive, so
		// Jane@Example.COM must match an "@example.com" entry.
		$emailDomain = '@' . strtolower(substr((string)strrchr($email, '@'), 1));

		// Each entry names one domain. The leading "@" is added when missing:
		// a bare "company.com" used to match "@evilcompany.com" as well.
		foreach ($allowedDomains as $allowedDomain) {
			$allowedDomain = strtolower(trim((string)$allowedDomain));
			if ($allowedDomain === '') {
				continue;
			}
			if (!str_starts_with($allowedDomain, '@')) {
				$allowedDomain = '@' . $allowedDomain;
			}
			if ($emailDomain === $allowedDomain) {
				return OperationResult::success('Email domain allowed');
			}
		}

		$this->logger->warning('Email blocked by whitelist', [
			'email'  => $email,
			'domain' => $emailDomain,
		]);

		return OperationResult::failure('Email domain not in whitelist');
	}
}
