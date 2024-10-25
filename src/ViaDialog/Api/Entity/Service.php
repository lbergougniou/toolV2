<?php

namespace ViaDialog\Api\Entity;

class Service
{
    private array $sdaList = [];

    public function __construct(
        private int $id,
        private string $label,
        private string $product,
        private bool $enable
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getProduct(): string
    {
        return $this->product;
    }

    public function addSda(Sda $sda): void
    {
        $this->sdaList[] = $sda;
    }

    public function getSdaList(): array
    {
        return $this->sdaList;
    }

    public function isEnable(): bool
    {
        return $this->enable;
    }
}