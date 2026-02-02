/**
 * Script JavaScript pour l'interface de test d'emails
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('emailTestForm');

    if (!form) {
        console.error('Formulaire emailTestForm non trouvé');
        return;
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        console.log('Formulaire soumis');

        const submitBtn = form.querySelector('button[type="submit"]');
        const btnText = submitBtn.querySelector('.btn-text');
        const loadingSpinner = submitBtn.querySelector('.loading');
        const resultsContainer = document.getElementById('resultsContainer');

        // Récupération des données du formulaire
        const formData = {
            email_type: form.email_type.value,
            quantity: parseInt(form.quantity.value),
            days: parseInt(form.days.value)
        };

        console.log('Données du formulaire:', formData);

        // Affichage du loading
        submitBtn.disabled = true;
        btnText.classList.add('d-none');
        loadingSpinner.classList.remove('d-none');
        resultsContainer.innerHTML = '';

        try {
            // Construction de l'URL de l'API
            const apiUrl = 'api/send_emails.php';
            console.log('Appel API vers:', apiUrl);

            // Appel de l'API
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            console.log('Réponse HTTP:', response.status, response.statusText);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Erreur HTTP:', errorText);
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }

            const result = await response.json();
            console.log('Résultat API:', result);

            // Affichage des résultats
            if (result.success) {
                displaySuccessResults(result);
            } else {
                displayErrorResults(result);
            }

        } catch (error) {
            console.error('Erreur lors de l\'appel API:', error);
            displayErrorResults({
                error: 'Erreur réseau : ' + error.message
            });
        } finally {
            // Réinitialisation du bouton
            submitBtn.disabled = false;
            btnText.classList.remove('d-none');
            loadingSpinner.classList.add('d-none');
        }
    });
});

/**
 * Affiche les résultats en cas de succès
 */
function displaySuccessResults(result) {
    const resultsContainer = document.getElementById('resultsContainer');

    let html = `
        <div class="card result-card border-success">
            <div class="card-header bg-success text-white">
                <i class="fas fa-check-circle me-2"></i>
                Résultat : ${escapeHtml(result.message)}
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <strong>Emails trouvés:</strong> ${result.emails_found}<br>
                    <strong>Emails envoyés avec succès:</strong> ${result.success_count}
                </div>
    `;

    if (result.results && result.results.length > 0) {
        html += '<h6 class="mb-3">Détails des envois:</h6>';

        result.results.forEach((emailResult, index) => {
            const statusClass = emailResult.success ? '' : 'error';
            const statusIcon = emailResult.success ?
                '<i class="fas fa-check-circle text-success me-2"></i>' :
                '<i class="fas fa-times-circle text-danger me-2"></i>';

            html += `
                <div class="email-result ${statusClass}">
                    ${statusIcon}
                    <strong>Email #${index + 1}</strong> (ID: ${emailResult.email_id})<br>
                    <small>Code HTTP: ${emailResult.http_code}</small>
                    ${emailResult.error ? `<br><small class="text-danger">Erreur: ${escapeHtml(emailResult.error)}</small>` : ''}
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>
    `;

    resultsContainer.innerHTML = html;
}

/**
 * Affiche les résultats en cas d'erreur
 */
function displayErrorResults(result) {
    const resultsContainer = document.getElementById('resultsContainer');

    const html = `
        <div class="card result-card border-danger">
            <div class="card-header bg-danger text-white">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Erreur
            </div>
            <div class="card-body">
                <div class="alert alert-danger mb-0">
                    ${escapeHtml(result.error || 'Une erreur est survenue')}
                </div>
                ${result.trace ? `<pre class="mt-3 p-2 bg-light border rounded"><code>${escapeHtml(result.trace)}</code></pre>` : ''}
            </div>
        </div>
    `;

    resultsContainer.innerHTML = html;
}

/**
 * Échappe les caractères HTML pour éviter les injections XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
