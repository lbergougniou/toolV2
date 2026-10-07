<?php
namespace App\Config;

/**
 * Classe MailjetCodes
 * 
 * Cette classe contient les définitions des codes de résultat et de risque
 * retournés par l'API de vérification d'email de Mailjet, avec leurs explications.
 */
class MailjetCodes
{
    /**
     * Constantes pour les types de résultats de vérification Mailjet
     */
    const RESULT_CODES = [
        'deliverable' => "Adresse email valide et délivrable",
        'catch_all' => "Domaine catch-all qui accepte tous les emails sans valider leur existence",
        'undeliverable' => "Adresse email non délivrable, n'existe pas ou rejette les messages",
        'do_not_send' => "Adresse email à laquelle il ne faut pas envoyer de messages",
        'unknown' => "Impossible de déterminer la validité de l'adresse email"
    ];

    /**
     * Constantes pour les niveaux de risque Mailjet
     */
    const RISK_LEVELS = [
        'low' => "Faible risque de bounce",
        'medium' => "Risque modéré de bounce, à utiliser avec précaution",
        'high' => "Risque élevé de bounce, utilisation non recommandée",
        'unknown' => "Niveau de risque indéterminé"
    ];

    /**
     * Constantes pour les explications combinées (résultat + risque)
     */
    const COMBINED_EXPLANATIONS = [
        'deliverable_low' => "Cette adresse email est valide et a un faible risque de bounce. Elle est recommandée",
        'deliverable_medium' => "Cette adresse email est valide mais présente un risque modéré. À utiliser avec précaution et surveiller les taux de bounce.",
        'deliverable_high' => "Cette adresse email est valide mais présente un risque élevé de bounce. Son utilisation n'est pas recommandée pour des campagnes importantes.",
        'catch_all' => "Ce domaine accepte tous les emails, même ceux qui n'existent pas réellement. Impossible de confirmer si cette adresse spécifique est valide. Utilisez avec prudence.",
        'undeliverable' => "Cette adresse email n'est pas délivrable. Elle est invalide ou n'existe pas. Ne pas utiliser pour l'envoi de mail",
        'do_not_send' => "Cette adresse email est à éviter. La boîte peut être pleine, inactive ou configurée pour rejeter vos messages.",
        'unknown' => "Impossible de déterminer la validité de cette adresse."
    ];
}