<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Config;
use System\Library\Database;
use System\Library\Request;
use System\Library\Session;

/**
 * Anti-bot checks for public self-registration.
 */
final class RegistrationGuard
{
    private const HONEYPOT_FIELDS = ['website', 'fax_number'];
    private const SESSION_STARTED = 'reg_form_started_at';
    private const SESSION_WORD    = 'reg_captcha_word';
    private const SESSION_JS      = 'reg_js_token';

    /** Human-readable challenge words (avoid lookalikes). */
    private const WORDS = [
        'apple', 'bridge', 'candle', 'dolphin', 'eagle', 'forest', 'garden',
        'harbor', 'island', 'jungle', 'kettle', 'lemon', 'marble', 'nectar',
        'orange', 'planet', 'quartz', 'river', 'sunset', 'tiger', 'umbra',
        'violet', 'willow', 'yellow', 'zebra', 'anchor', 'bronze', 'cobalt',
    ];

    /** Common disposable / throwaway domains. */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
        'grr.la', 'guerrillamailblock.com', 'pokemail.net', 'spam4.me',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', '10minutemail.com',
        '10minutemail.net', 'throwaway.email', 'yopmail.com', 'yopmail.fr',
        'trashmail.com', 'trashmail.me', 'getnada.com', 'nada.email',
        'maildrop.cc', 'dispostable.com', 'fakeinbox.com', 'mailnesia.com',
        'moakt.com', 'emailondeck.com', 'mintemail.com', 'mytemp.email',
        'tmpmail.org', 'tmpmail.net', 'mailcatch.com', 'spamgourmet.com',
        'tempail.com', 'discard.email', 'mailnull.com', 'spamspot.com',
    ];

    public function __construct(
        private Session $session,
        private Request $request,
        private ?Database $db = null,
        private ?Config $config = null,
    ) {}

    public function isRegistrationOpen(): bool
    {
        if ($this->config === null) {
            return true;
        }
        return (bool) $this->config->get('user.registration_open', true);
    }

    private function minFormSeconds(): int
    {
        $n = (int) ($this->config?->get('user.min_form_seconds', 5) ?? 5);
        return max(3, min(60, $n));
    }

    /** Call when rendering the registration form (GET or failed POST). */
    public function prepareForm(): array
    {
        if (!$this->session->has(self::SESSION_STARTED)) {
            $this->session->set(self::SESSION_STARTED, time());
        }

        $word = self::WORDS[array_rand(self::WORDS)];
        $this->session->set(self::SESSION_WORD, $word);

        $jsToken = bin2hex(random_bytes(16));
        $this->session->set(self::SESSION_JS, $jsToken);

        // Split token so a naive scraper cannot copy one attribute.
        $mid = (int) (strlen($jsToken) / 2);

        return [
            'honeypots'      => self::HONEYPOT_FIELDS,
            'captcha_word'   => $word,
            'captcha_prompt' => 'Type this word to continue',
            'js_token_a'     => substr($jsToken, 0, $mid),
            'js_token_b'     => substr($jsToken, $mid),
            'min_seconds'    => $this->minFormSeconds(),
        ];
    }

    /**
     * @return array<string, string> field => error (empty = ok)
     */
    public function validateSubmission(array $posted, string $captchaAnswer, string $jsProof): array
    {
        $errors = [];

        // Honeypots — reject quietly.
        foreach (self::HONEYPOT_FIELDS as $field) {
            $val = trim((string) ($this->request->post($field, 'raw', '') ?? ''));
            if ($val !== '') {
                $errors['_general'] = 'Registration could not be completed. Please try again.';
                return $errors;
            }
        }

        // Timing.
        $started = (int) ($this->session->get(self::SESSION_STARTED) ?? 0);
        $minSec = $this->minFormSeconds();
        if ($started <= 0 || (time() - $started) < $minSec) {
            $errors['_general'] = 'Please take a moment to complete the form, then try again.';
            return $errors;
        }

        // JS proof — most spam bots never run page scripts.
        $expectedJs = (string) ($this->session->get(self::SESSION_JS) ?? '');
        $this->session->remove(self::SESSION_JS);
        if ($expectedJs === '' || !hash_equals($expectedJs, trim($jsProof))) {
            $errors['_general'] = 'Please enable JavaScript and try again.';
            return $errors;
        }

        // Word captcha (harder than trivial arithmetic for scripted solvers).
        $expectedWord = strtolower((string) ($this->session->get(self::SESSION_WORD) ?? ''));
        $this->session->remove(self::SESSION_WORD);
        $given = strtolower(trim($captchaAnswer));
        if ($expectedWord === '' || $given === '' || !hash_equals($expectedWord, $given)) {
            $errors['captcha'] = 'Incorrect word. Please try the new challenge.';
        }

        $username = strtolower(trim((string) ($posted['username'] ?? '')));
        $email = strtolower(trim((string) ($posted['email'] ?? '')));
        $display = trim((string) ($posted['display_name'] ?? ''));

        if ($username !== '' && ($msg = $this->usernameLooksAutomated($username)) !== null) {
            $errors['username'] = $msg;
        }
        if ($display !== '' && ($msg = $this->displayNameLooksAutomated($display)) !== null) {
            $errors['display_name'] = $msg;
        }
        if ($email !== '' && ($msg = $this->emailLooksAbusive($email)) !== null) {
            $errors['email'] = $msg;
        }

        if ($email !== '' && empty($errors['email']) && $this->canonicalEmailTaken($email)) {
            $errors['email'] = 'Email is already registered.';
        }

        return $errors;
    }

    public function canonicalEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $domain = rtrim($domain, '.');

        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        if (str_contains($local, '+')) {
            $local = explode('+', $local, 2)[0];
        }

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }

    public function usernameLooksAutomated(string $username): ?string
    {
        $username = strtolower($username);

        if (preg_match('/^[a-z]{14,}$/', $username)) {
            return 'Please choose a shorter username, or include a number.';
        }

        if (strlen($username) >= 10) {
            $letters = preg_replace('/[^a-z]/', '', $username) ?? '';
            if ($letters !== '') {
                $vowels = preg_match_all('/[aeiou]/', $letters);
                $ratio = $vowels / strlen($letters);
                if ($ratio < 0.18) {
                    return 'Username looks invalid. Please choose something more readable.';
                }
            }
        }

        if (preg_match('/[bcdfghjklmnpqrstvwxyz]{6,}/i', $username)) {
            return 'Username looks invalid. Please choose something more readable.';
        }

        return null;
    }

    public function displayNameLooksAutomated(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $caps = preg_match_all('/[A-Z]/', $name);
        if (strlen($name) >= 10 && $caps >= 4 && !str_contains($name, ' ')) {
            return 'Display name looks invalid. Use a normal name or leave it blank.';
        }

        if (preg_match('/^[A-Za-z]{14,}$/', $name) && !str_contains($name, ' ')) {
            $lower = strtolower($name);
            $vowels = preg_match_all('/[aeiou]/', $lower);
            if ($vowels / strlen($lower) < 0.22) {
                return 'Display name looks invalid. Use a normal name or leave it blank.';
            }
        }

        return null;
    }

    public function emailLooksAbusive(string $email): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        [$local, $domain] = explode('@', strtolower($email), 2);
        $domain = rtrim($domain, '.');

        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return 'Disposable email addresses are not allowed.';
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $dotCount = substr_count($local, '.');
            if ($dotCount >= 3) {
                return 'This email address is not allowed. Use your normal Gmail address without extra dots.';
            }
            if ($dotCount >= 2 && preg_match('/^(?:[a-z]\.){2,}/', $local)) {
                return 'This email address is not allowed.';
            }
        }

        return null;
    }

    private function canonicalEmailTaken(string $email): bool
    {
        if ($this->db === null) {
            return false;
        }
        $canonical = $this->canonicalEmail($email);

        try {
            $row = $this->db->query(
                "SELECT `id` FROM `#__users` WHERE `email_canonical` = ? LIMIT 1",
                [$canonical]
            )->row;
            if ($row !== []) {
                return true;
            }
        } catch (\Throwable) {
            // Column may not exist yet.
        }

        return $this->db->exists('users', ['email' => strtolower(trim($email))]);
    }
}
