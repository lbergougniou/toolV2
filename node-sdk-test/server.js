'use strict';

const express = require('express');
const path    = require('path');
const dotenv  = require('dotenv');
const { ScorimmoClient, ScorimmoAuthError, ScorimmoApiError } = require('scorimmo-node');

dotenv.config({ path: path.join(__dirname, '../.env') });

const app  = express();
const PORT = process.env.NODE_SDK_TEST_PORT || 3099;

app.use(express.urlencoded({ extended: true }));
app.use(express.json());

const DEFAULT_USERNAME = process.env.SCORIMMO_USERNAME ?? '';
const DEFAULT_PASSWORD = process.env.SCORIMMO_PASSWORD ?? '';

// ─── HTML helpers ────────────────────────────────────────────────────────────

function layout(body) {
  return `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test SDK Scorimmo Node.js</title>
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
    pre { background: #1a1a1a; padding: 16px; border-radius: 4px; border: 1px solid #333; overflow-x: auto; font-size: 12px; color: #b2dfdb; max-height: 500px; overflow-y: auto; }
    .action-fields { display: none; margin-top: -8px; margin-bottom: 14px; }
    .action-fields.active { display: block; }
    .row { display: flex; gap: 16px; }
    .row > div { flex: 1; }
    .badge { font-size: 11px; background: #1a3a1a; color: #81c784; border: 1px solid #2a5a2a; padding: 2px 8px; border-radius: 10px; vertical-align: middle; margin-left: 8px; }
  </style>
</head>
<body>
${body}
<script>
function toggleFields() {
  const action = document.getElementById('action').value;
  document.querySelectorAll('.action-fields').forEach(el => el.classList.remove('active'));
  const target = document.getElementById('field-' + action);
  if (target) target.classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => toggleFields());
</script>
</body>
</html>`;
}

// ─── Routes ──────────────────────────────────────────────────────────────────

app.get('/', (req, res) => {
  res.send(layout(`
    <h1>Test SDK Scorimmo <span class="badge">Node.js</span></h1>
    <p style="color:#777">Teste les appels à l'API via <code>scorimmo-node</code></p>

    <form method="POST" action="/run">
      <div class="row">
        <div>
          <label>Identifiant (SCORIMMO_USERNAME)</label>
          <input type="text" name="username" value="${esc(DEFAULT_USERNAME)}" placeholder="identifiant" required>
        </div>
        <div>
          <label>Mot de passe (SCORIMMO_PASSWORD)</label>
          <input type="password" name="password" value="${esc(DEFAULT_PASSWORD)}" placeholder="••••••••" required>
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
        <input type="number" name="limit" value="10" min="1" max="50">
      </div>

      <div id="field-get_lead" class="action-fields">
        <label>ID du lead</label>
        <input type="number" name="lead_id" placeholder="ex: 42">
      </div>

      <button type="submit">Envoyer la requête</button>
    </form>
  `));
});

app.post('/run', async (req, res) => {
  const { username, password, action, limit, lead_id } = req.body;

  let resultHtml = '';

  try {
    const client = new ScorimmoClient({ username, password });

    let title, data;

    switch (action) {
      case 'token': {
        const token = await client.getToken();
        title = 'Token JWT';
        data  = { token };
        break;
      }
      case 'recent_leads': {
        const leads = await client.leads.since(new Date(Date.now() - 24 * 60 * 60 * 1000));
        title = 'Leads des dernières 24h';
        data  = leads;
        break;
      }
      case 'list_leads': {
        const n = Math.min(50, Math.max(1, parseInt(limit) || 10));
        data  = await client.leads.list({ limit: n });
        title = `Liste des leads (limit ${n})`;
        break;
      }
      case 'get_lead': {
        const id = parseInt(lead_id);
        if (!id) throw new Error('ID du lead requis');
        data  = await client.leads.get(id);
        title = `Lead #${id}`;
        break;
      }
      default:
        throw new Error('Action inconnue');
    }

    resultHtml = `<div class="result"><h2>${esc(title)}</h2><pre>${esc(JSON.stringify(data, null, 2))}</pre></div>`;

  } catch (err) {
    console.error('[SDK Error]', err);
    let msg;
    if (err instanceof ScorimmoAuthError) msg = `Authentification échouée : ${err.message}`;
    else if (err instanceof ScorimmoApiError) msg = `Erreur API (${err.statusCode}) : ${err.message}`;
    else msg = `${err.constructor.name} : ${err.message}`;
    resultHtml = `<div class="error"><strong>Erreur :</strong> ${esc(msg)}</div>`;
  }

  const actionSel = (v) => req.body.action === v ? 'selected' : '';

  res.send(layout(`
    <h1>Test SDK Scorimmo <span class="badge">Node.js</span></h1>
    <p style="color:#777">Teste les appels à l'API via <code>scorimmo-node</code></p>

    <form method="POST" action="/run">
      <div class="row">
        <div>
          <label>Identifiant (SCORIMMO_USERNAME)</label>
          <input type="text" name="username" value="${esc(username)}" placeholder="identifiant" required>
        </div>
        <div>
          <label>Mot de passe (SCORIMMO_PASSWORD)</label>
          <input type="password" name="password" value="${esc(password)}" placeholder="••••••••" required>
        </div>
      </div>

      <label>Action</label>
      <select name="action" id="action" onchange="toggleFields()">
        <option value="token" ${actionSel('token')}>Obtenir le token JWT</option>
        <option value="recent_leads" ${actionSel('recent_leads')}>Leads des dernières 24h</option>
        <option value="list_leads" ${actionSel('list_leads')}>Lister les leads</option>
        <option value="get_lead" ${actionSel('get_lead')}>Récupérer un lead par ID</option>
      </select>

      <div id="field-list_leads" class="action-fields ${req.body.action === 'list_leads' ? 'active' : ''}">
        <label>Nombre de leads (limit)</label>
        <input type="number" name="limit" value="${esc(limit || '10')}" min="1" max="50">
      </div>

      <div id="field-get_lead" class="action-fields ${req.body.action === 'get_lead' ? 'active' : ''}">
        <label>ID du lead</label>
        <input type="number" name="lead_id" value="${esc(lead_id || '')}" placeholder="ex: 42">
      </div>

      <button type="submit">Envoyer la requête</button>
    </form>

    ${resultHtml}
  `));
});

// ─── Utils ───────────────────────────────────────────────────────────────────

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Start ───────────────────────────────────────────────────────────────────

app.listen(PORT, () => {
  console.log(`Test SDK Scorimmo Node.js → http://localhost:${PORT}`);
});
