<?php

namespace ViaDialog\Api\Entity;

/**
 * Entité Service ViaDialog
 * 
 * Cette classe représente un service de communication dans le système ViaDialog.
 * Un service peut être de différents types (contact center, messagerie, etc.)
 * et peut être associé à plusieurs numéros SDA.
 * 
 * @package ViaDialog\Api\Entity
 * @author Scorimmo
 */
class Service
{
    /**
     * Liste des SDA associés à ce service
     * 
     * @var array<Sda> Collection des objets SDA liés au service
     */
    private array $sdaList = [];

    /**
     * Constructeur de l'entité Service
     * 
     * @param int $id Identifiant unique du service
     * @param string $label Libellé/nom du service (ex: "Support Client PDV-123")
     * @param string $product Type de produit ViaDialog (VIACONTACT, VIAMESSAGE, etc.)
     * @param bool $enable État d'activation du service (true = actif, false = inactif)
     */
    public function __construct(
        private int $id,
        private string $label,
        private string $product,
        private bool $enable
    ) {}

    /**
     * Récupère l'identifiant unique du service
     * 
     * @return int L'ID du service
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Récupère le libellé du service
     * 
     * @return string Le nom/libellé du service tel qu'affiché dans l'interface
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Récupère le type de produit ViaDialog
     * 
     * @return string Le produit associé (VIACONTACT pour centre de contact,
     *                VIAMESSAGE pour messagerie, etc.)
     */
    public function getProduct(): string
    {
        return $this->product;
    }

    /**
     * Ajoute un SDA à la liste des SDA associés au service
     * 
     * Cette méthode permet d'associer un numéro SDA au service.
     * Un service peut avoir plusieurs SDA (par exemple, un numéro principal
     * et des numéros secondaires).
     * 
     * @param Sda $sda L'objet SDA à ajouter au service
     * @return void
     */
    public function addSda(Sda $sda): void
    {
        $this->sdaList[] = $sda;
    }

    /**
     * Récupère la liste complète des SDA associés au service
     * 
     * @return array<Sda> Tableau contenant tous les objets SDA liés au service
     */
    public function getSdaList(): array
    {
        return $this->sdaList;
    }

    /**
     * Vérifie si le service est activé
     * 
     * @return bool true si le service est actif et opérationnel, 
     *              false s'il est désactivé ou suspendu
     */
    public function isEnable(): bool
    {
        return $this->enable;
    }
}