<?php

/**
 * Interface de test du SDK Scorimmo PHP — API v2
 * Permet de tester les appels API via scorimmo/scorimmo-php
 *
 * @see https://github.com/ScorimmoSAS/scorimmo-php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Scorimmo\Client\ScorimmoClient;
use Scorimmo\Exception\ScorimmoAuthException;
use Scorimmo\Exception\ScorimmoApiException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$email    = $_ENV['SCORIMMO_EMAIL'] ?? '';
$password = $_ENV['SCORIMMO_PASSWORD'] ?? '';
$baseUrl  = $_ENV['SCORIMMO_URL'] ?? 'https://pro.scorimmo.com';

$result = null;
$error  = null;

set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? $email);
    $password = trim($_POST['password'] ?? $password);
    $baseUrl  = trim($_POST['base_url'] ?? $baseUrl);
    $action   = $_POST['action'] ?? 'recent_leads';

    try {
        $client = new ScorimmoClient(
            email:    $email,
            password: $password,
            baseUrl:  $baseUrl,
        );

        switch ($action) {

            // ── Token ────────────────────────────────────────────────────────────
            case 'token':
                $token        = $client->getToken();
                $refreshToken = $client->getRefreshToken();
                $result = [
                    'action' => 'Token JWT',
                    'data'   => [
                        'access_token'  => $token,
                        'refresh_token' => $refreshToken,
                    ],
                ];
                break;

            case 'validate_token':
                $info   = $client->validateToken();
                $result = ['action' => 'Validation du token', 'data' => $info];
                break;

            // ── Leads ─────────────────────────────────────────────────────────────
            case 'recent_leads':
                $hours  = max(1, min(720, (int) ($_POST['hours'] ?? 24)));
                $leads  = $client->leads->since(new DateTime("-{$hours} hours"));
                $result = [
                    'action' => "Leads des dernières {$hours}h",
                    'count'  => count($leads),
                    'data'   => $leads,
                ];
                break;

            case 'list_leads':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $page   = max(1, (int) ($_POST['page'] ?? 1));
                $leads  = $client->leads->list(['limit' => $limit, 'page' => $page, 'sort' => 'created_at:desc']);
                $result = [
                    'action' => "Leads (page {$page}, limit {$limit})",
                    'meta'   => $leads['meta'] ?? null,
                    'data'   => $leads['data'] ?? $leads,
                ];
                break;

            case 'get_lead':
                $leadId  = (int) ($_POST['lead_id'] ?? 0);
                $include = array_filter(explode(',', $_POST['include'] ?? ''));
                if (!$leadId) {
                    throw new \InvalidArgumentException('ID du lead requis');
                }
                $lead   = $client->leads->get($leadId, $include);
                $result = ['action' => "Lead #{$leadId}" . ($include ? ' (include: ' . implode(', ', $include) . ')' : ''), 'data' => $lead];
                break;

            case 'update_lead':
                $leadId  = (int) ($_POST['lead_id'] ?? 0);
                $payload = json_decode($_POST['update_payload'] ?? '{}', true) ?? [];
                if (!$leadId) {
                    throw new \InvalidArgumentException('ID du lead requis');
                }
                if (empty($payload)) {
                    throw new \InvalidArgumentException('Payload JSON requis');
                }
                $updated = $client->leads->update($leadId, $payload);
                $result  = ['action' => "Update lead #{$leadId}", 'data' => $updated];
                break;

            // ── Référentiels ──────────────────────────────────────────────────────
            case 'list_stores':
                $stores = $client->stores->list(['limit' => 100]);
                $result = [
                    'action' => 'Points de vente accessibles',
                    'meta'   => $stores['meta'] ?? null,
                    'data'   => $stores['data'] ?? $stores,
                ];
                break;

            case 'list_users':
                $limit = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
                $users = $client->users->list(['limit' => $limit]);
                $result = [
                    'action' => "Utilisateurs (limit {$limit})",
                    'meta'   => $users['meta'] ?? null,
                    'data'   => $users['data'] ?? $users,
                ];
                break;

            case 'list_status':
                $statuses = $client->status->list(['limit' => 100]);
                $result   = [
                    'action' => 'Statuts disponibles',
                    'meta'   => $statuses['meta'] ?? null,
                    'data'   => $statuses['data'] ?? $statuses,
                ];
                break;

            // ── Rendez-vous / Commentaires / Rappels ─────────────────────────────
            case 'list_appointments':
                $limit = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $data  = $client->appointments->list(['limit' => $limit, 'sort' => 'created_at:desc']);
                $result = [
                    'action' => "Rendez-vous (limit {$limit})",
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'list_comments':
                $limit = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $data  = $client->comments->list(['limit' => $limit, 'sort' => 'created_at:desc']);
                $result = [
                    'action' => "Commentaires (limit {$limit})",
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;
        }
    } catch (ScorimmoAuthException $e) {
        $error = 'Authentification échouée : ' . $e->getMessage();
    } catch (ScorimmoApiException $e) {
        $error = 'Erreur API (' . $e->getStatusCode() . ') : ' . $e->getMessage();
    } catch (\Exception $e) {
        $error = get_class($e) . ' : ' . $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test SDK Scorimmo PHP — API v2</title>
    <style>
        body { font-family: monospace; max-width: 960px; margin: 40px auto; padding: 0 20px; background: #0f0f0f; color: #e0e0e0; }
        h1 { color: #4fc3f7; border-bottom: 1px solid #333; padding-bottom: 10px; }
        h2 { color: #81c784; margin-top: 30px; }
        .badge { display: inline-block; background: #1565c0; color: #fff; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 8px; vertical-align: middle; }
        form { background: #1a1a1a; padding: 20px; border-radius: 6px; border: 1px solid #333; }
        label { display: block; margin-bottom: 4px; color: #aaa; font-size: 12px; }
        input[type=text], input[type=email], input[type=password], input[type=number], select, textarea {
            width: 100%; padding: 8px; margin-bottom: 14px; background: #2a2a2a;
            border: 1px solid #444; border-radius: 4px; color: #e0e0e0; font-family: monospace;
            box-sizing: border-box;
        }
        textarea { resize: vertical; min-height: 60px; }
        button { background: #1565c0; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1976d2; }
        .error { background: #3e1a1a; border: 1px solid #c62828; padding: 14px; border-radius: 4px; color: #ef9a9a; margin-top: 20px; }
        .result { margin-top: 20px; }
        .result h2 { margin-bottom: 4px; }
        .meta { background: #1a2a1a; border: 1px solid #2e4a2e; padding: 10px 14px; border-radius: 4px; margin-bottom: 8px; color: #a5d6a7; font-size: 12px; }
        pre { background: #1a1a1a; padding: 16px; border-radius: 4px; border: 1px solid #333; overflow-x: auto; font-size: 12px; color: #b2dfdb; max-height: 600px; overflow-y: auto; }
        .action-fields { display: none; margin-top: -8px; margin-bottom: 14px; }
        .action-fields.active { display: block; }
        .row { display: flex; gap: 16px; }
        .row > div { flex: 1; }
        .group-label { color: #777; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 4px 8px; pointer-events: none; }
        optgroup { color: #aaa; }
    </style>
</head>
<body>

<h1>Test SDK Scorimmo PHP <span class="badge">API v2</span></h1>
<p style="color:#777">Teste les appels à l'API v2 via <code>scorimmo/scorimmo-php</code></p>

<form method="POST">
    <div class="row">
        <div>
            <label>Email (SCORIMMO_EMAIL)</label>
            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="user@scorimmo.com" required>
        </div>
        <div>
            <label>Mot de passe (SCORIMMO_PASSWORD)</label>
            <input type="password" name="password" value="<?= htmlspecialchars($password) ?>" placeholder="••••••••" required>
        </div>
    </div>

    <label>URL de base (SCORIMMO_URL)</label>
    <input type="text" name="base_url" value="<?= htmlspecialchars($baseUrl) ?>" placeholder="https://pp.scorimmo.com">

    <label>Action</label>
    <select name="action" id="action" onchange="toggleFields()">
        <optgroup label="── Auth">
            <option value="token">Obtenir le token JWT</option>
            <option value="validate_token">Valider le token (scopes, stores)</option>
        </optgroup>
        <optgroup label="── Leads">
            <option value="recent_leads" selected>Leads récents (since)</option>
            <option value="list_leads">Lister les leads (paginé)</option>
            <option value="get_lead">Récupérer un lead par ID</option>
            <option value="update_lead">Mettre à jour un lead</option>
        </optgroup>
        <optgroup label="── Référentiels">
            <option value="list_stores">Points de vente</option>
            <option value="list_users">Utilisateurs</option>
            <option value="list_status">Statuts disponibles</option>
        </optgroup>
        <optgroup label="── Activités">
            <option value="list_appointments">Rendez-vous</option>
            <option value="list_comments">Commentaires</option>
        </optgroup>
    </select>

    <!-- Leads récents -->
    <div id="field-recent_leads" class="action-fields">
        <label>Depuis combien d'heures</label>
        <input type="number" name="hours" value="<?= (int) ($_POST['hours'] ?? 24) ?>" min="1" max="720" placeholder="24">
    </div>

    <!-- Liste des leads -->
    <div id="field-list_leads" class="action-fields">
        <div class="row">
            <div>
                <label>Limit</label>
                <input type="number" name="limit" value="<?= (int) ($_POST['limit'] ?? 10) ?>" min="1" max="100">
            </div>
            <div>
                <label>Page</label>
                <input type="number" name="page" value="<?= (int) ($_POST['page'] ?? 1) ?>" min="1">
            </div>
        </div>
    </div>

    <!-- Get lead par ID -->
    <div id="field-get_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="lead_id" value="<?= (int) ($_POST['lead_id'] ?? '') ?>" placeholder="ex: 42">
        <label>Relations à inclure (include)</label>
        <input type="text" name="include" value="<?= htmlspecialchars($_POST['include'] ?? '') ?>" placeholder="customer, seller, appointments, comments, reminders, requests">
    </div>

    <!-- Update lead -->
    <div id="field-update_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="lead_id" value="<?= (int) ($_POST['lead_id'] ?? '') ?>" placeholder="ex: 42">
        <label>Payload JSON (champs à modifier)</label>
        <textarea name="update_payload" placeholder='{"external_lead_id": "CRM-123"}'><?= htmlspecialchars($_POST['update_payload'] ?? '') ?></textarea>
    </div>

    <!-- Actions avec limit générique -->
    <?php foreach (['list_users', 'list_appointments', 'list_comments'] as $action): ?>
    <div id="field-<?= $action ?>" class="action-fields">
        <label>Limit</label>
        <input type="number" name="limit" value="<?= (int) ($_POST['limit'] ?? 10) ?>" min="1" max="100">
    </div>
    <?php endforeach; ?>

    <button type="submit">Envoyer la requête</button>
</form>

<?php if ($error): ?>
    <div class="error"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="result">
        <h2><?= htmlspecialchars($result['action']) ?>
            <?php if (isset($result['count'])): ?>
                <span style="color:#777; font-size:14px">&nbsp;— <?= $result['count'] ?> résultat(s)</span>
            <?php endif; ?>
        </h2>

        <?php if (!empty($result['meta'])): ?>
            <div class="meta">
                <?php foreach ($result['meta'] as $k => $v): ?>
                    <strong><?= htmlspecialchars($k) ?></strong>: <?= htmlspecialchars((string) $v) ?> &nbsp;&nbsp;
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <pre><?= htmlspecialchars(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
<?php endif; ?>

<script>
function toggleFields() {
    const action = document.getElementById('action').value;
    document.querySelectorAll('.action-fields').forEach(el => el.classList.remove('active'));
    const target = document.getElementById('field-' + action);
    if (target) target.classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => {
    const action = document.getElementById('action');
    action.value = <?= json_encode($_POST['action'] ?? 'recent_leads') ?>;
    toggleFields();
});
</script>

</body>
</html>
