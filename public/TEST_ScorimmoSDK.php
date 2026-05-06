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
ini_set('max_execution_time', '0');

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

            // ── Auth ──────────────────────────────────────────────────────────────
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

            case 'refresh_token':
                $refreshToken = trim($_POST['refresh_token_value'] ?? '');
                if (!$refreshToken) {
                    throw new \InvalidArgumentException('Refresh token requis');
                }
                $data   = $client->refreshAccessToken($refreshToken);
                $result = ['action' => 'Refresh token', 'data' => $data];
                break;

            case 'revoke_token':
                $refreshToken = trim($_POST['revoke_token_value'] ?? '');
                $data   = $client->revokeToken($refreshToken ?: null);
                $result = ['action' => 'Révocation du token', 'data' => $data];
                break;

            // ── Leads ─────────────────────────────────────────────────────────────
            case 'recent_leads':
                $hours    = max(1, min(720, (int) ($_POST['hours'] ?? 24)));
                $maxPages = max(1, min(100, (int) ($_POST['max_pages'] ?? 5)));
                $field    = in_array($_POST['date_field'] ?? '', ['updated_at']) ? 'updated_at' : 'created_at';
                $storeId  = (int) ($_POST['store_id_filter'] ?? 0) ?: null;
                $logs     = [];
                $leads    = $client->leads->since(
                    date: new DateTime("-{$hours} hours"),
                    field: $field,
                    maxPages: $maxPages,
                    storeId: $storeId,
                    onProgress: function (int $page, int $count, int $total, array $meta) use (&$logs, $maxPages): void {
                        $nextPage = $meta['next_page'] ?? null;
                        $logs[] = sprintf(
                            'Page %d/%d — %d lead(s) récupéré(s) | total cumulé : %d | next_page : %s',
                            $page,
                            $maxPages,
                            $count,
                            $total,
                            $nextPage !== null ? "#{$nextPage}" : 'aucune (fin)',
                        );
                    },
                );
                $result = [
                    'action' => "Leads des dernières {$hours}h (champ: {$field})" . ($storeId ? " [store #{$storeId}]" : ''),
                    'count'  => count($leads),
                    'logs'   => $logs,
                    'data'   => $leads,
                ];
                break;

            case 'list_leads':
                $limit   = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $page    = max(1, (int) ($_POST['page'] ?? 1));
                $storeId = (int) ($_POST['store_id_filter'] ?? 0) ?: null;
                $status  = trim($_POST['status_filter'] ?? '');
                $query   = array_filter([
                    'limit'    => $limit,
                    'page'     => $page,
                    'sort'     => 'created_at:desc',
                    'store_id' => $storeId,
                    'status'   => $status ?: null,
                ], fn($v) => $v !== null && $v !== '');
                $leads  = $client->leads->list($query);
                $result = [
                    'action' => "Leads (page {$page}, limit {$limit})",
                    'meta'   => $leads['meta'] ?? null,
                    'data'   => $leads['data'] ?? $leads,
                ];
                break;

            case 'get_lead':
                $leadId  = (int) ($_POST['resource_id'] ?? 0);
                $include = array_filter(explode(',', $_POST['include'] ?? ''));
                if (!$leadId) {
                    throw new \InvalidArgumentException('ID du lead requis');
                }
                $lead   = $client->leads->get($leadId, $include);
                $result = ['action' => "Lead #{$leadId}" . ($include ? ' (include: ' . implode(', ', $include) . ')' : ''), 'data' => $lead];
                break;

            case 'update_lead':
                $leadId  = (int) ($_POST['resource_id'] ?? 0);
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

            // ── Customers ─────────────────────────────────────────────────────────
            case 'list_customers':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $page   = max(1, (int) ($_POST['page'] ?? 1));
                $email_ = trim($_POST['customer_email'] ?? '');
                $phone  = trim($_POST['customer_phone'] ?? '');
                $query  = array_filter([
                    'limit' => $limit,
                    'page'  => $page,
                    'sort'  => 'created_at:desc',
                    'email' => $email_ ?: null,
                    'phone' => $phone ?: null,
                ], fn($v) => $v !== null);
                $data   = $client->customers->list($query);
                $result = [
                    'action' => "Clients (page {$page}, limit {$limit})",
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_customer':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du client requis');
                }
                $result = ['action' => "Client #{$id}", 'data' => $client->customers->get($id)];
                break;

            // ── Requests (biens) ──────────────────────────────────────────────────
            case 'list_requests':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $page   = max(1, (int) ($_POST['page'] ?? 1));
                $leadId = (int) ($_POST['lead_id_filter'] ?? 0) ?: null;
                $query  = array_filter([
                    'limit'   => $limit,
                    'page'    => $page,
                    'sort'    => 'created_at:desc',
                    'lead_id' => $leadId,
                ], fn($v) => $v !== null);
                $data   = $client->requests->list($query);
                $result = [
                    'action' => "Biens (page {$page}, limit {$limit})" . ($leadId ? " [lead #{$leadId}]" : ''),
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_request':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du bien requis');
                }
                $result = ['action' => "Bien #{$id}", 'data' => $client->requests->get($id)];
                break;

            // ── Appointments ──────────────────────────────────────────────────────
            case 'list_appointments':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $leadId = (int) ($_POST['lead_id_filter'] ?? 0) ?: null;
                $query  = array_filter([
                    'limit'   => $limit,
                    'sort'    => 'created_at:desc',
                    'lead_id' => $leadId,
                ], fn($v) => $v !== null);
                $data   = $client->appointments->list($query);
                $result = [
                    'action' => "Rendez-vous (limit {$limit})" . ($leadId ? " [lead #{$leadId}]" : ''),
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_appointment':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du RDV requis');
                }
                $result = ['action' => "Rendez-vous #{$id}", 'data' => $client->appointments->get($id)];
                break;

            // ── Comments ──────────────────────────────────────────────────────────
            case 'list_comments':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $leadId = (int) ($_POST['lead_id_filter'] ?? 0) ?: null;
                $query  = array_filter([
                    'limit'   => $limit,
                    'sort'    => 'created_at:desc',
                    'lead_id' => $leadId,
                ], fn($v) => $v !== null);
                $data   = $client->comments->list($query);
                $result = [
                    'action' => "Commentaires (limit {$limit})" . ($leadId ? " [lead #{$leadId}]" : ''),
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_comment':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du commentaire requis');
                }
                $result = ['action' => "Commentaire #{$id}", 'data' => $client->comments->get($id)];
                break;

            // ── Reminders ─────────────────────────────────────────────────────────
            case 'list_reminders':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 10)));
                $leadId = (int) ($_POST['lead_id_filter'] ?? 0) ?: null;
                $query  = array_filter([
                    'limit'   => $limit,
                    'sort'    => 'created_at:desc',
                    'lead_id' => $leadId,
                ], fn($v) => $v !== null);
                $data   = $client->reminders->list($query);
                $result = [
                    'action' => "Rappels (limit {$limit})" . ($leadId ? " [lead #{$leadId}]" : ''),
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_reminder':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du rappel requis');
                }
                $result = ['action' => "Rappel #{$id}", 'data' => $client->reminders->get($id)];
                break;

            // ── Référentiels ──────────────────────────────────────────────────────
            case 'list_stores':
                $data   = $client->stores->list(['limit' => 100]);
                $result = [
                    'action' => 'Points de vente accessibles',
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_store':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID du point de vente requis');
                }
                $result = ['action' => "Point de vente #{$id}", 'data' => $client->stores->get($id)];
                break;

            case 'list_users':
                $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
                $data   = $client->users->list(['limit' => $limit]);
                $result = [
                    'action' => "Utilisateurs (limit {$limit})",
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'get_user':
                $id = (int) ($_POST['resource_id'] ?? 0);
                if (!$id) {
                    throw new \InvalidArgumentException('ID de l\'utilisateur requis');
                }
                $result = ['action' => "Utilisateur #{$id}", 'data' => $client->users->get($id)];
                break;

            case 'list_status':
                $data   = $client->status->list(['limit' => 100]);
                $result = [
                    'action' => 'Statuts disponibles',
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'list_origins':
                $storeId         = (int) ($_POST['store_id_filter'] ?? 0) ?: null;
                $trackingChannel = trim($_POST['tracking_channel'] ?? '');
                $include         = trim($_POST['origins_include'] ?? '');
                $query           = array_filter([
                    'limit'            => 100,
                    'store_id'         => $storeId,
                    'tracking_channel' => $trackingChannel ?: null,
                    'include'          => $include ?: null,
                ], fn($v) => $v !== null);
                $data   = $client->origins->list($query);
                $result = [
                    'action' => 'Origines',
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'list_additional_fields':
                $storeId  = (int) ($_POST['store_id_filter'] ?? 0) ?: null;
                $interest = trim($_POST['interest_filter'] ?? '');
                $query    = array_filter([
                    'store_id' => $storeId,
                    'interest' => $interest ?: null,
                ], fn($v) => $v !== null);
                $data   = $client->additionalFields->list($query);
                $result = [
                    'action' => 'Champs additionnels',
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;

            case 'list_request_fields':
                $storeId  = (int) ($_POST['store_id_filter'] ?? 0) ?: null;
                $interest = trim($_POST['interest_filter'] ?? '');
                $query    = array_filter([
                    'store_id' => $storeId,
                    'interest' => $interest ?: null,
                ], fn($v) => $v !== null);
                $data   = $client->requestFields->list($query);
                $result = [
                    'action' => 'Champs de demande',
                    'meta'   => $data['meta'] ?? null,
                    'data'   => $data['data'] ?? $data,
                ];
                break;
        }
    } catch (ScorimmoAuthException $e) {
        $error = 'Authentification échouée : ' . $e->getMessage();
    } catch (ScorimmoApiException $e) {
        $error = 'Erreur API (' . $e->statusCode . ') : ' . $e->getMessage();
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
    <input type="text" name="base_url" value="<?= htmlspecialchars($baseUrl) ?>" placeholder="https://pro.scorimmo.com">

    <label>Action</label>
    <select name="action" id="action" onchange="toggleFields()">
        <optgroup label="── Auth">
            <option value="token">Obtenir le token JWT</option>
            <option value="validate_token">Valider le token (scopes, stores)</option>
            <option value="refresh_token">Rafraîchir le token</option>
            <option value="revoke_token">Révoquer le(s) token(s)</option>
        </optgroup>
        <optgroup label="── Leads">
            <option value="recent_leads" selected>Leads récents (since)</option>
            <option value="list_leads">Lister les leads (paginé)</option>
            <option value="get_lead">Récupérer un lead par ID</option>
            <option value="update_lead">Mettre à jour un lead</option>
        </optgroup>
        <optgroup label="── Clients">
            <option value="list_customers">Lister les clients</option>
            <option value="get_customer">Récupérer un client par ID</option>
        </optgroup>
        <optgroup label="── Biens (Requests)">
            <option value="list_requests">Lister les biens</option>
            <option value="get_request">Récupérer un bien par ID</option>
        </optgroup>
        <optgroup label="── Activités">
            <option value="list_appointments">Rendez-vous (liste)</option>
            <option value="get_appointment">Récupérer un RDV par ID</option>
            <option value="list_comments">Commentaires (liste)</option>
            <option value="get_comment">Récupérer un commentaire par ID</option>
            <option value="list_reminders">Rappels (liste)</option>
            <option value="get_reminder">Récupérer un rappel par ID</option>
        </optgroup>
        <optgroup label="── Référentiels">
            <option value="list_stores">Points de vente (liste)</option>
            <option value="get_store">Récupérer un point de vente par ID</option>
            <option value="list_users">Utilisateurs (liste)</option>
            <option value="get_user">Récupérer un utilisateur par ID</option>
            <option value="list_status">Statuts disponibles</option>
            <option value="list_origins">Origines</option>
            <option value="list_additional_fields">Champs additionnels</option>
            <option value="list_request_fields">Champs de demande</option>
        </optgroup>
    </select>

    <!-- Auth : refresh -->
    <div id="field-refresh_token" class="action-fields">
        <label>Refresh token</label>
        <input type="text" name="refresh_token_value" value="<?= htmlspecialchars($_POST['refresh_token_value'] ?? '') ?>" placeholder="eyJ...">
    </div>

    <!-- Auth : revoke -->
    <div id="field-revoke_token" class="action-fields">
        <label>Refresh token à révoquer (laisser vide pour révoquer tous les tokens)</label>
        <input type="text" name="revoke_token_value" value="<?= htmlspecialchars($_POST['revoke_token_value'] ?? '') ?>" placeholder="eyJ… ou vide = revoke_all">
    </div>

    <!-- Leads récents -->
    <div id="field-recent_leads" class="action-fields">
        <div class="row">
            <div>
                <label>Depuis combien d'heures</label>
                <input type="number" name="hours" value="<?= (int) ($_POST['hours'] ?? 24) ?>" min="1" max="720">
            </div>
            <div>
                <label>Max pages (100 leads/page)</label>
                <input type="number" name="max_pages" value="<?= (int) ($_POST['max_pages'] ?? 5) ?>" min="1" max="100">
            </div>
        </div>
        <div class="row">
            <div>
                <label>Champ de date</label>
                <select name="date_field">
                    <option value="created_at" <?= (($_POST['date_field'] ?? '') === 'updated_at') ? '' : 'selected' ?>>created_at</option>
                    <option value="updated_at" <?= (($_POST['date_field'] ?? '') === 'updated_at') ? 'selected' : '' ?>>updated_at</option>
                </select>
            </div>
            <div>
                <label>Store ID (optionnel)</label>
                <input type="number" name="store_id_filter" value="<?= (int) ($_POST['store_id_filter'] ?? 0) ?: '' ?>" placeholder="tous">
            </div>
        </div>
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
        <div class="row">
            <div>
                <label>Store ID (optionnel)</label>
                <input type="number" name="store_id_filter" value="<?= (int) ($_POST['store_id_filter'] ?? 0) ?: '' ?>" placeholder="tous">
            </div>
            <div>
                <label>Statut (optionnel)</label>
                <input type="text" name="status_filter" value="<?= htmlspecialchars($_POST['status_filter'] ?? '') ?>" placeholder="ex: Succès">
            </div>
        </div>
    </div>

    <!-- Get lead par ID -->
    <div id="field-get_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="resource_id" value="<?= (int) ($_POST['resource_id'] ?? '') ?: '' ?>" placeholder="ex: 42">
        <label>Relations à inclure (include)</label>
        <input type="text" name="include" value="<?= htmlspecialchars($_POST['include'] ?? '') ?>" placeholder="customer, seller, appointments, comments, reminders, requests">
    </div>

    <!-- Update lead -->
    <div id="field-update_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="resource_id" value="<?= (int) ($_POST['resource_id'] ?? '') ?: '' ?>" placeholder="ex: 42">
        <label>Payload JSON (champs à modifier)</label>
        <textarea name="update_payload" placeholder='{"external_lead_id": "CRM-123"}'><?= htmlspecialchars($_POST['update_payload'] ?? '') ?></textarea>
    </div>

    <!-- Lister les clients -->
    <div id="field-list_customers" class="action-fields">
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
        <div class="row">
            <div>
                <label>Email (optionnel)</label>
                <input type="text" name="customer_email" value="<?= htmlspecialchars($_POST['customer_email'] ?? '') ?>" placeholder="filter par email">
            </div>
            <div>
                <label>Téléphone (optionnel)</label>
                <input type="text" name="customer_phone" value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>" placeholder="filter par téléphone">
            </div>
        </div>
    </div>

    <!-- Lister les biens -->
    <div id="field-list_requests" class="action-fields">
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
        <label>Lead ID (optionnel)</label>
        <input type="number" name="lead_id_filter" value="<?= (int) ($_POST['lead_id_filter'] ?? 0) ?: '' ?>" placeholder="tous les leads">
    </div>

    <!-- Activités avec lead_id optionnel (appointments, comments, reminders) -->
    <?php foreach (['list_appointments', 'list_comments', 'list_reminders'] as $act): ?>
    <div id="field-<?= $act ?>" class="action-fields">
        <div class="row">
            <div>
                <label>Limit</label>
                <input type="number" name="limit" value="<?= (int) ($_POST['limit'] ?? 10) ?>" min="1" max="100">
            </div>
            <div>
                <label>Lead ID (optionnel)</label>
                <input type="number" name="lead_id_filter" value="<?= (int) ($_POST['lead_id_filter'] ?? 0) ?: '' ?>" placeholder="tous les leads">
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Get par ID générique (customer, request, appointment, comment, reminder, store, user) -->
    <?php foreach (['get_customer', 'get_request', 'get_appointment', 'get_comment', 'get_reminder', 'get_store', 'get_user'] as $act): ?>
    <div id="field-<?= $act ?>" class="action-fields">
        <label>ID</label>
        <input type="number" name="resource_id" value="<?= (int) ($_POST['resource_id'] ?? '') ?: '' ?>" placeholder="ex: 42">
    </div>
    <?php endforeach; ?>

    <!-- Utilisateurs avec limit -->
    <div id="field-list_users" class="action-fields">
        <label>Limit</label>
        <input type="number" name="limit" value="<?= (int) ($_POST['limit'] ?? 20) ?>" min="1" max="100">
    </div>

    <!-- Origines -->
    <div id="field-list_origins" class="action-fields">
        <div class="row">
            <div>
                <label>Store ID (optionnel)</label>
                <input type="number" name="store_id_filter" value="<?= (int) ($_POST['store_id_filter'] ?? 0) ?: '' ?>" placeholder="tous">
            </div>
            <div>
                <label>Canal de tracking (optionnel)</label>
                <select name="tracking_channel">
                    <option value="">tous</option>
                    <option value="phone" <?= (($_POST['tracking_channel'] ?? '') === 'phone') ? 'selected' : '' ?>>phone</option>
                    <option value="email" <?= (($_POST['tracking_channel'] ?? '') === 'email') ? 'selected' : '' ?>>email</option>
                </select>
            </div>
        </div>
        <label>Include (optionnel)</label>
        <input type="text" name="origins_include" value="<?= htmlspecialchars($_POST['origins_include'] ?? '') ?>" placeholder="tracking">
    </div>

    <!-- Champs additionnels / champs de demande -->
    <?php foreach (['list_additional_fields', 'list_request_fields'] as $act): ?>
    <div id="field-<?= $act ?>" class="action-fields">
        <div class="row">
            <div>
                <label>Store ID (optionnel)</label>
                <input type="number" name="store_id_filter" value="<?= (int) ($_POST['store_id_filter'] ?? 0) ?: '' ?>" placeholder="tous">
            </div>
            <div>
                <label>Intérêt (optionnel)</label>
                <input type="text" name="interest_filter" value="<?= htmlspecialchars($_POST['interest_filter'] ?? '') ?>" placeholder="ex: Location">
            </div>
        </div>
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

        <?php if (!empty($result['logs'])): ?>
            <div style="margin-bottom:8px">
                <strong style="color:#ffd54f;font-size:12px">Pagination log</strong>
                <pre style="background:#1a1500;border-color:#5c4a00;color:#ffd54f;max-height:200px"><?php
                    foreach ($result['logs'] as $line) {
                        echo htmlspecialchars($line) . "\n";
                    }
                ?></pre>
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
// Désactive les champs des sections cachées avant soumission pour éviter
// qu'ils n'écrasent les valeurs des champs visibles (même name= sur plusieurs sections).
document.querySelector('form').addEventListener('submit', function () {
    document.querySelectorAll('.action-fields:not(.active) input, .action-fields:not(.active) select, .action-fields:not(.active) textarea')
        .forEach(el => el.disabled = true);
});
</script>

</body>
</html>
