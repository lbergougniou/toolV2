<?php

/**
 * Mode strict pour le typage - Force la vérification des types au runtime
 */
declare(strict_types=1);

namespace ViaDialog\Api\Exception;

use Exception;
use Throwable;

/**
 * Exception générique pour les erreurs liées à l'API ViaDialog
 * 
 * Cette classe sert de base pour toutes les exceptions spécifiques à l'API ViaDialog.
 * Elle hérite de la classe Exception standard de PHP et ajoute un formatage
 * automatique du message d'erreur avec un préfixe identifiant.
 * 
 * Utilisations typiques :
 * - Erreurs de communication avec l'API
 * - Réponses d'erreur du serveur ViaDialog
 * - Problèmes de format de données reçues
 * - Timeouts et erreurs réseau
 * 
 * @package ViaDialog\Api\Exception
 * @author Scorimmo
 * @since 1.0.0
 */
class ApiException extends Exception
{
    /**
     * Constructeur de l'exception ApiException
     * 
     * Crée une nouvelle exception avec un message formaté automatiquement.
     * Le message final aura toujours le préfixe "Erreur API ViaDialog : "
     * pour faciliter l'identification de la source de l'erreur dans les logs.
     * 
     * Exemples d'utilisation :
     * ```php
     * throw new ApiException('Service non trouvé');
     * // Résultat: "Erreur API ViaDialog : Service non trouvé"
     * 
     * throw new ApiException('Timeout', 408);
     * // Résultat: "Erreur API ViaDialog : Timeout" avec code 408
     * ```
     *
     * @param string $message Le message d'erreur descriptif (sans préfixe)
     * @param int $code Le code d'erreur HTTP ou interne (0 par défaut)
     * @param Throwable|null $previous L'exception précédente pour la chaîne d'exceptions (null par défaut)
     * 
     * @see Exception::__construct() Pour la documentation de la classe parent
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        // Formatage automatique du message avec préfixe identificateur
        $formattedMessage = 'Erreur API ViaDialog : ' . $message;
        
        // Appel du constructeur parent avec le message formaté
        parent::__construct($formattedMessage, $code, $previous);
    }
}