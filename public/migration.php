<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

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

try {
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    $activeViaContactServices = $client->getServiceList(
        ['eq,product,VIACONTACT', 'eq,enable,true'],
        500
    );
    
    // Vérifier le mode de filtrage (par défaut : tous les services)
    $cleanupMode = isset($_GET['cleanup']) && $_GET['cleanup'] === '1';
    
    if ($cleanupMode) {
        // Mode nettoyage : Filtrer pour ne garder que les anciens formats (sauf 01e-Campagnes)
        $servicesToDisplay = array_filter($activeViaContactServices, function($service) {
            $label = $service->getLabel();
            // Nouveau format : "01e-<id_pdv (int)>-<nom>" (ex: "01e-12345-Support")
            // Ancien format : "01e-<nom>" (ex: "01e-Alysia")
            
            // Exclure 01e-Campagnes du mode nettoyage
            if ($label === '01e-Campagnes') {
                return false;
            }
            
            // Détecter le nouveau format : commence par "01e-" puis des chiffres puis "-"
            $newFormatPattern = '/^01e-\d+-/';
            $isNewFormat = preg_match($newFormatPattern, $label);
            
            // Garder seulement les anciens formats
            return $label && strpos($label, '01e-') === 0 && !$isNewFormat;
        });
    } else {
        // Mode normal : tous les services
        $servicesToDisplay = $activeViaContactServices;
    }
    
    // Trier les services par ordre alphabétique
    usort($servicesToDisplay, function($a, $b) {
        return strcasecmp($a->getLabel(), $b->getLabel());
    });
    
} catch (ApiException $e) {
    die('Erreur lors de la récupération des services : ' . $e->getMessage());
} catch (Exception $e) {
    die('Erreur inattendue : ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration SDA</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Select2 Bootstrap theme -->
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="card-title mb-0">
                                    <i class="bi bi-telephone-plus"></i>
                                    Migration et Gestion SDA
                                </h2>
                                <p class="mb-0 mt-2"><small>Ajouter des SDA à un service ou supprimer tous les SDA d'un service</small></p>
                            </div>
                            <div>
                                <?php if ($cleanupMode): ?>
                                    <a href="?" class="btn btn-light btn-sm">
                                        <i class="bi bi-list"></i> Mode normal
                                    </a>
                                    <span class="badge bg-warning text-dark ms-2">Mode nettoyage</span>
                                <?php else: ?>
                                    <a href="?cleanup=1" class="btn btn-warning btn-sm">
                                        <i class="bi bi-tools"></i> Mode nettoyage
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <form id="migrationForm">
                            <!-- Section 1: Sélection du service -->
                            <div class="mb-4">
                                <h5 class="text-primary">1. Sélection du service</h5>
                                <div class="mb-3">
                                    <label for="service" class="form-label">Service ViaContact :</label>
                                    <select id="service" name="service" class="form-select">
                                        <option value="">
                                            <?php echo $cleanupMode ? 'Sélectionnez un service (anciens formats uniquement)' : 'Sélectionnez un service'; ?>
                                        </option>
                                        <?php foreach ($servicesToDisplay as $service): ?>
                                            <option value="<?= htmlspecialchars($service->getId()) ?>">
                                                <?= htmlspecialchars($service->getLabel()) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Section 2: Ajout des SDA -->
                            <div class="mb-4">
                                <h5 class="text-primary">2. Ajout des numéros SDA (optionnel)</h5>
                                <div class="mb-3">
                                    <label for="sdaInput" class="form-label">Numéros SDA :</label>
                                    <textarea id="sdaInput" name="sdaInput" class="form-control" rows="6" 
                                              placeholder="524727709,524727716,524727725,524727726"></textarea>
                                    <div class="form-text">
                                        Saisissez les numéros SDA à 9 chiffres, séparés par des virgules.<br>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons d'action -->
                            <div class="row gap-2">
                                <div class="col-md-6" id="migrateButtonCol">
                                    <div class="d-grid">
                                        <button type="button" id="migrateButton" class="btn btn-success btn-lg">
                                            <i class="bi bi-check-circle"></i>
                                            Ajouter les SDA
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6" id="deleteButtonCol">
                                    <div class="d-grid">
                                        <button type="button" id="deleteButton" class="btn btn-danger btn-lg">
                                            <i class="bi bi-trash"></i>
                                            Supprimer tous les SDA
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Zone de résultats -->
                        <div id="result" class="mt-4"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="js/migration.js"></script>
</body>
</html>