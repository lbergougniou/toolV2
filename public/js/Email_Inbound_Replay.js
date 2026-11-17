/**
 * Rejeu d'emails entrants : recherche, aperçu du payload signé et envoi sur le webhook cible.
 */
(() => {
    const API_URL = 'api/inbound_replay.php';
    const STATUS_LABELS = {new: 'Nouveau', current: 'En cours', treated: 'Traité', wait_ai: 'Attente IA'};
    const {targetBaseUrl, isProdTarget} = window.inboundReplay;

    const searchForm = document.getElementById('searchForm');
    const resultsBlock = document.getElementById('resultsBlock');
    const resultsTitle = document.getElementById('resultsTitle');
    const rows = document.getElementById('emailRows');
    const checkAll = document.getElementById('checkAll');
    const sendButton = document.getElementById('sendButton');
    const dialog = document.getElementById('payloadDialog');
    let mode = 'filters';

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const call = async (body) => {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({success: false, error: `HTTP ${response.status}`}));
        if (!data.success) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        return data;
    };

    const sendOptions = () => {
        const container = document.getElementById('sendOptions');
        return {
            recipient: container.querySelector('[name="recipient"]').value.trim(),
            subject_prefix: container.querySelector('[name="subject_prefix"]').value,
            fresh: container.querySelector('[name="fresh"]').checked,
            keep_date: container.querySelector('[name="keep_date"]').checked,
        };
    };

    const setLoading = (button, loading) => {
        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
    };

    const showError = (message) => {
        resultsBlock.classList.remove('hidden');
        resultsTitle.textContent = 'Erreur';
        rows.innerHTML = `<tr><td colspan="10" class="cell-error"><i class="fas fa-triangle-exclamation"></i> ${escapeHtml(message)}</td></tr>`;
    };

    document.querySelectorAll('.tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            mode = tab.dataset.mode;
            document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t === tab));
            document.querySelectorAll('.mode').forEach((m) => m.classList.toggle('hidden', m.dataset.mode !== mode));
        });
    });

    searchForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = searchForm.querySelector('button[type="submit"]');
        const formData = new FormData(searchForm);
        const filters = mode === 'ids'
            ? {ids: formData.get('ids')}
            : {...Object.fromEntries(formData), ids: '', exclude_duplicates: formData.has('exclude_duplicates')};

        setLoading(button, true);
        try {
            const {emails} = await call({action: 'search', filters});
            renderEmails(emails);
        } catch (error) {
            showError(error.message);
        } finally {
            setLoading(button, false);
        }
    });

    const renderEmails = (emails) => {
        resultsBlock.classList.remove('hidden');
        resultsTitle.textContent = `${emails.length} email(s)`;
        checkAll.checked = true;
        if (emails.length === 0) {
            rows.innerHTML = '<tr><td colspan="10" class="empty-state">Aucun email ne correspond</td></tr>';
            return;
        }
        rows.innerHTML = emails.map((email) => `
            <tr data-id="${email.id}">
                <td><input type="checkbox" class="row-check" value="${email.id}" checked></td>
                <td class="nowrap">${email.id}</td>
                <td class="nowrap">${escapeHtml(email.created_at)}</td>
                <td class="ellipsis" title="${escapeHtml(email.sender_email)}">${escapeHtml(email.sender_email)}</td>
                <td class="ellipsis" title="${escapeHtml(email.to_email)}">${escapeHtml(email.to_email)}</td>
                <td class="ellipsis" title="${escapeHtml(email.sender_subject)}">${escapeHtml(email.sender_subject)}</td>
                <td>${escapeHtml(email.origin)}</td>
                <td class="nowrap">${escapeHtml(STATUS_LABELS[email.status] ?? email.status)}${email.substatus ? `<br><small>${escapeHtml(email.substatus)}</small>` : ''}</td>
                <td class="send-status nowrap"></td>
                <td><button type="button" class="icon-button preview" title="Voir le payload"><i class="fas fa-code"></i></button></td>
            </tr>`).join('');
    };

    checkAll.addEventListener('change', () => {
        rows.querySelectorAll('.row-check').forEach((box) => { box.checked = checkAll.checked; });
    });

    rows.addEventListener('click', async (event) => {
        const button = event.target.closest('.preview');
        if (!button) {
            return;
        }
        const id = button.closest('tr').dataset.id;
        document.getElementById('payloadTitle').textContent = `Payload de l'email ${id}`;
        const content = document.getElementById('payloadContent');
        content.textContent = 'Chargement…';
        dialog.showModal();
        try {
            const {payload} = await call({action: 'preview', id, options: sendOptions()});
            content.textContent = JSON.stringify(payload, null, 2);
        } catch (error) {
            content.textContent = error.message;
        }
    });

    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    const renderSendStatus = (result) => {
        const cell = rows.querySelector(`tr[data-id="${result.source_id}"] .send-status`);
        if (!cell) {
            return;
        }
        const response = result.response ?? {};
        const message = typeof response === 'object' ? (response.message ?? response.error ?? '') : String(response);
        const emailId = typeof response === 'object' ? response.data?.email_id ?? response.email_id : null;
        const link = emailId ? ` <a href="${escapeHtml(targetBaseUrl)}/email/voir/${encodeURIComponent(emailId)}" target="_blank" rel="noopener">#${emailId}</a>` : '';
        cell.className = `send-status nowrap ${result.success ? 'is-success' : 'is-error'}`;
        cell.title = result.error ?? (typeof response === 'object' ? JSON.stringify(response) : response);
        cell.innerHTML = `<i class="fas ${result.success ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${result.http_code || '—'} ${escapeHtml(message)}${link}`;
    };

    sendButton.addEventListener('click', async () => {
        const ids = [...rows.querySelectorAll('.row-check:checked')].map((box) => box.value);
        if (ids.length === 0) {
            return;
        }
        if (isProdTarget && !window.confirm(`La cible est la PRODUCTION : ${ids.length} email(s) vont créer de vrais emails/leads. Continuer ?`)) {
            return;
        }
        setLoading(sendButton, true);
        ids.forEach((id) => {
            const cell = rows.querySelector(`tr[data-id="${id}"] .send-status`);
            cell.className = 'send-status nowrap';
            cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        });
        try {
            const {results} = await call({action: 'send', ids, options: sendOptions()});
            results.forEach(renderSendStatus);
            const ok = results.filter((r) => r.success).length;
            resultsTitle.textContent = `${ok}/${results.length} email(s) acceptés par la cible`;
        } catch (error) {
            ids.forEach((id) => {
                const cell = rows.querySelector(`tr[data-id="${id}"] .send-status`);
                cell.className = 'send-status nowrap is-error';
                cell.innerHTML = `<i class="fas fa-circle-xmark"></i> ${escapeHtml(error.message)}`;
            });
        } finally {
            setLoading(sendButton, false);
        }
    });
})();
