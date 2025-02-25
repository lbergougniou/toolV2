<?php
namespace App\Config;

class SmtpCodes
{
    /**
     * Constante CODES qui répertorie les codes de statut SMTP et leurs descriptions.
     * Les codes sont classés en plusieurs catégories :
     */
    const CODES = [
        // Codes de succès (2xx)
        250 => "Adresse valide et acceptée",  // Succès standard
        251 => "Utilisateur non local, le message sera transmis",  // Transfert possible

        // Erreurs temporaires (4xx) - Problèmes potentiellement résolus ultérieurement
        450 => "Action non exécutée: boîte mail temporairement indisponible",  // Serveur occupé
        451 => "Action annulée: erreur de traitement",  // Erreur interne
        452 => "Action non exécutée: système de stockage insuffisant",  // Manque d'espace

        // Erreurs permanentes (5xx) - Nécessitent une intervention
        500 => "Erreur de syntaxe dans la commande",  // Commande invalide
        501 => "Erreur de syntaxe dans les paramètres",  // Paramètres incorrects
        503 => "Séquence de commandes incorrecte",  // Ordre des commandes invalide
        550 => "Action non exécutée: boîte mail inexistante ou accès refusé",  // Adresse invalide
        551 => "Utilisateur non local",  // Destinataire inexistant
        552 => "Action annulée: espace de stockage dépassé",  // Boîte pleine
        553 => "Action non exécutée: nom de boîte invalide",  // Nom de boîte incorrect
        554 => "Transaction échouée ou commande invalide",  // Échec global

        // Codes spécifiques étendus (plus détaillés)
        "550-5.1.1" => "Adresse email inexistante",  // Adresse précise non trouvée
        "550-5.2.1" => "Boîte mail pleine",  // Quota dépassé
        "550-5.7.1" => "Expéditeur rejeté",  // Problème avec l'expéditeur
        "554-5.7.1" => "Service refusé, utilisateur bloqué"  // Blocage total
    ];
}