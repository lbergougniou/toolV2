<?php

namespace ViaDialog\Api;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use ViaDialog\Api\Repository\ServiceRepository;
use ViaDialog\Api\Repository\SdaRepository;
use ViaDialog\Api\Mapper\ServiceMapper;
use ViaDialog\Api\Mapper\SdaMapper;
use ViaDialog\Api\Exception\AuthenticationException;
use ViaDialog\Api\Exception\ApiException;

/**
 * Client principal pour l'API ViaDialog
 * 
 * Cette classe constitue le point d'entrée principal pour toutes les interactions
 * avec l'API ViaDialog. Elle encapsule l'authentification, la gestion des tokens,
 * et fournit une interface unifiée pour les opérations sur les services et SDA.
 * 
 * Fonctionnalités principales :
 * - Authentification automatique avec gestion du token Bearer
 * - Opérations CRUD sur les services ViaContact
 * - Gestion des SDA (Sélection Directe à l'Arrivée)
 * - Configuration et mise à jour des webhooks
 * - Logging et monitoring des requêtes API
 * 
 * Architecture :
 * - Utilise le pattern Repository pour l'accès aux données
 * - Implémente le pattern Mapper pour la transformation des données
 * - Gestion centralisée des erreurs avec exceptions typées
 * - Client HTTP Guzzle avec middleware d'authentification
 * 
 * @package ViaDialog\Api
 * @author Scorimmo
 * @since 1.0.0
 */
class ViaDialogClient
{
    /** @var int ID de l'entreprise par défaut pour les opérations ViaDialog */
    private const COMPANY_ID = 10000085;
    
    /** @var string URL de base de l'API ViaDialog */
    private const BASE_URI = 'https://viaflow-dashboard.viadialog.com';

    /** @var Client Instance du client HTTP Guzzle configuré */
    private Client $httpClient;
    
    /** @var string|null Token d'accès Bearer pour l'authentification */
    private ?string $accessToken = null;
    
    /** @var ServiceRepository Repository pour les opérations sur les services */
    private ServiceRepository $serviceRepository;
    
    /** @var SdaRepository Repository pour les opérations sur les SDA */
    private SdaRepository $sdaRepository;
    
    /** @var ServiceMapper Mapper pour la conversion des données de service */
    private ServiceMapper $serviceMapper;
    
    /** @var SdaMapper Mapper pour la conversion des données SDA */
    private SdaMapper $sdaMapper;

