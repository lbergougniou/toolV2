<?php

declare(strict_types=1);

namespace App\Email;

use Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Rejoue des emails reçus (lus en base via le tunnel SSH) sur le webhook de réception Oxeva
 * (`POST /email/inbound`) d'une instance cible, avec l'enveloppe complète et la signature HMAC
 * attendue par InboundEmailSignatureVerifier : `X-Scorimmo-Signature: sha256=<hmac>` de
 * `<X-Scorimmo-Timestamp>.<body brut>`.
 *
 * Transport `smtp` : l'email est reconstruit en MIME et remis au MTA Oxeva par InboundSmtpSender ;
 * le MTA appelle lui-même le webhook. Teste toute la chaîne (SMTP, extraction MIME, signature côté
 * MTA) mais sans retour direct : l'email créé se retrouve par son destinataire.
 *
 * Les traceurs d'une instance portent son domaine (mail.scorimmo.com en prod, mail-pp.scorimmo.com
 * en PPD) : `recipientDomain` remplace le domaine du destinataire, quel que soit le transport, pour
 * qu'un email de prod rejoué en PPD arrive sur le traceur correspondant.
 *
 * Le webhook dédoublonne deux fois : par `id` × destinataire (clé de livraison) et par le hash
 * plain|html|destinataire (doublon de contenu). L'option `fresh` génère un id unique et ajoute un
 * commentaire HTML invisible pour que chaque rejeu soit traité comme un nouvel email.
 */
