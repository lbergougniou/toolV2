<?php

namespace ViaDialog\Api\Mapper;

use ViaDialog\Api\Entity\Service;
use ViaDialog\Api\Entity\Sda;

/**
 * Mapper pour la conversion entre les données Service et l'entité Service
 * 
 * Cette classe implémente le pattern Mapper pour gérer la transformation
 * bidirectionnelle entre les données brutes de l'API ViaDialog et les objets
 * métier Service. Elle gère également la conversion des SDA associés.
 * 
 * Caractéristiques principales :
 * - Conversion API → Entité : Transformation des réponses JSON complexes
 * - Gestion des relations : Service contient une collection de SDA
 * - Mapping flexible : Gestion des variations de nommage API
 * - Valeurs par défaut : Protection contre les données incomplètes
 * 
 * @package ViaDialog\Api\Mapper
 * @author Scorimmo
 * @since 1.0.0
 */
class ServiceMapper
{
    /**
     * Convertit les données brutes de l'API en entité Service
     * 
     * Cette méthode transforme un tableau associatif complexe (incluant
     * les SDA associés) en objet Service fortement typé. Elle gère les
     * incohérences de nommage de l'API et applique des valeurs par défaut
     * intelligentes pour les champs optionnels.
     * 
     * Structure attendue du tableau d'entrée :
     * ```php
     * [
     *     'id' => 67890,                    // obligatoire
     *     'label' => 'Service Client',      // obligatoire
     *     'product' => 'VIACONTACT',        // obligatoire
     *     'enable' => true,                 // obligatoire
     *     'sdaLists' => [                   // optionnel (peut être vide)
     *         [
     *             'id' => 12345,
     *             'sdaNumber' => '+33123456789',     // ou 'commercial'
     *             'sdaUsage' => 'INBOUND',           // optionnel
     *             'releasedDate' => '2024-01-15',    // optionnel
     *             'enable' => true,                  // optionnel (défaut: true)
     *             'number' => '0123456789',          // ou 'commercial'
     *             'status' => 'ACTIVE'               // optionnel (défaut: 'active')
     *         ]
     *     ]
     * ]
     * ```
     * 
     * Gestion des inconsistances API :
     * - 'sdaNumber' peut être dans 'commercial'
     * - 'number' peut être dans 'commercial'
     * - Dates par défaut à 'now' si non fournies
     * 
     * @param array $data Tableau associatif contenant les données du service
     * @return Service Instance de l'entité Service avec ses SDA associés
     * 
     * @throws \DateMalformedStringException Si une date SDA n'est pas valide
     * @throws \InvalidArgumentException Si des champs obligatoires sont manquants
     * 
     * @example
     * ```php
     * $mapper = new ServiceMapper();
     * $serviceData = ['id' => 1, 'label' => 'Support', ...];
     * $service = $mapper->mapToEntity($serviceData);
     * echo count($service->getSdaList()); // Nombre de SDA associés
     * ```
     */
    public function mapToEntity(array $data): Service
    {
        // Création de l'entité Service principale
        $service = new Service(
            $data['id'],           // ID unique du service
            $data['label'],        // Nom/libellé du service
            $data['product'],      // Type de produit ViaDialog
            $data['enable']        // État d'activation
        );
        
        // Traitement des SDA associés (si présents)
        foreach ($data['sdaLists'] ?? [] as $sdaData) {
            // Création d'une entité SDA pour chaque élément de la liste
            $service->addSda(
                new Sda(
                    $sdaData['id'],
                    
                    // Gestion des variations de nommage API : 'sdaNumber' ou 'commercial'
                    $sdaData['sdaNumber'] ?? $sdaData['commercial'],
                    
                    // Usage du SDA (optionnel, chaîne vide par défaut)
                    $sdaData['sdaUsage'] ?? '',
                    
                    // Date de release (par défaut : maintenant si non fournie)
                    new \DateTimeImmutable($sdaData['releasedDate'] ?? 'now'),
                    
                    // État d'activation (par défaut : actif)
                    $sdaData['enable'] ?? true,
                    
                    // Numéro court : 'number' ou 'commercial' en fallback
                    $sdaData['number'] ?? $sdaData['commercial'],
                    
                    // Statut (par défaut : actif)
                    $sdaData['status'] ?? 'active'
                )
            );
        }
        
        return $service;
    }

    /**
     * Convertit une entité Service en tableau pour l'API
     * 
     * Cette méthode sérialise un objet Service (et ses SDA associés) en
     * tableau associatif compatible avec les attentes de l'API ViaDialog.
     * Elle effectue le mapping inverse en utilisant la nomenclature
     * attendue par l'API.
     * 
     * Particularités du mapping de sortie :
     * - Les SDA sont mappés avec le champ 'commercial' (nomenclature API)
     * - Seules les informations essentielles sont incluses
     * - Structure optimisée pour les requêtes API
     * 
     * @param Service $service L'entité Service à convertir
     * @return array Tableau associatif prêt pour l'envoi à l'API
     * 
     * @example
     * ```php
     * $mapper = new ServiceMapper();
     * $arrayData = $mapper->mapToArray($serviceEntity);
     * // Résultat : structure compatible API ViaDialog
     * ```
     */
    public function mapToArray(Service $service): array
    {
        return [
            'id' => $service->getId(),
            'label' => $service->getLabel(),
            'product' => $service->getProduct(),
            
            // Mapping des SDA associés avec la nomenclature API
            'sdaLists' => array_map(function (Sda $sda) {
                return [
                    'id' => $sda->getId(),
                    // Utilisation de 'commercial' pour correspondre à l'API
                    'commercial' => $sda->getNumber(),
                ];
            }, $service->getSdaList()),
        ];
    }
}