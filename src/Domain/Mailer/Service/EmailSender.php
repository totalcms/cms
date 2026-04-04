<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * EmailSender wraps PHPMailer for sending emails.
 */
readonly class EmailSender
{
	private LoggerInterface $logger;

	public function __construct(
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('email.log')->createLogger('email-sender');
	}

	/**
	 * Send an email using PHPMailer.
	 *
	 * @param array<string,mixed> $emailData
	 * @param int $timeout SMTP connection timeout in seconds (default: 30)
	 *
	 * @return array{success:bool,message:string,error?:string}
	 */
	public function send(array $emailData, int $timeout = 30): array
	{
		$mail = new PHPMailer(true);

		try {
			// Server settings
			$smtpConfig = $this->config->smtp;

			// Always use SMTP if type is smtp
			if (($smtpConfig['type'] ?? 'smtp') === 'smtp') {
				$mail->isSMTP();
				$mail->Host     = $smtpConfig['host'] ?? '127.0.0.1';
				$mail->Port     = (int)($smtpConfig['port'] ?? 25);
				$mail->Timeout  = $timeout; // Set SMTP connection timeout

				// Only use SMTP auth if username is provided
				if (isset($smtpConfig['username']) && $smtpConfig['username'] !== '') {
					$mail->SMTPAuth = true;
					$mail->Username = $smtpConfig['username'];
					$mail->Password = $smtpConfig['password'] ?? '';
				}

				// Handle secure connection
				$secure = $smtpConfig['secure'] ?? '';
				if ($secure === 'tls') {
					$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				} elseif ($secure === 'ssl') {
					$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				}
			}

			// Set charset to UTF-8
			$mail->CharSet = PHPMailer::CHARSET_UTF8;

			// From
			$fromEmail = empty($emailData['from']) ? $smtpConfig['from'] ?? '' : $emailData['from'];
			$fromName  = empty($emailData['fromName']) ? $smtpConfig['fromName'] ?? '' : $emailData['fromName'];
			$mail->setFrom($fromEmail, $fromName);

			// To
			if (isset($emailData['to'])) {
				$toName = $emailData['toName'] ?? '';
				$mail->addAddress($emailData['to'], $toName);
			}

			// Reply-To
			if (isset($emailData['replyTo']) && $emailData['replyTo'] !== '') {
				$mail->addReplyTo($emailData['replyTo']);
			}

			// CC
			if (isset($emailData['cc']) && is_array($emailData['cc'])) {
				foreach ($emailData['cc'] as $ccEmail) {
					if ($ccEmail !== '') {
						$mail->addCC($ccEmail);
					}
				}
			}

			// BCC
			if (isset($emailData['bcc']) && is_array($emailData['bcc'])) {
				foreach ($emailData['bcc'] as $bccEmail) {
					if ($bccEmail !== '') {
						$mail->addBCC($bccEmail);
					}
				}
			}

			// Subject
			$mail->Subject = $emailData['subject'] ?? 'No Subject';

			// Body
			if (isset($emailData['bodyHtml']) && $emailData['bodyHtml'] !== '') {
				$mail->isHTML(true);
				$mail->Body = $emailData['bodyHtml'];

				// Add plain text version if provided
				if (isset($emailData['bodyText']) && $emailData['bodyText'] !== '') {
					$mail->AltBody = $emailData['bodyText'];
				}
			} elseif (isset($emailData['bodyText']) && $emailData['bodyText'] !== '') {
				$mail->isHTML(false);
				$mail->Body = $emailData['bodyText'];
			}

			// Send
			$mail->send();

			$this->logger->info('Email sent successfully', [
				'to'      => $emailData['to'] ?? 'unknown',
				'subject' => $emailData['subject'] ?? 'No Subject',
				'from'    => $fromEmail,
			]);

			return [
				'success' => true,
				'message' => 'Email sent successfully',
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to send email', [
				'to'      => $emailData['to'] ?? 'unknown',
				'subject' => $emailData['subject'] ?? 'No Subject',
				'error'   => $e->getMessage(),
			]);

			// Provide helpful error message
			$errorMsg = $e->getMessage();
			if ($errorMsg === '') {
				$errorMsg = 'SMTP connection failed. Please verify your SMTP settings (host, port, username, password).';
			}

			return [
				'success' => false,
				'message' => $errorMsg,
				'error'   => $errorMsg,
			];
		}
	}
}
