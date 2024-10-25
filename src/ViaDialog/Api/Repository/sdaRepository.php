<?php

namespace ViaDialog\Api\Repository;

use GuzzleHttp\Client;
use ViaDialog\Api\Exception\ApiException;

class SdaRepository
{
    public function __construct(private Client $httpClient) {}

    public function findBy(array $criteria): array
    {
        try {
            $query = http_build_query($criteria);
            $response = $this->httpClient->get("/gw/provisioning/api/sdas?{$query}", [
                'debug' => true, // Ceci va logger la requête complète et la réponse
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            error_log("Erreur lors de la récupération des SDA: " . $e->getMessage());
            error_log("Requête: " . $e->getRequest()->getMethod() . ' ' . $e->getRequest()->getUri());
            error_log("Headers de la requête: " . json_encode($e->getRequest()->getHeaders()));
            if ($e->hasResponse()) {
                error_log("Réponse: " . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase());
                error_log("Corps de la réponse: " . $e->getResponse()->getBody());
            }
            throw new ApiException("Erreur lors de la récupération des SDA: " . $e->getMessage());
        }
    }
}