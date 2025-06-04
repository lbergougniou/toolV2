<?php
namespace App\Services\AI;

/**
 * Interface pour les providers d'IA
 */
interface AIProviderInterface
{
    /**
     * Exécute un prompt avec les données fournies
     *
     * @param string $promptName Nom du prompt à exécuter
     * @param array $data Données à envoyer
     * @param string $promptText Texte du prompt
     * @return array Réponse parsée
     * @throws \Exception En cas d'erreur
     */
    public function executePrompt(string $promptName, array $data, string $promptText = ''): array;

    /**
     * Change le modèle utilisé
     *
     * @param string $model Nom du modèle
     * @throws \Exception Si le modèle n'est pas valide
     */
    public function setModel(string $model): void;
}