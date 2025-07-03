<?php

namespace ViaDialog\Api\Entity;

/**
 * Entité SDA (Sélection Directe à l'Arrivée)
 * 
 * Cette classe représente un numéro SDA dans le système ViaDialog.
 * Un SDA permet d'attribuer un numéro direct à un service ou un utilisateur
 * pour faciliter les appels entrants.
 * 
 * @package ViaDialog\Api\Entity
 * @author Scorimmo
 */
class Sda
{
    /**
     * Constructeur de l'entité SDA
     * 
     * @param int $id Identifiant unique du SDA
     * @param string $sdaNumber Numéro SDA complet (ex: +33123456789)
     * @param string $sdaUsage Type d'utilisation du SDA (INBOUND, OUTBOUND, etc.)
     * @param \DateTimeImmutable $releasedDate Date de mise en service du SDA
     * @param bool $enable État d'activation du SDA (true = actif, false = inactif)
     * @param string $number Numéro court ou alias du SDA
     * @param string $status Statut actuel du SDA (ACTIVE, SUSPENDED, etc.)
     */
    public function __construct(
        private int $id,
        private string $sdaNumber,
        private string $sdaUsage,
        private \DateTimeImmutable $releasedDate,
        private bool $enable,
        private string $number,
        private string $status
    ) {}

    /**
     * Récupère l'identifiant unique du SDA
     * 
     * @return int L'ID du SDA
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Récupère le numéro SDA complet
     * 
     * @return string Le numéro SDA au format international (ex: +33123456789)
     */
    public function getSdaNumber(): string
    {
        return $this->sdaNumber;
    }

    /**
     * Récupère le type d'utilisation du SDA
     * 
     * @return string Le type d'usage du SDA (INBOUND pour les appels entrants, 
     *                OUTBOUND pour les appels sortants, etc.)
     */
    public function getSdaUsage(): string
    {
        return $this->sdaUsage;
    }

    /**
     * Récupère la date de mise en service du SDA
     * 
     * @return \DateTimeImmutable La date de release du SDA (immuable pour éviter les modifications accidentelles)
     */
    public function getReleasedDate(): \DateTimeImmutable
    {
        return $this->releasedDate;
    }

    /**
     * Vérifie si le SDA est activé
     * 
     * @return bool true si le SDA est actif, false sinon
     */
    public function isEnable(): bool
    {
        return $this->enable;
    }

    /**
     * Récupère le numéro court ou l'alias du SDA
     * 
     * @return string Le numéro court, souvent utilisé pour l'affichage 
     *                ou la configuration interne
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Récupère le statut actuel du SDA
     * 
     * @return string Le statut du SDA (ACTIVE, SUSPENDED, TERMINATED, etc.)
     */
    public function getStatus(): string
    {
        return $this->status;
    }
}