final readonly class InboundEmailReplayer
{
    public const int MAX_EMAILS = 50;
    private const int MAX_DAYS = 365;
    private const array STATUSES = ['new', 'current', 'treated', 'wait_ai'];
    private const array SUBSTATUSES = ['lead_created', 'replied', 'forwarded', 'deleted', 'deleted_auto'];
    private const array ORDERS = ['recent' => 'e.id DESC', 'oldest' => 'e.id ASC', 'random' => 'RAND()'];
    public const string TRANSPORT_WEBHOOK = 'webhook';
    public const string TRANSPORT_SMTP = 'smtp';

    /** @var array<string, array{label: string, search_methods: list<array{field: string, pattern: string, operator: string}>}> */
    private array $emailTypes;

    public function __construct(
        private DatabaseConnection $db,
        private string $targetUrl,
        private string $secret,
        private string $recipientDomain = '',
        private ?InboundSmtpSender $smtp = null,
    ) {
        $config = file_get_contents(__DIR__ . '/../../config/email_types.json');
        if ($config === false) {
            throw new RuntimeException('config/email_types.json introuvable');
        }
        $this->emailTypes = json_decode($config, true, 16, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string> clé => libellé
     */
    public function getEmailTypes(): array
    {
        $types = array_map(static fn(array $type): string => $type['label'], $this->emailTypes);
        asort($types);
        return $types;
    }

    /**
     * Origines parsées des 30 derniers jours, par volume décroissant.
     *
     * @return list<string>
     */
    public function getRecentOrigins(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT origin FROM si_received_email
             WHERE created_at > NOW() - INTERVAL 30 DAY AND origin IS NOT NULL
             GROUP BY origin ORDER BY COUNT(*) DESC LIMIT 200',
        );
        return array_column($rows, 'origin');
    }

    /**
     * Base de l'instance cible sans identifiants (lien vers l'email créé).
     */
    public function getTargetBaseUrl(): string
    {
        $parts = parse_url($this->targetUrl);
        return sprintf('%s://%s%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '', isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>> Emails sans le contenu (aperçu)
     */
    public function search(array $filters): array
    {
        $where = ['e.content_id IS NOT NULL', 'e.anonymized_at IS NULL'];
        $params = [];

        $ids = self::parseIds((string) ($filters['ids'] ?? ''));
        if ($ids !== []) {
            $where[] = 'e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
            $limit = count($ids);
            $order = 'e.id ASC';
        } else {
            $days = max(1, min(self::MAX_DAYS, (int) ($filters['days'] ?? 20)));
            $where[] = 'e.created_at > NOW() - INTERVAL ? DAY';
            $params[] = $days;

            $type = (string) ($filters['type'] ?? '');
            if ($type !== '') {
                $where[] = $this->typeCondition($type, $params);
            }
            foreach (['sender' => 'e.sender_email', 'recipient' => 'e.to_email', 'subject' => 'e.sender_subject', 'origin' => 'e.origin'] as $key => $column) {
                $value = trim((string) ($filters[$key] ?? ''));
                if ($value !== '') {
                    $where[] = "$column LIKE ?";
                    $params[] = '%' . self::escapeLike($value) . '%';
                }
            }
            $content = trim((string) ($filters['content'] ?? ''));
            if ($content !== '') {
                $where[] = '(c.html LIKE ? OR c.plain LIKE ?)';
                $params[] = '%' . self::escapeLike($content) . '%';
                $params[] = '%' . self::escapeLike($content) . '%';
            }
            $status = (string) ($filters['status'] ?? '');
            if (in_array($status, self::STATUSES, true)) {
                $where[] = 'e.status = ?';
                $params[] = $status;
            }
            $substatus = (string) ($filters['substatus'] ?? '');
            if ($substatus === 'none') {
                $where[] = 'e.substatus IS NULL';
            } elseif (in_array($substatus, self::SUBSTATUSES, true)) {
                $where[] = 'e.substatus = ?';
                $params[] = $substatus;
            }
            if (!empty($filters['exclude_duplicates'])) {
                // Les doublons détectés à la réception sont auto-supprimés en `deleted_auto`.
                $where[] = "(e.substatus IS NULL OR e.substatus <> 'deleted_auto')";
            }
            $limit = max(1, min(self::MAX_EMAILS, (int) ($filters['quantity'] ?? 5)));
            $order = self::ORDERS[(string) ($filters['order'] ?? '')] ?? self::ORDERS['recent'];
        }

        $sql = sprintf(
            'SELECT e.id, e.created_at, e.sender_email, e.to_email, e.sender_subject, e.origin, e.status,
                    e.substatus, e.lead_id, LENGTH(c.html) AS html_length, LENGTH(c.plain) AS plain_length
             FROM si_received_email e
             INNER JOIN si_received_email_content c ON c.id = e.content_id
             WHERE %s
             ORDER BY %s
             LIMIT %d',
            implode(' AND ', $where),
            $order,
            $limit,
        );

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>> Résultat par email
     */
    public function replay(array $ids, array $options): array
    {
        $smtp = $this->smtpSender($options);
        if ($smtp === null && $this->secret === '') {
            throw new RuntimeException('INBOUND_TARGET_SECRET non renseigné dans le .env');
        }
        $envelopeFrom = self::optionalAddress($options, 'envelope_from');
        // Tunnel ouvert une fois pour le lot.
        $endpoint = $smtp?->connect();

        $results = [];
        foreach ($this->fetchFull($ids) as $i => $email) {
            if ($i > 0) {
                usleep(200_000);
            }
            $payload = $this->buildPayload($email, $options);
            $results[] = ['source_id' => (int) $email['id']] + ($smtp === null
                ? $this->post($this->encode($payload))
                : $smtp->send($endpoint, $envelopeFrom ?: $payload['envelope']['from'], $payload['envelope']['to'], $this->buildMime($payload)));
        }
        return $results;
    }

    /**
     * Payload JSON du webhook, ou message MIME brut en transport SMTP.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|string
     */
    public function preview(int $id, array $options): array|string
    {
        $emails = $this->fetchFull([$id]);
        if ($emails === []) {
            throw new InvalidArgumentException("Email $id introuvable ou anonymisé");
        }
        $payload = $this->buildPayload($emails[0], $options);
        return $this->smtpSender($options) === null ? $payload : $this->buildMime($payload);
    }

    /**
     * Libellé de la cible SMTP, vide si le transport SMTP n'est pas configuré.
     */
    public function getSmtpTarget(): string
    {
        return $this->smtp?->describe() ?? '';
    }

    /**
     * Domaine imposé aux destinataires, vide = domaine d'origine conservé.
     */
    public function getRecipientDomain(): string
    {
        return $this->recipientDomain;
    }

    /**
     * @return list<int>
     */
    public static function parseIds(string $raw): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id): bool => $id > 0,
        )));
        if (count($ids) > self::MAX_EMAILS) {
            throw new InvalidArgumentException(sprintf('%d IDs maximum', self::MAX_EMAILS));
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function fetchFull(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sql = sprintf(
            'SELECT e.id, e.created_at, e.received_date, e.sender_email, e.to_email, e.sender_subject,
                    e.additional_data, c.plain, c.html
             FROM si_received_email e
             INNER JOIN si_received_email_content c ON c.id = e.content_id
             WHERE e.anonymized_at IS NULL AND e.id IN (%s)
             ORDER BY e.id ASC',
            implode(',', array_fill(0, count($ids), '?')),
        );
        return $this->db->fetchAll($sql, $ids);
    }

    /**
     * Payload au format du MTA Oxeva (cf. InboundWebhookPayloadParser côté webapp).
     *
     * @param array<string, mixed> $email
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $email, array $options): array
    {
        $fresh = !empty($options['fresh']);
        $html = $email['html'];
        $plain = $email['plain'];
        if ($fresh) {
            $marker = 'toolv2-' . bin2hex(random_bytes(6));
            // Seul le hash de contenu change : le commentaire HTML n'est pas vu par le parsing.
            if (is_string($html) && trim($html) !== '') {
                $html .= "\n<!-- $marker -->";
            } else {
                $plain = rtrim((string) $plain) . "\n";
            }
        }

        $recipient = self::optionalAddress($options, 'recipient') ?: (string) $email['to_email'];
        if ($this->recipientDomain !== '') {
            // Partie locale (traceur) conservée, domaine de l'instance cible imposé.
            $recipient = strstr($recipient, '@', true) ?: $recipient;
            $recipient .= '@' . $this->recipientDomain;
        }
        $subject = (string) ($email['sender_subject'] ?? '');
        $prefix = trim((string) ($options['subject_prefix'] ?? ''));
        if ($prefix !== '') {
            $subject = $prefix . ' ' . $subject;
        }

        $receivedAt = !empty($options['keep_date']) && $email['received_date'] !== null
            ? new DateTimeImmutable((string) $email['received_date'])
            : new DateTimeImmutable();

        return [
            'id' => $fresh
                ? sprintf('toolv2-%d-%s@scorimmo.tool', $email['id'], bin2hex(random_bytes(8)))
                : sprintf('toolv2-%d@scorimmo.tool', $email['id']),
            'received_at' => $receivedAt->format(DATE_ATOM),
            'envelope' => [
                'from' => (string) $email['sender_email'],
                'to' => $recipient,
            ],
            'subject' => $subject,
            'text' => $plain,
            'html' => $html,
            'auth' => $this->authResults($email['additional_data'] ?? null),
        ];
    }

    /**
     * Résultats SPF / DKIM / DMARC d'origine, `pass` à défaut pour ne pas peser dans le score spam.
     *
     * @return array{spf: string, dkim: string, dmarc: string}
     */
    private function authResults(?string $additionalData): array
    {
        $security = [];
        if ($additionalData !== null && $additionalData !== '') {
            try {
                $security = json_decode($additionalData, true, 16, JSON_THROW_ON_ERROR)['security'] ?? [];
            } catch (JsonException) {
            }
        }
        return [
            'spf' => $security['spf_result'] ?? 'pass',
            'dkim' => $security['dkim_result'] ?? 'pass',
            'dmarc' => $security['dmarc_result'] ?? 'pass',
        ];
    }

    /**
     * Expéditeur SMTP du transport demandé, null pour le webhook.
     *
     * @param array<string, mixed> $options
     */
    private function smtpSender(array $options): ?InboundSmtpSender
    {
        if (($options['transport'] ?? self::TRANSPORT_WEBHOOK) !== self::TRANSPORT_SMTP) {
            return null;
        }
        return $this->smtp ?? throw new RuntimeException('INBOUND_SMTP_HOST non renseigné dans le .env');
    }

    /**
     * Adresse saisie dans les options (vide = non forcée), validée car reprise telle quelle dans
     * les en-têtes MIME et l'enveloppe.
     *
     * @param array<string, mixed> $options
     */
    private static function optionalAddress(array $options, string $key): string
    {
        $address = trim((string) ($options[$key] ?? ''));
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Adresse invalide ($key) : $address");
        }
        return $address;
    }

    /**
     * Message RFC 5322 multipart/alternative reconstruit depuis le payload : les en-têtes d'origine
     * ne sont pas stockés, seul le contenu parsé par Scorimmo (texte, HTML, objet, From) est rejoué.
     *
     * @param array<string, mixed> $payload
     */
    private function buildMime(array $payload): string
    {
        $boundary = 'toolv2-' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . $payload['envelope']['from'],
            'To: ' . $payload['envelope']['to'],
            'Subject: ' . mb_encode_mimeheader((string) $payload['subject'], 'UTF-8', 'B', "\r\n"),
            'Date: ' . (new DateTimeImmutable((string) $payload['received_at']))->format(DATE_RFC2822),
            'Message-ID: <' . $payload['id'] . '>',
            'MIME-Version: 1.0',
        ];

        $parts = [];
        foreach (['text/plain' => $payload['text'], 'text/html' => $payload['html']] as $type => $content) {
            if (is_string($content) && trim($content) !== '') {
                $parts[] = "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
            }
        }
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";

        $body = '';
        foreach ($parts as $part) {
            $body .= "--$boundary\r\n$part\r\n";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "--$boundary--\r\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array{code: int, success: bool, response: mixed, error: string|null, duration_ms: int}
     */
    private function post(string $body): array
    {
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $start = hrtime(true);
        $ch = curl_init($this->targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Scorimmo-Timestamp: ' . $timestamp,
                'X-Scorimmo-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        return [
            'code' => $httpCode,
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => $decoded ?? (is_string($response) ? mb_substr($response, 0, 2000) : null),
            'error' => $error !== '' ? $error : null,
            'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function typeCondition(string $type, array &$params): string
    {
        $methods = $this->emailTypes[$type]['search_methods'] ?? throw new InvalidArgumentException("Type d'email inconnu : $type");
        $columns = ['sender_email' => 'e.sender_email', 'html' => 'c.html', 'plain' => 'c.plain', 'sender_subject' => 'e.sender_subject', 'to_email' => 'e.to_email'];

        $conditions = [];
        foreach ($methods as $method) {
            $column = $columns[$method['field']] ?? throw new InvalidArgumentException("Champ non géré : {$method['field']}");
            $conditions[] = "$column LIKE ?";
            $params[] = '%' . self::escapeLike($method['pattern']) . '%';
        }
        return '(' . implode(' OR ', $conditions) . ')';
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
