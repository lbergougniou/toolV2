<?php

namespace ViaDialog\Api\Mapper;

use ViaDialog\Api\Entity\Service;
use ViaDialog\Api\Entity\Sda;

class ServiceMapper
{
    /**
     * Convertit les données brutes en entité Service
     */
    public function mapToEntity(array $data): Service
    {
        $service = new Service(
            $data['id'],
            $data['label'],
            $data['product'],
            $data['enable']
        );
        foreach ($data['sdaLists'] ?? [] as $sdaData) {
            $service->addSda(
                new Sda(
                    $sdaData['id'],
                    $sdaData['sdaNumber'] ?? $sdaData['commercial'],
                    $sdaData['sdaUsage'] ?? '',
                    new \DateTimeImmutable($sdaData['releasedDate'] ?? 'now'),
                    $sdaData['enable'] ?? true,
                    $sdaData['number'] ?? $sdaData['commercial'],
                    $sdaData['status'] ?? 'active'
                )
            );
        }
        return $service;
    }

    /**
     * Convertit une entité Service en tableau pour l'API
     */
    public function mapToArray(Service $service): array
    {
        return [
            'id' => $service->getId(),
            'label' => $service->getLabel(),
            'product' => $service->getProduct(),
            'sdaLists' => array_map(function (Sda $sda) {
                return [
                    'id' => $sda->getId(),
                    'commercial' => $sda->getNumber(),
                ];
            }, $service->getSdaList()),
        ];
    }
}
