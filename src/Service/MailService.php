<?php

declare(strict_types=1);

namespace Hvm\Service;

use Hvm\Support\Config;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Mailversand über SMTP (PHPMailer, TLS). Wird ausschließlich vom Outbox-Worker aufgerufen.
 *
 * Der Transport ist austauschbar (Tests): callable(array{to: string, subject: string, html: string, text: string,
 * from: string, from_name: string}): void, wirft bei Fehlern eine Ausnahme.
 */
final class MailService
{
    /** @var (callable(array<string, string>): void)|null */
    private $transport;

    public function __construct(private readonly Config $config, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function withTransport(callable $transport): self
    {
        return new self($this->config, $transport);
    }

    /**
     * Fehlende Konfiguration als Hinweis ohne Werte, null wenn versandbereit.
     */
    public function missingConfiguration(): ?string
    {
        $missing = [];
        if ($this->transport === null && trim((string) $this->config->get('app.mail.host', '')) === '') {
            $missing[] = 'MAIL_HOST';
        }
        if (filter_var((string) $this->config->get('app.mail.from', ''), FILTER_VALIDATE_EMAIL) === false) {
            $missing[] = 'MAIL_FROM';
        }

        return $missing === [] ? null : implode(', ', $missing) . ' nicht gesetzt';
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === null;
    }

    /**
     * Empfänger der internen Lead-Benachrichtigung (LEAD_NOTIFY_TO), null wenn nicht gesetzt oder ungültig.
     */
    public function leadNotifyAddress(): ?string
    {
        $address = trim((string) $this->config->get('app.lead_notify_to', ''));

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Ungültige Empfängeradresse.');
        }
        $missing = $this->missingConfiguration();
        if ($missing !== null) {
            throw new \RuntimeException('Mailversand nicht konfiguriert: ' . $missing);
        }
        $message = [
            'to' => $to,
            'subject' => trim((string) preg_replace('/[\r\n]+/', ' ', $subject)),
            'html' => $html,
            'text' => $text,
            'from' => (string) $this->config->get('app.mail.from'),
            'from_name' => (string) $this->config->get('app.mail.from_name', 'Hausverwaltung Müller GmbH'),
        ];

        if ($this->transport !== null) {
            ($this->transport)($message);

            return;
        }
        $this->sendSmtp($message);
    }

    /**
     * @param array<string, string> $message
     */
    private function sendSmtp(array $message): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $this->config->get('app.mail.host');
        $mail->Port = (int) $this->config->get('app.mail.port', 587);
        $user = (string) $this->config->get('app.mail.user', '');
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = (string) $this->config->get('app.mail.password', '');
        }
        $encryption = strtolower((string) $this->config->get('app.mail.encryption', 'tls'));
        if ($encryption === 'ssl' || $encryption === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->XMailer = ' ';
        $mail->setFrom($message['from'], $message['from_name']);
        $mail->addAddress($message['to']);
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body = $message['html'];
        $mail->AltBody = $message['text'];
        $mail->send();
    }
}
