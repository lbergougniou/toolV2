<?php
/**
 * Interface de mise à jour en masse des services ViaDialog
 * Permet d'appliquer des modifications à plusieurs services simultanément
 * Supporte les types: VIACONTACT, VIACALL, WEBCALLBACK
 */

// Augmentation du timeout d'exécution pour les mises à jour en masse
set_time_limit(300); // 5 minutes

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\ViadialogUpdateService;
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

// Traitement du formulaire en POST
$results = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Initialisation du client ViaDialog
        $client = new ViaDialogClient(
            $_ENV['VIAD_API_USERNAME'],
            $_ENV['VIAD_API_PASSWORD'],
            $_ENV['VIAD_API_COMPANY'],
            $_ENV['VIAD_API_GRANT_TYPE'],
            $_ENV['VIAD_API_SLUG']
        );

        $updateService = new ViadialogUpdateService($client);

        // Parsing des IDs de services
        $serviceIdsStr = $_POST['serviceIds'] ?? '';
        $serviceIds = $updateService->parseServiceIds($serviceIdsStr);

        if (empty($serviceIds)) {
            throw new Exception('Aucun ID de service valide fourni');
        }

        // Parsing des modifications JSON
        $modificationsJson = trim($_POST['modifications'] ?? '');

        if (empty($modificationsJson)) {
            throw new Exception('Aucune modification spécifiée');
        }

        $modifications = json_decode($modificationsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Format JSON invalide: ' . json_last_error_msg());
        }

        if (empty($modifications)) {
            throw new Exception('Le JSON de modifications est vide');
        }

        // Application des modifications
        $results = $updateService->applyModifications($serviceIds, $modifications);

    } catch (ApiException $e) {
        $error = 'Erreur API : ' . $e->getMessage();
        error_log($error);
    } catch (Exception $e) {
        $error = 'Erreur : ' . $e->getMessage();
        error_log($error);
    }
}

// Exemples de modifications
$exemples = [
    'VIACONTACT' => [
        'script' => [
            'scriptId' => 'Entrant',
            'scriptVersion' => '10'
        ]
    ],
    'VIACALL' => [
        'maxUser' => 5,
        'maxLine' => 10
    ],
    'WEBCALLBACK' => [
        'label' => 'Nouveau Label',
        'status' => 'ACTIVE'
    ]
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mise à jour des Services ViaDialog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .main-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .result-success {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        .result-error {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .info-badge {
            background: #e7f3ff;
            border-left: 3px solid #2196F3;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .example-section {
            background: #fff3cd;
            border-left: 3px solid #ffc107;
            padding: 15px;
            margin-top: 15px;
            border-radius: 5px;
        }
        .example-code {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .example-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .example-tab {
            padding: 8px 16px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .example-tab:hover {
            background: #5a6268;
        }
        .example-tab.active {
            background: #667eea;
        }
        .json-editor {
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="main-card p-4">
                    <h1 class="text-center mb-4">
                        <i class="bi bi-tools"></i> Mise à jour des Services ViaDialog
                    </h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Erreur !</strong> <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($results): ?>
                        <div class="section-header">
                            <h3 class="mb-0"><i class="bi bi-clipboard-check"></i> Résultats de la mise à jour</h3>
                        </div>

                        <?php
                        $successCount = 0;
                        $failureCount = 0;
                        foreach ($results as $result):
                            if ($result['success']) $successCount++;
                            else $failureCount++;
                        ?>
                            <div class="<?= $result['success'] ? 'result-success' : 'result-error' ?>">
                                <i class="bi bi-<?= $result['success'] ? 'check-circle' : 'x-circle' ?>"></i>
                                <strong>Service #<?= htmlspecialchars($result['serviceId']) ?></strong>:
                                <?= $result['success']
                                    ? htmlspecialchars($result['message'] ?? 'Mise à jour réussie')
                                    : htmlspecialchars($result['error'])
                                ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="alert alert-info mt-3">
                            <strong>Résumé :</strong>
                            Total: <?= count($results) ?> |
                            <span class="text-success">Succès: <?= $successCount ?></span> |
                            <span class="text-danger">Échecs: <?= $failureCount ?></span>
                        </div>

                        <div class="text-center mt-3">
                            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise"></i> Nouvelle mise à jour
                            </a>
                        </div>
                    <?php else: ?>

                        <div class="info-badge">
                            <i class="bi bi-info-circle"></i>
                            <strong>Information :</strong> Cette interface permet de mettre à jour plusieurs services ViaDialog simultanément.
                            Supporte les types: <strong>VIACONTACT</strong>, <strong>VIACALL</strong>, <strong>WEBCALLBACK</strong>.
                        </div>

                        <form method="POST" id="updateForm">

                            <!-- Section IDs de services -->
                            <div class="form-section">
                                <h4 class="mb-3"><i class="bi bi-list-ol"></i> Services à modifier</h4>
                                <div class="mb-3">
                                    <label for="serviceIds" class="form-label">
                                        IDs des services <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="serviceIds"
                                        name="serviceIds"
                                        placeholder="Ex: 123456, 789012, 345678"
                                        required
                                    >
                                    <div class="form-text">
                                        Séparez les IDs par des virgules. Les espaces sont ignorés.
                                    </div>
                                </div>
                            </div>

                            <!-- Section Modifications JSON -->
                            <div class="form-section">
                                <h4 class="mb-3"><i class="bi bi-code-square"></i> Modifications (Format JSON)</h4>

                                <div class="mb-3">
                                    <label for="modifications" class="form-label">
                                        Données à modifier <span class="text-danger">*</span>
                                    </label>
                                    <textarea
                                        class="form-control json-editor"
                                        id="modifications"
                                        name="modifications"
                                        rows="10"
                                        placeholder='Collez ici vos modifications au format JSON...'
                                        required
                                    ></textarea>
                                    <div class="form-text">
                                        Format JSON. Seuls les champs spécifiés seront modifiés.
                                    </div>
                                </div>

                                <!-- Exemple -->
                                <div class="example-section">
                                    <h5 class="mb-3"><i class="bi bi-lightbulb"></i> Exemple de modification</h5>

                                    <div class="example-content">
                                        <strong>Format universel (valable pour VIACONTACT, VIACALL, WEBCALLBACK):</strong>
                                        <div class="example-code">{
  "scriptId": "Entrant - PROD",
  "scriptVersion": 1,
  "script": {
    "scriptId": "Entrant - PROD",
    "scriptVersion": "1"
  }
}</div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyExample()">
                                            <i class="bi bi-clipboard"></i> Copier dans le formulaire
                                        </button>
                                    </div>

                                    <div class="alert alert-info mt-3 mb-0">
                                        <small>
                                            <strong>Note importante:</strong> Ce format s'applique à tous les types de services (VIACONTACT, VIACALL, WEBCALLBACK).
                                            Le type de service est automatiquement déterminé par le numéro de service que vous saisissez.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-between mt-4">
                                <button type="reset" class="btn btn-secondary btn-lg">
                                    <i class="bi bi-x-circle"></i> Réinitialiser
                                </button>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-upload"></i> Appliquer les modifications
                                </button>
                            </div>
                        </form>

                    <?php endif; ?>
                </div>

                <div class="text-center mt-3">
                    <a href="index.php" class="btn btn-outline-light">
                        <i class="bi bi-house"></i> Retour à l'accueil
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/viad_edit_service.js"></script>
</body>
</html>
