<?php
// Ce script doit être placé à la racine de votre projet

// Charger l'autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Utiliser notre classe PhoneUtil
use App\PhoneUtil;

// Fonction d'affichage des résultats
function testPhone($number) {
    $phoneUtil = new PhoneUtil();
    $phoneUtil->setDebug(true);
    
    echo "\n========= TEST POUR: $number =========\n";
    
    // Tester la fonction complète
    $result = $phoneUtil->detectPhoneCountry($number);
    
    // Afficher si le numéro est considéré comme valide
    echo "Est valide: " . ($result['isValid'] ? "OUI" : "NON") . "\n";
    
    // Afficher les logs détaillés pour le débogage
    echo "\nLOGS DE DÉTECTION:\n";
    if (isset($result['logs'])) {
        foreach ($result['logs'] as $log) {
            echo "- " . $log['message'];
            if ($log['data'] !== null) {
                if (is_array($log['data']) || is_object($log['data'])) {
                    echo ": " . json_encode($log['data'], JSON_PRETTY_PRINT);
                } else {
                    echo ": " . $log['data'];
                }
            }
            echo "\n";
        }
    }
    
    // Afficher les informations de détection
    echo "\nRÉSULTAT COMPLET:\n";
    // Enlever les logs pour une meilleure lisibilité
    unset($result['logs']);
    print_r($result);
    
    echo "\n=======================================\n";
}

// En-tête
echo "\n============ TEST DE DÉTECTION DES NUMÉROS DOM-TOM ============\n";

// Tester le numéro problématique principal
testPhone("0692093550"); // La Réunion

// Autres numéros DOM-TOM
testPhone("0690123456"); // Guadeloupe
testPhone("0696123456"); // Martinique
testPhone("0694123456"); // Guyane

// Numéros français métropolitains pour comparaison
testPhone("0612345678"); // Mobile
testPhone("0123456789"); // Fixe Paris

// Formats internationaux
testPhone("+262692093550"); // La Réunion
testPhone("+33612345678");  // France métropolitaine

echo "\n============ FIN DES TESTS ============\n";