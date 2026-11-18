<?php

declare(strict_types=1);

/**
 * Rejeu d'emails reçus (base via tunnel SSH) sur le webhook Oxeva `/email/inbound` d'une instance
 * cible, signés HMAC, ou par SMTP (swaks) au MTA Oxeva pour tester la chaîne de bout en bout.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Email\InboundEmailReplayer;
use App\Email\InboundSmtpSender;
use Database\DatabaseConnection;
use Dotenv\Dotenv;

$error = null;
$emailTypes = [];
$origins = [];
$targetBaseUrl = '';
$secretConfigured = false;
$smtpTarget = '';
$recipientDomain = '';

try {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    $secretConfigured = ($_ENV['INBOUND_TARGET_SECRET'] ?? '') !== '';
    if (($_ENV['INBOUND_TARGET_URL'] ?? '') === '') {
        throw new RuntimeException('INBOUND_TARGET_URL non renseigné dans le .env');
    }
    $replayer = new InboundEmailReplayer(
        DatabaseConnection::getInstance(),
        $_ENV['INBOUND_TARGET_URL'],
        $_ENV['INBOUND_TARGET_SECRET'] ?? '',
        trim($_ENV['INBOUND_RECIPIENT_DOMAIN'] ?? ''),
        InboundSmtpSender::fromEnv($_ENV),
    );
    $emailTypes = $replayer->getEmailTypes();
    $origins = $replayer->getRecentOrigins();
    $targetBaseUrl = $replayer->getTargetBaseUrl();
    $smtpTarget = $replayer->getSmtpTarget();
    $recipientDomain = $replayer->getRecipientDomain();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$isProdTarget = str_contains($targetBaseUrl, 'pro.scorimmo.com');
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejeu d'emails - Scorimmo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/inbound_replay.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-title"><i class="fas fa-envelope-open-text"></i> Rejeu d'emails entrants</div>
    <div class="topbar-target <?= $isProdTarget ? 'is-prod' : '' ?>" title="Webhook cible">
        <i class="fas fa-bullseye"></i> <?= $e($targetBaseUrl ?: 'cible non configurée') ?>/email/inbound
    </div>
</header>

<main class="page">
    <?php if ($error !== null): ?>
        <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= $e($error) ?></div>
    <?php endif; ?>
    <?php if (!$secretConfigured): ?>
        <div class="alert alert-warning"><i class="fas fa-key"></i> INBOUND_TARGET_SECRET absent du .env : la recherche fonctionne, pas l'envoi webhook.</div>
    <?php endif; ?>

    <section class="block">
        <div class="tabs">
            <button type="button" class="tab active" data-mode="filters"><i class="fas fa-filter"></i> Filtres</button>
            <button type="button" class="tab" data-mode="ids"><i class="fas fa-hashtag"></i> Par ID</button>
        </div>

        <form id="searchForm" autocomplete="off">
            <div class="mode" data-mode="filters">
                <div class="field-grid">
                    <label class="field">
                        <span>Source</span>
                        <select name="type">
                            <option value="">Toutes</option>
                            <?php foreach ($emailTypes as $key => $label): ?>
                                <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Origine (parsée)</span>
                        <input type="text" name="origin" list="origins" placeholder="ex : Leboncoin">
                        <datalist id="origins">
                            <?php foreach ($origins as $origin): ?>
                                <option value="<?= $e($origin) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </label>
                    <label class="field">
                        <span>Expéditeur contient</span>
                        <input type="text" name="sender" placeholder="ex : seloger.com">
                    </label>
                    <label class="field">
                        <span>Destinataire contient</span>
                        <input type="text" name="recipient" placeholder="ex : traceur@mail.scorimmo.com">
                    </label>
                    <label class="field">
                        <span>Objet contient</span>
                        <input type="text" name="subject">
                    </label>
                    <label class="field">
                        <span>Contenu contient</span>
                        <input type="text" name="content" placeholder="HTML ou texte">
                    </label>
                    <label class="field">
                        <span>Statut</span>
                        <select name="status">
                            <option value="">Tous</option>
                            <option value="new">Nouveau</option>
                            <option value="current">En cours</option>
                            <option value="treated">Traité</option>
                            <option value="wait_ai">Attente IA</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Sous-statut</span>
                        <select name="substatus">
                            <option value="">Tous</option>
                            <option value="lead_created">Lead créé</option>
                            <option value="replied">Répondu</option>
                            <option value="forwarded">Transféré</option>
                            <option value="deleted">Supprimé</option>
                            <option value="deleted_auto">Supprimé auto (doublon)</option>
                            <option value="none">Aucun</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Période (jours)</span>
                        <input type="number" name="days" min="1" max="365" value="20">
                    </label>
                    <label class="field">
                        <span>Quantité</span>
                        <input type="number" name="quantity" min="1" max="<?= InboundEmailReplayer::MAX_EMAILS ?>" value="5">
                    </label>
                    <label class="field">
                        <span>Tri</span>
                        <select name="order">
                            <option value="recent">Plus récents</option>
                            <option value="oldest">Plus anciens</option>
                            <option value="random">Aléatoire</option>
                        </select>
                    </label>
                    <label class="checkbox">
                        <input type="checkbox" name="exclude_duplicates" checked> Exclure les doublons
                    </label>
                </div>
            </div>

            <div class="mode hidden" data-mode="ids">
                <label class="field">
                    <span>IDs d'emails (séparés par virgule, espace ou retour à la ligne, <?= InboundEmailReplayer::MAX_EMAILS ?> max)</span>
                    <textarea name="ids" rows="3" placeholder="123456, 123457"></textarea>
                </label>
            </div>

            <div class="actions">
                <button type="submit" class="button-default"><i class="fas fa-magnifying-glass"></i> Rechercher</button>
            </div>
        </form>
    </section>

    <section class="block">
        <h2 class="titled-separator"><span>Options d'envoi</span></h2>
        <div class="field-grid" id="sendOptions">
            <label class="field">
                <span>Transport</span>
                <select name="transport">
                    <option value="<?= InboundEmailReplayer::TRANSPORT_WEBHOOK ?>">Webhook direct (<?= $e($targetBaseUrl ?: 'non configuré') ?>)</option>
                    <option value="<?= InboundEmailReplayer::TRANSPORT_SMTP ?>" <?= $smtpTarget === '' ? 'disabled' : '' ?>>SMTP via MTA Oxeva (<?= $e($smtpTarget ?: 'INBOUND_SMTP_HOST absent') ?>)</option>
                </select>
            </label>
            <label class="field hidden" data-transport="smtp" title="Vide = expéditeur d'origine (SPF en échec côté MTA, mais détection du parser préservée)">
                <span>MAIL FROM forcé</span>
                <input type="email" name="envelope_from" placeholder="Vide = expéditeur d'origine">
            </label>
            <label class="field">
                <span>Destinataire forcé</span>
                <input type="email" name="recipient" placeholder="<?= $e($recipientDomain === '' ? 'Vide = destinataire d\'origine' : "Vide = traceur d'origine @$recipientDomain") ?>">
            </label>
            <label class="field">
                <span>Préfixe d'objet</span>
                <input type="text" name="subject_prefix" value="TECH :">
            </label>
            <label class="checkbox" title="Id de livraison unique + marqueur invisible : contourne l'idempotence et l'anti-doublon de contenu">
                <input type="checkbox" name="fresh" checked> Forcer un nouvel email
            </label>
            <label class="checkbox">
                <input type="checkbox" name="keep_date"> Conserver la date de réception d'origine
            </label>
        </div>
    </section>

    <section class="block hidden" id="resultsBlock">
        <div class="results-head">
            <h2 class="titled-separator"><span id="resultsTitle">Emails</span></h2>
            <button type="button" class="button-default" id="sendButton" <?= $secretConfigured || $smtpTarget !== '' ? '' : 'disabled' ?>>
                <i class="fas fa-paper-plane"></i> Envoyer la sélection
            </button>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th><input type="checkbox" id="checkAll" checked></th>
                    <th>ID</th>
                    <th>Reçu le</th>
                    <th>Expéditeur</th>
                    <th>Destinataire</th>
                    <th>Objet</th>
                    <th>Origine</th>
                    <th>Statut</th>
                    <th>Envoi</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="emailRows"></tbody>
            </table>
        </div>
    </section>
</main>

<dialog id="payloadDialog">
    <div class="dialog-head">
        <span id="payloadTitle">Payload</span>
        <button type="button" class="icon-button" data-close><i class="fas fa-xmark"></i></button>
    </div>
    <pre id="payloadContent"></pre>
</dialog>

<script>
    window.inboundReplay = {
        targetBaseUrl: <?= json_encode($targetBaseUrl, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
        isProdTarget: <?= $isProdTarget ? 'true' : 'false' ?>,
        smtpTarget: <?= json_encode($smtpTarget, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
    };
</script>
<script src="js/Email_Inbound_Replay.js"></script>
</body>
</html>
