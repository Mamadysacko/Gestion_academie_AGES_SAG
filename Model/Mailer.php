<?php

use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
    public function sendText(string $to, string $subject, string $body): bool
    {
        $host = trim((string) (getenv('AGES_SMTP_HOST') ?: ''));
        if ($host === '') {
            return $this->sendWithPhpMail($to, $subject, $body);
        }

        $username = (string) (getenv('AGES_SMTP_USER') ?: '');
        $password = (string) (getenv('AGES_SMTP_PASSWORD') ?: '');
        $from = trim((string) (getenv('AGES_MAIL_FROM') ?: ''));
        $encryption = strtolower(trim((string) (getenv('AGES_SMTP_ENCRYPTION') ?: 'tls')));
        $port = filter_var(getenv('AGES_SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);

        if (($username === '') !== ($password === '')) {
            error_log('AGES : configuration SMTP incomplète (identifiant et mot de passe doivent être renseignés ensemble).');
            return false;
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            error_log('AGES : AGES_MAIL_FROM doit être une adresse e-mail valide pour utiliser SMTP.');
            return false;
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            error_log('AGES : AGES_SMTP_ENCRYPTION doit valoir tls, ssl ou none.');
            return false;
        }
        if ($port === false || $port < 1 || $port > 65535) {
            error_log('AGES : AGES_SMTP_PORT doit être un port valide entre 1 et 65535.');
            return false;
        }

        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->SMTPAuth = $username !== '';
            $mailer->Username = $username;
            $mailer->Password = $password;
            if ($encryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($from, (string) (getenv('AGES_MAIL_FROM_NAME') ?: 'AGES'));
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->isHTML(false);
            return $mailer->send();
        } catch (Throwable $error) {
            error_log('AGES : l’envoi SMTP du message a échoué : ' . $error->getMessage());
            return false;
        }
    }

    private function sendWithPhpMail(string $to, string $subject, string $body): bool
    {
        $sender = getenv('AGES_MAIL_FROM') ?: 'noreply@localhost';
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            $sender = 'noreply@localhost';
        }
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8')
            : $subject;
        $headers = "From: {$sender}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($to, $encodedSubject, $body, $headers);
        if (!$sent) {
            error_log('AGES : l’envoi via mail() a échoué. Configurez un serveur SMTP pour remettre les messages.');
        }
        return $sent;
    }
}
