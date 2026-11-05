<?php
declare(strict_types=1);

return [
    // ─── Engine ──────────────────────────────────────────────────────────
    // 'mail'  → uses PHP's mail() (dev only)
    // 'smtp'  → uses SMTP with STARTTLS/SSL (production)
    'engine' => 'mail',

    // ─── Identity ────────────────────────────────────────────────────────
    'from'      => '',          // e.g. 'noreply@example.com'
    'from_name' => '',          // display name on From
    'sender'    => '',          // envelope sender (Return-Path); defaults to 'from'
    'reply_to'  => '',          // defaults to 'from'
    'log'       => true,        // write a copy under storage/mail

    // ─── SMTP (only used if engine = 'smtp') ─────────────────────────────
    // Use 'tls://' prefix for STARTTLS (port 587)
    // Use 'ssl://' prefix for implicit TLS (port 465)
    // Use no prefix for plain SMTP (port 25 — dev only)
    'smtp_hostname' => '',     // e.g. 'tls://smtp.gmail.com'
    'smtp_username' => '',     // e.g. 'your@email.com'
    'smtp_password' => '',     // e.g. 'your-app-password' (NOT your login password)
    'smtp_port'     => 587,    // 587 (STARTTLS) or 465 (SSL) — NOT 25
    'smtp_timeout'  => 10,

    // ─── Optional ────────────────────────────────────────────────────────
    'verp' => false,           // VERP envelope sender (rarely needed)
];