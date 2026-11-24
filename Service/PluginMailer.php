<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Mail\KimaiMailer;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Versendet Mails entweder über den eigenen SMTP-Zugang aus den Plugin-Einstellungen
 * oder – wenn dort nichts eingetragen ist – über den Mailserver von Kimai (MAILER_URL).
 */
final class PluginMailer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly KimaiMailer $kimaiMailer,
    )
    {
    }

    public function hasOwnSmtp(): bool
    {
        return $this->settings->string('smtp_host') !== '';
    }

    public function send(Email $email): void
    {
        if (!$this->hasOwnSmtp()) {
            $this->kimaiMailer->send($email);

            return;
        }

        $host = $this->settings->string('smtp_host');
        $port = (int) ($this->settings->string('smtp_port', '465') ?: 465);
        $user = $this->settings->string('smtp_user');
        $from = $this->settings->string('smtp_from') ?: $user;

        // 465 = SSL/TLS direkt, sonst STARTTLS
        $transport = new EsmtpTransport($host, $port, $port === 465);
        if ($user !== '') {
            $transport->setUsername($user);
            $transport->setPassword($this->settings->string('smtp_password'));
        }

        if (\count($email->getFrom()) === 0) {
            if ($from === '') {
                throw new \RuntimeException('Keine Absender-Adresse eingetragen');
            }
            $email->from(new Address($from));
        }

        (new Mailer($transport))->send($email);
    }
}
