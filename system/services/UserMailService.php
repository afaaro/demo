<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Mail;
use System\Library\Url;

/** Transactional emails for account lifecycle (approval, etc.). */
final class UserMailService
{
    public function __construct(
        private Mail $mail,
        private Url $url,
    ) {}

    public function sendApproved(User $user): void
    {
        $loginUrl = $this->url->to('user/login', [], absolute: true);
        $name = $user->displayLabel();
        $text = "Hello {$name},\n\n"
            . "Your account has been approved. You can sign in here:\n{$loginUrl}\n";
        $html = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your account has been approved.</p>'
            . '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">Sign in</a></p>';
        $this->mail->send((string) $user->email, 'Account approved', $text, $html);
    }

    public function sendRejected(string $email, string $displayName = ''): void
    {
        $name = $displayName !== '' ? $displayName : 'there';
        $text = "Hello {$name},\n\nYour registration request was not approved.\n";
        $html = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your registration request was not approved.</p>';
        $this->mail->send($email, 'Registration update', $text, $html);
    }
}
