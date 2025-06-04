<?php

require_once __DIR__ . '/WebSearchService.php';
require_once __DIR__ . '/AIService2.php';

use App\Logger\Logger;

class RealEstateAgent
{
    private WebSearchService $searchService;
    private AIService2 $aiService;
    private int $maxSearchTime = 90; // secondes
    private Logger $logger; 
    
    public function __construct(Logger $logger)
    {
        $this->searchService = new WebSearchService($logger);
        $this->aiService = new AIService2();
        $this->logger = $logger;
    }
    
    /**
     * Recherche une propriété immobilière par référence et agence
     * 
     * @param string $reference Référence du bien
     * @param string $agency Nom de l'agence
     * @return array Données structurées de l'annonce
     */
    public function searchProperty(string $reference, string $agency): array
    {
        $startTime = time();
        
        try {
            // Étape 1 : Rechercher sur le web
            $searchResults = $this->performWebSearch($reference, $agency);
            
            $this->logger->info("performWebSearch", $searchResults);

            if (empty($searchResults)) {
                $result = [
                    'error' => 'Aucune annonce trouvée pour cette référence et cette agence.',
                    'type_bien' => null,
                    'prix' => null,
                    'surface' => null,
                    'nombre_pieces' => null,
                    'lien_annonce' => null
                ];

                return $result;

                $this->logger->info("performWebSearch", $result);
            }
            
            // Étape 2 : Analyser les résultats avec l'IA
            $structuredData = $this->analyzeWithAI($searchResults, $reference, $agency);
            
            $this->logger->info("performWebSearch", $structuredData);

            // Vérifier le temps écoulé
            if (time() - $startTime > $this->maxSearchTime) {
                throw new Exception("Temps de recherche maximum dépassé.");
            }
            
            return $structuredData;
            
        } catch (Exception $e) {
            error_log("Erreur dans RealEstateAgent: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Effectue la recherche web
     */
    private function performWebSearch(string $reference, string $agency): array
    {
        $results = [];
        
        // Recherche directe sur les sites immobiliers principaux
        $sites = [
            #'test' => "$reference $agency",
            'leboncoin.fr' => "site:leboncoin.fr \"$reference\" $agency",
            'ouestfrance-immo.com' => "site:ouestfrance-immo.com $reference $agency",
            'bienici.com' => "site:bienici.com $reference $agency",
            'seloger.com' => "site:seloger.com $reference $agency",
            'logic-immo.com' => "site:logic-immo.com $reference $agency",
            'paruvendu.fr' => "site:paruvendu.fr $reference $agency",
        ];
        
        foreach ($sites as $site => $query) {
            $siteResults = $this->searchService->searchGoogle($query);
            if (!empty($siteResults)) {
                $results = array_merge($results, $siteResults);
            }
        }
        
        // Filtrer les résultats de moins de 3 mois
        $filteredResults = $this->filterRecentResults($results);
        
        // Récupérer le contenu des pages
        $detailedResults = [];
        foreach (array_slice($filteredResults, 0, 5) as $result) { // Limiter à 5 pages
            $content = $this->searchService->fetchPageContent($result['url']);
            $this->logger->info($result['url'], $content);
            if ($content) {
                $detailedResults[] = [
                    'url' => $result['url'],
                    'title' => $result['title'],
                    'snippet' => $result['snippet'],
                    'content' => $content
                ];
            }
        }
        
        $this->logger->info("performWebSearch", $detailedResults);
        return $detailedResults;
    }
    
    /**
     * Filtre les résultats pour ne garder que ceux de moins de 3 mois
     */
    private function filterRecentResults(array $results): array
    {
        $threeMonthsAgo = new DateTime('-3 months');
        $filtered = [];
        
        foreach ($results as $result) {
            // Essayer de détecter la date dans le snippet ou le titre
            if ($this->isRecentListing($result, $threeMonthsAgo)) {
                $filtered[] = $result;
            }
        }
        
        // Si aucun résultat filtré par date, retourner tous les résultats
        return !empty($filtered) ? $filtered : $results;
    }
    
    /**
     * Vérifie si une annonce est récente
     */
    private function isRecentListing(array $result, DateTime $threshold): bool
    {
        // Patterns pour détecter les dates
        $patterns = [
            '/(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/',
            '/publié[e]?\s+le\s+(\d{1,2})\s+(\w+)\s+(\d{4})/i',
            '/mise?\s+en\s+ligne\s+le\s+(\d{1,2})\s+(\w+)\s+(\d{4})/i'
        ];
        
        $text = $result['title'] . ' ' . $result['snippet'];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                try {
                    // Convertir en date et comparer
                    $date = new DateTime($matches[0]);
                    if ($date >= $threshold) {
                        return true;
                    }
                } catch (Exception $e) {
                    // Ignorer les erreurs de parsing
                }
            }
        }
        
        // Par défaut, inclure le résultat
        return true;
    }
    
    /**
     * Analyse les résultats avec l'IA
     */
    private function analyzeWithAI(array $searchResults, string $reference, string $agency): array
    {
        $context = $this->prepareContextForAI($searchResults, $reference, $agency);
        $response = $this->aiService->analyzeProperty($context);
        
        // Valider et nettoyer la réponse
        return $this->validateAIResponse($response);
    }
    
    /**
     * Prépare le contexte pour l'analyse IA
     */
    private function prepareContextForAI(array $results, string $reference, string $agency): string
    {
        $context = "Recherche d'annonce immobilière en France\n";
        $context .= "Référence: $reference\n";
        $context .= "Agence: $agency\n\n";
        $context .= "Résultats trouvés:\n\n";
        
        foreach ($results as $i => $result) {
            $context .= "--- Résultat " . ($i + 1) . " ---\n";
            $context .= "URL: " . $result['url'] . "\n";
            $context .= "Titre: " . $result['title'] . "\n";
            $context .= "Extrait: " . substr($result['content'], 0, 2000) . "\n\n";
        }
        
        return $context;
    }
    
    /**
     * Valide et nettoie la réponse de l'IA
     */
    private function validateAIResponse(array $response): array
    {
        $validated = [
            'type_bien' => null,
            'prix' => null,
            'surface' => null,
            'nombre_pieces' => null,
            'lien_annonce' => null
        ];
        
        // Type de bien
        if (isset($response['type_bien']) && in_array(strtolower($response['type_bien']), 
            ['maison', 'appartement', 'garage', 'terrain', 'local commercial', 'bureau'])) {
            $validated['type_bien'] = ucfirst(strtolower($response['type_bien']));
        }
        
        // Prix
        if (isset($response['prix']) && is_numeric($response['prix']) && $response['prix'] > 0) {
            $validated['prix'] = (int)$response['prix'];
        }
        
        // Surface
        if (isset($response['surface']) && is_numeric($response['surface']) && $response['surface'] > 0) {
            $validated['surface'] = (float)$response['surface'];
        }
        
        // Nombre de pièces
        if (isset($response['nombre_pieces']) && is_numeric($response['nombre_pieces']) && $response['nombre_pieces'] > 0) {
            $validated['nombre_pieces'] = (int)$response['nombre_pieces'];
        }
        
        // Lien annonce
        if (isset($response['lien_annonce']) && filter_var($response['lien_annonce'], FILTER_VALIDATE_URL)) {
            $validated['lien_annonce'] = $response['lien_annonce'];
        }
        
        return $validated;
    }
}