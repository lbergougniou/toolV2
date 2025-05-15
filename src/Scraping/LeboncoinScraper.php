<?php

namespace App\Scraping;

use App\Scraping\Interfaces\ScraperInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Scraper robuste pour Leboncoin avec gestion des erreurs et validation
 * Implémente des mécanismes de retry, rate limiting et validation des données
 */
class LeboncoinScraper implements ScraperInterface
{
    /** @var int Nombre maximum de résultats à retourner */
    private const MAX_RESULTS = 10;
    
    /** @var int Nombre maximum de tentatives en cas d'échec */
    private const MAX_RETRIES = 3;
    
    /** @var int Délai entre les tentatives en millisecondes */
    private const RETRY_DELAY = 1000;
    
    /** @var int Timeout des requêtes en secondes */
    private const TIMEOUT = 30;
    
    /** @var int Délai minimum entre deux requêtes (rate limiting) */
    private const RATE_LIMIT_DELAY = 3000;

    /** @var HttpClientInterface Instance du client HTTP */
    private $client;
    
    /** @var LoggerInterface Logger pour tracer les opérations */
    private $logger;
    
    /** @var float Timestamp de la dernière requête pour le rate limiting */
    private $lastRequestTime = 0;

    /**
     * Règles de validation des données extraites
     * Définit les limites min/max acceptables pour chaque champ
     */
    private const VALIDATION_RULES = [
        'prix' => ['min' => 1, 'max' => 10000000],
        'surface' => ['min' => 1, 'max' => 10000],
        'pieces' => ['min' => 1, 'max' => 50]
    ];

    /**
     * Initialise le scraper avec configuration du client HTTP
     * @param LoggerInterface|null $logger Logger optionnel
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->client = HttpClient::create([
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
            'timeout' => self::TIMEOUT,
            'max_redirects' => 5
        ]);
    }

    /**
     * Valide les données extraites selon les règles définies
     * Nettoie les valeurs hors limites en les mettant à null
     * 
     * @param array $data Données à valider
     * @return array Données validées
     */
    private function validateData(array $data): array
    {
        foreach (self::VALIDATION_RULES as $field => $rules) {
            if (isset($data[$field]) && $data[$field] !== null) {
                if ($data[$field] < $rules['min'] || $data[$field] > $rules['max']) {
                    $data[$field] = null;
                    $this->logger->warning("Invalid $field value: {$data[$field]}");
                }
            }
        }
        return $data;
    }

    /**
     * Effectue une requête HTTP avec retry et rate limiting
     * 
     * @param string $url URL à requêter
     * @param int $retryCount Compteur de tentatives actuel
     * @throws \RuntimeException En cas d'échec après tous les essais
     * @return string Contenu de la réponse
     */
    private function makeRequest(string $url, int $retryCount = 0)
    {
        try {
            // Implémentation du rate limiting
            $timeSinceLastRequest = microtime(true) - $this->lastRequestTime;
            if ($timeSinceLastRequest < (self::RATE_LIMIT_DELAY / 1000)) {
                $sleepTime = ($this->RATE_LIMIT_DELAY - ($timeSinceLastRequest * 1000)) * 1000;
                usleep($sleepTime);
            }

            // Exécution de la requête
            $response = $this->client->request('GET', $url);
            $this->lastRequestTime = microtime(true);
            
            $statusCode = $response->getStatusCode();
            
            // Gestion spécifique du rate limiting
            if ($statusCode === 429) {
                throw new \RuntimeException('Rate limit exceeded');
            }
            
            // Vérification du code HTTP
            if ($statusCode >= 400) {
                throw new \RuntimeException("HTTP error $statusCode");
            }

            return $response->getContent();

        } catch (\Exception $e) {
            // Retry pattern avec délai
            if ($retryCount < self::MAX_RETRIES) {
                $this->logger->warning(sprintf(
                    "Request failed, retrying... (%d/%d)", 
                    $retryCount + 1, 
                    self::MAX_RETRIES
                ));
                usleep(self::RETRY_DELAY * 1000);
                return $this->makeRequest($url, $retryCount + 1);
            }
            throw $e;
        }
    }

