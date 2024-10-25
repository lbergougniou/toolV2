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

class ViaDialogClient
{
    private const COMPANY_ID = 10000085;
    private const BASE_URI = 'https://viaflow-dashboard.viadialog.com';

    private Client $httpClient;
    private ?string $accessToken = null;
    private ServiceRepository $serviceRepository;
    private SdaRepository $sdaRepository;
    private ServiceMapper $serviceMapper;
    private SdaMapper $sdaMapper;

    public function __construct(
        private string $username,
        private string $password,
        private string $company,
        private string $grantType,
        private string $slug
    ) {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $this->accessToken
                ? $request->withHeader('Authorization', 'Bearer ' . $this->accessToken)
                : $request;
        }));

        $this->httpClient = new Client([
            'base_uri' => self::BASE_URI,
            'handler' => $stack,
            'verify' => false,
            'curl' => [
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ],
        ]);

        $this->serviceRepository = new ServiceRepository($this->httpClient);
        $this->sdaRepository = new SdaRepository($this->httpClient);
        $this->serviceMapper = new ServiceMapper();
        $this->sdaMapper = new SdaMapper();

        $this->authenticate();
    }

    private function authenticate(): void
    {
        try {
            $response = $this->httpClient->post('/gw/auth/login', [
                'json' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'company' => $this->company,
                    'grant_type' => $this->grantType,
                    'slug' => $this->slug,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $this->accessToken = $data['access_token'] ?? null;

            if (!$this->accessToken) {
                throw new AuthenticationException("Token d'accès non trouvé dans la réponse.");
            }
        } catch (\Exception $e) {
            throw new AuthenticationException("Échec de l'authentification: " . $e->getMessage());
        }
    }

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

    public function getServiceList(array $criteria = [], int $size = 500, int $page = 0): array
    {
        $this->ensureAuthenticated();
        try {
            $query = $this->buildQueryString($criteria, $size, $page);
            $url = '/gw/provisioning/api/via-services/stats/v2?' . $query;
            
            $this->logUrl($url);

            $response = $this->httpClient->get($url);
            $servicesData = json_decode((string) $response->getBody(), true);
            return array_map([$this->serviceMapper, 'mapToEntity'], $servicesData);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des services: " . $e->getMessage());
        }
    }

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

    public function updateServiceWithSda(string $serviceId, array $newSdas): array
    {
        $this->ensureAuthenticated();
        try {
            $serviceDetails = $this->getServiceDetails($serviceId);
            
            foreach ($newSdas as $newSda) {
                $serviceDetails['sdaLists'][] = [
                    "noir" => $newSda,
                    "technique" => $newSda,
                    "commercial" => $newSda,
                    "handoff" => "handoff1",
                    "scriptId" => null,
                    "scriptVersion" => null,
                    "companyId" => self::COMPANY_ID,
                    "weight" => null,
                    "legacyId" => null
                ];
            }

            $response = $this->httpClient->put("/gw/provisioning/api/via-services", [
                'json' => $serviceDetails
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la mise à jour du service avec les nouveaux SDA: " . $e->getMessage());
        }
    }

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

    private function buildQueryString(array $criteria, int $size, int $page): string
    {
        $query = http_build_query(['size' => $size, 'page' => $page]);
        foreach ($criteria as $filter) {
            $query .= '&filter=' . urlencode($filter);
        }
        return $query;
    }

    private function ensureAuthenticated(): void
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }
    }

    private function logUrl(string $url, string $method = 'GET'): void
    {
        error_log("[ViaDialogClient] {$method} " . self::BASE_URI . $url);
    }
}