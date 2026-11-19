<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Sends through the one central mailbox (formulare@machma.app) via SMTP. */
final class SmtpMailer implements Mailer
{
    /** @param array{host:string, port:int, user?:string, pass?:string, secure?:string, from:string} $smtp */
    public function __construct(private readonly array $smtp, private readonly int $timeout = 15)
    {
    }

    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->smtp['host'];
            $mail->Port = (int) $this->smtp['port'];
            $mail->Timeout = $this->timeout;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->XMailer = ' '; // no "X-Mailer: PHPMailer" header
            $user = (string) ($this->smtp['user'] ?? '');
            $mail->SMTPAuth = $user !== '';
            if ($user !== '') {
                $mail->Username = $user;
                $mail->Password = (string) ($this->smtp['pass'] ?? '');
            }
            $secure = (string) ($this->smtp['secure'] ?? ($mail->Port === 465 ? 'ssl' : ($mail->Port === 587 ? 'tls' : '')));
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($this->smtp['from'], HeaderSanitizer::line($message->fromName, 120), false);
            $mail->Sender = $this->smtp['from'];
            foreach ($message->to as $address) {
                $mail->addAddress($address);
            }
            foreach ($message->cc as $address) {
                $mail->addCC($address);
            }
            foreach ($message->bcc as $address) {
                $mail->addBCC($address);
            }
            if ($message->replyTo !== null && ($replyTo = HeaderSanitizer::email($message->replyTo)) !== null) {
                $mail->addReplyTo($replyTo);
            }
            foreach ($message->headers as $name => $value) {
                $mail->addCustomHeader($name, HeaderSanitizer::line($value));
            }
            $mail->isHTML(false);
            $mail->Subject = HeaderSanitizer::line($message->subject);
            $mail->Body = $message->body;
            $mail->send();
        } catch (PHPMailerException $e) {
            $reason = stripos($e->getMessage(), 'connect') !== false || stripos($mail->ErrorInfo, 'timed out') !== false
                ? 'smtp_connect' : 'smtp_error';
            throw new MailException($e->getMessage(), $reason, $e);
        }
    }
}
