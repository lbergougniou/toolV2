<?php

class AIService2
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;
    private string $provider;
    
    public function __construct()
    {
        // Déterminer quel provider utiliser (OpenAI ou Anthropic)
        $this->provider = $_ENV['AI_PROVIDER'] ?? 'openai'; // 'openai' ou 'anthropic'
        
        if ($this->provider === 'anthropic') {
            $this->apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
            $this->apiUrl = 'https://api.anthropic.com/v1/messages';
            $this->model = 'claude-3-sonnet-20240229'; // Modèle optimisé pour ce type de tâche
        } else {
            $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
            $this->apiUrl = 'https://api.openai.com/v1/chat/completions';
            $this->model = 'gpt-4-turbo-preview'; // GPT-4 Turbo pour des résultats optimaux
        }
        
        if (empty($this->apiKey)) {
            throw new Exception("Clé API IA manquante dans le fichier .env");
        }
    }
    
    /**
     * Analyse une propriété immobilière à partir du contexte
     */
    public function analyzeProperty(string $context): array
    {
        $prompt = $this->buildPrompt($context);
        
        try {
            if ($this->provider === 'anthropic') {
                $response = $this->callAnthropicAPI($prompt);
            } else {
                $response = $this->callOpenAIAPI($prompt);
            }
            
            // Parser la réponse JSON
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Réponse IA invalide: " . json_last_error_msg());
            }
            
            return $data;
            
        } catch (Exception $e) {
            error_log("Erreur AI Service: " . $e->getMessage());
            
            // Retourner une structure vide en cas d'erreur
            return [
                'type_bien' => null,
                'prix' => null,
                'surface' => null,
                'nombre_pieces' => null,
                'lien_annonce' => null
            ];
        }
    }
    
    /**
     * Construit le prompt pour l'IA
     */
    private function buildPrompt(string $context): string
    {
        return <<<PROMPT
Tu es un expert en analyse d'annonces immobilières françaises. Analyse les informations fournies et extrais les données demandées.

CONTEXTE:
$context

INSTRUCTIONS:
1. Recherche dans les résultats une annonce qui correspond EXACTEMENT à la référence et à l'agence mentionnées
2. L'annonce doit dater de moins de 3 mois
3. Extrais les informations suivantes de l'annonce trouvée:
   - Type de bien (maison, appartement, garage, terrain, local commercial, bureau)
   - Prix en euros (nombre uniquement, sans symbole)
   - Surface en m² (nombre uniquement)
   - Nombre de pièces (nombre uniquement)
   - Lien actif vers l'annonce

RÈGLES IMPORTANTES:
- Si une information n'est pas fiable ou manquante, retourne null pour cette valeur
- Le lien doit être une URL complète et valide
- Vérifie que l'annonce correspond bien à la référence et l'agence données
- Ne retourne que des informations dont tu es sûr à 100%

Réponds UNIQUEMENT avec un objet JSON valide dans ce format exact:
{
    "type_bien": "appartement",
    "prix": 250000,
    "surface": 75.5,
    "nombre_pieces": 3,
    "lien_annonce": "https://www.example.com/annonce-123"
}

Si aucune annonce correspondante n'est trouvée, retourne:
{
    "type_bien": null,
    "prix": null,
    "surface": null,
    "nombre_pieces": null,
    "lien_annonce": null
}
PROMPT;
    }
    
    /**
     * Appel API OpenAI
     */
    private function callOpenAIAPI(string $prompt): string
    {
        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es un assistant spécialisé dans l\'analyse d\'annonces immobilières. Tu réponds toujours en JSON valide.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1, // Très faible pour des résultats cohérents
            'max_tokens' => 500,
            'response_format' => ['type' => 'json_object']
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Erreur API OpenAI: HTTP $httpCode - $response");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception("Réponse OpenAI invalide");
        }
        
        return $result['choices'][0]['message']['content'];
    }
    
    /**
     * Appel API Anthropic (Claude)
     */
    private function callAnthropicAPI(string $prompt): string
    {
        $data = [
            'model' => $this->model,
            'max_tokens' => 500,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Erreur API Anthropic: HTTP $httpCode - $response");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['content'][0]['text'])) {
            throw new Exception("Réponse Anthropic invalide");
        }
        
        // Extraire le JSON de la réponse
        $text = $result['content'][0]['text'];
        
        // Chercher le JSON dans la réponse
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            return $matches[0];
        }
        
        throw new Exception("Aucun JSON trouvé dans la réponse");
    }
}