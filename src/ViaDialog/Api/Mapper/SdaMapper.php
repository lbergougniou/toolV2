<?php

namespace ViaDialog\Api\Mapper;

use ViaDialog\Api\Entity\Sda;

class SdaMapper
{
    /**
     * Convertit les données brutes en entité Sda
     */
    public function mapToEntity(array $data): Sda
    {
        return new Sda(
            $data['id'],
            $data['sdaNumber'],
            $data['sdaUsage'],
            new \DateTimeImmutable($data['releasedDate']),
            $data['enable'],
            $data['number'] ?? $data['sdaNumber'],
            $data['status'] ?? ($data['enable'] ? 'active' : 'inactive')
        );
    }

    /**
     * Convertit une entité Sda en tableau pour l'API
     */
    public function mapToArray(Sda $sda): array
    {
        return [
            'id' => $sda->getId(),
            'sdaNumber' => $sda->getSdaNumber(),
            'sdaUsage' => $sda->getSdaUsage(),
            'releasedDate' => $sda
                ->getReleasedDate()
                ->format(\DateTimeInterface::ATOM),
            'enable' => $sda->isEnable(),
            'number' => $sda->getNumber(),
            'status' => $sda->getStatus(),
        ];
    }
}
