/**
 * Script pour l'interface de mise à jour des services ViaDialog
 * Gère la validation, la copie d'exemple et la soumission du formulaire
 */

// Exemple JSON universel
const exampleJson = `{
  "scriptId": "Entrant - PROD",
  "scriptVersion": 1,
  "script": {
    "scriptId": "Entrant - PROD",
    "scriptVersion": "1"
  }
}`;

/**
 * Copie l'exemple JSON dans le textarea
 */
function copyExample() {
    const textarea = document.getElementById('modifications');
    textarea.value = exampleJson;
    textarea.focus();

    // Animation de confirmation
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i> Copié !';
    btn.classList.add('btn-success');
    btn.classList.remove('btn-outline-primary');

    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-primary');
    }, 2000);
}

/**
 * Initialisation au chargement de la page
 */
document.addEventListener('DOMContentLoaded', function() {

    // Validation du formulaire
    const updateForm = document.getElementById('updateForm');
    if (updateForm) {
        updateForm.addEventListener('submit', function(e) {
            const serviceIds = document.getElementById('serviceIds').value.trim();
            const modifications = document.getElementById('modifications').value.trim();

            if (!serviceIds) {
                e.preventDefault();
                alert('Veuillez saisir au moins un ID de service.');
                return false;
            }

            if (!modifications) {
                e.preventDefault();
                alert('Veuillez saisir les modifications au format JSON.');
                return false;
            }

            // Validation du JSON
            try {
                JSON.parse(modifications);
            } catch (error) {
                e.preventDefault();
                alert('Format JSON invalide:\n' + error.message);
                return false;
            }

            // Confirmation avant soumission
            const confirm = window.confirm(
                'Êtes-vous sûr de vouloir appliquer ces modifications ?\n\n' +
                'Cette action modifiera tous les services listés.'
            );

            if (!confirm) {
                e.preventDefault();
                return false;
            }
        });
    }

    // Validation JSON en temps réel
    const modificationsTextarea = document.getElementById('modifications');
    if (modificationsTextarea) {
        modificationsTextarea.addEventListener('input', function() {
            try {
                JSON.parse(this.value);
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } catch (error) {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            }
        });
    }
});