    /**
     * Recherche des annonces selon les critères fournis
     * 
     * @param string|null $reference Référence de l'annonce
     * @param float|null $prix Prix exact recherché
     * @param string|null $localisation Lieu de recherche
     * @return array Résultats de la recherche ou message d'erreur
     * @throws \InvalidArgumentException Si les critères sont invalides
     */
    public function searchByReference(string $reference = null, ?float $prix = null, ?string $localisation = null): array 
    {
        // Validation des paramètres
        if ($reference === null && ($prix === null || $localisation === null)) {
            throw new \InvalidArgumentException("Paramètres de recherche insuffisants");
        }

        try {
            // Construction de l'URL et exécution de la requête
            $url = $this->buildSearchUrl($reference, $prix, $localisation);
            $content = $this->makeRequest($url);
            
            // Parsing du contenu
            $crawler = new Crawler($content);
            $results = [];
            
            $articles = $crawler->filter('article[data-qa-id="aditem_container"]');
            
            // Extraction des données de chaque annonce
            foreach ($articles as $index => $article) {
                if ($index >= self::MAX_RESULTS) break;
                
                try {
                    $node = new Crawler($article);
                    $data = $this->extractAdData($node);
                    
                    // Validation et nettoyage des données
                    $data = $this->validateData($data);
                    
                    if ($this->isValidAd($data)) {
                        $results[] = $data;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error extracting ad: " . $e->getMessage());
                    continue;
                }
            }

            return $results;

        } catch (\Exception $e) {
            $this->logger->error("Search failed: " . $e->getMessage());
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vérifie si une annonce est valide selon nos critères
     * 
     * @param array $data Données de l'annonce
     * @return bool true si l'annonce est valide
     */
    private function isValidAd(array $data): bool
    {
        return !empty($data['titre']) 
            && ($data['prix'] !== null || $data['surface'] !== null)
            && !empty($data['lien']);
    }

    /**
     * Construit l'URL de recherche avec validation des paramètres
     * 
     * @param string|null $reference Référence recherchée
     * @param float|null $prix Prix exact
     * @param string|null $localisation Lieu
     * @return string URL formatée
     * @throws \InvalidArgumentException Si un paramètre est invalide
     */
    private function buildSearchUrl(?string $reference, ?float $prix, ?string $localisation): string
    {
        $url = 'https://www.leboncoin.fr/recherche?category=8&owner_type=pro&sort=relevance';
        
        if ($reference !== null) {
            if (!preg_match('/^[a-zA-Z0-9\s\.-]{2,50}$/', $reference)) {
                throw new \InvalidArgumentException("Format de référence invalide");
            }
            $url .= '&text=' . urlencode($reference);
        }
    
        if ($prix !== null) {
            if ($prix < 0 || $prix > 10000000) {
                throw new \InvalidArgumentException("Prix hors limites");
            }
            $url .= '&price=' . $prix . '-' . $prix;
        }
    
        if ($localisation !== null) {
            if (preg_match('/^d_(\d{2,3})$/i', $localisation)) {
                $url .= '&departments=' . substr($localisation, 2);
            } else {
                if (!preg_match('/^[a-zA-Z0-9\s-]{2,50}$/', $localisation)) {
                    throw new \InvalidArgumentException("Format de localisation invalide");
                }
                $url .= '&locations=' . urlencode($localisation);
            }
        }
    
        return $url;
    }
    /**
     * Extrait les données d'une annonce depuis son nœud DOM
     * 
     * @param Crawler $node Nœud DOM de l'annonce
     * @return array Données extraites
     */
    private function extractAdData(Crawler $node): array
    {
       try {
           // Extraction du titre via deux sélecteurs possibles
           $titre = $this->extractText($node, 'h2') ?? 
                    $this->extractText($node, 'p[data-test-id="adcard-title"]');
    
           // Extractions des données de base
           $prix = $this->extractPrice($this->extractText($node, 'p[data-test-id="price"]'));
           $localisation = $this->extractText($node, 'p[aria-label*="Située"]');
           $link = $this->extractLink($node, 'a[href*="/ad/"]');
           $agence = $this->extractText($node, 'a[href*="/boutique/"] strong');
    
           // Analyse détaillée du titre et de la localisation
           $propertyInfo = $this->extractPropertyInfo($titre);
           $locationInfo = $this->extractLocationInfo($localisation);
    
           // Construction du tableau de résultats
           return [
               'titre' => $titre,
               'type_bien' => $propertyInfo['type'],
               'nb_pieces' => $propertyInfo['pieces'],
               'prix' => $prix, 
               'surface' => $propertyInfo['surface'],
               'localisation' => $localisation,
               'code_postal' => $locationInfo['code_postal'],
               'ville' => $locationInfo['ville'],
               'agence' => $agence,
               'lien' => $link
           ];
       } catch (\Exception $e) {
           $this->logger->error("Erreur extraction annonce: " . $e->getMessage());
           return [];
       }
    }

    
    /**
     * Extrait les informations sur le bien depuis le titre
     * @param string|null $titre Titre de l'annonce
     * @return array Informations extraites (type, pièces, surface)
     */
    private function extractPropertyInfo(?string $titre): array
    {
        $result = [
            'type' => 'Non précisé',
            'pieces' => null,
            'surface' => null
        ];

        if (!$titre) return $result;

        // Définition des types de biens et leurs mots-clés
        $types = [
            'Maison' => ['maison', 'villa', 'pavillon', 'longère'],
            'Appartement' => ['appartement', 'studio', 'loft'],
            'Longère' => ['longère'],
            'Chalet' => ['chalet'],
            'Garage' => ['garage'],
            'Parking' =>['parking', 'box'],
            'Château' => ['chateau', 'château'],
            'Duplex' => ['duplex'],
            'Terrain' => ['terrain', 'parcelle'],
            'Local commercial' => ['local', 'commerce', 'boutique', 'entrepôt'],
            'Bureaux' => ['bureaux'],
            'Immeuble' => ['immeuble']
        ];

        $titre_lower = mb_strtolower($titre);
        
        // Détection du type de bien
        foreach ($types as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($titre_lower, $keyword) !== false) {
                    $result['type'] = $type;
                    break 2;
                }
            }
        }

        // Extraction du nombre de pièces (formats: "X pièces", "TX", "studio")
        if (preg_match('/(\d+)\s*(?:pieces|pièces|p\.|p )/i', $titre, $matches)) {
            $result['pieces'] = (int)$matches[1];
        } elseif (preg_match('/t(\d+)/i', $titre, $matches)) {
            $result['pieces'] = (int)$matches[1];
        } elseif (mb_stripos($titre, 'studio') !== false) {
            $result['pieces'] = 1;
        }

        // Extraction de la surface (format: "XX m²")
        if (preg_match('/(\d+[.,]?\d*)\s*m[²2]/i', $titre, $matches)) {
            $result['surface'] = (float)str_replace(',', '.', $matches[1]);
        }
        
        $this->logger->debug("Extracted surface: " . $result['surface']);
        $this->logger->debug("Extracted piece: " . $result['pieces']);
        return $result;
    }

    /**
     * Extrait le code postal et la ville depuis la localisation
     * @param string|null $localisation Texte de localisation
     * @return array Code postal et ville extraits
     */
    private function extractLocationInfo(?string $localisation): array
    {
        $result = [
            'code_postal' => null,
            'ville' => null
        ];
    
        if (!$localisation) {
            return $result;
        }
    
        // Gestion des codes départements (ex: d_47)
        if (preg_match('/^d_(\d{2,3})$/i', trim($localisation), $matches)) {
            $result['code_postal'] = $matches[1];
            $result['ville'] = 'Département ' . $matches[1];
            return $result;
        }
    
        // Format ville + code postal
        if (preg_match('/^([^0-9]+?)\s*(\d{5})\s*$/i', trim($localisation), $matches)) {
            $result['ville'] = trim($matches[1]);
            $result['code_postal'] = $matches[2];
        } 
        // Format code postal + ville
        elseif (preg_match('/^(\d{5})\s*(.+?)$/i', trim($localisation), $matches)) {
            $result['code_postal'] = $matches[1];
            $result['ville'] = trim($matches[2]);
        }
    
        return $result;
    }

    /**
     * Extrait le texte d'un élément DOM
     * @param Crawler $node Nœud DOM parent
     * @param string $selector Sélecteur CSS
     * @return string|null Texte extrait ou null
     */
    private function extractText(Crawler $node, string $selector): ?string 
    {
        try {
            return $node->filter($selector)->count() ? trim($node->filter($selector)->text()) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convertit un prix texte en nombre
     * @param string|null $priceText Prix au format texte
     * @return float|null Prix converti ou null
     */
    private function extractPrice(?string $priceText): ?float
    {
        if (!$priceText) return null;
        
        // Nettoyage du texte (garde uniquement chiffres, point et virgule)
        $priceText = preg_replace('/[^0-9,.]/', '', $priceText);
        
        // Conversion en float si format valide
        if (preg_match('/^(\d+[.,]?\d*)$/', $priceText, $matches)) {
            $price = (float)str_replace(',', '.', $matches[1]);
            $this->logger->debug("Extracted price: " . $price);
            return $price;
        }
        
        return null;
    }

    /**
     * Construit l'URL complète d'une annonce
     * @param Crawler $node Nœud DOM parent
     * @param string $selector Sélecteur CSS pour le lien
     * @return string|null URL complète ou null
     */
    private function extractLink(Crawler $node, string $selector): ?string
    {
        try {
            $link = $node->filter($selector)->attr('href');
            $baseUrl = 'https://www.leboncoin.fr';
            $fullUrl = $link ? $baseUrl . $link : null;
            $this->logger->debug("Extracted link: " . $fullUrl);
            return $fullUrl;
        } catch (\Exception $e) {
            return null;
        }
    }
}