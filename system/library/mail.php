<?php
declare(strict_types=1);

namespace System\Library;

class Mail
{
    private object $adaptor;
    /** @var list<string> */
    private array $attachments = [];
    private ?Config $config = null;

    /**
     * @param Config|string $adaptor Config instance (app mail) or adaptor name ('mail', 'smtp')
     * @param array<string, mixed> $option Adaptor options when the first argument is a string
     */
    public function __construct(Config|string $adaptor = 'mail', array $option = [])
    {
        if ($adaptor instanceof Config) {
            $this->config = $adaptor;
            [$engine, $option] = self::optionsFromConfig($adaptor);
            $this->bootAdaptor($engine, $option);
            return;
        }

        $this->bootAdaptor($adaptor, $option);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function optionsFromConfig(Config $config): array
    {
        $from = trim((string) $config->get('mail.from', ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }

        $sender = trim((string) $config->get('mail.sender', ''));
        $replyTo = trim((string) $config->get('mail.reply_to', ''));
        $engine = strtolower(trim((string) $config->get('mail.engine', 'mail')));
        if ($engine === '') {
            $engine = 'mail';
        }

        return [
            $engine,
            [
                'from'          => $from,
                'from_name'     => trim((string) $config->get('mail.from_name', 'App')),
                'sender'        => $sender !== '' ? $sender : $from,
                'reply_to'      => $replyTo !== '' ? $replyTo : $from,
                'smtp_hostname' => (string) $config->get('mail.smtp_hostname', ''),
                'smtp_username' => (string) $config->get('mail.smtp_username', ''),
                'smtp_password' => (string) $config->get('mail.smtp_password', ''),
                'smtp_port'     => (int) $config->get('mail.smtp_port', 587),
                'smtp_timeout'  => (int) $config->get('mail.smtp_timeout', 10),
                'verp'          => (bool) $config->get('mail.verp', false),
            ],
        ];
    }

    /** @param array<string, mixed> $option */
    private function bootAdaptor(string $adaptor, array $option): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $adaptor)) {
            throw new \InvalidArgumentException("Invalid mail adaptor name: {$adaptor}");
        }

        $class = 'System\\Library\\Mail\\' . ucfirst($adaptor);
        if (!class_exists($class)) {
            throw new \RuntimeException("Mail adaptor '{$adaptor}' not found.");
        }

        $instance = new $class($option);
        foreach ([
            'setTo', 'setFrom', 'setSender', 'setReplyTo',
            'setSubject', 'setText', 'setHtml', 'addAttachment', 'send',
        ] as $method) {
            if (!method_exists($instance, $method)) {
                throw new \RuntimeException(
                    "Mail adaptor '{$adaptor}' is missing required method '{$method}'."
                );
            }
        }

        $this->adaptor = $instance;
        $this->applyIdentity($option);
    }

    /** @param array<string, mixed> $option */
    private function applyIdentity(array $option): void
    {
        $from = trim((string) ($option['from'] ?? ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->adaptor->setFrom($from);
            $sender = trim((string) ($option['sender'] ?? $from));
            $replyTo = trim((string) ($option['reply_to'] ?? $from));
            if ($sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                $this->adaptor->setSender($sender);
            }
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $this->adaptor->setReplyTo($replyTo);
            }
        }
    }

    public function setTo(string|array $to): static
    {
        $addresses = is_array($to) ? $to : [$to];
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email address: {$address}");
            }
        }
        $this->adaptor->setTo($to);
        return $this;
    }

    public function setFrom(string $from): static
    {
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid from email: {$from}");
        }
        $this->adaptor->setFrom($from);
        return $this;
    }

    public function setSender(string $sender): static
    {
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid sender email: {$sender}");
        }
        $this->adaptor->setSender($sender);
        return $this;
    }

    public function setReplyTo(string $replyTo): static
    {
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid reply-to email: {$replyTo}");
        }
        $this->adaptor->setReplyTo($replyTo);
        return $this;
    }

    public function setSubject(string $subject): static
    {
        $this->adaptor->setSubject(str_replace(["\r", "\n"], '', $subject));
        return $this;
    }

    public function setText(string $text): static
    {
        $this->adaptor->setText($text);
        return $this;
    }

    public function setHtml(string $html): static
    {
        $this->adaptor->setHtml($html);
        return $this;
    }

    public function addAttachment(string $filename): static
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $filename) || str_contains($filename, '..')) {
            throw new \InvalidArgumentException("Invalid attachment path: {$filename}");
        }
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \RuntimeException("Attachment not found or not readable: {$filename}");
        }
        $this->attachments[] = $filename;
        return $this;
    }

    public function send(string $to, string $subject, string $bodyText, string $bodyHtml = ''): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $ok = false;
        try {
            $this->setTo($to);
            $this->setSubject($subject);
            $this->setText($bodyText);
            if ($bodyHtml !== '') {
                $this->setHtml($bodyHtml);
            }
            $ok = $this->deliver();
        } catch (\Throwable) {
            $ok = false;
        }

        if ($this->config !== null && (bool) $this->config->get('mail.log', true)) {
            $this->logMessage($to, $subject, $bodyText, $bodyHtml, $ok);
        }

        $this->attachments = [];
        return $ok;
    }

    public function deliver(): bool
    {
        foreach ($this->attachments as $file) {
            $this->adaptor->addAttachment($file);
        }
        return $this->adaptor->send();
    }

    private function logMessage(string $to, string $subject, string $text, string $html, bool $sent): void
    {
        $dir = (defined('BASEDIR') ? rtrim((string) BASEDIR, '/\\') . DIRECTORY_SEPARATOR : sys_get_temp_dir() . DIRECTORY_SEPARATOR)
            . 'storage' . DIRECTORY_SEPARATOR . 'mail';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt';
        @file_put_contents($file, json_encode([
            'sent'    => $sent,
            'to'      => $to,
            'subject' => $subject,
            'text'    => $text,
            'html'    => $html,
            'at'      => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
