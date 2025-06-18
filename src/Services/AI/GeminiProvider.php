<?php
namespace App\Services\AI;

use App\Logger\Logger;

/**
 * Provider pour l'API Gemini de Google
 */
class GeminiProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private Logger $logger;
    private array $config;

    /**
     * URL de l'API Gemini
     */
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

    /**
     * Modèles disponibles
     */
    private const MODELS = [
        'gemini-1.5-pro',
        'gemini-1.5-flash',
        'gemini-2.0-flash',
        'gemini-2.0-pro'
    ];

    public function __construct(Logger $logger, array $config = [])
    {
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $this->logger = $logger;
        $this->config = $config;
        $this->model = $config['model'] ?? 'gemini-1.5-pro';

        if (empty($this->apiKey)) {
            throw new \Exception('GEMINI_API_KEY non définie');
        }
    }

    /**
     * Exécute un prompt
     */
    public function executePrompt(string $promptName, array $data, string $promptText = ''): array
    {
        if (empty($promptText)) {
            throw new \Exception("Prompt vide");
        }

        $formattedPrompt = $this->formatPrompt($promptName, $promptText, $data);
        $response = $this->callAPI($formattedPrompt);
        
        return $this->parseResponse($response, $promptName);
    }

    /**
     * Formate le prompt avec les données
     */
    private function formatPrompt(string $promptName, string $prompt, array $data): string
    {
        if ($promptName === 'order_name') {
            $formattedData = "Données à analyser :\n";
            $formattedData .= "Nom: " . ($data['nom'] ?? '') . "\n";
            $formattedData .= "Prénom: " . ($data['prenom'] ?? '') . "\n";
            $formattedData .= "Email: " . ($data['email'] ?? '') . "\n\n";
            
            return $formattedData . $prompt . "\n\nRéponds UNIQUEMENT avec le JSON demandé.";
        }
        
        // Format générique pour les futurs prompts
        $formattedData = "Données à traiter :\n";
        foreach ($data as $key => $value) {
            $formattedData .= ucfirst($key) . ": " . $value . "\n";
        }
        
        return $formattedData . "\n" . $prompt;
    }

    /**
     * Appelle l'API Gemini
     */
    private function callAPI(string $prompt): string
    {
        $url = sprintf(self::API_URL, $this->model, $this->apiKey);
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $this->config['temperature'] ?? 0.1,
                'maxOutputTokens' => $this->config['max_tokens'] ?? 1024,
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Erreur cURL: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("Erreur API HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);
        
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception("Format de réponse inattendu" . $response);
        }

        return $decoded['candidates'][0]['content']['parts'][0]['text'];
    }

    /**
     * Parse la réponse de l'API
     */
    private function parseResponse(string $response, string $promptName): array
    {
        // Extraction du JSON
        $cleanResponse = trim($response);
        $cleanResponse = preg_replace('/```json\s*/', '', $cleanResponse);
        $cleanResponse = preg_replace('/```\s*$/', '', $cleanResponse);
        
        // Pour les réponses texte, retourner directement
        if (strpos($cleanResponse, '{') === false) {
            return ['response' => $cleanResponse];
        }
        
        $jsonStart = strpos($cleanResponse, '{');
        $jsonEnd = strrpos($cleanResponse, '}');
        
        if ($jsonStart === false || $jsonEnd === false) {
            // Si pas de JSON, retourner comme texte
            return ['response' => $cleanResponse];
        }
        
        $jsonString = substr($cleanResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
        $parsed = json_decode($jsonString, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning("Impossible de parser le JSON, retour en texte", [
                'error' => json_last_error_msg()
            ]);
            return ['response' => $cleanResponse];
        }
        
        // Traitement spécifique pour order_name
        if ($promptName === 'order_name') {
            return [
                'prenom' => $this->normalizeValue($parsed['prenom'] ?? null),
                'nom' => $this->normalizeValue($parsed['nom'] ?? null)
            ];
        }
        
        return $parsed;
    }

    /**
     * Normalise une valeur
     */
    private function normalizeValue($value): ?string
    {
        if (empty($value) || strtolower($value) === 'null' || strtolower($value) === 'inconnu') {
            return null;
        }
        
        return trim($value);
    }

    /**
     * Change le modèle
     */
    public function setModel(string $model): void
    {
        if (!in_array($model, self::MODELS)) {
            throw new \Exception("Modèle non valide: {$model}");
        }
        
        $this->model = $model;
    }

    /**
     * Retourne le nom du provider
     */
    public function getProviderName(): string
    {
        return 'Gemini';
    }
}