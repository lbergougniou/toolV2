/**
 * Script pour la normalisation des noms et prénoms
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiForm');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = document.getElementById('spinner');

    // Gestion de la soumission du formulaire
    if (form) {
        form.addEventListener('submit', function(e) {
            // Validation avant soumission
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            // Afficher le spinner
            if (spinner && submitBtn) {
                spinner.classList.remove('d-none');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Traitement en cours...';
            }
        });
    }

    // Auto-focus sur le premier champ vide
    const inputs = form.querySelectorAll('input[type="text"], input[type="email"]');
    for (let input of inputs) {
        if (!input.value.trim()) {
            input.focus();
            break;
        }
    }
});

/**
 * Validation côté client
 */
function validateForm() {
    const nom = document.getElementById('nom').value.trim();
    const prenom = document.getElementById('prenom').value.trim();
    const email = document.getElementById('email').value.trim();
    
    if (!nom && !prenom && !email) {
        showToast('Veuillez remplir au moins un champ', 'error');
        return false;
    }
    
    return true;
}

/**
 * Affiche un toast de notification
 */
function showToast(message, type = 'info') {
    // Créer l'élément toast s'il n'existe pas
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '9999';
        document.body.appendChild(toastContainer);
    }

    // Créer le toast
    const toastId = 'toast-' + Date.now();
    const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
    
    const toastHTML = `
        <div class="toast ${bgClass} text-white" id="${toastId}" role="alert">
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHTML);
    
    // Initialiser et afficher le toast
    const toastElement = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: 3000
    });
    
    bsToast.show();
    
    // Nettoyer après fermeture
    toastElement.addEventListener('hidden.bs.toast', function() {
        toastElement.remove();
    });
}