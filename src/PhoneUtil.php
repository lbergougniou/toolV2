<?php

namespace App;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

/**
 * Classe PhoneUtil - Utilitaire pour la validation et la détection des numéros téléphoniques
 * 
 * Cette classe permet de :
 * - Détecter le pays d'origine d'un numéro de téléphone
 * - Valider les numéros de téléphone selon différents formats
 * - Gérer spécifiquement les numéros français et des DOM-TOM
 * 
 * Version utilisant un fichier JSON pour obtenir les informations des pays
 */
class PhoneUtil
{
    /**
     * @var PhoneNumberUtil Instance de la bibliothèque libphonenumber
     */
    private PhoneNumberUtil $phoneUtil;

    /**
     * @var bool Active ou désactive le mode débogage
     */
    private bool $debug = false;

    /**
     * @var array Stockage des messages de débogage
     */
    private array $logs = [];
    
    /**
     * @var array Données des pays chargées depuis le fichier JSON
     */
    private array $countriesData = [];
    
    /**
     * @var string Chemin vers le fichier JSON des données de pays
     */
    private string $jsonFilePath;

    /**
     * Constructeur - Initialise l'instance de PhoneNumberUtil et charge les données JSON
     * 
     * @param string $jsonFilePath Chemin vers le fichier JSON (optionnel)
     */
    public function __construct(string $jsonFilePath = null)
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
        
        // Définir le chemin par défaut du fichier JSON s'il n'est pas fourni
        $this->jsonFilePath = $jsonFilePath ?? __DIR__ . '/../public/data/countries_data.json';
        
