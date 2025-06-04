<?php
/**
 * Factory pour les fournisseurs d'IA
 * 
 * Cette classe permet de créer le bon fournisseur d'IA en fonction du type demandé
 */
namespace ai;

class AIFactory {
    /**
     * Crée une instance du fournisseur d'IA demandé
     * 
     * @param string $type Le type de fournisseur (gemini, openai, chatgpt)
     * @param array $options Options supplémentaires (modèle, etc.)
     * @return AIProviderInterface Une instance du fournisseur d'IA
     * @throws \Exception Si le type de fournisseur n'est pas supporté
     */
    public static function create(string $type, array $options = []): AIProviderInterface {
        return match (strtolower($type)) {
            'gemini' => new GeminiProvider(),
            'openai', 'chatgpt' => new OpenAIProvider($options['model'] ?? 'gpt-4o'),
            // Ajoutez ici d'autres fournisseurs à l'avenir
            default => throw new \Exception("Fournisseur d'IA non supporté: $type")
        };
    }
    
    /**
     * Récupère la liste des fournisseurs disponibles
     * 
     * @return array Liste des fournisseurs disponibles avec leurs modèles
     */
    public static function getAvailableProviders(): array {
        return [
            'gemini' => [
                'name' => 'Google Gemini',
                'models' => ['gemini-2.5-pro-preview-05-06']
            ],
            'openai' => [
                'name' => 'OpenAI ChatGPT',
                'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo']
            ]
        ];
    }
}