<?php

namespace App\Scraping\Enum;

enum ScraperType: string
{
    case LEBONCOIN = 'leboncoin';
    
    /**
     * Retourne le nom d'affichage du scraper
     */
    public function getDisplayName(): string
    {
        return match($this) {
            self::LEBONCOIN => 'Le Bon Coin',
        };
    }

    /**
     * Retourne le nom de la classe du scraper
     */
    public function getClassName(): string
    {
        return match($this) {
            self::LEBONCOIN => 'LeboncoinScraper',
        };
    }

    /**
     * Retourne tous les scrapers disponibles sous forme de tableau pour les formulaires
     * @return array<string, string>
     */
    public static function getFormChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getDisplayName();
        }
        return $choices;
    }
}
