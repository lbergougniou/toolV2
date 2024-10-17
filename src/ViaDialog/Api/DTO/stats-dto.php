<?php

declare(strict_types=1);

namespace ViaDialog\Api\DTO;

use DateTimeImmutable;
use JsonSerializable;

/**
 * DTO représentant des statistiques
 */
class StatsDTO implements JsonSerializable
{
    /**
     * @param string $agentId Identifiant de l'agent
     * @param int $totalCalls Nombre total d'appels
     * @param int $answeredCalls Nombre d'appels répondus
     * @param int $missedCalls Nombre d'appels manqués
     * @param float $averageHandlingTime Temps moyen de traitement
     * @param DateTimeImmutable $periodStart Début de la période
     * @param DateTimeImmutable $periodEnd Fin de la période
     */
    public function __construct(
        public readonly string $agentId,
        public readonly int $totalCalls,
        public readonly int $answeredCalls,
        public readonly int $missedCalls,
        public readonly float $averageHandlingTime,
        public readonly DateTimeImmutable $periodStart,
        public readonly DateTimeImmutable $periodEnd
    ) {}

    /**
     * Crée une instance de StatsDTO à partir d'un tableau de données
     * 
     * @param array $data Données statistiques
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['agentId'],
            $data['totalCalls'],
            $data['answeredCalls'],
            $data['missedCalls'],
            $data['averageHandlingTime'],
            new DateTimeImmutable($data['periodStart']),
            new DateTimeImmutable($data['periodEnd'])
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
            'periodStart' => $this->periodStart->format(DateTimeImmutable::ATOM),
            'periodEnd' => $this->periodEnd->format(DateTimeImmutable::ATOM)
        ]);
    }
}
