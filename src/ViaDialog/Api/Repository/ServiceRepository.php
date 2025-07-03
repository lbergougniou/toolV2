<?php

namespace ViaDialog\Api\Repository;

use GuzzleHttp\Client;
use ViaDialog\Api\Exception\ApiException;

/**
 * Repository pour la gestion des Services ViaDialog
 * 
 * Cette classe implémente le pattern Repository pour encapsuler l'accès
 * aux données des services via l'API ViaDialog. Elle fournit une interface
 * unifiée pour les opérations CRUD sur les services.
 * 
 * Responsabilités :
 * - Récupération de services individuels ou par critères
 * - Mise à jour des configurations de service
 * - Gestion centralisée des erreurs API
 * - Abstraction des endpoints et formats de données
 * 
 * Endpoints API utilisés :
 * - GET /provisioning/api/via-services/{id} : Service individuel
 * - GET /provisioning/api/via-services/stats/v2 : Recherche avec critères
 * - PUT /provisioning/api/via-services/{id} : Mise à jour
 * 
 * @package ViaDialog\Api\Repository
 * @author Scorimmo
 * @since 1.0.0
 */
class ServiceRepository
{
    /**
     * Constructeur du repository Service
     * 
     * @param Client $httpClient Instance du client HTTP Guzzle pré-configuré
     *                          avec l'authentification et les headers nécessaires
     */
    public function __construct(private Client $httpClient) {}

    /**
     * Récupère un service spécifique par son identifiant
     * 
     * Cette méthode effectue une requête directe pour récupérer les détails
     * complets d'un service, incluant sa configuration et ses SDA associés.
     * 
     * @param int $id Identifiant unique du service à récupérer
     * 
     * @return array Tableau associatif contenant toutes les données du service
     *               Structure typique :
     *               [
     *                   'id' => 12345,
     *                   'label' => 'Service Client PDV-ABC',
     *                   'product' => 'VIACONTACT',
     *                   'enable' => true,
     *                   'sdaLists' => [...]
     *               ]
     * 
     * @throws ApiException Si le service n'existe pas (404) ou en cas d'erreur API
     * 
     * @example
     * ```php
     * $repository = new ServiceRepository($httpClient);
     * $service = $repository->find(12345);
     * echo $service['label']; // Affiche le nom du service
     * ```
     */
    public function find(int $id): array
    {
        try {
            // Requête GET directe vers l'endpoint du service spécifique
            $response = $this->httpClient->get("/provisioning/api/via-services/{$id}");
            
            // Décodage de la réponse JSON
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            // Transformation en exception métier avec contexte
            throw new ApiException("Erreur lors de la récupération du service: " . $e->getMessage());
        }
    }

    /**
     * Recherche des services selon des critères spécifiés
     * 
     * Cette méthode utilise l'endpoint de statistiques v2 qui permet
     * des recherches avancées avec filtres et pagination. Idéale pour
     * les listes de services avec critères complexes.
     * 
     * Critères de recherche supportés (exemples) :
     * - 'product' : Filtrer par type de produit (VIACONTACT, VIAMESSAGE, etc.)
     * - 'enable' : Filtrer par état d'activation (true/false)
     * - 'label' : Recherche par nom/libellé (recherche partielle possible)
     * - 'limit' : Limitation du nombre de résultats
     * - 'offset' : Pagination des résultats
     * - 'sort' : Tri des résultats (field:direction)
     * 
     * @param array $criteria Tableau associatif des critères de recherche
     *                       Exemple : ['product' => 'VIACONTACT', 'enable' => true]
     * 
     * @return array Tableau contenant la liste des services correspondants
     *               Structure typique avec métadonnées :
     *               [
     *                   'data' => [services...],
     *                   'total' => 150,
     *                   'limit' => 50,
     *                   'offset' => 0
     *               ]
     * 
     * @throws ApiException En cas d'erreur lors de la requête API
     * 
     * @example
     * ```php
     * $repository = new ServiceRepository($httpClient);
     * 
     * // Rechercher tous les services VIACONTACT actifs
     * $services = $repository->findBy([
     *     'product' => 'VIACONTACT',
     *     'enable' => true,
     *     'limit' => 100
     * ]);
     * 
     * // Rechercher avec pagination et tri
     * $services = $repository->findBy([
     *     'limit' => 20,
     *     'offset' => 40,
     *     'sort' => 'label:asc'
     * ]);
     * ```
     */
    public function findBy(array $criteria): array
    {
        try {
            // Construction de la query string à partir des critères
            $query = http_build_query($criteria);
            
            // Requête vers l'endpoint de statistiques v2 (plus riche en fonctionnalités)
            $response = $this->httpClient->get("/provisioning/api/via-services/stats/v2?{$query}");
            
            // Décodage de la réponse JSON
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            // Transformation en exception métier
            throw new ApiException("Erreur lors de la récupération des services: " . $e->getMessage());
        }
    }

    /**
     * Met à jour un service existant
     * 
     * Cette méthode permet de modifier la configuration d'un service
     * en envoyant les nouvelles données via une requête PUT. Seuls
     * les champs fournis dans le tableau de données seront modifiés.
     * 
     * Champs modifiables typiques :
     * - 'label' : Nouveau nom du service
     * - 'enable' : Activation/désactivation
     * - 'sdaLists' : Modification des SDA associés
     * - Configuration spécifique selon le type de service
     * 
     * @param int $id Identifiant du service à mettre à jour
     * @param array $data Tableau associatif des données à modifier
     *                   Seuls les champs présents seront mis à jour
     * 
     * @return array Réponse de l'API contenant les données mises à jour
     *               Généralement le service complet avec ses nouvelles valeurs
     * 
     * @throws ApiException Si le service n'existe pas ou en cas d'erreur de validation
     * 
     * @example
     * ```php
     * $repository = new ServiceRepository($httpClient);
     * 
     * // Renommer un service
     * $result = $repository->update(12345, [
     *     'label' => 'Nouveau nom du service'
     * ]);
     * 
     * // Désactiver un service
     * $result = $repository->update(12345, [
     *     'enable' => false
     * ]);
     * 
     * // Modifier les SDA associés
     * $result = $repository->update(12345, [
     *     'sdaLists' => [
     *         ['id' => 1, 'commercial' => '+33123456789'],
     *         ['id' => 2, 'commercial' => '+33987654321']
     *     ]
     * ]);
     * ```
     */
    public function update(int $id, array $data): array
    {
        try {
            // Requête PUT avec les données JSON dans le corps de la requête
            $response = $this->httpClient->put("/provisioning/api/via-services/{$id}", [
                'json' => $data  // Guzzle encode automatiquement en JSON
            ]);
            
            // Décodage de la réponse contenant les données mises à jour
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            // Transformation en exception métier avec contexte
            throw new ApiException("Erreur lors de la mise à jour du service: " . $e->getMessage());
        }
    }
}