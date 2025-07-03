<?php

namespace ViaDialog\Api\Repository;

use GuzzleHttp\Client;
use ViaDialog\Api\Exception\ApiException;

/**
 * Repository pour la gestion des SDA (Sélection Directe à l'Arrivée)
 * 
 * Cette classe implémente le pattern Repository pour encapsuler l'accès
 * aux données SDA via l'API ViaDialog. Elle fournit une couche d'abstraction
 * entre la logique métier et les appels HTTP directs.
 * 
 * Responsabilités :
 * - Exécution des requêtes HTTP vers l'endpoint SDA
 * - Transformation des réponses JSON en tableaux PHP
 * - Gestion centralisée des erreurs avec logging détaillé
 * - Construction automatique des query strings pour les critères de recherche
 * 
 * Endpoint API utilisé : /gw/provisioning/api/sdas
 * 
 * @package ViaDialog\Api\Repository
 * @author Scorimmo
 * @since 1.0.0
 */
class SdaRepository
{
    /**
     * Constructeur du repository SDA
     * 
     * @param Client $httpClient Instance du client HTTP Guzzle configuré
     *                          avec l'authentification et les headers nécessaires
     */
    public function __construct(private Client $httpClient) {}

    /**
     * Recherche des SDA selon des critères spécifiés
     * 
     * Cette méthode permet de récupérer une liste de SDA en appliquant
     * des filtres via des paramètres de requête. Les critères sont
     * automatiquement convertis en query string HTTP.
     * 
     * Critères de recherche supportés (exemples) :
     * - 'status' : Filtrer par statut (ACTIVE, INACTIVE, etc.)
     * - 'usage' : Filtrer par type d'usage (INBOUND, OUTBOUND, etc.)
     * - 'enable' : Filtrer par état d'activation (true/false)
     * - 'limit' : Limiter le nombre de résultats
     * - 'offset' : Pagination des résultats
     * 
     * @param array $criteria Tableau associatif des critères de recherche
     *                       Exemple : ['status' => 'ACTIVE', 'limit' => 50]
     * 
     * @return array Tableau contenant les données SDA retournées par l'API
     *               Structure typique : [['id' => 1, 'sdaNumber' => '+33...'], ...]
     * 
     * @throws ApiException Si une erreur survient lors de l'appel API
     *                     (erreur réseau, réponse invalide, authentification, etc.)
     * 
     * @example
     * ```php
     * $repository = new SdaRepository($httpClient);
     * 
     * // Rechercher tous les SDA actifs
     * $activeSdas = $repository->findBy(['status' => 'ACTIVE']);
     * 
     * // Rechercher avec pagination
     * $pagedSdas = $repository->findBy(['limit' => 20, 'offset' => 40]);
     * 
     * // Rechercher par type d'usage
     * $inboundSdas = $repository->findBy(['usage' => 'INBOUND']);
     * ```
     */
    public function findBy(array $criteria): array
    {
        try {
            // Construction automatique de la query string à partir des critères
            $query = http_build_query($criteria);
            
            // Exécution de la requête GET avec debugging activé pour le monitoring
            $response = $this->httpClient->get("/gw/provisioning/api/sdas?{$query}", [
                'debug' => true, // Active le logging détaillé des requêtes/réponses
            ]);
            
            // Décodage de la réponse JSON en tableau PHP
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            // Logging détaillé pour faciliter le debugging en cas d'erreur
            
            // Log du message d'erreur principal
            error_log("Erreur lors de la récupération des SDA: " . $e->getMessage());
            
            // Log des détails de la requête HTTP
            error_log("Requête: " . $e->getRequest()->getMethod() . ' ' . $e->getRequest()->getUri());
            error_log("Headers de la requête: " . json_encode($e->getRequest()->getHeaders()));
            
            // Log de la réponse si disponible (erreurs HTTP 4xx/5xx)
            if ($e->hasResponse()) {
                error_log("Réponse: " . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase());
                error_log("Corps de la réponse: " . $e->getResponse()->getBody());
            }
            
            // Transformation en exception métier avec message contextualisé
            throw new ApiException("Erreur lors de la récupération des SDA: " . $e->getMessage());
        }
    }
}