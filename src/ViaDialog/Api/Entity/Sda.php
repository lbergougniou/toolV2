<?php

namespace ViaDialog\Api\Entity;

class Sda
{
    public function __construct(
        private int $id,
        private string $sdaNumber,
        private string $sdaUsage,
        private \DateTimeImmutable $releasedDate,
        private bool $enable,
        private string $number,
        private string $status
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getSdaNumber(): string
    {
        return $this->sdaNumber;
    }

    public function getSdaUsage(): string
    {
        return $this->sdaUsage;
    }

    public function getReleasedDate(): \DateTimeImmutable
    {
        return $this->releasedDate;
    }

    public function isEnable(): bool
    {
        return $this->enable;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
