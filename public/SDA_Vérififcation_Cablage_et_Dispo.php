<?php

/**
 * Vérification des SDA entre Viadialog et Scorimmo
 *
 * Ce script compare les SDA enregistrées dans la base de données Scorimmo
 * avec celles présentes dans l'API Viadialog.
 *
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;
use Database\DatabaseConnection;

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Variables pour stocker les données
$sdas_viadialog = [];
$sdas_mysql = [];
$sdas_manquantes_viadialog = [];
$sdas_manquantes_mysql = [];
$disponibilite_par_indicatif = [];
$error_message = null;

// Correspondance indicatifs téléphoniques français
$indicatifs_regions = [
    '01' => 'Île-de-France',
    '02' => 'Nord-Ouest',
    '03' => 'Nord-Est',
    '04' => 'Sud-Est',
    '05' => 'Sud-Ouest',
    '06' => 'Mobile',
    '07' => 'Mobile',
    '08' => 'Numéros spéciaux',
    '09' => 'VoIP/Box'
];

// Seuils d'alerte
const SEUIL_CRITIQUE = 20;
const SEUIL_ALERTE = 30;

// Services Viadialog à exclure de la comparaison (SDA jamais présentes dans Scorimmo)
$services_exclus = [
    5006728, // Service exclu - SDA non gérées dans Scorimmo
];

/**
 * Normalise un numéro de téléphone au format national (0xxxxxxxxx)
 */
function normaliserNumero(string $numero): string
{
    $numero = trim($numero);

    // Déjà en format national
    if (str_starts_with($numero, '0') && strlen($numero) == 10) {
        return $numero;
    }

    // Conversion international vers national
    if (str_starts_with($numero, '+33') && strlen($numero) == 12) {
        return '0' . substr($numero, 3);
    }

    return $numero;
}

/**
 * Convertit un numéro national vers le format international (+33)
 */
function versFormatInternational(string $numero): string
{
    $numero = trim($numero);

    if (str_starts_with($numero, '0') && strlen($numero) == 10) {
        return '+33' . substr($numero, 1);
    }

    return $numero;
}

/**
 * Extrait l'indicatif d'un numéro français
 */
function extraireIndicatif(string $numero): string
{
    $numero = trim($numero);

    if (str_starts_with($numero, '+33') && strlen($numero) >= 5) {
        return '0' . $numero[3];
    }

    if (str_starts_with($numero, '0') && strlen($numero) >= 3) {
        return substr($numero, 0, 2);
    }

    return '';
}

/**
 * Vérifie si un numéro est au format français valide
 */
function estNumeroValide(string $numero): bool
{
    if (empty($numero)) {
        return false;
    }

    $numero = trim($numero);

    // Format national français (0xxxxxxxxx)
    if (preg_match('/^0[1-9]\d{8}$/', $numero)) {
        return true;
    }

    // Format international français (+33xxxxxxxxx)
    if (preg_match('/^\+33[1-9]\d{8}$/', $numero)) {
        return true;
    }

    return false;
}

/**
 * Détermine la classe CSS selon le nombre de SDA disponibles
 */
function getClasseSeuil(int $count): string
{
    if ($count < SEUIL_CRITIQUE) {
        return 'danger';
    } elseif ($count < SEUIL_ALERTE) {
        return 'warning';
    }
    return 'success';
}

