<?php

/**
 * Mode strict pour le typage - Force la vérification des types au runtime
 */
declare(strict_types=1);

namespace ViaDialog\Api\Exception;

use Throwable;

/**
 * Exception spécifique pour les erreurs d'authentification ViaDialog
 * 
 * Cette classe hérite d'ApiException et est spécialisée pour gérer
 * tous les problèmes liés à l'authentification avec l'API ViaDialog.
 * Elle ajoute un préfixe spécifique au message d'erreur pour faciliter
 * l'identification rapide des problèmes d'authentification.
 * 
 * Cas d'utilisation typiques :
 * - Identifiants invalides (username/password incorrects)
 * - Token d'authentification expiré ou invalide
 * - Permissions insuffisantes pour accéder à une ressource
 * - Problèmes de configuration d'authentification
 * - Erreurs de grant type ou de scope OAuth
 * 
 * Message final généré :
 * "Erreur API ViaDialog : Erreur d'authentification : [message spécifique]"
 * 
 * @package ViaDialog\Api\Exception
 * @author Scorimmo
 * @since 1.0.0
 * @see ApiException La classe parent pour toutes les exceptions API
 */
class AuthenticationException extends ApiException
{
    /**
     * Constructeur de l'exception AuthenticationException
     * 
     * Crée une nouvelle exception d'authentification avec un double préfixe :
     * 1. Le préfixe général "Erreur API ViaDialog" (via la classe parent)
     * 2. Le préfixe spécifique "Erreur d'authentification"
     * 
     * Cette approche permet une hiérarchisation claire des messages d'erreur
     * et facilite le debugging et la gestion des logs.
     * 
     * Exemples d'utilisation :
     * ```php
     * throw new AuthenticationException('Token expiré');
     * // Résultat: "Erreur API ViaDialog : Erreur d'authentification : Token expiré"
     * 
     * throw new AuthenticationException('Identifiants invalides', 401);
     * // Résultat: "Erreur API ViaDialog : Erreur d'authentification : Identifiants invalides"
     * 
     * throw new AuthenticationException('Accès refusé', 403, $previousException);
     * // Avec chaînage d'exception pour conserver le contexte d'erreur
     * ```
     * 
     * @param string $message Le message d'erreur spécifique à l'authentification
     * @param int $code Le code d'erreur HTTP (typiquement 401, 403) ou interne (0 par défaut)
     * @param Throwable|null $previous L'exception précédente pour conserver la chaîne d'erreurs (null par défaut)
     * 
     * @see ApiException::__construct() Pour la documentation du constructeur parent
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        // Appel du constructeur parent avec un message préfixé spécifiquement
        // Le parent ajoutera automatiquement le préfixe "Erreur API ViaDialog : "
        parent::__construct(
            "Erreur d'authentification : " . $message,
            $code,
            $previous
        );
    }
}