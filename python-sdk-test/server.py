"""Test server for the Scorimmo Python SDK."""

import json
import os
from datetime import datetime, timedelta, timezone
from html import escape

from dotenv import load_dotenv
from flask import Flask, request
from scorimmo.client import ScorimmoApiError, ScorimmoAuthError, ScorimmoClient

load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "../.env"))

app = Flask(__name__)
PORT = int(os.environ.get("PYTHON_SDK_TEST_PORT", 3098))

DEFAULT_USERNAME = os.environ.get("SCORIMMO_USERNAME", "")
DEFAULT_PASSWORD = os.environ.get("SCORIMMO_PASSWORD", "")

# ─── HTML helpers ─────────────────────────────────────────────────────────────

STYLE = """
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
"""

SCRIPT = """
<script>
function toggleFields() {
  const action = document.getElementById('action').value;
  document.querySelectorAll('.action-fields').forEach(el => el.classList.remove('active'));
  const target = document.getElementById('field-' + action);
  if (target) target.classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => toggleFields());
</script>
"""


def layout(body: str) -> str:
    return f"""<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test SDK Scorimmo Python</title>
  <style>{STYLE}</style>
</head>
<body>
{body}
{SCRIPT}
</body>
</html>"""


def form_html(username: str = "", password: str = "", action: str = "recent_leads", limit: str = "10", lead_id: str = "") -> str:
    def sel(v: str) -> str:
        return "selected" if action == v else ""

    def active(v: str) -> str:
        return "active" if action == v else ""

    return f"""
  <h1>Test SDK Scorimmo <span class="badge">Python</span></h1>
  <p style="color:#777">Teste les appels à l'API via <code>scorimmo-python</code></p>

  <form method="POST" action="/run">
    <div class="row">
      <div>
        <label>Identifiant (SCORIMMO_USERNAME)</label>
        <input type="text" name="username" value="{escape(username)}" placeholder="identifiant" required>
      </div>
      <div>
        <label>Mot de passe (SCORIMMO_PASSWORD)</label>
        <input type="password" name="password" value="{escape(password)}" placeholder="••••••••" required>
      </div>
    </div>

    <label>Action</label>
    <select name="action" id="action" onchange="toggleFields()">
      <option value="token" {sel("token")}>Obtenir le token JWT</option>
      <option value="recent_leads" {sel("recent_leads")}>Leads des dernières 24h</option>
      <option value="list_leads" {sel("list_leads")}>Lister les leads</option>
      <option value="get_lead" {sel("get_lead")}>Récupérer un lead par ID</option>
    </select>

    <div id="field-list_leads" class="action-fields {active('list_leads')}">
      <label>Nombre de leads (limit)</label>
      <input type="number" name="limit" value="{escape(limit)}" min="1" max="50">
    </div>

    <div id="field-get_lead" class="action-fields {active('get_lead')}">
      <label>ID du lead</label>
      <input type="number" name="lead_id" value="{escape(lead_id)}" placeholder="ex: 42">
    </div>

    <button type="submit">Envoyer la requête</button>
  </form>
"""


# ─── Routes ───────────────────────────────────────────────────────────────────

@app.get("/")
def index():
    return layout(form_html(username=DEFAULT_USERNAME, password=DEFAULT_PASSWORD))


@app.post("/run")
def run():
    username = request.form.get("username", "")
    password = request.form.get("password", "")
    action   = request.form.get("action", "recent_leads")
    limit    = request.form.get("limit", "10")
    lead_id  = request.form.get("lead_id", "")

    result_html = ""

    try:
        client = ScorimmoClient(username=username, password=password)

        match action:
            case "token":
                token = client.get_token()
                title = "Token JWT"
                data  = {"token": token}

            case "recent_leads":
                since = datetime.now(timezone.utc) - timedelta(hours=24)
                leads = client.leads.since(since)
                title = "Leads des dernières 24h"
                data  = leads

            case "list_leads":
                n     = max(1, min(50, int(limit) if limit.isdigit() else 10))
                data  = client.leads.list(limit=n)
                title = f"Liste des leads (limit {n})"

            case "get_lead":
                lid = int(lead_id) if lead_id.isdigit() else 0
                if not lid:
                    raise ValueError("ID du lead requis")
                data  = client.leads.get(lid)
                title = f"Lead #{lid}"

            case _:
                raise ValueError("Action inconnue")

        result_html = f'<div class="result"><h2>{escape(title)}</h2><pre>{escape(json.dumps(data, indent=2, ensure_ascii=False))}</pre></div>'

    except ScorimmoAuthError as e:
        result_html = f'<div class="error"><strong>Erreur :</strong> {escape(f"Authentification échouée : {e}")}</div>'
    except ScorimmoApiError as e:
        result_html = f'<div class="error"><strong>Erreur :</strong> {escape(f"Erreur API ({e.status_code}) : {e}")}</div>'
    except Exception as e:
        result_html = f'<div class="error"><strong>Erreur :</strong> {escape(f"{type(e).__name__} : {e}")}</div>'

    return layout(form_html(username=username, password=password, action=action, limit=limit, lead_id=lead_id) + result_html)


# ─── Start ────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    print(f"Test SDK Scorimmo Python → http://localhost:{PORT}")
    app.run(port=PORT)
