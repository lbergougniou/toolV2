<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Instance Scorimmo destinataire d'un rejeu : webhook `/email/inbound`, secret HMAC
 * (INBOUND_EMAIL_WEBHOOK_SECRET de l'instance), domaine de ses traceurs et compte API v1
 * (`/api/login_check` puis `/api/email`) pour le transport API.
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
        public string $apiUsername = '',
        public string $apiPassword = '',
    ) {
    }

    public function hasApiCredentials(): bool
    {
        return $this->apiUsername !== '' && $this->apiPassword !== '';
    }

    /**
     * URL d'un autre chemin de l'instance, identifiants basic auth de l'URL du webhook conservés.
     */
    public function endpoint(string $path): string
    {
        $parts = parse_url($this->url);
        $userInfo = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        return sprintf('%s://%s%s%s%s', $parts['scheme'] ?? 'https', $userInfo, $parts['host'] ?? '', isset($parts['port']) ? ':' . $parts['port'] : '', $path);
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
