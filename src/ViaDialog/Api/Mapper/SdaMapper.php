<?php

namespace ViaDialog\Api\Mapper;

use ViaDialog\Api\Entity\Sda;

/**
 * Mapper pour la conversion entre les données SDA et l'entité Sda
 * 
 * Cette classe implémente le pattern Mapper pour gérer la transformation
 * bidirectionnelle entre les données brutes de l'API ViaDialog (tableaux)
 * et les objets métier de type Sda.
 * 
 * Responsabilités :
 * - Conversion API → Entité : Transformation des réponses JSON en objets Sda
 * - Conversion Entité → API : Sérialisation des objets Sda pour les requêtes
 * - Gestion des valeurs par défaut et des champs optionnels
 * - Validation et formatage des dates
 * 
 * @package ViaDialog\Api\Mapper
 * @author Scorimmo
 * @since 1.0.0
 */
class SdaMapper
{
    /**
     * Convertit les données brutes de l'API en entité Sda
     * 
     * Cette méthode transforme un tableau associatif (typiquement issu d'une
     * réponse JSON de l'API) en objet Sda fortement typé. Elle gère les
     * valeurs par défaut pour les champs optionnels et effectue les conversions
     * de types nécessaires (notamment pour les dates).
     * 
     * Structure attendue du tableau d'entrée :
     * ```php
     * [
     *     'id' => 12345,                           // obligatoire
     *     'sdaNumber' => '+33123456789',           // obligatoire
     *     'sdaUsage' => 'INBOUND',                 // obligatoire
     *     'releasedDate' => '2024-01-15T10:30:00Z', // obligatoire (ISO 8601)
     *     'enable' => true,                        // obligatoire
     *     'number' => '0123456789',                // optionnel (défaut: sdaNumber)
     *     'status' => 'ACTIVE'                     // optionnel (défaut: basé sur enable)
     * ]
     * ```
     * 
     * @param array $data Tableau associatif contenant les données du SDA
     * @return Sda Instance de l'entité Sda créée à partir des données
     * 
     * @throws \DateMalformedStringException Si la date fournie n'est pas valide
     * @throws \InvalidArgumentException Si des champs obligatoires sont manquants
     * 
     * @example
     * ```php
     * $mapper = new SdaMapper();
     * $sdaData = ['id' => 1, 'sdaNumber' => '+33123456789', ...];
     * $sda = $mapper->mapToEntity($sdaData);
     * ```
     */
    public function mapToEntity(array $data): Sda
    {
        return new Sda(
            $data['id'],                                    // ID unique du SDA
            $data['sdaNumber'],                             // Numéro SDA complet
            $data['sdaUsage'],                              // Type d'utilisation
            new \DateTimeImmutable($data['releasedDate']),  // Date de mise en service
            $data['enable'],                                // État d'activation
            
            // Numéro court - utilise sdaNumber si 'number' n'est pas défini
            $data['number'] ?? $data['sdaNumber'],
            
            // Statut - génère automatiquement basé sur 'enable' si non fourni
            $data['status'] ?? ($data['enable'] ? 'active' : 'inactive')
        );
    }

    /**
     * Convertit une entité Sda en tableau pour l'API
     * 
     * Cette méthode effectue la transformation inverse : elle sérialise un
     * objet Sda en tableau associatif compatible avec les attentes de l'API
     * ViaDialog. Toutes les dates sont formatées au standard ISO 8601 (ATOM).
     * 
     * @param Sda $sda L'entité Sda à convertir
     * @return array Tableau associatif prêt pour l'envoi à l'API
     * 
     * @example
     * ```php
     * $mapper = new SdaMapper();
     * $arrayData = $mapper->mapToArray($sdaEntity);
     * // Résultat : tableau compatible API ViaDialog
     * ```
     */
    public function mapToArray(Sda $sda): array
    {
        return [
            'id' => $sda->getId(),
            'sdaNumber' => $sda->getSdaNumber(),
            'sdaUsage' => $sda->getSdaUsage(),
            
            // Formatage de la date au standard ISO 8601 (ATOM) pour l'API
            'releasedDate' => $sda
                ->getReleasedDate()
                ->format(\DateTimeInterface::ATOM),
                
            'enable' => $sda->isEnable(),
            'number' => $sda->getNumber(),
            'status' => $sda->getStatus(),
        ];
    }
}