        // Charger les données des pays depuis le fichier JSON
        $this->loadCountriesData();
    }
    
    /**
     * Charge les données des pays depuis le fichier JSON
     * 
     * @return void
     * @throws \RuntimeException Si le fichier est introuvable ou invalide
     */
    private function loadCountriesData(): void
    {
        if (!file_exists($this->jsonFilePath)) {
            throw new \RuntimeException("Le fichier JSON des pays n'existe pas: {$this->jsonFilePath}");
        }
        
        $jsonContent = file_get_contents($this->jsonFilePath);
        if ($jsonContent === false) {
            throw new \RuntimeException("Impossible de lire le fichier JSON: {$this->jsonFilePath}");
        }
        
        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Erreur de décodage JSON: " . json_last_error_msg());
        }
        
        if (!isset($data['countries']) || !is_array($data['countries'])) {
            throw new \RuntimeException("Format JSON invalide: la clé 'countries' est manquante ou n'est pas un tableau");
        }
        
        $this->countriesData = $data['countries'];
        $this->log("Données des pays chargées", count($this->countriesData) . " pays trouvés");
    }
    
    /**
     * Recherche un pays dans les données JSON par son code pays
     * 
     * @param string $countryCode Code ISO du pays (2 lettres)
     * @return array|null Données du pays ou null si non trouvé
     */
    private function findCountryByCode(string $countryCode): ?array
    {
        $code = strtolower($countryCode);
        
        foreach ($this->countriesData as $country) {
            if (strtolower($country['code']) === $code) {
                return $country;
            }
        }
        
        return null;
    }
    
    /**
     * Recherche un pays dans les données JSON par son préfixe téléphonique et sous-préfixe
     * 
     * @param string $phoneNumber Numéro de téléphone au format international (+XX...)
     * @return array|null Données du pays ou null si non trouvé
     */
    private function findCountryByPhonePrefix(string $phoneNumber): ?array
    {
        if (!str_starts_with($phoneNumber, '+')) {
            return null;
        }
        
        // Pour les territoires avec des sous-préfixes (comme +262 pour Réunion/Mayotte)
        foreach ($this->countriesData as $country) {
            if (isset($country['phoneNumbering']['phoneCode']) && 
                str_starts_with($phoneNumber, $country['phoneNumbering']['phoneCode'])) {
                
                // Si des sous-préfixes sont définis, vérifier aussi les sous-préfixes
                if (isset($country['phoneNumbering']['subPrefixes']) && 
                    !empty($country['phoneNumbering']['subPrefixes'])) {
                    
                    $numericNumber = substr($phoneNumber, 1); // Enlever le +
                    
                    foreach ($country['phoneNumbering']['subPrefixes'] as $subPrefix) {
                        if (str_starts_with($numericNumber, $subPrefix)) {
                            return $country;
                        }
                    }
                } else {
                    // Si pas de sous-préfixes, retourner le pays correspondant au préfixe principal
                    return $country;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Active ou désactive le mode débogage
     * 
     * @param bool $debug État du mode débogage
     * @return void
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
    
    /**
     * Récupère les logs générés pendant le traitement
     * 
     * @return array Liste des messages de log
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
    
    /**
     * Ajoute un message au journal de débogage
     * 
     * @param string $message Message à journaliser
     * @param mixed $data Données additionnelles (optionnel)
     * @return void
     */
    private function log(string $message, $data = null): void
    {
        if ($this->debug) {
            $this->logs[] = [
                'time' => microtime(true),
                'message' => $message,
                'data' => $data
            ];
        }
    }
    
    /**
     * Méthode principale : détecte le pays d'un numéro de téléphone
     * 
     * @param string $phoneNumber Numéro de téléphone à analyser
     * @return array Informations sur le pays du numéro
     */
    public function detectPhoneCountry(string $phoneNumber): array
    {
        // Réinitialiser les logs
        $this->logs = [];
        
        // Enregistrer le numéro original
        $this->log("Numéro original", $phoneNumber);
        
        // Nettoyage du numéro (suppression de tous les caractères non-numériques sauf +)
        $cleanNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        $this->log("Numéro nettoyé", $cleanNumber);
        
        // Prétraitement pour convertir les numéros français au format international
        // Format: 06XXXXXXXX -> +336XXXXXXXX
        if (preg_match('/^0([1-9])([0-9]{8})$/', $cleanNumber, $matches)) {
            $cleanNumber = '+33' . $matches[1] . $matches[2];
            $this->log("Conversion du format français en international", $cleanNumber);
        }
        
        // Conversion des formats avec 00 en préfixe international (+)
        // Format: 0033XXXXXXX -> +33XXXXXXX
        if (preg_match('/^00([0-9]{2,3})([0-9]+)$/', $cleanNumber, $matches)) {
            $cleanNumber = '+' . $matches[1] . $matches[2];
            $this->log("Conversion du format 00XX en +XX", $cleanNumber);
        }
        
        // Si le numéro ne commence pas par +, tentative d'analyse avec le préfixe français
        if (!str_starts_with($cleanNumber, '+')) {
            $cleanNumber = '+33' . ltrim($cleanNumber, '0');
            $this->log("Ajout du préfixe français par défaut", $cleanNumber);
        }
        
        try {
            // Tentative de parsing avec le contexte français (FR)
            $this->log("Tentative de parsing avec région FR", $cleanNumber);
            $parsedNumber = $this->phoneUtil->parse($cleanNumber, 'FR');
            
            // Validation selon les règles générales
            $isValidGlobal = $this->phoneUtil->isValidNumber($parsedNumber);
            
            $this->log("Résultat de validation", [
                'isValidGlobal' => $isValidGlobal
            ]);
            
            // Si le numéro est globalement valide
            if ($isValidGlobal) {
                // Détermination du code pays
                $countryCode = strtolower($this->phoneUtil->getRegionCodeForNumber($parsedNumber));
                $this->log("Code pays détecté par libphonenumber", $countryCode);
                
                // Récupérer le numéro au format international pour la recherche par préfixe
                $internationalNumber = $this->phoneUtil->format($parsedNumber, PhoneNumberFormat::INTERNATIONAL);
                $this->log("Numéro international formaté", $internationalNumber);
                
                // Convertir le format libphonenumber (+33 6 12 34 56 78) en format brut +33612345678
                $internationalRaw = '+' . $parsedNumber->getCountryCode() . $parsedNumber->getNationalNumber();
                $this->log("Numéro international brut", $internationalRaw);
                
                // Recherche du pays par préfixe téléphonique pour les cas particuliers (DOM-TOM)
                $countryByPrefix = $this->findCountryByPhonePrefix($internationalRaw);
                
                if ($countryByPrefix !== null) {
                    $this->log("Pays trouvé par préfixe", $countryByPrefix);
                    
                    // Déterminer si c'est un territoire français (DOM-TOM)
                    $isFrenchTerritory = ($countryByPrefix['phoneNumbering']['territory'] === 'fr');
                    
                    return [
                        'success' => true,
                        'isValid' => true,
                        'countryCode' => strtolower($countryByPrefix['code']),
                        'phoneCode' => $countryByPrefix['phoneNumbering']['phoneCode'],
                        'isFrench' => ($countryByPrefix['code'] === 'fr' || $isFrenchTerritory),
                        'region' => isset($countryByPrefix['region']) ? $countryByPrefix['region'] : null,
                        'country' => $countryByPrefix['name']['fr'] ?? null,
                        'territory' => $countryByPrefix['phoneNumbering']['territory'] ?? null
                    ];
                }
                
                // Recherche dans notre fichier JSON par le code pays
                $countryData = $this->findCountryByCode($countryCode);
                
                if ($countryData !== null) {
                    $this->log("Pays trouvé dans le JSON par code", $countryData);
                    
                    // Déterminer si c'est un territoire français (DOM-TOM)
                    $isFrenchTerritory = isset($countryData['phoneNumbering']['territory']) && 
                                         $countryData['phoneNumbering']['territory'] === 'fr';
                    
                    return [
                        'success' => true,
                        'isValid' => true,
                        'countryCode' => strtolower($countryData['code']),
                        'phoneCode' => $countryData['phoneNumbering']['phoneCode'],
                        'isFrench' => ($countryData['code'] === 'fr' || $isFrenchTerritory),
                        'region' => isset($countryData['region']) ? $countryData['region'] : null,
                        'country' => $countryData['name']['fr'] ?? null,
                        'territory' => $countryData['phoneNumbering']['territory'] ?? null
                    ];
                }
                
                // Si le pays n'est pas dans notre JSON, fournir les infos de base
                return [
                    'success' => true,
                    'isValid' => true,
                    'countryCode' => $countryCode,
                    'phoneCode' => '+' . $parsedNumber->getCountryCode(),
                    'country' => null,
                    'region' => null,
                    'territory' => null
                ];
            }
        } catch (NumberParseException $e) {
            $this->log("Erreur lors du parsing", $e->getMessage());
        }
        
        // ====== ÉCHEC DE VALIDATION : NUMÉRO INVALIDE ======
        $this->log("Échec de toutes les méthodes de validation");
        
        return [
            'success' => true,
            'isValid' => false,
            'message' => 'Numéro de téléphone non valide',
            'logs' => $this->logs
        ];
    }

    /**
     * Méthode utilitaire - Vérifie si un numéro est valide
     * 
     * @param string $phoneNumber Numéro à vérifier
     * @return bool True si le numéro est valide, false sinon
     */
    public function isValidPhoneNumber(string $phoneNumber): bool
    {
        $result = $this->detectPhoneCountry($phoneNumber);
        return isset($result['isValid']) && $result['isValid'] === true;
    }

    /**
     * Récupère toutes les données pays depuis le fichier JSON
     * 
     * @return array Tableau des pays
     */
    public function getAllCountries(): array
    {
        return $this->countriesData;
    }

    /**
     * Récupère les pays groupés par région
     * 
     * @return array Tableau des pays groupés par région
     */
    public function getCountriesByRegion(): array
    {
        $regions = [];
        
        foreach ($this->countriesData as $country) {
            $region = isset($country['region']) ? $country['region'] : 'Autre';
            
            if (!isset($regions[$region])) {
                $regions[$region] = [];
            }
            
            // Ajouter le pays à sa région
            $regions[$region][] = [
                'code' => $country['code'],
                'name' => $country['name']['fr'] ?? $country['name']['en'] ?? $country['code'],
                'flag_code' => $country['code'],
                'phoneCode' => $country['phoneNumbering']['phoneCode'] ?? null,
                'territory' => $country['phoneNumbering']['territory'] ?? null
            ];
        }
        
        // Trier les régions par nom
        ksort($regions);
        
        // Trier les pays dans chaque région par nom
        foreach ($regions as &$countries) {
            usort($countries, function ($a, $b) {
                return $a['name'] <=> $b['name'];
            });
        }
        
        return $regions;
    }
    
    /**
     * Formate un numéro de téléphone selon le format du pays
     *
     * @param string $phoneNumber Le numéro à formater
     * @param bool $national Utiliser le format national (true) ou international (false)
     * @return string|null Le numéro formaté ou null si impossible
     */
    public function formatPhoneNumber(string $phoneNumber, bool $national = false): ?string
    {
        $countryInfo = $this->detectPhoneCountry($phoneNumber);
        
        if (!isset($countryInfo['isValid']) || !$countryInfo['isValid']) {
            return null;
        }
        
        try {
            $parsedNumber = $this->phoneUtil->parse($phoneNumber, 'FR');
            
            if ($national) {
                return $this->phoneUtil->format($parsedNumber, PhoneNumberFormat::NATIONAL);
            } else {
                return $this->phoneUtil->format($parsedNumber, PhoneNumberFormat::INTERNATIONAL);
            }
        } catch (NumberParseException $e) {
            return null;
        }
    }
}