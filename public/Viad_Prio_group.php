<?php
/**
 * Interface d'affichage des priorités agents par groupe ViaDialog
 * Permet de sélectionner un groupe actif et d'afficher les agents avec leur priorité
 */

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

// Initialisation du client ViaDialog
$client = new ViaDialogClient(
    $_ENV['VIAD_API_USERNAME'],
    $_ENV['VIAD_API_PASSWORD'],
    $_ENV['VIAD_API_COMPANY'],
    $_ENV['VIAD_API_GRANT_TYPE'],
    $_ENV['VIAD_API_SLUG']
);

// Récupération de la liste des groupes actifs
$activeGroups = [];
$error = null;
$groupData = null;
$agents = [];

try {
    $activeGroups = $client->getActiveGroups();
    // Tri par label
    usort($activeGroups, fn($a, $b) => strcasecmp($a['label'] ?? '', $b['label'] ?? ''));
} catch (ApiException $e) {
    $error = 'Erreur lors de la récupération des groupes: ' . $e->getMessage();
}

// Traitement si un groupe est sélectionné
if (isset($_GET['groupId']) && !empty($_GET['groupId'])) {
    try {
        $groupId = (int) $_GET['groupId'];
        $groupData = $client->getGroup($groupId);

        // Extraction et tri des agents par priorité
        if (isset($groupData['viaAgentViaGroupDTOs']) && is_array($groupData['viaAgentViaGroupDTOs'])) {
            $agents = $groupData['viaAgentViaGroupDTOs'];
            // Tri par priorité (1 en premier, 99 en dernier)
            usort($agents, fn($a, $b) => ($a['priority'] ?? 99) - ($b['priority'] ?? 99));
        }
    } catch (ApiException $e) {
        $error = 'Erreur lors de la récupération du groupe: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Priorités Agents par Groupe</title>
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
        .priority-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 1.1rem;
        }
        .priority-1 { background: #28a745; color: white; }
        .priority-2 { background: #17a2b8; color: white; }
        .priority-3 { background: #ffc107; color: black; }
        .priority-high { background: #dc3545; color: white; }
        .agent-row {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }
        .agent-row:hover {
            background-color: #f8f9fa;
        }
        .agent-row:last-child {
            border-bottom: none;
        }
        .group-info {
            background: #e7f3ff;
            border-left: 3px solid #2196F3;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="main-card p-4">
                    <h1 class="text-center mb-4">
                        <i class="bi bi-people"></i> Priorités Agents par Groupe
                    </h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Erreur !</strong> <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Sélection du groupe -->
                    <form method="GET" class="mb-4">
                        <div class="row align-items-end">
                            <div class="col-md-9">
                                <label for="groupId" class="form-label">
                                    <i class="bi bi-collection"></i> Sélectionner un groupe actif
                                </label>
                                <select class="form-select" id="groupId" name="groupId" required>
                                    <option value="">-- Choisir un groupe --</option>
                                    <?php foreach ($activeGroups as $group): ?>
                                        <option value="<?= htmlspecialchars($group['id'] ?? '') ?>"
                                            <?= (isset($_GET['groupId']) && $_GET['groupId'] == ($group['id'] ?? '')) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($group['label'] ?? 'Sans nom') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Afficher
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if ($groupData): ?>
                        <!-- Informations du groupe -->
                        <div class="group-info">
                            <h5 class="mb-2">
                                <i class="bi bi-info-circle"></i>
                                <?= htmlspecialchars($groupData['label'] ?? 'Groupe') ?>
                            </h5>
                            <small class="text-muted">
                                ID: <?= htmlspecialchars($groupData['id'] ?? 'N/A') ?> |
                                Agents: <?= count($agents) ?>
                            </small>
                        </div>

                        <!-- Liste des agents -->
                        <div class="section-header">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ol"></i> Agents par priorité
                            </h5>
                        </div>

                        <?php if (empty($agents)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-circle"></i> Aucun agent trouvé dans ce groupe.
                            </div>
                        <?php else: ?>
                            <!-- Bouton copier -->
                            <div class="mb-3 text-end">
                                <button type="button" class="btn btn-outline-primary" id="copyBtn">
                                    <i class="bi bi-clipboard"></i> Copier pour email
                                </button>
                            </div>

                            <div class="border rounded">
                                <?php foreach ($agents as $agent): ?>
                                    <?php
                                    $priority = $agent['priority'] ?? 99;
                                    $agentName = $agent['viaAgentRefDTO']['label'] ?? 'Agent inconnu';
                                    $priorityClass = match(true) {
                                        $priority == 1 => 'priority-1',
                                        $priority == 2 => 'priority-2',
                                        $priority == 3 => 'priority-3',
                                        default => 'priority-high'
                                    };
                                    ?>
                                    <div class="agent-row d-flex align-items-center">
                                        <span class="priority-badge <?= $priorityClass ?> me-3">
                                            <?= htmlspecialchars($priority) ?>
                                        </span>
                                        <span class="fw-medium">
                                            <?= htmlspecialchars($agentName) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Données pour JS -->
                            <script>
                                window.groupName = <?= json_encode($groupData['label'] ?? 'Groupe') ?>;
                                window.agentsList = <?= json_encode(array_map(fn($a) => [
                                    'priority' => $a['priority'] ?? 99,
                                    'name' => $a['viaAgentRefDTO']['label'] ?? 'Agent inconnu'
                                ], $agents)) ?>;
                            </script>

                            <!-- Légende -->
                            <div class="mt-3 text-muted small">
                                <i class="bi bi-info-circle"></i> Légende des priorités :
                                <span class="badge priority-1 ms-2">1</span> Haute
                                <span class="badge priority-2 ms-2">2</span> Moyenne
                                <span class="badge priority-3 ms-2">3</span> Normale
                                <span class="badge priority-high ms-2">4+</span> Basse
                            </div>
                        <?php endif; ?>
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
    <script src="js/viad_prio_group.js"></script>
</body>
</html>
