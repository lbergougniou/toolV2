<?php

use App\Logger\Logger;

class WebSearchService
{
    private string $googleApiKey;
    private string $googleSearchEngineId;
    private int $timeout = 30;
    private Logger $logger; 
    
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->googleApiKey = $_ENV['GOOGLE_API_KEY'] ?? '';
        $this->googleSearchEngineId = $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? '';
        
        if (empty($this->googleApiKey) || empty($this->googleSearchEngineId)) {
            $this->logger->info("WebSearchService", "Configuration Google manquante dans le fichier .env");
            throw new Exception("Configuration Google manquante dans le fichier .env");
        }
    }
    
    /**
     * Recherche sur Google via l'API Custom Search
     */
    public function searchGoogle(string $query, int $limit = 2): array
    {
        $results = [];
        
        try {
            $url = "https://www.googleapis.com/customsearch/v1?" . http_build_query([
                'key' => $this->googleApiKey,
                'cx' => $this->googleSearchEngineId,
                'q' => $query,
                'num' => $limit,
                'lr' => 'lang_fr', // Langue française
                'cr' => 'countryFR' // Pays France
            ]);
            $response = $this->makeRequest($url);
            $data = json_decode($response, true);

            if (isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $results[] = [
                        'title' => $item['title'] ?? '',
                        'url' => $item['link'] ?? '',
                        'snippet' => $item['snippet'] ?? '',
                        'displayLink' => $item['displayLink'] ?? ''
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Erreur Google Search: " . $e->getMessage());
        }
        return $results;
    }
    
    /**
     * Récupère le contenu d'une page web
     */
    public function fetchPageContent(string $url): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ]
            ]);
            
            $response = curl_exec($ch);
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200 && $response !== false && !empty($response)) {
                // Nettoyer le HTML
                
                return $this->cleanHtml($response);
            }
            
        } catch (Exception $e) {
            error_log("Erreur fetch content: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Effectue une requête HTTP
     */
    private function makeRequest(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("Erreur cURL: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Code HTTP invalide: " . $httpCode);
        }
        
        return $response;
    }
    
    /**
     * Nettoie le HTML pour extraire le texte pertinent
     */
    private function cleanHtml(string $html): string
    {
        $this->logger->info("cleanHtml", $html);
        // Supprimer les scripts et styles
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
        
        // Extraire le texte
        $text = strip_tags($html);
        $this->logger->info("cleanHtml0", $text);
        // Nettoyer les espaces
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $this->logger->info("cleanHtml1", $text);
        // Limiter la taille
        if (strlen($text) > 100000) {
            $text = substr($text, 0, 100000);
        }
        $this->logger->info("cleanHtml2", $text);
        return $text;
    }
    
    /**
     * Alternative : Utiliser Serper API (si Google API n'est pas disponible)
     */
    public function searchSerper(string $query, int $limit = 10): array
    {
        $serperApiKey = $_ENV['SERPER_API_KEY'] ?? '';
        if (empty($serperApiKey)) {
            return [];
        }
        
        $results = [];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://google.serper.dev/search',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'q' => $query,
                    'location' => 'France',
                    'gl' => 'fr',
                    'hl' => 'fr',
                    'num' => $limit
                ]),
                CURLOPT_HTTPHEADER => [
                    'X-API-KEY: ' . $serperApiKey,
                    'Content-Type: application/json'
                ]
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if (isset($data['organic'])) {
                foreach ($data['organic'] as $item) {
                    $results[] = [
                        'title' => $item['title'] ?? '',
                        'url' => $item['link'] ?? '',
                        'snippet' => $item['snippet'] ?? '',
                        'displayLink' => parse_url($item['link'] ?? '', PHP_URL_HOST)
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Erreur Serper Search: " . $e->getMessage());
        }
        
        return $results;
    }
}