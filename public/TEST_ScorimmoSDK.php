<?php

/**
 * Interface de test du SDK Scorimmo PHP
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

$username = $_ENV['SCORIMMO_USERNAME'] ?? '';
$password = $_ENV['SCORIMMO_PASSWORD'] ?? '';

// Traitement du formulaire
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? $username);
    $password = trim($_POST['password'] ?? $password);
    $action   = $_POST['action'] ?? 'recent_leads';

    try {
        $client = new ScorimmoClient(username: $username, password: $password);

        switch ($action) {
            case 'recent_leads':
                $leads  = $client->leads->since(new DateTime('-24 hours'));
                $result = ['action' => 'Leads des dernières 24h', 'data' => $leads];
                break;

            case 'list_leads':
                $limit  = max(1, min(50, (int) ($_POST['limit'] ?? 10)));
                $leads  = $client->leads->list(['limit' => $limit]);
                $result = ['action' => "Liste des leads (limit $limit)", 'data' => $leads];
                break;

            case 'get_lead':
                $leadId = (int) ($_POST['lead_id'] ?? 0);
                if (!$leadId) {
                    throw new \InvalidArgumentException('ID du lead requis');
                }
                $lead   = $client->leads->get($leadId);
                $result = ['action' => "Lead #$leadId", 'data' => $lead];
                break;

            case 'token':
                $token  = $client->getToken();
                $result = ['action' => 'Token JWT', 'data' => ['token' => $token]];
                break;
        }
    } catch (ScorimmoAuthException $e) {
        $error = 'Authentification échouée : ' . $e->getMessage();
    } catch (ScorimmoApiException $e) {
        $error = 'Erreur API (' . $e->getCode() . ') : ' . $e->getMessage();
    } catch (\Exception $e) {
        $error = get_class($e) . ' : ' . $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test SDK Scorimmo PHP</title>
    <style>
        body { font-family: monospace; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #0f0f0f; color: #e0e0e0; }
        h1 { color: #4fc3f7; border-bottom: 1px solid #333; padding-bottom: 10px; }
        h2 { color: #81c784; margin-top: 30px; }
        form { background: #1a1a1a; padding: 20px; border-radius: 6px; border: 1px solid #333; }
        label { display: block; margin-bottom: 4px; color: #aaa; font-size: 12px; }
        input[type=text], input[type=password], input[type=number], select {
            width: 100%; padding: 8px; margin-bottom: 14px; background: #2a2a2a;
            border: 1px solid #444; border-radius: 4px; color: #e0e0e0; font-family: monospace;
            box-sizing: border-box;
        }
        button { background: #1565c0; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1976d2; }
        .error { background: #3e1a1a; border: 1px solid #c62828; padding: 14px; border-radius: 4px; color: #ef9a9a; margin-top: 20px; }
        .result { margin-top: 20px; }
        .result h2 { margin-bottom: 8px; }
        pre { background: #1a1a1a; padding: 16px; border-radius: 4px; border: 1px solid #333; overflow-x: auto; font-size: 12px; color: #b2dfdb; max-height: 500px; overflow-y: auto; }
        .action-fields { display: none; margin-top: -8px; margin-bottom: 14px; }
        .action-fields.active { display: block; }
        .row { display: flex; gap: 16px; }
        .row > div { flex: 1; }
    </style>
</head>
<body>

<h1>Test SDK Scorimmo PHP</h1>
<p style="color:#777">Teste les appels à l'API via <code>scorimmo/scorimmo-php</code></p>

<form method="POST">
    <div class="row">
        <div>
            <label>Identifiant (SCORIMMO_USERNAME)</label>
            <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="identifiant" required>
        </div>
        <div>
            <label>Mot de passe (SCORIMMO_PASSWORD)</label>
            <input type="password" name="password" value="<?= htmlspecialchars($password) ?>" placeholder="••••••••" required>
        </div>
    </div>

    <label>Action</label>
    <select name="action" id="action" onchange="toggleFields()">
        <option value="token">Obtenir le token JWT</option>
        <option value="recent_leads" selected>Leads des dernières 24h</option>
        <option value="list_leads">Lister les leads</option>
        <option value="get_lead">Récupérer un lead par ID</option>
    </select>

    <div id="field-list_leads" class="action-fields">
        <label>Nombre de leads (limit)</label>
        <input type="number" name="limit" value="<?= (int) ($_POST['limit'] ?? 10) ?>" min="1" max="50">
    </div>

    <div id="field-get_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="lead_id" value="<?= (int) ($_POST['lead_id'] ?? '') ?>" placeholder="ex: 42">
    </div>

    <button type="submit">Envoyer la requête</button>
</form>

<?php if ($error): ?>
    <div class="error"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="result">
        <h2><?= htmlspecialchars($result['action']) ?></h2>
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
// Init on load
document.addEventListener('DOMContentLoaded', () => {
    toggleFields();
    const action = document.getElementById('action');
    const selected = <?= json_encode($_POST['action'] ?? 'recent_leads') ?>;
    action.value = selected;
    toggleFields();
});
</script>

</body>
</html>
