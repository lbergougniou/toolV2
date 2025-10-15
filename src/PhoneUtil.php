<?php

namespace App;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

/**
 * Classe PhoneUtil - Utilitaire de validation et normalisation des numéros téléphoniques
 *
 * Fonctionnalités principales :
 * - Détection du pays d'origine d'un numéro de téléphone
 * - Validation des numéros selon les standards internationaux
 * - Normalisation intelligente : format local pour France métropolitaine, international pour DOM-TOM et autres pays
 * - Gestion spécifique des territoires français (DOM-TOM) avec détection automatique
 *
 * Utilise libphonenumber pour la validation et un fichier JSON pour les données géographiques
 *
 * @package App
 * @version 2.0
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
     * Initialise l'utilitaire de numéros de téléphone
     *
     * @param string|null $jsonFilePath Chemin personnalisé vers le JSON des pays (optionnel)
     * @throws \RuntimeException Si le fichier JSON est invalide ou introuvable
     */
    public function __construct(string $jsonFilePath = null)
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
        $this->jsonFilePath = $jsonFilePath ?? __DIR__ . '/../public/data/countries_data.json';
        $this->loadCountriesData();
    }
    
    /**
     * Charge et valide les données des pays depuis le fichier JSON
     *
     * @throws \RuntimeException Si le fichier est introuvable, illisible ou invalide
     */
    private function loadCountriesData(): void
    {
        if (!file_exists($this->jsonFilePath)) {
            throw new \RuntimeException("Fichier JSON introuvable : {$this->jsonFilePath}");
        }

        $jsonContent = file_get_contents($this->jsonFilePath);
        if ($jsonContent === false) {
            throw new \RuntimeException("Impossible de lire le fichier : {$this->jsonFilePath}");
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("JSON invalide : " . json_last_error_msg());
        }

        if (!isset($data['countries']) || !is_array($data['countries'])) {
            throw new \RuntimeException("Structure JSON invalide : clé 'countries' manquante");
        }

        $this->countriesData = $data['countries'];
        $this->log("Données chargées", count($this->countriesData) . " pays");
    }
    
    /**
     * Recherche un pays par son code ISO
     *
     * @param string $countryCode Code ISO à 2 lettres (ex: 'FR', 'RE')
     * @return array|null Données du pays ou null si introuvable
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
     * Recherche un pays par son préfixe téléphonique (avec gestion des sous-préfixes)
     * Utilisé notamment pour différencier les territoires partageant le même préfixe (ex: Réunion/Mayotte avec +262)
     *
     * @param string $phoneNumber Numéro au format international (+XXXXXXXXXXXX)
     * @return array|null Données du pays ou null si introuvable
     */
    private function findCountryByPhonePrefix(string $phoneNumber): ?array
    {
        if (!str_starts_with($phoneNumber, '+')) {
            return null;
        }

        $numericNumber = substr($phoneNumber, 1); // Retirer le '+'

        foreach ($this->countriesData as $country) {
            $phoneCode = $country['phoneNumbering']['phoneCode'] ?? null;

            if (!$phoneCode || !str_starts_with($phoneNumber, $phoneCode)) {
                continue;
            }

            // Vérification des sous-préfixes (pour DOM-TOM partageant le même code pays)
            if (!empty($country['phoneNumbering']['subPrefixes'])) {
                foreach ($country['phoneNumbering']['subPrefixes'] as $subPrefix) {
                    if (str_starts_with($numericNumber, $subPrefix)) {
                        return $country;
                    }
                }
            } else {
                return $country;
            }
        }

        return null;
    }

    /**
     * Convertit un numéro nettoyé en format international
     *
     * @param string $cleanNumber Numéro nettoyé (uniquement chiffres et '+')
     * @return string Numéro au format international (+XXXXXXXXXXXX)
     */
    private function convertToInternational(string $cleanNumber): string
    {
        // Format local français (0XXXXXXXXX)
        if (preg_match('/^0([1-9])([0-9]{8})$/', $cleanNumber, $matches)) {
            $domTomPrefix = $this->detectDomTomPrefix($cleanNumber);

            if ($domTomPrefix !== null) {
                $this->log("Conversion DOM-TOM", $domTomPrefix);
                return $domTomPrefix . $matches[1] . $matches[2];
            }

            $this->log("Conversion France", "+33");
            return '+33' . $matches[1] . $matches[2];
        }

        // Format 00XX (00336XXXXXXXX → +336XXXXXXXX)
        if (preg_match('/^00([0-9]{2,3})([0-9]+)$/', $cleanNumber, $matches)) {
            return '+' . $matches[1] . $matches[2];
        }

        // Déjà au format international ou fallback
        if (!str_starts_with($cleanNumber, '+')) {
            return '+33' . ltrim($cleanNumber, '0');
        }

        return $cleanNumber;
    }

    /**
     * Calcule le numéro normalisé selon les règles métier
     *
     * Règles de normalisation :
     * - France métropolitaine (code 'fr') : format local sans indicatif (0XXXXXXXXX)
     * - DOM-TOM et autres pays : format international avec indicatif (+XXXXXXXXXXXX)
     *
     * @param string $originalNumber Numéro saisi par l'utilisateur
     * @param string $countryCode Code ISO du pays détecté
     * @param string $internationalNumber Numéro au format international brut
     * @return string Numéro normalisé
     */
    private function calculateNormalizedNumber(string $originalNumber, string $countryCode, string $internationalNumber): string
    {
        // France métropolitaine uniquement (pas les DOM-TOM)
        if (strtolower($countryCode) === 'fr') {
            if (str_starts_with($internationalNumber, '+33')) {
                $nationalNumber = substr($internationalNumber, 3);
                if (strlen($nationalNumber) === 9) {
                    return '0' . $nationalNumber;
                }
            }

            // Si déjà au format local valide
            $cleanOriginal = preg_replace('/[^0-9]/', '', $originalNumber);
            if (preg_match('/^0[1-9][0-9]{8}$/', $cleanOriginal)) {
                return $cleanOriginal;
            }
        }

        // DOM-TOM et autres pays : format international
        return $internationalNumber;
    }

    /**
     * Détecte si un numéro au format local français appartient à un DOM-TOM
     *
     * Analyse les numéros commençant par 06 ou 07 pour identifier s'ils correspondent
     * à des territoires d'outre-mer (La Réunion, Guadeloupe, Martinique, etc.)
     *
     * @param string $localNumber Numéro au format local (0XXXXXXXXX)
     * @return string|null Indicatif international du DOM-TOM (+262, +590, etc.) ou null si France métropolitaine
     */
    private function detectDomTomPrefix(string $localNumber): ?string
    {
        // Seuls les 06 et 07 peuvent être des DOM-TOM
        if (!preg_match('/^0([67])(\d{8})$/', $localNumber, $matches)) {
            return null;
        }

        $fullNumber = $matches[1] . $matches[2]; // Ex: "693498839"

        // Parcourir les territoires français avec sous-préfixes
        foreach ($this->countriesData as $country) {
            if (($country['phoneNumbering']['territory'] ?? null) !== 'fr') {
                continue;
            }

            if (empty($country['phoneNumbering']['subPrefixes'])) {
                continue;
            }

            $phoneCode = $country['phoneNumbering']['phoneCode'];
            $codeWithoutPlus = ltrim($phoneCode, '+');

            foreach ($country['phoneNumbering']['subPrefixes'] as $subPrefix) {
                // Extraire la partie locale du sous-préfixe (après le code pays)
                // Ex: "262693" → "693" (après avoir retiré "262")
                if (str_starts_with($subPrefix, $codeWithoutPlus)) {
                    $localPart = substr($subPrefix, strlen($codeWithoutPlus));

                    if (str_starts_with($fullNumber, $localPart)) {
                        $this->log("DOM-TOM détecté", [
                            'country' => $country['name']['fr'],
                            'phoneCode' => $phoneCode,
                            'subPrefix' => $subPrefix
                        ]);
                        return $phoneCode;
                    }
                }
            }
        }

        return null; // France métropolitaine
    }
    
    /**
     * Active ou désactive le mode débogage
     *
     * @param bool $debug True pour activer le débogage
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Récupère les logs de débogage collectés
     *
     * @return array Tableau de logs avec timestamp, message et données
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Enregistre un message de débogage (si le mode debug est activé)
     *
     * @param string $message Description de l'étape
     * @param mixed $data Données contextuelles (optionnel)
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
     * Détecte le pays d'origine d'un numéro de téléphone et retourne ses informations
     *
     * Accepte tous formats : local (06...), international (+33...), ou avec 00
     * Gère automatiquement la détection des DOM-TOM même en format local
     *
     * @param string $phoneNumber Numéro à analyser (n'importe quel format)
     * @return array Tableau associatif contenant :
     *               - success: bool (toujours true)
     *               - isValid: bool (true si numéro valide)
     *               - countryCode: string (code ISO du pays, ex: 'fr', 're')
     *               - phoneCode: string (indicatif international, ex: '+33', '+262')
     *               - country: string (nom du pays en français)
     *               - isFrench: bool (true si France ou territoire français)
     *               - normalizedNumber: string (numéro au format normalisé)
     *               - territory: string|null ('fr' pour les DOM-TOM, null sinon)
     *               - region: string|null (région géographique)
     *               - message: string (si erreur)
     *               - logs: array (si mode debug activé)
     */
    public function detectPhoneCountry(string $phoneNumber): array
    {
        $this->logs = [];
        $this->log("Numéro original", $phoneNumber);

        // Nettoyage : ne garder que chiffres et '+'
        $cleanNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        $this->log("Numéro nettoyé", $cleanNumber);

        // Conversion en format international
        $cleanNumber = $this->convertToInternational($cleanNumber);
        $this->log("Format international", $cleanNumber);
        
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

                    // Calculer le numéro normalisé
                    $normalizedNumber = $this->calculateNormalizedNumber($phoneNumber, $countryByPrefix['code'], $internationalRaw);

                    return [
                        'success' => true,
                        'isValid' => true,
                        'countryCode' => strtolower($countryByPrefix['code']),
                        'phoneCode' => $countryByPrefix['phoneNumbering']['phoneCode'],
                        'isFrench' => ($countryByPrefix['code'] === 'fr' || $isFrenchTerritory),
                        'region' => isset($countryByPrefix['region']) ? $countryByPrefix['region'] : null,
                        'country' => $countryByPrefix['name']['fr'] ?? null,
                        'territory' => $countryByPrefix['phoneNumbering']['territory'] ?? null,
                        'normalizedNumber' => $normalizedNumber
                    ];
                }
                
                // Recherche dans notre fichier JSON par le code pays
                $countryData = $this->findCountryByCode($countryCode);

                if ($countryData !== null) {
                    $this->log("Pays trouvé dans le JSON par code", $countryData);

                    // Déterminer si c'est un territoire français (DOM-TOM)
                    $isFrenchTerritory = isset($countryData['phoneNumbering']['territory']) &&
                                         $countryData['phoneNumbering']['territory'] === 'fr';

                    // Calculer le numéro normalisé
                    $normalizedNumber = $this->calculateNormalizedNumber($phoneNumber, $countryData['code'], $internationalRaw);

                    return [
                        'success' => true,
                        'isValid' => true,
                        'countryCode' => strtolower($countryData['code']),
                        'phoneCode' => $countryData['phoneNumbering']['phoneCode'],
                        'isFrench' => ($countryData['code'] === 'fr' || $isFrenchTerritory),
                        'region' => isset($countryData['region']) ? $countryData['region'] : null,
                        'country' => $countryData['name']['fr'] ?? null,
                        'territory' => $countryData['phoneNumbering']['territory'] ?? null,
                        'normalizedNumber' => $normalizedNumber
                    ];
                }

                // Si le pays n'est pas dans notre JSON, fournir les infos de base
                $normalizedNumber = $this->calculateNormalizedNumber($phoneNumber, $countryCode, $internationalRaw);

                return [
                    'success' => true,
                    'isValid' => true,
                    'countryCode' => $countryCode,
                    'phoneCode' => '+' . $parsedNumber->getCountryCode(),
                    'country' => null,
                    'region' => null,
                    'territory' => null,
                    'normalizedNumber' => $normalizedNumber
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
     * Normalise un numéro de téléphone selon les règles métier :
     * - France métropolitaine : format local (0XXXXXXXXX)
     * - DOM-TOM et autres pays : format international (+XXXXXXXXXXXX)
     *
     * @param string $phoneNumber Numéro à normaliser
     * @return string|null Numéro normalisé ou null si invalide
     */
    public function normalizePhoneNumber(string $phoneNumber): ?string
    {
        $result = $this->detectPhoneCountry($phoneNumber);

        return ($result['isValid'] ?? false) ? ($result['normalizedNumber'] ?? null) : null;
    }

    /**
     * Vérifie si un numéro de téléphone est valide
     *
     * @param string $phoneNumber Numéro à vérifier
     * @return bool True si valide, false sinon
     */
    public function isValidPhoneNumber(string $phoneNumber): bool
    {
        $result = $this->detectPhoneCountry($phoneNumber);
        return $result['isValid'] ?? false;
    }

    /**
     * Récupère toutes les données des pays depuis le JSON
     *
     * @return array Tableau des pays avec leurs informations
     */
    public function getAllCountries(): array
    {
        return $this->countriesData;
    }

    /**
     * Récupère les pays groupés et triés par région géographique
     *
     * @return array Tableau associatif [région => [pays...]]
     */
    public function getCountriesByRegion(): array
    {
        $regions = [];

        foreach ($this->countriesData as $country) {
            $region = $country['region'] ?? 'Autre';

            if (!isset($regions[$region])) {
                $regions[$region] = [];
            }

            $regions[$region][] = [
                'code' => $country['code'],
                'name' => $country['name']['fr'] ?? $country['name']['en'] ?? $country['code'],
                'flag_code' => $country['code'],
                'phoneCode' => $country['phoneNumbering']['phoneCode'] ?? null,
                'territory' => $country['phoneNumbering']['territory'] ?? null
            ];
        }

        ksort($regions);

        foreach ($regions as &$countries) {
            usort($countries, fn($a, $b) => $a['name'] <=> $b['name']);
        }

        return $regions;
    }
}