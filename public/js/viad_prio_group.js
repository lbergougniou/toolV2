/**
 * Script pour l'interface des priorités agents par groupe
 * Gère l'affichage, la modification (priorité, suppression, ajout) et la sauvegarde
 */

document.addEventListener('DOMContentLoaded', function () {

    // --- Auto-submit sur changement de groupe ---
    const groupSelect = document.getElementById('groupId');
    if (groupSelect) {
        groupSelect.addEventListener('change', function () {
            if (this.value) this.closest('form').submit();
        });
    }

    // --- Bouton copier pour email ---
    const copyBtn = document.getElementById('copyBtn');
    if (copyBtn && window.agentsList) {
        copyBtn.addEventListener('click', function () {
            let text = window.groupName + '\n';
            window.agentsList.forEach(a => { text += a.priority + ' - ' + a.name + '\n'; });
            navigator.clipboard.writeText(text).then(function () {
                const orig = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="bi bi-check"></i> Copié !';
                copyBtn.classList.replace('btn-outline-primary', 'btn-success');
                setTimeout(function () {
                    copyBtn.innerHTML = orig;
                    copyBtn.classList.replace('btn-success', 'btn-outline-primary');
                }, 2000);
            }).catch(err => alert('Erreur lors de la copie: ' + err));
        });
    }

    // --- Mode édition ---
    if (!window.agentsList) return;

    let agentsState = deepCopy(window.agentsList);
    let editMode = false;

    const editBtn      = document.getElementById('editBtn');
    const saveBtn      = document.getElementById('saveBtn');
    const cancelBtn    = document.getElementById('cancelBtn');
    const agentsListEl = document.getElementById('agentsList');
    const addSection   = document.getElementById('addAgentSection');
    const agentCount   = document.getElementById('agentCount');
    const alertContainer = document.getElementById('alertContainer');

    function deepCopy(arr) { return JSON.parse(JSON.stringify(arr)); }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function getPriorityClass(p) {
        if (p == 1) return 'priority-1';
        if (p == 2) return 'priority-2';
        if (p == 3) return 'priority-3';
        return 'priority-high';
    }

    function showAlert(type, message) {
        if (!alertContainer) return;
        const div = document.createElement('div');
        div.className = 'alert alert-' + type + ' alert-dismissible fade show mt-2';
        div.innerHTML = escapeHtml(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        alertContainer.appendChild(div);
        setTimeout(() => div.remove(), 5000);
    }

    function renderAgents() {
        if (!agentsListEl) return;
        agentsListEl.innerHTML = '';

        agentsState.forEach(function (agent, index) {
            const row = document.createElement('div');
            row.className = 'agent-row d-flex align-items-center gap-2';

            if (editMode) {
                row.innerHTML =
                    '<input type="number" class="form-control priority-input" value="' + agent.priority +
                    '" min="1" max="6" data-index="' + index + '" style="width:70px;flex-shrink:0">' +
                    '<span class="fw-medium flex-grow-1">' + escapeHtml(agent.name) + '</span>' +
                    '<button class="btn btn-sm btn-outline-danger delete-btn" data-index="' + index +
                    '" title="Supprimer"><i class="bi bi-trash"></i></button>';
            } else {
                const cls = getPriorityClass(agent.priority);
                row.innerHTML =
                    '<span class="priority-badge ' + cls + ' me-3">' + agent.priority + '</span>' +
                    '<span class="fw-medium">' + escapeHtml(agent.name) + '</span>';
            }
            agentsListEl.appendChild(row);
        });

        if (editMode) {
            agentsListEl.querySelectorAll('.priority-input').forEach(function (input) {
                input.addEventListener('change', function () {
                    agentsState[parseInt(this.dataset.index)].priority = Math.min(6, Math.max(1, parseInt(this.value) || 1));
                });
            });
            agentsListEl.querySelectorAll('.delete-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    agentsState.splice(parseInt(this.dataset.index), 1);
                    renderAgents();
                    if (agentCount) agentCount.textContent = agentsState.length;
                });
            });
        }
    }

    function enterEditMode() {
        editMode = true;
        agentsState = deepCopy(window.agentsList);
        editBtn.classList.add('d-none');
        saveBtn.classList.remove('d-none');
        cancelBtn.classList.remove('d-none');
        if (addSection) addSection.classList.remove('d-none');
        renderAgents();
    }

    function exitEditMode(saved) {
        editMode = false;
        if (saved) window.agentsList = deepCopy(agentsState);
        else agentsState = deepCopy(window.agentsList);
        editBtn.classList.remove('d-none');
        saveBtn.classList.add('d-none');
        cancelBtn.classList.add('d-none');
        if (addSection) addSection.classList.add('d-none');
        renderAgents();
        if (agentCount) agentCount.textContent = agentsState.length;
    }

    if (editBtn)   editBtn.addEventListener('click', enterEditMode);
    if (cancelBtn) cancelBtn.addEventListener('click', () => exitEditMode(false));

    // --- Sauvegarde ---
    if (saveBtn) {
        saveBtn.addEventListener('click', async function () {
            // Lire les priorités depuis les inputs avant envoi
            agentsListEl.querySelectorAll('.priority-input').forEach(function (input) {
                agentsState[parseInt(input.dataset.index)].priority = Math.max(1, parseInt(input.value) || 1);
            });

            const payload = agentsState.map(a => ({
                viaAgentRefDTO: { id: a.id, label: a.name },
                priority: a.priority
            }));

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enregistrement...';

            try {
                const resp = await fetch('Viad_Prio_group.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ groupId: window.groupId, agents: payload })
                });
                const data = await resp.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    showAlert('danger', 'Erreur : ' + (data.error || 'Inconnue'));
                }
            } catch (err) {
                showAlert('danger', 'Erreur réseau : ' + err.message);
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-save"></i> Sauvegarder';
            }
        });
    }

    // --- Ajout d'un agent ---
    const addAgentIdEl   = document.getElementById('addAgentId');
    const addAgentNameEl = document.getElementById('addAgentName');
    const addPriorityEl  = document.getElementById('addPriority');
    const addAgentBtn    = document.getElementById('addAgentBtn');

    if (addAgentBtn) {
        addAgentBtn.addEventListener('click', function () {
            const agentId = parseInt(addAgentIdEl.value);
            const agentName = (addAgentNameEl.value || '').trim();

            if (!agentId || agentId < 1) {
                showAlert('warning', 'Veuillez saisir un ID agent valide.');
                return;
            }
            if (agentsState.find(a => a.id === agentId)) {
                showAlert('warning', 'Cet agent est déjà dans le groupe.');
                return;
            }

            const priority = Math.min(6, Math.max(1, parseInt(addPriorityEl.value) || 1));
            agentsState.push({ id: agentId, name: agentName || String(agentId), priority });
            agentsState.sort((a, b) => a.priority - b.priority);

            addAgentIdEl.value = '';
            addAgentNameEl.value = '';
            addPriorityEl.value = 1;

            renderAgents();
            if (agentCount) agentCount.textContent = agentsState.length;
        });
    }
});
