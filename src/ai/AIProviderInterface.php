<?php
/**
 * Interface pour les fournisseurs d'IA
 * 
 * Cette interface définit la méthode commune à tous les fournisseurs d'IA
 */
namespace ai;

interface AIProviderInterface {
    /**
     * Envoie une requête au fournisseur d'IA et retourne la réponse
     * 
     * @param string $prompt Le prompt à envoyer
     * @return string La réponse du fournisseur d'IA
     */
    public function sendRequest(string $prompt): string;
}