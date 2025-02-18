<?php

namespace App\Scraping\Interfaces;

interface ScraperInterface
{
    /**
     * Recherche une annonce par sa référence
     *
     * @param string $reference
     * @return array
     */
    public function searchByReference(string $reference): array;
}
