<?php
declare(strict_types=1);

namespace System\Library\Mail;

class Smtp
{
    protected string|array $to = '';
    protected string $from = '';
    protected string $from_name = '';
    protected string $sender = '';
    protected string $reply_to = '';
    protected string $reply_to_name = '';
    protected string $subject = '';
    protected string $text = '';
    protected string $html = '';
    protected array $attachments = [];

    protected string $smtp_hostname = '';
    protected string $smtp_username = '';
    protected string $smtp_password = '';
    protected int $smtp_port = 25;
    protected int $smtp_timeout = 5;
    protected int $max_attempts = 3;
    protected bool $verp = false;

    public function __construct(array $option = [])
    {
        $allowed = [
            'to', 'from', 'from_name', 'sender', 'reply_to', 'reply_to_name',
            'subject', 'text', 'html',
            'smtp_hostname', 'smtp_username', 'smtp_password', 'smtp_port',
            'smtp_timeout', 'max_attempts', 'verp',
        ];

        foreach ($option as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $this->{$key} = $value;
            }
        }
    }

    public function setTo(string|array $to): void
    {
        $addresses = is_array($to) ? $to : [$to];
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid to email: {$address}");
            }
        }
        $this->to = $to;
    }

    public function setFrom(string $from): void
    {
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid from email: {$from}");
        }
        $this->from = $from;
    }

    public function setSender(string $sender): void
    {
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid sender email: {$sender}");
        }
        $this->sender = $sender;
    }

    public function setReplyTo(string $reply_to): void
    {
        if (!filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid reply-to email: {$reply_to}");
        }
        $this->reply_to = $reply_to;
    }

    public function setSubject(string $subject): void
    {
        // Strip CR/LF to prevent header injection.
        $this->subject = str_replace(["\r", "\n"], '', $subject);
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function setHtml(string $html): void
    {
        $this->html = $html;
    }

    public function addAttachment(string $filename): void
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \RuntimeException("Attachment not found: {$filename}");
        }
        $this->attachments[] = $filename;
    }

    public function send(): bool
    {
        if (empty($this->to)) {
            throw new \RuntimeException('E-Mail "to" is required.');
        }
        if (empty($this->from)) {
            throw new \RuntimeException('E-Mail "from" is required.');
        }
        if (empty($this->subject)) {
            throw new \RuntimeException('E-Mail "subject" is required.');
        }
        if (empty($this->text) && empty($this->html)) {
            throw new \RuntimeException('E-Mail "message" is required.');
        }
        if (empty($this->smtp_hostname)) {
            throw new \RuntimeException('SMTP hostname is required.');
        }
        if (empty($this->smtp_username)) {
            throw new \RuntimeException('SMTP username is required.');
        }
        if (empty($this->smtp_password)) {
            throw new \RuntimeException('SMTP password is required.');
        }

        // 1. Parse hostname and TLS requirement
        $hostname = $this->smtp_hostname;
        $useTls = false;
        $isImplicitSsl = false;

        if (stripos($hostname, 'tls://') === 0) {
            $useTls = true;
            $hostname = substr($hostname, 6);
        } elseif (stripos($hostname, 'ssl://') === 0) {
            $useTls = true;
            $isImplicitSsl = true;
            $hostname = substr($hostname, 6);
        }

        // 2. Build the raw MIME message
        $eol = "\r\n";
        $boundary = '----=_NextPart_' . bin2hex(random_bytes(16));

        $toList = is_array($this->to) ? implode(',', $this->to) : $this->to;

        $senderName = $this->from_name !== ''
            ? '=?UTF-8?B?' . base64_encode($this->from_name) . '?= '
            : '';

        $header  = 'MIME-Version: 1.0' . $eol;
        $header .= 'To: <' . $toList . '>' . $eol;
        $header .= 'Subject: =?UTF-8?B?' . base64_encode($this->subject) . '?=' . $eol;
        $header .= 'Date: ' . date('D, d M Y H:i:s O') . $eol;
        $header .= 'From: ' . $senderName . '<' . $this->from . '>' . $eol;

        if ($this->reply_to !== '') {
            $replyName = $this->reply_to_name !== ''
                ? '=?UTF-8?B?' . base64_encode($this->reply_to_name) . '?= '
                : '';
            $header .= 'Reply-To: ' . $replyName . '<' . $this->reply_to . '>' . $eol;
        }

        $domain = substr(strrchr($this->from, '@') ?: '@localhost', 1);
        $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), time(), $domain);

        $header .= 'Message-ID: ' . $messageId . $eol;
        $header .= 'Return-Path: ' . ($this->sender !== '' ? $this->sender : $this->from) . $eol;
        $header .= 'X-Mailer: PHP/' . PHP_VERSION . $eol;
        $header .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol . $eol;

        $message = '--' . $boundary . $eol;

        if ($this->html === '') {
            $message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
            $message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $message .= chunk_split(base64_encode($this->text)) . $eol;
        } else {
            $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '_alt"' . $eol . $eol;
            $message .= '--' . $boundary . '_alt' . $eol;
            $message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
            $message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $message .= chunk_split(base64_encode(
                $this->text !== '' ? $this->text : strip_tags($this->html)
            )) . $eol;

            $message .= '--' . $boundary . '_alt' . $eol;
            $message .= 'Content-Type: text/html; charset="utf-8"' . $eol;
            $message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $message .= chunk_split(base64_encode($this->html)) . $eol;
            $message .= '--' . $boundary . '_alt--' . $eol;
        }

        foreach ($this->attachments as $attachment) {
            $content = file_get_contents($attachment);
            if ($content === false) {
                continue;
            }

            $filename = basename($attachment);

            $message .= '--' . $boundary . $eol;
            $message .= 'Content-Type: application/octet-stream; name="' . $filename . '"' . $eol;
            $message .= 'Content-Transfer-Encoding: base64' . $eol;
            $message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . $eol;
            $message .= 'Content-ID: <' . urlencode($filename) . '>' . $eol;
            $message .= 'X-Attachment-Id: ' . urlencode($filename) . $eol . $eol;
            $message .= chunk_split(base64_encode($content)) . $eol;
        }

        $message .= '--' . $boundary . '--' . $eol;
        $fullMessage = $header . $message;

        // 3. Connect (with TLS verification)
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);

        $remote = ($isImplicitSsl ? 'ssl://' : 'tcp://') . $hostname . ':' . $this->smtp_port;
        $handle = @stream_socket_client($remote, $errno, $errstr, $this->smtp_timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$handle) {
            throw new \RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($handle, $this->smtp_timeout);

        // Greeting
        $this->handleReply($handle, 220, 'Error: Greeting not accepted.');

        // EHLO
        $servername = 'localhost';
        if (defined('HTTP_SERVER')) {
            $parsed = parse_url(HTTP_SERVER, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '' && !preg_match('/[\r\n\s]/', $parsed)) {
                $servername = $parsed;
            }
        }

        fwrite($handle, 'EHLO ' . $servername . $eol);
        $reply = $this->handleReply($handle, 250, 'Error: EHLO not accepted.');
        $capabilities = strtoupper($reply);

        // STARTTLS
        if ($useTls && !$isImplicitSsl) {
            if (!str_contains($capabilities, 'STARTTLS')) {
                throw new \RuntimeException('STARTTLS is not supported by the server.');
            }

            fwrite($handle, 'STARTTLS' . $eol);
            $this->handleReply($handle, 220, 'Error: STARTTLS not accepted.');

            if (stream_socket_enable_crypto($handle, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new \RuntimeException('TLS could not be established.');
            }

            fwrite($handle, 'EHLO ' . $servername . $eol);
            $this->handleReply($handle, 250, 'Error: EHLO not accepted after STARTTLS.');
        }

        // AUTH LOGIN
        fwrite($handle, 'AUTH LOGIN' . $eol);
        $this->handleReply($handle, 334, 'Error: AUTH LOGIN not accepted.');

        fwrite($handle, base64_encode($this->smtp_username) . $eol);
        $this->handleReply($handle, 334, 'Error: Username not accepted.');

        fwrite($handle, base64_encode($this->smtp_password) . $eol);
        $this->handleReply($handle, 235, 'Error: Password not accepted.');

        // MAIL FROM
        $from = $this->sender !== '' ? $this->sender : $this->from;
        if ($this->verp) {
            fwrite($handle, 'MAIL FROM: <' . $from . '>XVERP' . $eol);
        } else {
            fwrite($handle, 'MAIL FROM: <' . $from . '>' . $eol);
        }
        $this->handleReply($handle, 250, 'Error: MAIL FROM not accepted.');

        // RCPT TO
        $recipients = is_array($this->to) ? $this->to : [$this->to];
        foreach ($recipients as $recipient) {
            fwrite($handle, 'RCPT TO: <' . $recipient . '>' . $eol);
            $reply = $this->handleReply($handle, false);
            $code = (int) substr($reply, 0, 3);
            if ($code !== 250 && $code !== 251) {
                throw new \RuntimeException("RCPT TO <{$recipient}> not accepted: " . trim($reply));
            }
        }

        // DATA
        fwrite($handle, 'DATA' . $eol);
        $this->handleReply($handle, 354, 'Error: DATA not accepted.');

        // Send body (RFC 5321: max 998 chars per line)
        $fullMessage = str_replace(["\r\n", "\r"], "\n", $fullMessage);
        $lines = explode("\n", $fullMessage);

        foreach ($lines as $line) {
            if ($line === '') {
                fwrite($handle, $eol);
                continue;
            }

            $offset = 0;
            $len = mb_strlen($line, 'UTF-8');

            while ($offset < $len) {
                $chunk = mb_strcut($line, $offset, 998, 'UTF-8');
                fwrite($handle, $chunk . $eol);
                $offset += strlen($chunk);
            }
        }

        fwrite($handle, '.' . $eol);
        $this->handleReply($handle, 250, 'Error: Message not accepted.');

        fclose($handle);

        return true;
    }

    /**
     * @param resource $handle
     * @return string
     */
    private function handleReply($handle, int|false $status_code = false, string|false $error_text = false, int $counter = 0): string
    {
        $reply = '';

        while (($line = fgets($handle, 515)) !== false) {
            $reply .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }

        // Retry if the server is slow.
        if ($line === false && $reply === '' && $counter < $this->max_attempts) {
            sleep(1);
            return $this->handleReply($handle, $status_code, $error_text, $counter + 1);
        }

        if ($status_code !== false) {
            if ((int) substr($reply, 0, 3) !== $status_code) {
                throw new \RuntimeException($error_text !== false ? $error_text : 'SMTP error: ' . trim($reply));
            }
        }

        return $reply;
    }
}