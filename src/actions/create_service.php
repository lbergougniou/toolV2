<?php
/**
 * Création de service ViaDialog
 * 
 * Ce script permet de créer un nouveau service ViaDialog basé sur une configuration
 * prédéfinie et d'y associer éventuellement un numéro SDA.
 * 
 * Méthode HTTP acceptée : POST
 * Format des données : JSON
 * 
 * Structure attendue des données POST :
 * {
 *   "serviceType": "type_de_service",
 *   "idPdv": "identifiant_point_de_vente",
 *   "sdaNumber": "+33123456789" (optionnel)
 * }
 */

// Chargement de l'autoloader Composer
require_once __DIR__ . '/../../vendor/autoload.php';

// Import des classes nécessaires
use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

// Configuration des headers CORS pour permettre les requêtes cross-origin
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');                    // Autoriser toutes les origines
header('Access-Control-Allow-Methods: POST, OPTIONS');       // Méthodes HTTP autorisées
header('Access-Control-Allow-Headers: Content-Type');        // Headers autorisés

// Gestion des requêtes OPTIONS pour la pré-validation CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Vérification que la requête utilise la méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

// Chargement des variables d'environnement
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
} catch (Exception $e) {
    error_log('Erreur lors du chargement des variables d\'environnement : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur de configuration']);
    exit();
}

// Chargement de la configuration des services depuis le fichier JSON
$configFile = __DIR__ . '/../../config/services.json';
if (!file_exists($configFile)) {
    error_log('Fichier de configuration des services non trouvé : ' . $configFile);
    echo json_encode(['error' => 'Configuration des services non trouvée']);
    exit();
}

// Lecture et décodage du fichier de configuration
$configContent = file_get_contents($configFile);
$servicesConfig = json_decode($configContent, true);

// Vérification de la validité du JSON de configuration
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('Erreur lors du parsing de la configuration : ' . json_last_error_msg());
    echo json_encode(['error' => 'Configuration des services invalide']);
    exit();
}

// Lecture et décodage des données POST
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Vérification de la validité du JSON des données d'entrée
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'Données JSON invalides']);
    exit();
}

// Validation de la présence des champs obligatoires
if (!isset($data['serviceType']) || !isset($data['idPdv'])) {
    echo json_encode(['error' => 'Données manquantes (serviceType ou idPdv)']);
    exit();
}

// Extraction et nettoyage des données d'entrée
$serviceType = trim($data['serviceType']);        // Type de service (défini dans la config)
$idPdv = trim($data['idPdv']);                   // Identifiant du point de vente
$sdaNumber = isset($data['sdaNumber']) ? trim($data['sdaNumber']) : '';  // Numéro SDA (optionnel)

// Validation du type de service contre la configuration
if (!isset($servicesConfig[$serviceType])) {
    echo json_encode(['error' => 'Type de service non reconnu : ' . $serviceType]);
    exit();
}

// Validation de l'ID PDV (alphanumérique, tirets et underscores autorisés)
if (empty($idPdv) || !preg_match('/^[A-Za-z0-9_-]+$/', $idPdv)) {
    echo json_encode(['error' => 'ID PDV invalide. Seuls les lettres, chiffres, tirets et underscores sont autorisés.']);
    exit();
}

// Validation du format du numéro SDA si fourni (format français international)
if (!empty($sdaNumber) && !preg_match('/^\+33[0-9]{9}$/', $sdaNumber)) {
    echo json_encode(['error' => 'Numéro SDA invalide. Format attendu : +33xxxxxxxxx']);
    exit();
}

