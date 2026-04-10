<?php
/**
 * Interface d'affichage et modification des priorités agents par groupe ViaDialog
 * Permet de sélectionner un groupe actif, afficher/modifier les agents avec leur priorité
 *
 * Gère également les appels AJAX (POST JSON) pour la mise à jour des agents.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;
use ViaDialog\GroupAgentService;

// --- Appel AJAX : mise à jour des agents ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json');
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    $body    = json_decode(file_get_contents('php://input'), true);
    $groupId = (int) ($body['groupId'] ?? 0);
    $agents  = $body['agents'] ?? [];
    try {
        $client  = new ViaDialogClient(
            $_ENV['VIAD_API_USERNAME'],
            $_ENV['VIAD_API_PASSWORD'],
            $_ENV['VIAD_API_COMPANY'],
            $_ENV['VIAD_API_GRANT_TYPE'],
            $_ENV['VIAD_API_SLUG']
        );
        $result = (new GroupAgentService($client))->updateGroupAgents($groupId, $agents);
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (\InvalidArgumentException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

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
            // Tri par priorité puis par label
            usort($agents, function ($a, $b) {
                $pDiff = ($a['priority'] ?? 99) - ($b['priority'] ?? 99);
                if ($pDiff !== 0) return $pDiff;
                return strcasecmp(
                    $a['viaAgentRefDTO']['label'] ?? '',
                    $b['viaAgentRefDTO']['label'] ?? ''
                );
            });
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
            flex-shrink: 0;
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
        .priority-input {
            width: 70px;
            flex-shrink: 0;
        }
        .add-agent-section {
            background: #f8f9fa;
            border: 1px dashed #adb5bd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
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
                                Agents: <span id="agentCount"><?= count($agents) ?></span>
                            </small>
                        </div>

                        <!-- Section agents -->
                        <div class="section-header d-flex align-items-center justify-content-between">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ol"></i> Agents par priorité
                            </h5>
                        </div>

                        <!-- Conteneur alertes dynamiques -->
                        <div id="alertContainer"></div>

                        <?php if (empty($agents)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-circle"></i> Aucun agent trouvé dans ce groupe.
                            </div>
                        <?php else: ?>
                            <!-- Boutons d'action -->
                            <div class="mb-3 d-flex gap-2 justify-content-end">
                                <button type="button" class="btn btn-outline-primary" id="copyBtn">
                                    <i class="bi bi-clipboard"></i> Copier pour email
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="editBtn">
                                    <i class="bi bi-pencil"></i> Modifier
                                </button>
                                <button type="button" class="btn btn-success d-none" id="saveBtn">
                                    <i class="bi bi-save"></i> Sauvegarder
                                </button>
                                <button type="button" class="btn btn-outline-danger d-none" id="cancelBtn">
                                    <i class="bi bi-x-circle"></i> Annuler
                                </button>
                            </div>

                            <!-- Liste des agents -->
                            <div class="border rounded" id="agentsList">
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

                            <!-- Section ajout agent (masquée par défaut) -->
                            <div class="add-agent-section d-none" id="addAgentSection">
                                <h6 class="mb-3"><i class="bi bi-person-plus"></i> Ajouter un agent</h6>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label small">ID agent <span class="text-muted">(viaAgentRefDTO.id)</span></label>
                                        <input type="number" class="form-control" id="addAgentId"
                                               placeholder="ex: 2006403" min="1">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">Nom</label>
                                        <input type="text" class="form-control" id="addAgentName"
                                               placeholder="ex: Luc-Tech">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Priorité</label>
                                        <input type="number" class="form-control" id="addPriority"
                                               value="1" min="1" max="6">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-primary w-100" id="addAgentBtn">
                                            <i class="bi bi-plus-circle"></i> Ajouter
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Données pour JS -->
                            <script>
                                window.groupId   = <?= json_encode((int)($groupData['id'] ?? 0)) ?>;
                                window.groupName = <?= json_encode($groupData['label'] ?? 'Groupe') ?>;
                                window.agentsList = <?= json_encode(array_map(fn($a) => [
                                    'id'       => $a['viaAgentRefDTO']['id'] ?? null,
                                    'name'     => $a['viaAgentRefDTO']['label'] ?? 'Agent inconnu',
                                    'priority' => $a['priority'] ?? 99,
                                ], $agents)) ?>;
                            </script>

                            <!-- Légende -->
                            <div class="mt-3 text-muted small">
                                <i class="bi bi-info-circle"></i> Légende des priorités :
                                <span class="badge priority-1 ms-2">1</span> Haute
                                <span class="badge priority-2 ms-2">2</span> Moyenne
                                <span class="badge priority-3 ms-2">3</span> Normale
                                <span class="badge priority-high ms-2">4-6</span> Basse
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
    <script src="js/Viad_Prio_group.js"></script>
</body>
</html>
