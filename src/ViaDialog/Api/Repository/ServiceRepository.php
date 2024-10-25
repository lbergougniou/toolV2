<?php

namespace ViaDialog\Api\Repository;

use GuzzleHttp\Client;
use ViaDialog\Api\Exception\ApiException;

class ServiceRepository
{
    public function __construct(private Client $httpClient) {}

    /**
     * Trouve un service par son ID
     */
    public function find(int $id): array
    {
        try {
            $response = $this->httpClient->get("/provisioning/api/via-services/{$id}");
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération du service: " . $e->getMessage());
        }
    }

    /**
     * Trouve des services selon les critères spécifiés
     */
    public function findBy(array $criteria): array
    {
        try {
            $query = http_build_query($criteria);
            $response = $this->httpClient->get("/provisioning/api/via-services/stats/v2?{$query}");
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la récupération des services: " . $e->getMessage());
        }
    }

    /**
     * Met à jour un service
     */
    public function update(int $id, array $data): array
    {
        try {
            $response = $this->httpClient->put("/provisioning/api/via-services/{$id}", ['json' => $data]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new ApiException("Erreur lors de la mise à jour du service: " . $e->getMessage());
        }
    }
}