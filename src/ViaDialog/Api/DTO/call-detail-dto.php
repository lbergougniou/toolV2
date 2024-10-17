<?php

declare(strict_types=1);

namespace ViaDialog\Api\DTO;

use DateTimeImmutable;
use JsonSerializable;

/**
 * DTO représentant les détails d'un appel
 */
class CallDetailDTO implements JsonSerializable
{
    /**
     * @param string $id Identifiant unique de l'appel
     * @param string $serviceId Identifiant du service
     * @param string $agentId Identifiant de l'agent
     * @param string $customerPhoneNumber Numéro de téléphone du client
     * @param DateTimeImmutable $startTime Heure de début de l'appel
     * @param DateTimeImmutable $endTime Heure de fin de l'appel
     * @param string $status Statut de l'appel
     * @param int|null $duration Durée de l'appel en secondes (optionnel)
     * @param string|null $recordingUrl URL de l'enregistrement (optionnel)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $serviceId,
        public readonly string $agentId,
        public readonly string $customerPhoneNumber,
        public readonly DateTimeImmutable $startTime,
        public readonly DateTimeImmutable $endTime,
        public readonly string $status,
        public readonly ?int $duration,
        public readonly ?string $recordingUrl
    ) {}

    /**
     * Crée une instance de CallDetailDTO à partir d'un tableau de données
     * 
     * @param array $data Données de l'appel
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['serviceId'],
            $data['agentId'],
            $data['customerPhoneNumber'],
            new DateTimeImmutable($data['startTime']),
            new DateTimeImmutable($data['endTime']),
            $data['status'],
            $data['duration'] ?? null,
            $data['recordingUrl'] ?? null
        );
    }

    /**
     * Sérialise l'objet en tableau
     * 
     * @return array
     */
    public function jsonSerialize(): array
    {
        return array_merge(get_object_vars($this), [
            'startTime' => $this->startTime->format(DateTimeImmutable::ATOM),
            'endTime' => $this->endTime->format(DateTimeImmutable::ATOM)
        ]);
    }
}
