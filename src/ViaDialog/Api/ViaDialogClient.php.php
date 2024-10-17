<?php

declare(strict_types=1);

namespace ViaDialog\Api;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use ViaDialog\Api\Exception\AuthenticationException;
use ViaDialog\Api\Exception\ApiException;
use ViaDialog\Api\DTO\ServiceDTO;
use ViaDialog\Api\DTO\AgentDTO;
use ViaDialog\Api\DTO\StatsDTO;
use ViaDialog\Api\DTO\CallDetailDTO;
use ViaDialog\Api\DTO\SdaDTO;

/**
 * Classe ViadApi
 * 
 * Cette classe fournit une interface pour interagir avec l'API ViaDialog.
 * Elle gère l'authentification, l'envoi de requêtes et le traitement des réponses.
 */
class ViadApi
{
    private const BASE_URL = 'https://viaflow-dashboard.viadialog.com';
    private const COMPANY_ID = 10000085;

    private Client $httpClient;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    /**
     * Constructeur de ViadApi
     * 
     * @param string $username Nom d'utilisateur pour l'authentification
     * @param string $password Mot de passe pour l'authentification
     * @param string $company Nom de la compagnie
     * @param string $grantType Type d'autorisation (généralement 'password')
     * @param string $slug Identifiant unique de l'application
     * @param Client|null $httpClient Client HTTP personnalisé (optionnel)
     */
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $company,
        private readonly string $grantType,
        private readonly string $slug,
        ?Client $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => self::BASE_URL,
            'timeout'  => 30.0,
        ]);
    }

    /**
     * Authentifie l'utilisateur auprès de l'API
     * 
     * @throws AuthenticationException Si l'authentification échoue
     */
    public function authenticate(): void
    {
        try {
            $response = $this->httpClient->post('/gw/auth/login', [
                'json' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'company' => $this->company,
                    'grant_type' => $this->grantType,
                    'slug' => $this->slug,
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (!isset($data['access_token']) || !isset($data['refresh_token'])) {
                throw new AuthenticationException('Réponse d\'authentification invalide');
            }

            $this->accessToken = $data['access_token'];
            $this->refreshToken = $data['refresh_token'];
        } catch (GuzzleException $e) {
            throw new AuthenticationException('Échec de l\'authentification : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Récupère les informations d'un service spécifique
     * 
     * @param string $serviceId Identifiant du service
     * @return ServiceDTO Informations du service
     * @throws ApiException Si la requête échoue
     */
    public function getService(string $serviceId): ServiceDTO
    {
        $response = $this->sendRequest('GET', "/provisioning/api/via-services/{$serviceId}");
        return ServiceDTO::fromArray(json_decode((string) $response->getBody(), true));
    }

    /**
     * Récupère la liste des services actifs
     * 
     * @return ServiceDTO[] Liste des services
     * @throws ApiException Si la requête échoue
     */
    public function getServiceList(): array
    {
        $response = $this->sendRequest('GET', '/provisioning/api/via-services/stats/v2', [
            'query' => ['size' => 500, 'filter' => 'eq,enable,true']
        ]);
        
        $data = json_decode((string) $response->getBody(), true);
        return array_map(fn ($item) => ServiceDTO::fromArray($item), $data);
    }

    /**
     * Met à jour un service
     * 
     * @param ServiceDTO $service Service à mettre à jour
     * @return ServiceDTO Service mis à jour
     * @throws ApiException Si la requête échoue
     */
    public function updateService(ServiceDTO $service): ServiceDTO
    {
        $response = $this->sendRequest('PUT', '/provisioning/api/via-services/', [
            'json' => $service->jsonSerialize()
        ]);
        
        return ServiceDTO::fromArray(json_decode((string) $response->getBody(), true));
    }

    /**
     * Récupère les statistiques de connexion d'un agent
     * 
     * @param string $agentId Identifiant de l'agent
     * @param DateTimeInterface $start Date de début de la période
     * @param DateTimeInterface $end Date de fin de la période
     * @return StatsDTO Statistiques de connexion
     * @throws ApiException Si la requête échoue
     */
    public function getAgentLoginStats(string $agentId, DateTimeInterface $start, DateTimeInterface $end): StatsDTO
    {
        $response = $this->sendRequest('POST', '/gw/viastats/api/agent/first-login/', [
            'json' => [
                'agentIds' => [$agentId],
                'start' => $start->format(DateTimeInterface::ATOM),
                'end' => $end->format(DateTimeInterface::ATOM),
                'companyId' => self::COMPANY_ID,
            ]
        ]);
        
        return StatsDTO::fromArray(json_decode((string) $response->getBody(), true));
    }

    /**
     * Récupère les statistiques d'un agent
     * 
     * @param string $agentId Identifiant de l'agent
     * @param DateTimeInterface $start Date de début de la période
     * @param DateTimeInterface $end Date de fin de la période
     * @return StatsDTO Statistiques de l'agent
     * @throws ApiException Si la requête échoue
     */
    public function getAgentStats(string $agentId, DateTimeInterface $start, DateTimeInterface $end): StatsDTO
    {
        $response = $this->sendRequest('POST', '/gw/viastats/api/agent/summary/aggregated/stat-by-agentId', [
            'json' => [
                'agentIds' => [$agentId],
                'start' => $start->format(DateTimeInterface::ATOM),
                'end' => $end->format(DateTimeInterface::ATOM),
            ]
        ]);
        
        return StatsDTO::fromArray(json_decode((string) $response->getBody(), true));
    }

    /**
     * Récupère les informations des agents
     * 
     * @return AgentDTO[] Liste des agents
     * @throws ApiException Si la requête échoue
     */
    public function getAgentInfo(): array
    {
        $response = $this->sendRequest('GET', '/gw/provisioning/api/via-agents/stats', [
            'query' => ['sort' => 'label,asc', 'size' => 3000, 'page' => 0]
        ]);
        
        $data = json_decode((string) $response->getBody(), true);
        return array_map(fn ($item) => AgentDTO::fromArray($item), $data);
    }

    /**
     * Récupère les détails des appels
     * 
     * @param string $serviceId Identifiant du service
     * @param DateTimeInterface $start Date de début de la période
     * @param DateTimeInterface $end Date de fin de la période
     * @return CallDetailDTO[] Liste des détails d'appels
     * @throws ApiException Si la requête échoue
     */
    public function getDetailCall(string $serviceId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->sendRequest('POST', '/gw/call-log/api/eventlog/calllog/', [
            'json' => [
                'companyId' => self::COMPANY_ID,
                'serviceIds' => [$serviceId],
                'groupIds' => [],
                'agentIds' => [],
                'postCodeIds' => null,
                'currentStatus' => [],
                'startDate' => $start->format(DateTimeInterface::ATOM),
                'endDate' => $end->format(DateTimeInterface::ATOM),
                'count' => true,
                'quality' => null,
                'record' => null,
                'phoneNumber' => null,
                'callId' => null,
                'taskId' => null,
                'page' => 0,
                'pageSize' => 2000,
            ]
        ]);
        
        $data = json_decode((string) $response->getBody(), true);
        return array_map(fn ($item) => CallDetailDTO::fromArray($item), $data);
    }

    /**
     * Récupère la liste des SDA disponibles
     * 
     * @param array $filters Filtres optionnels pour la requête
     * @return SdaDTO[] Liste des SDA
     * @throws ApiException Si la requête échoue
     */
    public function getSdaList(array $filters = []): array
    {
        $queryParams = array_merge(['unpaged' => 'true'], $filters);
        
        $response = $this->sendRequest('GET', '/gw/provisioning/api/sdas', [
            'query' => $queryParams
        ]);

        $data = json_decode((string) $response->getBody(), true);
        return array_map(fn ($item) => SdaDTO::fromArray($item), $data);
    }

    /**
     * Envoie une requête à l'API
     * 
     * @param string $method Méthode HTTP
     * @param string $uri URI de la requête
     * @param array $options Options de la requête
     * @return ResponseInterface Réponse de l'API
     * @throws ApiException Si la requête échoue
     */
    private function sendRequest(string $method, string $uri, array $options = []): ResponseInterface
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        $options['headers'] = [
            'Authorization' => "Bearer {$this->accessToken}",
            'Content-Type' => 'application/json',
        ];

        try {
            return $this->httpClient->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            throw new ApiException('Échec de la requête API : ' . $e->getMessage(), 0, $e);
        }
    }
}