try {
    // Vérification des variables d'environnement
    $requiredEnvVars = [
        'VIAD_API_USERNAME',
        'VIAD_API_PASSWORD',
        'VIAD_API_COMPANY',
        'VIAD_API_GRANT_TYPE',
        'VIAD_API_SLUG',
    ];
    foreach ($requiredEnvVars as $var) {
        if (!isset($_ENV[$var])) {
            throw new Exception("Variable d'environnement manquante : $var");
        }
    }

    // Connexion à l'API Viadialog
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    // Récupération paginée des SDA depuis l'API (toutes les SDA DIRECT)
    $page = 0;
    $pageSize = 500;
    $sdas_api_all = [];

    do {
        $batch = $client->getSdaListRaw(['eq,sdaUsage,DIRECT'], $pageSize, $page);
        $sdas_api_all = array_merge($sdas_api_all, $batch);
        $page++;
    } while (count($batch) === $pageSize);

    // Conversion en dictionnaire et analyse de disponibilité
    foreach ($sdas_api_all as $sda) {
        $numero = $sda['sdaNumber'] ?? '';

        // Analyse de disponibilité par indicatif
        if (estNumeroValide($numero)) {
            $indicatif = extraireIndicatif($numero);
            if (!empty($indicatif)) {
                if (!isset($disponibilite_par_indicatif[$indicatif])) {
                    $disponibilite_par_indicatif[$indicatif] = [
                        'disponibles' => 0,
                        'total' => 0,
                        'exemples' => []
                    ];
                }
                $disponibilite_par_indicatif[$indicatif]['total']++;

                if (empty($sda['viaServiceRefDTO'])) {
                    $disponibilite_par_indicatif[$indicatif]['disponibles']++;
                    if (count($disponibilite_par_indicatif[$indicatif]['exemples']) < 3) {
                        $disponibilite_par_indicatif[$indicatif]['exemples'][] = $numero;
                    }
                }
            }
        }

        // Ajouter seulement les SDA avec un service assigné pour la comparaison
        if (!empty($sda['viaServiceRefDTO'])) {
            $sdas_viadialog[$numero] = [
                'id' => $sda['id'] ?? null,
                'numero' => $numero,
                'service_id' => $sda['viaServiceRefDTO']['id'] ?? null,
                'service_label' => $sda['viaServiceRefDTO']['label'] ?? null,
                'has_service' => true
            ];
        }
    }

    // Connexion à la base de données MySQL
    $db = DatabaseConnection::getInstance();

    // Requête pour récupérer les SDA depuis Scorimmo
    $query = "
        SELECT
            sda.sda_number as telephone,
            serv.pos_id as PDV,
            pos.name as nom_pdv,
            serv.id_viad as id_service_viadialog
        FROM si_viad_sda sda
        INNER JOIN si_viad_service serv ON sda.viad_service_id = serv.id
        INNER JOIN si_pos pos ON serv.pos_id = pos.id
        WHERE sda.deleted_at IS NULL AND sda.enable = 1
    ";

    $results = $db->fetchAll($query);

    foreach ($results as $row) {
        $telephone = trim($row['telephone']);
        if (estNumeroValide($telephone)) {
            $telephone_normalise = normaliserNumero($telephone);
            $sdas_mysql[$telephone_normalise] = [
                'telephone' => $telephone,
                'pdv' => $row['PDV'],
                'nom_pdv' => $row['nom_pdv'],
                'id_service' => $row['id_service_viadialog']
            ];
        }
    }

    // Comparaison : SDA dans MySQL mais pas dans Viadialog
    foreach ($sdas_mysql as $numero_national => $info) {
        $numero_api = versFormatInternational($numero_national);
        if (!isset($sdas_viadialog[$numero_api])) {
            $sdas_manquantes_viadialog[] = [
                'numero' => $numero_api,
                'nom_pdv' => $info['nom_pdv'],
                'pdv' => $info['pdv'],
                'id_service' => $info['id_service']
            ];
        }
    }

    // Comparaison : SDA avec service dans Viadialog mais pas dans MySQL
    foreach ($sdas_viadialog as $numero_api => $info) {
        if ($info['has_service']) {
            // Exclure les services de la liste d'exceptions
            if (in_array($info['service_id'], $services_exclus)) {
                continue;
            }
            $numero_national = normaliserNumero($numero_api);
            if (!isset($sdas_mysql[$numero_national])) {
                $sdas_manquantes_mysql[] = [
                    'numero' => $numero_api,
                    'service_label' => $info['service_label'],
                    'service_id' => $info['service_id']
                ];
            }
        }
    }
} catch (ApiException $e) {
    $error_message = 'Erreur API Viadialog : ' . $e->getMessage();
} catch (Exception $e) {
    $error_message = 'Erreur : ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification SDA - Viadialog / Scorimmo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>

<body>
    <div class="container-fluid py-4">
        <h1 class="mb-4">Vérification SDA - Viadialog / Scorimmo</h1>

        <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php else: ?>

            <!-- Statistiques globales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-bg-primary">
                        <div class="card-body">
                            <h5 class="card-title">SDA Viadialog</h5>
                            <p class="card-text fs-2"><?= count($sdas_viadialog) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-info">
                        <div class="card-body">
                            <h5 class="card-title">SDA Scorimmo</h5>
                            <p class="card-text fs-2"><?= count($sdas_mysql) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-<?= count($sdas_manquantes_viadialog) > 0 ? 'danger' : 'success' ?>">
                        <div class="card-body">
                            <h5 class="card-title">Manquantes Viadialog</h5>
                            <p class="card-text fs-2"><?= count($sdas_manquantes_viadialog) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-<?= count($sdas_manquantes_mysql) > 0 ? 'warning' : 'success' ?>">
                        <div class="card-body">
                            <h5 class="card-title">Manquantes Scorimmo</h5>
                            <p class="card-text fs-2"><?= count($sdas_manquantes_mysql) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Disponibilité par indicatif -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Disponibilité SDA par indicatif</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Indicatif</th>
                                <th>Région</th>
                                <th>Disponibles</th>
                                <th>Total</th>
                                <th>État</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php ksort($disponibilite_par_indicatif); ?>
                            <?php foreach ($disponibilite_par_indicatif as $indicatif => $data): ?>
                                <?php $classe = getClasseSeuil($data['disponibles']); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($indicatif) ?></strong></td>
                                    <td><?= htmlspecialchars($indicatifs_regions[$indicatif] ?? 'Inconnu') ?></td>
                                    <td><span class="badge bg-<?= $classe ?>"><?= $data['disponibles'] ?></span></td>
                                    <td><?= $data['total'] ?></td>
                                    <td>
                                        <?php if ($data['disponibles'] >= SEUIL_ALERTE): ?>
                                            <span class="text-success">OK</span>
                                        <?php elseif ($data['disponibles'] >= SEUIL_CRITIQUE): ?>
                                            <span class="text-warning">Attention</span>
                                        <?php else: ?>
                                            <span class="text-danger">Critique</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SDA manquantes dans Viadialog -->
            <?php if (count($sdas_manquantes_viadialog) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">SDA manquantes dans Viadialog (<?= count($sdas_manquantes_viadialog) ?>)</h5>
                        <small>Ces SDA sont dans Scorimmo mais pas trouvées dans Viadialog avec un service actif</small>
                    </div>
                    <div class="card-body">
                        <table id="tableManquantesViadialog" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>N° SDA</th>
                                    <th>PDV</th>
                                    <th>Nom PDV</th>
                                    <th>ID Service</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sdas_manquantes_viadialog as $sda): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($sda['numero']) ?></code></td>
                                        <td><?= htmlspecialchars($sda['pdv']) ?></td>
                                        <td><?= htmlspecialchars($sda['nom_pdv']) ?></td>
                                        <td><?= htmlspecialchars($sda['id_service'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SDA manquantes dans Scorimmo -->
            <?php if (count($sdas_manquantes_mysql) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">SDA manquantes dans Scorimmo (<?= count($sdas_manquantes_mysql) ?>)</h5>
                        <small>Ces SDA ont un service dans Viadialog mais ne sont pas dans Scorimmo</small>
                    </div>
                    <div class="card-body">
                        <table id="tableManquantesScor" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>N° SDA</th>
                                    <th>Service Viadialog</th>
                                    <th>ID Service</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sdas_manquantes_mysql as $sda): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($sda['numero']) ?></code></td>
                                        <td><?= htmlspecialchars($sda['service_label'] ?? 'Inconnu') ?></td>
                                        <td><?= htmlspecialchars($sda['service_id'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Message si tout est synchronisé -->
            <?php if (count($sdas_manquantes_viadialog) == 0 && count($sdas_manquantes_mysql) == 0): ?>
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading">Synchronisation OK</h4>
                    <p>Aucune différence détectée entre Viadialog et Scorimmo.</p>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#tableManquantesViadialog').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json'
                },
                pageLength: 25
            });
            $('#tableManquantesScor').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json'
                },
                pageLength: 25
            });
        });
    </script>
</body>

</html>