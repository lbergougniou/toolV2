/**
 * Script pour l'interface des priorités agents par groupe
 * Gère la soumission automatique lors du changement de groupe
 */

document.addEventListener('DOMContentLoaded', function() {
    const groupSelect = document.getElementById('groupId');
    const copyBtn = document.getElementById('copyBtn');

    if (groupSelect) {
        // Soumission automatique lors du changement de groupe
        groupSelect.addEventListener('change', function() {
            if (this.value) {
                this.closest('form').submit();
            }
        });
    }

    if (copyBtn && window.agentsList) {
        copyBtn.addEventListener('click', function() {
            // Format pour email
            let text = window.groupName + '\n';

            window.agentsList.forEach(function(agent) {
                text += agent.priority + ' - ' + agent.name + '\n';
            });

            // Copier dans le presse-papier
            navigator.clipboard.writeText(text).then(function() {
                // Animation de confirmation
                const originalHtml = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="bi bi-check"></i> Copié !';
                copyBtn.classList.remove('btn-outline-primary');
                copyBtn.classList.add('btn-success');

                setTimeout(function() {
                    copyBtn.innerHTML = originalHtml;
                    copyBtn.classList.remove('btn-success');
                    copyBtn.classList.add('btn-outline-primary');
                }, 2000);
            }).catch(function(err) {
                alert('Erreur lors de la copie: ' + err);
            });
        });
    }
});
