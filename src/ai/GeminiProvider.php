<?php
/**
 * Fournisseur Gemini pour l'IA
 * 
 * Cette classe gère les appels à l'API Gemini 2.0 Flash avec gestion des rate limits
 */
namespace ai;

class GeminiProvider implements AIProviderInterface {
    /**
     * URL de l'API Gemini
     */
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro-preview-05-06:generateContent';
    
    /**
     * Clé API Gemini
     */
    private string $apiKey;
    
    /**
     * Nombre maximum de tentatives en cas d'erreur 429
     */
    private const MAX_RETRIES = 3;
    
    /**
     * Délai de base entre les tentatives (en secondes)
     */
    private const BASE_DELAY = 1;
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Chargement de la clé API depuis les variables d'environnement
        $this->loadEnvVariables();
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new \Exception("La clé API Gemini n'est pas configurée dans le fichier .env");
        }
    }
    
    /**
     * Charge les variables d'environnement depuis le fichier .env
     */
    private function loadEnvVariables(): void {
        $envFile = dirname(dirname(dirname(__FILE__))) . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Ignorer les commentaires
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                if (!empty($name)) {
                    $_ENV[$name] = $value;
                }
            }
        }
    }
    
    /**
     * Envoie une requête à l'API Gemini avec gestion des rate limits
     * 
     * @param string $prompt Le prompt à envoyer
     * @return string La réponse de l'API
     * @throws \Exception En cas d'erreur lors de l'appel à l'API
     */
    public function sendRequest(string $prompt): string {
        $attempt = 0;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->makeApiRequest($prompt);
            } catch (\Exception $e) {
                $attempt++;
                
                // Vérifier si c'est une erreur 429 (Too Many Requests)
                if ($this->isRateLimitError($e->getMessage()) && $attempt < self::MAX_RETRIES) {
                    // Calculer le délai avec backoff exponentiel
                    $delay = self::BASE_DELAY * pow(2, $attempt - 1);
                    
                    error_log("Rate limit atteint, attente de {$delay} secondes avant la tentative " . ($attempt + 1));
                    sleep($delay);
                    continue;
                }
                
                // Si ce n'est pas une erreur 429 ou si on a épuisé les tentatives
                throw $e;
            }
        }
        
        throw new \Exception("Échec après " . self::MAX_RETRIES . " tentatives. Rate limit toujours actif.");
    }
    
    /**
     * Effectue l'appel API proprement dit
     * 
     * @param string $prompt Le prompt à envoyer
     * @return string La réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    private function makeApiRequest(string $prompt): string {
        $url = self::API_URL . '?key=' . $this->apiKey;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'topP' => 1.0,
                'topK' => 5,
                'maxOutputTokens' => 4096
            ]
        ];
        
        // Configuration du contexte HTTP avec timeout
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: PropertySearchApp/1.0'
                ],
                'content' => json_encode($data),
                'timeout' => 60, // Timeout de 30 secondes
                'ignore_errors' => true // Pour capturer les erreurs HTTP
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        // Vérifier les en-têtes de réponse HTTP
        if (isset($http_response_header)) {
            $httpCode = $this->parseHttpResponseCode($http_response_header);
            
            if ($httpCode === 429) {
                throw new \Exception("Rate limit exceeded (HTTP 429)");
            } elseif ($httpCode >= 400) {
                throw new \Exception("Erreur HTTP $httpCode lors de l'appel à l'API Gemini");
            }
        }
        
        if ($result === false) {
            throw new \Exception("Erreur lors de l'appel à l'API Gemini - Connexion échouée");
        }
        
        $response = json_decode($result, true);
        
        // Gestion des erreurs dans la réponse JSON
        if (isset($response['error'])) {
            $errorMessage = $response['error']['message'] ?? 'Erreur inconnue';
            throw new \Exception("Erreur API Gemini: " . $errorMessage);
        }
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        } else {
            throw new \Exception("Format de réponse Gemini inattendu: " . json_encode($response));
        }
    }
    
    /**
     * Vérifie si l'erreur est liée au rate limit
     * 
     * @param string $errorMessage Le message d'erreur
     * @return bool True si c'est une erreur de rate limit
     */
    private function isRateLimitError(string $errorMessage): bool {
        return strpos($errorMessage, '429') !== false || 
               strpos($errorMessage, 'Too Many Requests') !== false ||
               strpos($errorMessage, 'Rate limit') !== false;
    }
    
    /**
     * Parse le code de réponse HTTP depuis les en-têtes
     * 
     * @param array $headers Les en-têtes HTTP
     * @return int Le code de réponse HTTP
     */
    private function parseHttpResponseCode(array $headers): int {
        if (!empty($headers[0])) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}