try {
    // Initialisation du client API ViaDialog
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    // Récupération de la configuration pour le type de service demandé
    $serviceConfig = $servicesConfig[$serviceType];
    
    // Génération du nom du service en remplaçant le placeholder par l'ID PDV
    $serviceName = str_replace('{{id_PDV}}', $idPdv, $serviceConfig['config']['name_format']);
    
    // Vérification de l'unicité du nom et génération d'un nom unique si nécessaire
    $finalServiceName = ensureUniqueServiceName($client, $serviceName);
    
    // Préparation du payload à partir de la configuration
    $payload = $serviceConfig['viad_payload'];
    $payload['label'] = $finalServiceName;
    
    // Configuration spécifique pour les services avec numéro SDA sortant
    if (isset($serviceConfig['config']['has_sda_field']) && $serviceConfig['config']['has_sda_field']) {
        // Attribution du numéro SDA ou configuration anonyme
        $payload['clinum'] = $sdaNumber;          // Numéro client (pour l'identification)
        $payload['numDisplayed'] = $sdaNumber;    // Numéro affiché (pour l'appelé)
        
        error_log("Service sortant configuré avec SDA: " . ($sdaNumber ?: 'anonyme'));
    }
    
    // Log du payload pour le debugging
    error_log('Payload à envoyer : ' . json_encode($payload, JSON_PRETTY_PRINT));
    
    // Création du service via l'API ViaDialog
    $response = $client->createService($payload);
    
    // Extraction de l'ID du service créé
    $serviceId = $response['id'] ?? null;
    if (!$serviceId) {
        throw new ApiException("ID du service non retourné par l'API");
    }
    
    // Gestion des webhooks associés au service
    $webhookResults = [];
    if (isset($serviceConfig['config']['webhook_ids']) && !empty($serviceConfig['config']['webhook_ids'])) {
        try {
            // Association du service aux webhooks configurés
            $webhookResults = $client->addServiceToWebhooks($serviceId, $serviceConfig['config']['webhook_ids']);
            error_log('Webhooks traités : ' . json_encode($webhookResults, JSON_PRETTY_PRINT));
        } catch (Exception $webhookException) {
            error_log('Erreur lors de la gestion des webhooks : ' . $webhookException->getMessage());
            // On continue l'exécution même si les webhooks échouent
        }
    }
    
    // Réponse de succès avec toutes les informations du service créé
    echo json_encode([
        'success' => true,
        'serviceName' => $finalServiceName,
        'serviceId' => $serviceId,
        'serviceDetails' => [
            'label' => $response['label'] ?? $finalServiceName,
            'product' => $response['product'] ?? 'VIACONTACT',
            'status' => $response['status'] ?? 'OPEN',
            'enable' => $response['enable'] ?? true
        ],
        'webhookResults' => $webhookResults,
        'originalResponse' => $response
    ]);

} catch (ApiException $e) {
    // Gestion spécifique des erreurs de l'API ViaDialog
    error_log('Erreur API ViaDialog : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur API : ' . $e->getMessage()]);
    
} catch (Exception $e) {
    // Gestion générale des autres erreurs
    error_log('Erreur inattendue : ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur inattendue : ' . $e->getMessage()]);
}

/**
 * Vérifie l'unicité du nom de service et ajoute un suffixe numérique si nécessaire
 * 
 * Cette fonction récupère la liste des services existants et vérifie si le nom
 * proposé est déjà utilisé. Si c'est le cas, elle ajoute un suffixe numérique
 * incrémental jusqu'à trouver un nom unique.
 * 
 * @param ViaDialogClient $client Instance du client API
 * @param string $baseName Nom de base proposé pour le service
 * @return string Nom final unique pour le service
 */
function ensureUniqueServiceName(ViaDialogClient $client, string $baseName): string
{
    try {
        // Récupération de la liste complète des services existants (limite à 1000)
        $existingServices = $client->getServiceList([], 1000);
        
        // Extraction des noms de tous les services existants
        $existingNames = array_map(function($service) {
            return $service->getLabel();
        }, $existingServices);
        
        $finalName = $baseName;
        $counter = 1;
        
        // Boucle d'incrémentation jusqu'à trouver un nom unique
        while (in_array($finalName, $existingNames)) {
            $counter++;
            $finalName = $baseName . '-' . $counter;
        }
        
        // Log si le nom a été modifié
        if ($counter > 1) {
            error_log("Nom de service modifié de '$baseName' vers '$finalName' pour éviter les doublons");
        }
        
        return $finalName;
        
    } catch (Exception $e) {
        // En cas d'erreur lors de la vérification, on retourne le nom de base
        error_log('Erreur lors de la vérification d\'unicité : ' . $e->getMessage());
        return $baseName;
    }
}
?>