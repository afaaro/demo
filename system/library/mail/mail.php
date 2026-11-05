<?php
declare(strict_types=1);

namespace System\Library\Mail;

class Mail
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

    public function __construct(array $option = [])
    {
        $allowed = ['to', 'from', 'from_name', 'sender', 'reply_to',
                    'reply_to_name', 'subject', 'text', 'html'];

        foreach ($option as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $this->{$key} = $value;
            }
        }
    }

    public function setTo(string|array $to): void
    {
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

        $to = is_array($this->to) ? implode(',', $this->to) : $this->to;

        $boundary = '----=_NextPart_' . bin2hex(random_bytes(16));
        $eol = "\r\n";

        // ─── Headers ──────────────────────────────────────────────────────
        $senderName = $this->from_name !== ''
            ? '=?UTF-8?B?' . base64_encode($this->from_name) . '?= '
            : '';

        $header  = 'MIME-Version: 1.0' . $eol;
        $header .= 'Date: ' . date('D, d M Y H:i:s O') . $eol;
        $header .= 'From: ' . $senderName . '<' . $this->from . '>' . $eol;

        if ($this->reply_to !== '') {
            $replyName = $this->reply_to_name !== ''
                ? '=?UTF-8?B?' . base64_encode($this->reply_to_name) . '?= '
                : '';
            $header .= 'Reply-To: ' . $replyName . '<' . $this->reply_to . '>' . $eol;
        }

        if ($this->sender !== '') {
            $header .= 'Return-Path: ' . $this->sender . $eol;
        }

        $header .= 'X-Mailer: PHP/' . PHP_VERSION . $eol;
        $header .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol . $eol;

        // ─── Body ─────────────────────────────────────────────────────────
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

        // ─── Attachments ──────────────────────────────────────────────────
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

        // ─── Send ─────────────────────────────────────────────────────────
        ini_set('sendmail_from', $this->from);

        $subject = mb_encode_mimeheader($this->subject, 'UTF-8', 'B');

        return mail($to, $subject, $message, $header);
    }
}