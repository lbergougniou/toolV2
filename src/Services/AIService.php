<?php
namespace App\Services;

use App\Services\AI\AIProviderInterface;
use App\Services\AI\GeminiProvider;
use App\Logger\Logger;

/**
 * Service principal pour gérer les providers d'IA
 */
class AIService
{
    private Logger $logger;
    private array $config;
    private array $prompts;
    private ?AIProviderInterface $provider = null;

    /**
     * Configuration par défaut
     */
    private const DEFAULT_CONFIG = [
        'provider' => 'gemini',
        'model' => 'gemini-1.5-flash',
        'temperature' => 0.1,
        'max_tokens' => 1024,
        'timeout' => 30
    ];

    /**
     * Providers disponibles
     */
    private const PROVIDERS = [
        'gemini' => GeminiProvider::class,
        // Futurs providers à ajouter ici
    ];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->loadPrompts();
        $this->config = self::DEFAULT_CONFIG;
    }

    /**
     * Charge les prompts
     */
    private function loadPrompts(): void
    {
        $promptFile = __DIR__ . '/../../config/prompts.json';
        
        if (file_exists($promptFile)) {
            $this->prompts = json_decode(file_get_contents($promptFile), true) ?? [];
            
            // Log des prompts disponibles avec leurs configs
            $promptsInfo = [];
            foreach ($this->prompts as $name => $prompt) {
                $promptsInfo[$name] = [
                    'provider' => $prompt['config']['provider'] ?? self::DEFAULT_CONFIG['provider'],
                    'model' => $prompt['config']['model'] ?? self::DEFAULT_CONFIG['model']
                ];
            }
            $this->logger->debug("Prompts chargés", $promptsInfo);
        } else {
            $this->prompts = [];
            $this->logger->warning("Fichier prompts.json non trouvé");
        }
    }

    /**
     * Obtient ou initialise le provider pour une configuration donnée
     */
    private function getProvider(array $config): AIProviderInterface
    {
        $providerName = $config['provider'];
        
        // Si pas de provider ou provider différent, initialiser
        if ($this->provider === null || $this->provider->getProviderName() !== $providerName) {
            if (!isset(self::PROVIDERS[$providerName])) {
                throw new \Exception("Provider inconnu: {$providerName}");
            }

            $providerClass = self::PROVIDERS[$providerName];
            $this->provider = new $providerClass($this->logger, $config);

            $this->logger->info("Provider initialisé", [
                'provider' => $providerName,
                'model' => $config['model']
            ]);
        } else {
            // Même provider, juste mettre à jour le modèle si nécessaire
            $this->provider->setModel($config['model']);
        }

        return $this->provider;
    }

    /**
     * Exécute un prompt
     */
    public function executePrompt(string $promptName, array $data): array
    {
        if (!isset($this->prompts[$promptName])) {
            throw new \Exception("Prompt '{$promptName}' non trouvé");
        }

        $promptConfig = $this->prompts[$promptName];
        $this->validateData($promptName, $data, $promptConfig['validation'] ?? []);

        // Créer la configuration effective pour ce prompt
        $effectiveConfig = $this->config;
        if (isset($promptConfig['config'])) {
            $effectiveConfig = array_merge($this->config, $promptConfig['config']);
        }

        // Obtenir le provider avec la bonne configuration
        $provider = $this->getProvider($effectiveConfig);

        // Exécuter le prompt
        return $provider->executePrompt(
            $promptName, 
            $data, 
            $promptConfig['prompt']
        );
    }

    /**
     * Valide les données selon la configuration du prompt
     */
    private function validateData(string $promptName, array $data, array $validation): void
    {
        // Validation des champs requis
        if (isset($validation['required_fields'])) {
            foreach ($validation['required_fields'] as $field) {
                if (empty($data[$field])) {
                    throw new \Exception("Le champ '{$field}' est requis");
                }
            }
        }
        
        // Validation "au moins un champ"
        if (isset($validation['at_least_one'])) {
            $hasData = false;
            foreach ($validation['at_least_one'] as $field) {
                if (!empty(trim($data[$field] ?? ''))) {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                $fields = implode(', ', $validation['at_least_one']);
                throw new \Exception("Au moins un de ces champs doit être renseigné: {$fields}");
            }
        }
        
        // Validation des longueurs minimales
        if (isset($validation['min_length'])) {
            foreach ($validation['min_length'] as $field => $minLength) {
                if (isset($data[$field]) && strlen($data[$field]) < $minLength) {
                    throw new \Exception("Le champ '{$field}' doit contenir au moins {$minLength} caractères");
                }
            }
        }
    }

    /**
     * Getters simplifiés
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getAvailablePrompts(): array
    {
        $prompts = [];
        foreach ($this->prompts as $name => $config) {
            $prompts[$name] = [
                'description' => $config['description'] ?? $name,
                'provider' => $config['config']['provider'] ?? $this->config['provider'],
                'model' => $config['config']['model'] ?? $this->config['model']
            ];
        }
        return $prompts;
    }

    public function getPromptInfo(string $promptName): array
    {
        if (!isset($this->prompts[$promptName])) {
            throw new \Exception("Prompt '{$promptName}' non trouvé");
        }
        
        $prompt = $this->prompts[$promptName];
        return [
            'name' => $promptName,
            'description' => $prompt['description'] ?? '',
            'config' => $prompt['config'] ?? [],
            'validation' => $prompt['validation'] ?? [],
            'response_format' => $prompt['response_format'] ?? 'text'
        ];
    }

    public function getProviderInfo(): array
    {
        return [
            'provider' => $this->config['provider'],
            'model' => $this->config['model'],
            'available_providers' => array_keys(self::PROVIDERS)
        ];
    }
}