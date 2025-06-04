<?php
/**
 * Fournisseur OpenAI ChatGPT pour l'IA avec gestion améliorée des rate limits
 */
namespace ai;

class OpenAIProvider implements AIProviderInterface {
    /**
     * URL de l'API OpenAI
     */
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Clé API OpenAI
     */
    private string $apiKey;
    
    /**
     * Modèle à utiliser
     */
    private string $model;
    
    /**
     * Nombre maximum de tentatives en cas d'erreur 429
     */
    private const MAX_RETRIES = 5;
    
    /**
     * Délai de base entre les tentatives (en secondes)
     */
    private const BASE_DELAY = 1;
    
    /**
     * Délai maximum entre les tentatives (en secondes)
     */
    private const MAX_DELAY = 120;
    
    /**
     * Fichier de cache pour tracker les rate limits
     */
    private string $rateLimitFile;
    
    /**
     * Constructeur
     * 
     * @param string $model Le modèle OpenAI à utiliser (défaut: gpt-4o-mini pour éviter les rate limits)
     */
    public function __construct(string $model = 'gpt-4o-mini') {
        // Chargement de la clé API depuis les variables d'environnement
        $this->loadEnvVariables();
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $this->model = $model;
        
        if (empty($this->apiKey)) {
            throw new \Exception("La clé API OpenAI n'est pas configurée dans le fichier .env");
        }
        
        // Fichier de cache pour les rate limits
        $this->rateLimitFile = sys_get_temp_dir() . '/openai_rate_limit_' . md5($this->apiKey) . '.txt';
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
                
                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    
                    if (!empty($name)) {
                        $_ENV[$name] = $value;
                    }
                }
            }
        }
    }
    
    /**
     * Vérifie si on est dans une période de rate limit
     */
    private function isInRateLimitPeriod(): bool {
        if (!file_exists($this->rateLimitFile)) {
            return false;
        }
        
        $data = file_get_contents($this->rateLimitFile);
        $rateLimitData = json_decode($data, true);
        
        if (!$rateLimitData || !isset($rateLimitData['until'])) {
            return false;
        }
        
        return time() < $rateLimitData['until'];
    }
    
    /**
     * Enregistre une période de rate limit
     */
    private function setRateLimitPeriod(int $seconds = 60): void {
        $data = [
            'until' => time() + $seconds,
            'last_error' => date('Y-m-d H:i:s'),
            'model' => $this->model
        ];
        
        file_put_contents($this->rateLimitFile, json_encode($data));
    }
    
    /**
     * Efface la période de rate limit (après succès)
     */
    private function clearRateLimitPeriod(): void {
        if (file_exists($this->rateLimitFile)) {
            unlink($this->rateLimitFile);
        }
    }
    
    /**
     * Envoie une requête à l'API OpenAI avec gestion améliorée des rate limits
     * 
     * @param string $prompt Le prompt à envoyer
     * @return string La réponse de l'API
     * @throws \Exception En cas d'erreur lors de l'appel à l'API
     */
    public function sendRequest(string $prompt): string {
        // Vérifier si on est dans une période de rate limit connue
        if ($this->isInRateLimitPeriod()) {
            $data = json_decode(file_get_contents($this->rateLimitFile), true);
            $waitTime = $data['until'] - time();
            throw new \Exception("Rate limit OpenAI en cours. Réessayez dans {$waitTime} secondes.");
        }
        
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = $this->makeApiRequest($prompt);
                
                // Succès - effacer le cache de rate limit
                $this->clearRateLimitPeriod();
                
                return $response;
                
            } catch (\Exception $e) {
                $attempt++;
                $lastError = $e;
                
                // Vérifier si c'est une erreur 429 (Too Many Requests)
                if ($this->isRateLimitError($e->getMessage())) {
                    if ($attempt < self::MAX_RETRIES) {
                        // Calculer le délai avec backoff exponentiel, mais plafonné
                        $delay = min(
                            self::BASE_DELAY * pow(2, $attempt),
                            self::MAX_DELAY
                        );
                        
                        error_log("OpenAI Rate limit atteint (modèle: {$this->model}), tentative $attempt/" . self::MAX_RETRIES . ", attente de {$delay} secondes");
                        
                        // Enregistrer la période de rate limit pour éviter d'autres tentatives immédiates
                        if ($attempt >= 2) {
                            $this->setRateLimitPeriod($delay * 2);
                        }
                        
                        sleep($delay);
                        continue;
                    } else {
                        // Toutes les tentatives épuisées - enregistrer une période de rate limit plus longue
                        $rateLimitDuration = $this->model === 'gpt-4o' ? 300 : 120; // 5 min pour GPT-4, 2 min pour autres
                        $this->setRateLimitPeriod($rateLimitDuration);
                        
                        throw new \Exception("Rate limit OpenAI persistant après " . self::MAX_RETRIES . " tentatives. Modèle: {$this->model}. Réessayez dans quelques minutes ou utilisez un modèle moins restrictif comme gpt-4o-mini.");
                    }
                } else {
                    // Ce n'est pas une erreur de rate limit, pas besoin de retry
                    throw $e;
                }
            }
        }
        
        throw $lastError ?? new \Exception("Échec après " . self::MAX_RETRIES . " tentatives");
    }
    
    /**
     * Effectue l'appel API proprement dit
     * 
     * @param string $prompt Le prompt à envoyer
     * @return string La réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    private function makeApiRequest(string $prompt): string {
        // Optimiser les paramètres selon le modèle
        $maxTokens = $this->getMaxTokensForModel();
        
        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'max_tokens' => $maxTokens,
            'top_p' => 0.95,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];
        
        // Configuration du contexte HTTP avec timeout
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'User-Agent: PropertySearchApp/1.0',
                    'OpenAI-Organization: ' . ($_ENV['OPENAI_ORG'] ?? '') // Organisation optionnelle
                ],
                'content' => json_encode($data),
                'timeout' => 90, // Timeout de 90 secondes
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents(self::API_URL, false, $context);
        
        // Vérifier les en-têtes de réponse HTTP
        if (isset($http_response_header)) {
            $httpCode = $this->parseHttpResponseCode($http_response_header);
            $rateLimitInfo = $this->extractRateLimitHeaders($http_response_header);
            
            // Log des informations de rate limit pour monitoring
            if (!empty($rateLimitInfo)) {
                error_log("OpenAI Rate Limit Info: " . json_encode($rateLimitInfo));
            }
            
            if ($httpCode === 429) {
                $retryAfter = $this->extractRetryAfter($http_response_header);
                $rateLimitType = $this->detectRateLimitType($result);
                
                $errorMsg = "Rate limit OpenAI dépassé (HTTP 429)";
                if ($retryAfter) {
                    $errorMsg .= " - Retry after {$retryAfter} seconds";
                }
                if ($rateLimitType) {
                    $errorMsg .= " - Type: $rateLimitType";
                }
                
                throw new \Exception($errorMsg);
                
            } elseif ($httpCode >= 400) {
                $errorDetails = $result ? json_decode($result, true) : null;
                $errorMessage = isset($errorDetails['error']['message']) 
                    ? $errorDetails['error']['message'] 
                    : "Erreur HTTP $httpCode";
                
                // Log détaillé des erreurs pour débuggage
                error_log("Erreur OpenAI API (HTTP $httpCode): " . json_encode($errorDetails));
                
                throw new \Exception("Erreur API OpenAI: " . $errorMessage . " (HTTP $httpCode)");
            }
        }
        
        if ($result === false) {
            throw new \Exception("Erreur de connexion à l'API OpenAI - Vérifiez votre connexion internet");
        }
        
        $response = json_decode($result, true);
        
        // Gestion des erreurs dans la réponse JSON
        if (isset($response['error'])) {
            $errorMessage = $response['error']['message'] ?? 'Erreur inconnue';
            $errorType = $response['error']['type'] ?? 'unknown_error';
            $errorCode = $response['error']['code'] ?? null;
            
            $fullErrorMsg = "Erreur API OpenAI [$errorType]";
            if ($errorCode) {
                $fullErrorMsg .= " ($errorCode)";
            }
            $fullErrorMsg .= ": " . $errorMessage;
            
            throw new \Exception($fullErrorMsg);
        }
        
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        } else {
            // Log de la réponse pour débuggage
            error_log("Réponse OpenAI inattendue: " . json_encode($response));
            throw new \Exception("Format de réponse OpenAI inattendu - vérifiez les logs pour plus de détails");
        }
    }
    
    /**
     * Détermine les max_tokens optimaux selon le modèle
     */
    private function getMaxTokensForModel(): int {
        return match ($this->model) {
            'gpt-4o' => 2048,
            'gpt-4o-mini' => 4096,
            'gpt-4-turbo' => 2048,
            'gpt-3.5-turbo' => 4096,
            default => 2048
        };
    }
    
    /**
     * Détecte le type de rate limit à partir de la réponse d'erreur
     */
    private function detectRateLimitType(?string $responseBody): ?string {
        if (!$responseBody) return null;
        
        $response = json_decode($responseBody, true);
        if (!$response || !isset($response['error']['message'])) return null;
        
        $message = strtolower($response['error']['message']);
        
        if (strpos($message, 'requests per minute') !== false) {
            return 'RPM (Requests Per Minute)';
        } elseif (strpos($message, 'tokens per minute') !== false) {
            return 'TPM (Tokens Per Minute)';
        } elseif (strpos($message, 'requests per day') !== false) {
            return 'RPD (Requests Per Day)';
        }
        
        return 'Unknown rate limit type';
    }
    
    /**
     * Extrait les informations de rate limit des headers
     */
    private function extractRateLimitHeaders(array $headers): array {
        $rateLimitInfo = [];
        
        foreach ($headers as $header) {
            if (stripos($header, 'x-ratelimit-') === 0) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $key = trim(strtolower($parts[0]));
                    $value = trim($parts[1]);
                    $rateLimitInfo[$key] = $value;
                }
            }
        }
        
        return $rateLimitInfo;
    }
    
    /**
     * Extrait la valeur Retry-After des headers HTTP
     */
    private function extractRetryAfter(array $headers): ?int {
        foreach ($headers as $header) {
            if (stripos($header, 'retry-after:') === 0) {
                return (int) trim(substr($header, 12));
            }
        }
        return null;
    }
    
    /**
     * Vérifie si l'erreur est liée au rate limit
     */
    private function isRateLimitError(string $errorMessage): bool {
        $rateLimitKeywords = [
            '429',
            'Too Many Requests',
            'Rate limit',
            'rate_limit_exceeded',
            'quota exceeded',
            'requests per minute',
            'tokens per minute',
            'billing hard limit'
        ];
        
        foreach ($rateLimitKeywords as $keyword) {
            if (stripos($errorMessage, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Parse le code de réponse HTTP depuis les en-têtes
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