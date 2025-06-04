<?php
/**
 * Point d'entrée pour la recherche d'annonces immobilières
 * Intègre à la fois l'interface utilisateur et le traitement des requêtes AJAX
 */

// Désactiver l'affichage des erreurs pour éviter les sorties HTML non désirées
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Buffer de sortie pour capturer les erreurs potentielles
ob_start();

// Chargement de l'autoloader Composer
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Imports pour le logger
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Imports pour les classes personnalisées
use App\PromptManager;
use App\ConfigManager;

// Initialisation du logger
$logger = new Logger('property_search');
$logger->pushHandler(new StreamHandler(dirname(__DIR__) . '/logs/search.log', Logger::DEBUG));

// Traitement de la requête AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyer le buffer de sortie pour éviter les caractères parasites
    ob_clean();
    
    // Headers pour JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    try {
        // Récupération des données JSON
        $jsonData = file_get_contents('php://input');
        $logger->info("Données POST reçues: " . $jsonData);
        
        if (empty($jsonData)) {
            throw new Exception('Aucune donnée reçue');
        }
        
        $searchData = json_decode($jsonData, true);
        
        // Vérification des données
        if (!$searchData || json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Données de recherche invalides ou JSON malformé: ' . json_last_error_msg());
        }
        
        // Log des données décodées
        $logger->info("Données décodées: " . json_encode($searchData));
        
        // Validation des champs obligatoires
        if (empty($searchData['agence'])) {
            throw new Exception('Le champ agence est obligatoire');
        }
        
        // Nettoyage et validation des données
        $cleanedData = [];
        
        // Champs requis
        $cleanedData['agence'] = trim($searchData['agence']);
        
        // Champs optionnels
        $optionalFields = ['reference', 'ville', 'code_postal', 'type_bien', 'prix', 'surface'];
        foreach ($optionalFields as $field) {
            if (!empty($searchData[$field])) {
                $cleanedData[$field] = trim($searchData[$field]);
            }
        }
        
        // Récupération du fournisseur d'IA sélectionné (optionnel)
        $selectedProvider = $searchData['ai_provider'] ?? null;
        
        // Journalisation de la recherche
        $logger->info("Recherche initiée avec les paramètres nettoyés: " . json_encode($cleanedData));
        if ($selectedProvider) {
            $logger->info("Fournisseur d'IA sélectionné: " . $selectedProvider);
        }
        
        // Gestion des prompts
        $promptManager = new PromptManager();
        $prompt = $promptManager->getPrompt('search_by_reference', $cleanedData);
        
        if (!$prompt) {
            throw new Exception("Le prompt 'search_by_reference' n'est pas configuré");
        }
        
        // Log du prompt généré (tronqué)
        $logger->info("Prompt généré: " . substr($prompt, 0, 500) . "...");
        
        // Utilisation de l'IA via le ConfigManager
        $configManager = new ConfigManager();
        
        if ($selectedProvider) {
            // Utiliser le fournisseur spécifié
            $aiProvider = $configManager->getProvider($selectedProvider);
            $logger->info("Utilisation du fournisseur spécifié: " . $selectedProvider);
        } else {
            // Utiliser le fournisseur par défaut
            $aiProvider = $configManager->getDefaultProvider();
            $logger->info("Utilisation du fournisseur par défaut");
        }
        
        $response = $aiProvider->sendRequest($prompt);
        
        // Log de la réponse brute
        $logger->info("Réponse brute IA: " . substr($response, 0, 1000) . (strlen($response) > 1000 ? "..." : ""));
        
        // Extraction du JSON de la réponse - Pattern amélioré
        $jsonPattern = '/```json\s*(\[.*?\]|\{.*?\})\s*```/s';
        if (preg_match($jsonPattern, $response, $matches)) {
            $extractedJson = $matches[1];
        } else {
            // Fallback : chercher directement un tableau ou objet JSON
            $jsonPattern = '/(\[.*?\]|\{.*?\})/s';
            if (preg_match($jsonPattern, $response, $matches)) {
                $extractedJson = $matches[0];
            } else {
                throw new Exception("Aucun JSON trouvé dans la réponse de l'IA");
            }
        }
        
        // Nettoyage du JSON (suppression des caractères de contrôle)
        $extractedJson = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $extractedJson);
        
        $result = json_decode($extractedJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error("Erreur de décodage JSON: " . json_last_error_msg() . " | JSON: " . $extractedJson);
            throw new Exception("Erreur de décodage JSON: " . json_last_error_msg());
        }
        
        // Journalisation du succès
        $logger->info("Résultat de recherche obtenu: " . json_encode($result));
        
        // Validation du résultat
        if (is_array($result) && !empty($result)) {
            // Renvoi du résultat
            $response = [
                'success' => true,
                'data' => $result,
                'count' => is_array($result[0]) ? count($result) : 1,
                'provider_used' => $selectedProvider ?? $configManager->getConfig()['default_provider']
            ];
        } else {
            // Résultat vide mais valide
            $response = [
                'success' => true,
                'data' => [],
                'count' => 0,
                'message' => 'Aucun résultat trouvé pour ces critères',
                'provider_used' => $selectedProvider ?? $configManager->getConfig()['default_provider']
            ];
        }
        
        // Nettoyage final du buffer avant envoi
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
        
    } catch (Exception $e) {
        // Journalisation de l'erreur
        $logger->error("Erreur: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        
        // Nettoyage du buffer avant envoi de l'erreur
        ob_clean();
        
        // Renvoi de l'erreur
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'non défini'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Nettoyage du buffer pour la page HTML
ob_end_clean();

// Chargement de la configuration des fournisseurs d'IA pour l'interface
try {
    $configManager = new ConfigManager();
    $availableProviders = $configManager->getAvailableProviders();
} catch (Exception $e) {
    $availableProviders = [];
    error_log("Erreur lors du chargement des fournisseurs d'IA: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche d'annonces immobilières</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome pour les icônes -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="text-center mb-4">Recherche d'annonces immobilières</h1>
        
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <!-- Sélection du fournisseur d'IA -->
                <?php if (!empty($availableProviders)): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="ai_provider" class="form-label">
                            <i class="fas fa-robot"></i> Fournisseur d'IA
                        </label>
                        <select id="ai_provider" class="form-select">
                            <option value="">Fournisseur par défaut</option>
                            <?php foreach ($availableProviders as $type => $info): ?>
                                <?php if ($info['enabled']): ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= $info['is_default'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($info['name']) ?> 
                                        (<?= htmlspecialchars($info['current_model']) ?>)
                                        <?= $info['is_default'] ? ' - Par défaut' : '' ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#aiConfigModal">
                            <i class="fas fa-cog"></i> Configuration IA
                        </button>
                    </div>
                </div>
                <hr>
                <?php endif; ?>
                
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="type_bien" class="form-label">Type de bien</label>
                        <select id="type_bien" class="form-select">
                            <option value="">Sélectionnez</option>
                            <option value="Appartement">Appartement</option>
                            <option value="Maison">Maison</option>
                            <option value="Terrain">Terrain</option>
                            <option value="Garage">Garage</option>
                            <option value="Bureau">Bureau</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="prix" class="form-label">Prix</label>
                        <input type="number" id="prix" class="form-control" placeholder="Prix en euros">
                    </div>
                    <div class="col-md-4">
                        <label for="surface" class="form-label">Surface</label>
                        <input type="number" id="surface" class="form-control" placeholder="Surface en m²">
                    </div>
                </div>
                
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label for="reference" class="form-label">Référence</label>
                        <input type="text" id="reference" class="form-control" placeholder="Ex: EP1560">
                    </div>
                    <div class="col-md-6">
                        <label for="agence" class="form-label">Agence <span class="text-danger">*</span></label>
                        <input type="text" id="agence" class="form-control" placeholder="Ex: RUE DE LA PAIX IMMO ANGERS 49" required>
                    </div>
                </div>
                
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label for="code_postal" class="form-label">Code postal</label>
                        <input type="text" id="code_postal" class="form-control" placeholder="Ex: 75001">
                    </div>
                    <div class="col-md-6">
                        <label for="ville" class="form-label">Ville</label>
                        <input type="text" id="ville" class="form-control" placeholder="Ex: Paris">
                    </div>
                </div>
                
                <div class="mt-4 d-flex align-items-center">
                    <button type="button" id="searchButton" class="btn btn-primary" disabled>
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <div id="loader" class="ms-3 d-none">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2">Recherche en cours...</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="resultsContainer"></div>
    </div>
    
    <!-- Modal de configuration IA -->
    <div class="modal fade" id="aiConfigModal" tabindex="-1" aria-labelledby="aiConfigModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="aiConfigModalLabel">
                        <i class="fas fa-robot"></i> Configuration des fournisseurs d'IA
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Configurez vos fournisseurs d'IA préférés. Assurez-vous d'avoir configuré les clés API correspondantes dans votre fichier .env.
                    </div>
                    
                    <?php foreach ($availableProviders as $type => $info): ?>
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-<?= $type === 'gemini' ? 'google' : 'openai' ?>"></i>
                                <?= htmlspecialchars($info['name']) ?>
                            </h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="provider_<?= $type ?>" 
                                       <?= $info['enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="provider_<?= $type ?>">
                                    Activé
                                </label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="model_<?= $type ?>" class="form-label">Modèle</label>
                                    <select class="form-select" id="model_<?= $type ?>">
                                        <?php foreach ($info['models'] as $model): ?>
                                            <option value="<?= htmlspecialchars($model) ?>" 
                                                    <?= $model === $info['current_model'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($model) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button class="btn btn-outline-primary btn-sm" 
                                            onclick="setAsDefault('<?= $type ?>')"
                                            <?= $info['is_default'] ? 'disabled' : '' ?>>
                                        <?= $info['is_default'] ? 'Fournisseur par défaut' : 'Définir par défaut' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary" onclick="saveAIConfig()">
                        <i class="fas fa-save"></i> Sauvegarder
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/search_ia.js"></script>
    
    <script>
        // Configuration des fournisseurs d'IA
        function setAsDefault(providerType) {
            // Cette fonction sera implémentée pour définir le fournisseur par défaut
            console.log('Setting default provider:', providerType);
            // TODO: Implémenter l'appel AJAX pour changer le fournisseur par défaut
        }
        
        function saveAIConfig() {
            // Cette fonction sera implémentée pour sauvegarder la configuration
            console.log('Saving AI configuration...');
            // TODO: Implémenter la sauvegarde de la configuration des fournisseurs
        }
        
        // Données des fournisseurs disponibles pour JavaScript
        window.availableProviders = <?= json_encode($availableProviders) ?>;
    </script>
</body>
</html>