    /**
     * Constructeur du client ViaDialog
     * 
     * Initialise le client avec les credentials d'authentification et configure
     * l'infrastructure nécessaire (client HTTP, repositories, mappers).
     * L'authentification est effectuée automatiquement lors de l'instanciation.
     * 
     * @param string $username Nom d'utilisateur pour l'authentification API
     * @param string $password Mot de passe pour l'authentification API
     * @param string $company Identifiant de l'entreprise
     * @param string $grantType Type de grant OAuth (généralement 'password')
     * @param string $slug Slug de l'application/environnement
     * 
     * @throws AuthenticationException Si l'authentification initiale échoue
     * 
     * @example
     * ```php
     * $client = new ViaDialogClient(
     *     'username',
     *     'password',
     *     'company_id',
     *     'password',
     *     'app_slug'
     * );
     * ```
     */
    public function __construct(
        private string $username,
        private string $password,
        private string $company,
        private string $grantType,
        private string $slug
    ) {
        // Configuration du stack de middlewares pour l'injection automatique du token
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            // Ajout automatique du header Authorization si un token est disponible
            return $this->accessToken
                ? $request->withHeader('Authorization', 'Bearer ' . $this->accessToken)
                : $request;
        }));

        // Configuration du client HTTP Guzzle
        $this->httpClient = new Client([
            'base_uri' => self::BASE_URI,
            'handler' => $stack,
            'timeout' => 60,    // Timeout de 60 secondes pour les requêtes
            'connect_timeout' => 10,  // Timeout de connexion de 10 secondes
            'verify' => false,  // Désactivation de la vérification SSL (environnement de dev)
            'curl' => [
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ],
        ]);

        // Initialisation des repositories et mappers
        $this->serviceRepository = new ServiceRepository($this->httpClient);
        $this->sdaRepository = new SdaRepository($this->httpClient);
        $this->serviceMapper = new ServiceMapper();
        $this->sdaMapper = new SdaMapper();

        // Authentification automatique lors de l'instanciation
        $this->authenticate();
    }

    /**
     * Authentifie le client auprès de l'API ViaDialog
     * 
     * Effectue une requête de login et stocke le token d'accès pour les
     * requêtes ultérieures. Le token est automatiquement injecté dans
     * tous les appels API via le middleware configuré.
     * 
     * @throws AuthenticationException Si l'authentification échoue ou si le token est absent
     * 
     * @return void
     */
    private function authenticate(): void
    {
        try {
            // Requête d'authentification avec les credentials
            $response = $this->httpClient->post('/gw/auth/login', [
                'json' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'company' => $this->company,
                    'grant_type' => $this->grantType,
                    'slug' => $this->slug,
                ],
            ]);

            // Extraction du token d'accès depuis la réponse
            $data = json_decode($response->getBody(), true);
            $this->accessToken = $data['access_token'] ?? null;

            // Vérification de la présence du token
            if (!$this->accessToken) {
                throw new AuthenticationException("Token d'accès non trouvé dans la réponse.");
            }
        } catch (\Exception $e) {
            throw new AuthenticationException("Échec de l'authentification: " . $e->getMessage());
        }
    }

    /**
     * Récupère un service spécifique par son ID
     * 
     * @param string $serviceId Identifiant unique du service
     * @return Service Entité Service complètement hydratée
     * @throws ApiException Si le service n'existe pas ou en cas d'erreur API
     */
    public function getService(string $serviceId): Service
    {
        $this->ensureAuthenticated();
        try {
            $serviceData = $this->serviceRepository->find($serviceId);
            return $this->serviceMapper->mapToEntity($serviceData);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération du service: " . $e->getMessage());
        }
    }

    /**
     * Récupère une liste de services selon des critères
     * 
     * Cette méthode permet de rechercher et paginer les services avec
     * des filtres personnalisés. Utilise l'endpoint de statistiques v2
     * pour des capacités de recherche avancées.
     * 
     * @param array $criteria Critères de filtrage (optionnel)
     * @param int $size Nombre maximum de résultats par page (défaut: 500)
     * @param int $page Numéro de page pour la pagination (défaut: 0)
     * 
     * @return array Tableau d'entités Service
     * @throws ApiException En cas d'erreur lors de la récupération
     * 
     * @example
     * ```php
     * $services = $client->getServiceList(['product' => 'VIACONTACT'], 100, 0);
     * ```
     */
    public function getServiceList(array $criteria = [], int $size = 500, int $page = 0): array
    {
        $this->ensureAuthenticated();
        try {
            // Construction de la query string avec pagination et filtres
            $query = $this->buildQueryString($criteria, $size, $page);
            $url = '/gw/provisioning/api/via-services/stats/v2?' . $query;
            
            $this->logUrl($url);

            $response = $this->httpClient->get($url);
            $servicesData = json_decode((string) $response->getBody(), true);
            
            // Transformation des données brutes en entités Service
            return array_map([$this->serviceMapper, 'mapToEntity'], $servicesData);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des services: " . $e->getMessage());
        }
    }

    /**
     * Récupère les détails complets d'un service
     * 
     * Contrairement à getService(), cette méthode retourne les données
     * brutes de l'API sans transformation en entité.
     * 
     * @param string $serviceId Identifiant du service
     * @return array Données brutes du service depuis l'API
     * @throws ApiException En cas d'erreur API
     */
    public function getServiceDetails(string $serviceId): array
    {
        $this->ensureAuthenticated();
        try {
            $response = $this->httpClient->get("/gw/provisioning/api/via-services/{$serviceId}");
            return json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des détails du service: " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau service ViaContact
     * 
     * Cette méthode permet de créer un service avec une configuration
     * personnalisée. Le payload doit être conforme au format attendu
     * par l'API ViaDialog.
     * 
     * @param array $serviceData Configuration complète du service à créer
     *                          Doit inclure au minimum : label, product, enable
     * 
     * @return array Réponse de l'API contenant les détails du service créé
     * @throws ApiException Si la création échoue ou si les données sont invalides
     * 
     * @example
     * ```php
     * $service = $client->createService([
     *     'label' => 'Service Client PDV-123',
     *     'product' => 'VIACONTACT',
     *     'enable' => true,
     *     // ... autres paramètres de configuration
     * ]);
     * ```
     */
    public function createService(array $serviceData): array
    {
        $this->ensureAuthenticated();
        try {
            $this->logUrl('/gw/provisioning/api/via-services', 'POST');
            
            // Création du service via requête POST
            $response = $this->httpClient->post('/gw/provisioning/api/via-services', [
                'json' => $serviceData
            ]);

            $responseData = json_decode((string) $response->getBody(), true);
            
            // Log de confirmation de création
            error_log("Service créé avec succès. ID: " . ($responseData['id'] ?? 'N/A'));
            
            return $responseData;
        } catch (\Exception $e) {
            error_log("Erreur lors de la création du service: " . $e->getMessage());
            throw new ApiException("Erreur lors de la création du service: " . $e->getMessage());
        }
    }

    /**
     * Met à jour un service en y ajoutant de nouveaux SDA
     * 
     * Cette méthode récupère la configuration actuelle du service,
     * y ajoute les nouveaux numéros SDA et sauvegarde la configuration
     * mise à jour.
     * 
     * @param string $serviceId Identifiant du service à modifier
     * @param array $newSdas Tableau des nouveaux numéros SDA à ajouter
     * 
     * @return array Réponse de l'API avec la configuration mise à jour
     * @throws ApiException En cas d'erreur lors de la mise à jour
     * 
     * @example
     * ```php
     * $result = $client->updateServiceWithSda('12345', ['+33123456789', '+33987654321']);
     * ```
     */
    public function updateServiceWithSda(string $serviceId, array $newSdas): array
    {
        $this->ensureAuthenticated();
        try {
            // Récupération de la configuration actuelle du service
            $serviceDetails = $this->getServiceDetails($serviceId);
            
            // Ajout des nouveaux SDA à la configuration
            foreach ($newSdas as $newSda) {
                $serviceDetails['sdaLists'][] = [
                    "noir" => $newSda,              // Numéro pour la liste noire
                    "technique" => $newSda,         // Numéro technique
                    "commercial" => $newSda,        // Numéro commercial affiché
                    "handoff" => "handoff1",        // Configuration de handoff
                    "scriptId" => null,             // ID du script (optionnel)
                    "scriptVersion" => null,        // Version du script (optionnel)
                    "companyId" => self::COMPANY_ID, // ID de l'entreprise
                    "weight" => null,               // Poids pour la répartition (optionnel)
                    "legacyId" => null              // ID legacy (optionnel)
                ];
            }

            // Sauvegarde de la configuration mise à jour
            $response = $this->httpClient->put("/gw/provisioning/api/via-services", [
                'json' => $serviceDetails
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la mise à jour du service avec les nouveaux SDA: " . $e->getMessage());
        }
    }

    /**
     * Récupère la liste des SDA disponibles
     * 
     * @param array $criteria Critères de filtrage (optionnel)
     * @param int $size Nombre maximum de résultats (défaut: 500)
     * @param int $page Numéro de page pour la pagination (défaut: 0)
     * 
     * @return array Tableau d'entités SDA
     * @throws ApiException En cas d'erreur lors de la récupération
     */
    public function getSdaList(array $criteria = [], int $size = 500, int $page = 0): array
    {
        $this->ensureAuthenticated();
        try {
            $query = $this->buildQueryString($criteria, $size, $page);
            $url = '/gw/provisioning/api/sdas?' . $query;
    
            $this->logUrl($url);

            $response = $this->httpClient->get($url);
    
            $sdasData = json_decode((string) $response->getBody(), true);
            return array_map([$this->sdaMapper, 'mapToEntity'], $sdasData);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des SDA: " . $e->getMessage());
        }
    }

    /**
     * Construit une query string pour les requêtes paginées avec filtres
     * 
     * @param array $criteria Critères de filtrage
     * @param int $size Taille de page
     * @param int $page Numéro de page
     * 
     * @return string Query string formatée pour l'URL
     */
    private function buildQueryString(array $criteria, int $size, int $page): string
    {
        // Paramètres de pagination
        $query = http_build_query(['size' => $size, 'page' => $page]);
        
        // Ajout des filtres personnalisés
        foreach ($criteria as $filter) {
            $query .= '&filter=' . urlencode($filter);
        }
        return $query;
    }

    /**
     * Vérifie que le client est authentifié et ré-authentifie si nécessaire
     * 
     * @throws AuthenticationException Si la ré-authentification échoue
     * 
     * @return void
     */
    private function ensureAuthenticated(): void
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }
    }

    /**
     * Récupère la liste des webhooks configurés
     * 
     * @param int $size Nombre maximum de webhooks à récupérer (défaut: 1000)
     * @param int $page Numéro de page pour la pagination (défaut: 0)
     * 
     * @return array Liste des webhooks avec leur configuration
     * @throws ApiException En cas d'erreur lors de la récupération
     */
    public function getWebhookList(int $size = 1000, int $page = 0): array
    {
        $this->ensureAuthenticated();
        try {
            $query = http_build_query(['size' => $size, 'page' => $page]);
            $url = '/gw/webhook/api/via-webhooks?' . $query;
            
            $this->logUrl($url);

            $response = $this->httpClient->get($url);
            return json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des webhooks: " . $e->getMessage());
        }
    }

    /**
     * Met à jour la configuration d'un webhook
     * 
     * @param array $webhookData Configuration complète du webhook à mettre à jour
     * 
     * @return array Réponse de l'API avec la configuration mise à jour
     * @throws ApiException En cas d'erreur lors de la mise à jour
     */
    public function updateWebhook(array $webhookData): array
    {
        $this->ensureAuthenticated();
        try {
            $this->logUrl('/gw/webhook/api/via-webhooks', 'PUT');
            
            $response = $this->httpClient->put('/gw/webhook/api/via-webhooks', [
                'json' => $webhookData
            ]);

            $responseData = json_decode((string) $response->getBody(), true);
            
            error_log("Webhook mis à jour avec succès. ID: " . ($webhookData['id'] ?? 'N/A'));
            
            return $responseData;
        } catch (\Exception $e) {
            error_log("Erreur lors de la mise à jour du webhook: " . $e->getMessage());
            throw new ApiException("Erreur lors de la mise à jour du webhook: " . $e->getMessage());
        }
    }

    /**
     * Ajoute un service à plusieurs webhooks spécifiés
     * 
     * Cette méthode permet d'associer automatiquement un service nouvellement
     * créé aux webhooks configurés. Elle évite les doublons et fournit un
     * rapport détaillé des opérations effectuées.
     * 
     * @param int $serviceId Identifiant du service à ajouter aux webhooks
     * @param array $webhookIds Liste des IDs des webhooks à mettre à jour
     * 
     * @return array Rapport détaillé des opérations effectuées pour chaque webhook
     *               Format : [['webhookId' => 1, 'success' => true, 'serviceAdded' => true], ...]
     * 
     * @throws ApiException En cas d'erreur lors de la gestion des webhooks
     * 
     * @example
     * ```php
     * $results = $client->addServiceToWebhooks(12345, [1, 2, 3]);
     * foreach ($results as $result) {
     *     if ($result['success']) {
     *         echo "Webhook {$result['webhookId']} mis à jour avec succès\n";
     *     }
     * }
     * ```
     */
    public function addServiceToWebhooks(int $serviceId, array $webhookIds): array
    {
        $this->ensureAuthenticated();
        $results = [];
        
        try {
            // Récupération de la liste complète des webhooks
            $webhooks = $this->getWebhookList();
            
            foreach ($webhookIds as $webhookId) {
                // Recherche du webhook correspondant à l'ID
                $webhook = null;
                foreach ($webhooks as $w) {
                    if ($w['id'] == $webhookId) {
                        $webhook = $w;
                        break;
                    }
                }
                
                if ($webhook) {
                    // Vérification que le service n'est pas déjà associé
                    if (!in_array($serviceId, $webhook['serviceChannelIds'])) {
                        // Ajout du service à la liste des services du webhook
                        $webhook['serviceChannelIds'][] = $serviceId;
                        
                        // Mise à jour du webhook via l'API
                        $updateResult = $this->updateWebhook($webhook);
                        $results[] = [
                            'webhookId' => $webhookId,
                            'webhookLabel' => $webhook['label'],
                            'success' => true,
                            'serviceAdded' => true
                        ];
                        
                        error_log("Service $serviceId ajouté au webhook {$webhook['label']} (ID: $webhookId)");
                    } else {
                        // Service déjà présent, pas de modification nécessaire
                        $results[] = [
                            'webhookId' => $webhookId,
                            'webhookLabel' => $webhook['label'],
                            'success' => true,
                            'serviceAdded' => false,
                            'message' => 'Service déjà présent dans ce webhook'
                        ];
                        
                        error_log("Service $serviceId déjà présent dans le webhook {$webhook['label']} (ID: $webhookId)");
                    }
                } else {
                    // Webhook non trouvé
                    $results[] = [
                        'webhookId' => $webhookId,
                        'success' => false,
                        'error' => "Webhook avec l'ID $webhookId non trouvé"
                    ];
                    
                    error_log("Webhook avec l'ID $webhookId non trouvé");
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            error_log("Erreur lors de l'ajout du service aux webhooks: " . $e->getMessage());
            throw new ApiException("Erreur lors de l'ajout du service aux webhooks: " . $e->getMessage());
        }
    }

    /**
     * Met à jour un service existant
     *
     * Cette méthode permet de mettre à jour la configuration complète d'un service.
     * Elle accepte soit un objet stdClass soit un tableau associatif.
     *
     * @param object|array $serviceData Configuration complète du service à mettre à jour
     *                                  Doit inclure au minimum l'ID du service
     *
     * @return array Réponse de l'API contenant les détails du service mis à jour
     * @throws ApiException Si la mise à jour échoue
     *
     * @example
     * ```php
     * $service = $client->getService('12345');
     * $service->maxUser = 10;
     * $result = $client->updateService($service);
     * ```
     */
    public function updateService($serviceData): array
    {
        $this->ensureAuthenticated();
        try {
            $this->logUrl('/gw/provisioning/api/via-services', 'PUT');

            // Conversion en array si nécessaire
            if (is_object($serviceData)) {
                $serviceData = json_decode(json_encode($serviceData), true);
            }

            // Mise à jour du service via requête PUT
            $response = $this->httpClient->put('/gw/provisioning/api/via-services', [
                'json' => $serviceData
            ]);

            $responseData = json_decode((string) $response->getBody(), true);

            // Log de confirmation de mise à jour
            error_log("Service mis à jour avec succès. ID: " . ($responseData['id'] ?? 'N/A'));

            return $responseData;
        } catch (\Exception $e) {
            error_log("Erreur lors de la mise à jour du service: " . $e->getMessage());
            throw new ApiException("Erreur lors de la mise à jour du service: " . $e->getMessage());
        }
    }

    /**
     * Log les URLs des requêtes API pour le monitoring et le debugging
     *
     * @param string $url URL relative de la requête
     * @param string $method Méthode HTTP utilisée (défaut: GET)
     *
     * @return void
     */
    private function logUrl(string $url, string $method = 'GET'): void
    {
        error_log("[ViaDialogClient] {$method} " . self::BASE_URI . $url);
    }
}