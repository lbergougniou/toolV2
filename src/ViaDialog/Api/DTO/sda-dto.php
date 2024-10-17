<?php

declare(strict_types=1);

namespace ViaDialog\Api\DTO;

use DateTimeImmutable;
use JsonSerializable;

/**
 * DTO représentant un numéro SDA (Sélection Directe à l'Arrivée)
 */
class SdaDTO implements JsonSerializable
{
    /**
     * @param string $id Identifiant unique du SDA
     * @param string $number Numéro SDA
     * @param string $status Statut du SDA
     * @param string|null $assignedServiceId Identifiant du service assigné (optionnel)
     * @param DateTimeImmutable|null $lastUsedDate Date de dernière utilisation (optionnel)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly string $status,
        public readonly ?string $assignedServiceId,
        public readonly ?DateTimeImmutable $lastUsedDate
    ) {}

    /**
     * Crée une instance de SdaDTO à partir d'un tableau de données
     * 
     * @param array $data Données du SDA
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['number'],
            $data['status'],
            $data['assignedServiceId'] ?? null,
            isset($data['lastUsedDate']) ? new DateTimeImmutable($data['lastUsedDate']) : null
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
            'lastUsedDate' => $this->lastUsedDate?->format(DateTimeImmutable::ATOM)
        ]);
    }
}
