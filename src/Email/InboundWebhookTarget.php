<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Instance Scorimmo destinataire d'un rejeu : webhook `/email/inbound`, secret HMAC
 * (INBOUND_EMAIL_WEBHOOK_SECRET de l'instance) et domaine de ses traceurs.
 */
final readonly class InboundWebhookTarget
{
    /**
     * @param string $recipientDomain Domaine imposé au destinataire, vide = domaine d'origine conservé
     * @param bool $verifyTls false pour une instance locale en HTTPS auto-signé
     */
    public function __construct(
        public string $label,
        public string $url,
        public string $secret,
        public string $recipientDomain = '',
        public bool $verifyTls = true,
    ) {
    }

    /**
     * Base de l'instance sans identifiants (lien vers l'email créé).
     */
    public function baseUrl(): string
    {
        $parts = parse_url($this->url);
        return sprintf('%s://%s%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '', isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function isProd(): bool
    {
        return str_contains($this->url, 'pro.scorimmo.com');
    }
}
