<?php
declare(strict_types=1);

namespace System\Services;

use RuntimeException;
use System\Library\Database;
use System\Library\Mail;
use System\Library\Url;

final class PasswordResetService
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private Database $db,
        private Mail $mail,
        private Url $url,
    ) {}

    /**
     * Always returns a generic outcome — does not reveal whether the email exists.
     */
    public function requestReset(string $email, string $ip = ''): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $user = User::findByEmail($email);
        if ($user === null || !$user->isActive()) {
            // Silent for pending/suspended/missing — no account enumeration.
            return;
        }

        $userId = (int) $user->id;
        // Invalidate prior unused tokens.
        $this->db->query(
            "UPDATE `#__password_reset_tokens`
                SET `used_at` = NOW()
              WHERE `user_id` = ? AND `used_at` IS NULL",
            [$userId]
        );

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $this->db->insert('password_reset_tokens', [
            'user_id'    => $userId,
            'token_hash' => $hash,
            'expires_at' => $expires,
            'request_ip' => mb_substr($ip, 0, 45),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $link = $this->url->to('user/password/reset', ['token' => $token], absolute: true);
        $name = $user->displayLabel();
        $mins = (int) (self::TTL_SECONDS / 60);

        $text = "Hello {$name},\n\n"
            . "We received a request to reset your password.\n"
            . "Open this link within {$mins} minutes:\n\n{$link}\n\n"
            . "If you did not request this, you can ignore this email.\n";

        $html = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>We received a request to reset your password.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Reset your password</a></p>'
            . '<p>This link expires in ' . $mins . ' minutes.</p>'
            . '<p>If you did not request this, you can ignore this email.</p>';

        $this->mail->send((string) $user->email, 'Password reset', $text, $html);
    }

    /** @return array{id:int, user_id:int}|null */
    public function findValidToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->db->query(
            "SELECT `id`, `user_id` FROM `#__password_reset_tokens`
              WHERE `token_hash` = ?
                AND `used_at` IS NULL
                AND `expires_at` > NOW()
              LIMIT 1",
            [$hash]
        )->row;

        if ($row === []) {
            return null;
        }
        return [
            'id'      => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
        ];
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        if (strlen($newPassword) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        $row = $this->findValidToken($token);
        if ($row === null) {
            throw new RuntimeException('This reset link is invalid or has expired.');
        }

        $user = User::find($row['user_id']);
        if ($user === null || !$user->isActive()) {
            throw new RuntimeException('This reset link is invalid or has expired.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Could not update password.');
        }

        $this->db->transaction(function (Database $db) use ($row, $hash) {
            $db->update('users', [
                'password_hash' => $hash,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['id' => $row['user_id']]);

            $db->update('password_reset_tokens', [
                'used_at' => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);

            // Invalidate any other outstanding tokens for this user.
            $db->query(
                "UPDATE `#__password_reset_tokens`
                    SET `used_at` = NOW()
                  WHERE `user_id` = ? AND `used_at` IS NULL",
                [$row['user_id']]
            );
        });
    }
}
