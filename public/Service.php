<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

$BASE_URL = rtrim($_SERVER['REQUEST_URI'], '/');

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Vérification de la présence des variables d'environnement nécessaires
$requiredEnvVars = [
    'VIAD_API_USERNAME',
    'VIAD_API_PASSWORD',
    'VIAD_API_COMPANY',
    'VIAD_API_GRANT_TYPE',
    'VIAD_API_SLUG',
];

foreach ($requiredEnvVars as $var) {
    if (!isset($_ENV[$var])) {
        die("La variable d'environnement $var n'est pas définie. Veuillez vérifier votre fichier .env");
    }
}

// Chargement de la configuration des services
$configFile = dirname(__DIR__) . '/config/services.json';
$servicesConfig = [];

if (file_exists($configFile)) {
    $configContent = file_get_contents($configFile);
    $servicesConfig = json_decode($configContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die('Erreur lors du chargement de la configuration des services : ' . json_last_error_msg());
    }
} else {
    die('Fichier de configuration des services non trouvé : ' . $configFile);
}

try {
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    error_log('Client ViaDialog initialisé avec succès...');
} catch (ApiException $e) {
    error_log('Erreur API : ' . $e->getMessage());
    die('Erreur lors de l\'initialisation du client : ' . $e->getMessage());
} catch (Exception $e) {
    error_log('Erreur inattendue : ' . $e->getMessage());
    die('Erreur inattendue : ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Création de Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <h1 class="text-center mb-4">Création de Services ViaDialog</h1>
                
                <div class="card">
                    <div class="card-body">
                        <form id="serviceForm">
                            <div class="mb-3">
                                <label for="serviceType" class="form-label">Type de service :</label>
                                <select id="serviceType" name="serviceType" class="form-select" required>
                                    <option value="">Sélectionnez un type de service</option>
                                    <?php foreach ($servicesConfig as $type => $config): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" 
                                                data-description="<?= htmlspecialchars($config['description']) ?>">
                                            <?= htmlspecialchars($type) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="serviceDescription" class="form-text text-muted fst-italic"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="idPdv" class="form-label">ID PDV :</label>
                                <input type="text" id="idPdv" name="idPdv" class="form-control" required 
                                       placeholder="Exemple: 3F_IDF" 
                                       pattern="[A-Za-z0-9_-]+" 
                                       title="Seuls les lettres, chiffres, tirets et underscores sont autorisés">
                                <div class="form-text">Format recommandé : 3F_IDF (lettres, chiffres, tirets et underscores uniquement)</div>
                            </div>
                            
                            <div class="mb-3" id="sdaNumberGroup" style="display: none;">
                                <label for="sdaNumber" class="form-label">Numéro SDA affiché :</label>
                                <input type="text" id="sdaNumber" name="sdaNumber" class="form-control" 
                                       placeholder="Exemple: +33524727820 (laisser vide pour anonyme)"
                                       pattern="(\+33[0-9]{9})?">
                                <div class="form-text">Format international : +33524727820 ou laisser vide pour un service anonyme</div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" id="createButton" class="btn btn-primary btn-lg">
                                    Créer le service
                                </button>
                            </div>
                        </form>
                        
                        <div id="loading" class="text-center mt-4 d-none">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Chargement...</span>
                            </div>
                            <p class="mt-2">Création du service en cours...</p>
                        </div>
                        
                        <div id="result" class="mt-4"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Configuration des services (passée depuis PHP)
        const servicesConfig = <?= json_encode($servicesConfig) ?>;
    </script>
    <script src="js/service.js"></script>
</body>
</html>