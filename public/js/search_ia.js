/**
 * Gestion de la recherche d'annonces immobilières avec IA
 */
document.addEventListener("DOMContentLoaded", function () {
    // Éléments DOM
    const searchButton = document.getElementById("searchButton");
    const agenceInput = document.getElementById("agence");
    const referenceInput = document.getElementById("reference");
    const typeBienInput = document.getElementById("type_bien");
    const prixInput = document.getElementById("prix");
    const surfaceInput = document.getElementById("surface");
    const codePostalInput = document.getElementById("code_postal");
    const villeInput = document.getElementById("ville");
    const aiProviderSelect = document.getElementById("ai_provider");
    const resultsContainer = document.getElementById("resultsContainer");
    const loader = document.getElementById("loader");

    // Fonction pour vérifier les champs requis
    function checkRequiredFields() {
        const agenceValue = agenceInput.value.trim();

        // On exige au moins l'agence et un autre champ
        if (!agenceValue) {
            searchButton.disabled = true;
            return;
        }

        // Si la référence est renseignée, on active le bouton
        if (referenceInput.value.trim()) {
            searchButton.disabled = false;
            return;
        }

        // Sinon, on vérifie si au moins un champ complémentaire est rempli
        const typeBienValue = typeBienInput.value;
        const prixValue = prixInput.value;
        const surfaceValue = surfaceInput.value;
        const codePostalValue = codePostalInput.value;
        const villeValue = villeInput.value;

        // Si au moins un de ces champs est rempli, on active le bouton
        searchButton.disabled = !(
            typeBienValue ||
            prixValue ||
            surfaceValue ||
            codePostalValue ||
            villeValue
        );
    }

    // Ajouter des écouteurs d'événements pour tous les champs
    const allInputs = document.querySelectorAll("input, select");
    allInputs.forEach((input) => {
        input.addEventListener("input", checkRequiredFields);
    });

    // Gestion du clic sur le bouton de recherche
    searchButton.addEventListener("click", function () {
        // Récupération des données du formulaire
        const searchData = {
            agence: agenceInput.value.trim(),
            reference: referenceInput.value.trim(),
            type_bien: typeBienInput.value,
            prix: prixInput.value,
            surface: surfaceInput.value,
            code_postal: codePostalInput.value,
            ville: villeInput.value,
        };

        // Ajout du fournisseur d'IA sélectionné si disponible
        if (aiProviderSelect && aiProviderSelect.value) {
            searchData.ai_provider = aiProviderSelect.value;
        }

        // Log pour débuggage
        console.log("Données envoyées:", searchData);

        // Affichage du loader
        loader.classList.remove("d-none");
        searchButton.disabled = true;
        resultsContainer.innerHTML = "";

        // Envoi de la requête au serveur
        fetch("index.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(searchData),
        })
            .then((response) => {
                console.log("Statut de la réponse:", response.status);
                console.log(
                    "Headers de la réponse:",
                    response.headers.get("content-type")
                );

                if (!response.ok) {
                    return response.text().then((text) => {
                        console.error("Réponse d'erreur du serveur:", text);
                        throw new Error(
                            `Erreur serveur ${
                                response.status
                            }: ${text.substring(0, 200)}`
                        );
                    });
                }

                // Vérifier le type de contenu
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    return response.text().then((text) => {
                        console.error("Réponse non-JSON reçue:", text);
                        throw new Error(
                            "Le serveur a retourné du contenu non-JSON"
                        );
                    });
                }

                return response.json();
            })
            .catch((jsonError) => {
                if (jsonError.name === "SyntaxError") {
                    return response.text().then((text) => {
                        console.error(
                            "Erreur de parsing JSON. Contenu reçu:",
                            text
                        );
                        throw new Error("Réponse JSON invalide du serveur");
                    });
                }
                throw jsonError;
            })
            .then((data) => {
                // Log pour débuggage
                console.log("Données reçues du serveur:", data);

                // Masquage du loader
                loader.classList.add("d-none");
                searchButton.disabled = false;

                // Affichage des résultats
                displayResults(data);
            })
            .catch((error) => {
                // Gestion des erreurs
                loader.classList.add("d-none");
                searchButton.disabled = false;

                console.error("Erreur:", error);
                resultsContainer.innerHTML = `
                <div class="alert alert-danger">
                    Une erreur est survenue lors de la recherche: ${error.message}
                </div>
            `;
            });
    });

    /**
     * Affiche les résultats de la recherche
     *
     * @param {Object} serverResponse La réponse complète du serveur
     */
    function displayResults(serverResponse) {
        resultsContainer.innerHTML = "";

        console.log("Traitement de la réponse:", serverResponse);

        // Gestion des erreurs du serveur
        if (serverResponse.success === false) {
            resultsContainer.innerHTML = `
                <div class="alert alert-danger">
                    Erreur: ${serverResponse.error || "Erreur inconnue"}
                </div>
            `;
            return;
        }

        // Extraction des données réelles
        let data = serverResponse.data || serverResponse;

        // Si serverResponse est directement un tableau (rétrocompatibilité)
        if (Array.isArray(serverResponse)) {
            data = serverResponse;
        }

        console.log("Données extraites pour affichage:", data);

        // Vérification si les données sont vides
        if (!data || (Array.isArray(data) && data.length === 0)) {
            const providerInfo = serverResponse.provider_used
                ? `<br><small class="text-muted">Fournisseur utilisé: ${getProviderDisplayName(
                      serverResponse.provider_used
                  )}</small>`
                : "";

            resultsContainer.innerHTML = `
                <div class="alert alert-warning">
                    <h5 class="alert-heading">Aucun résultat trouvé</h5>
                    <p>Aucune propriété correspondant à vos critères n'a été trouvée.</p>
                    <hr>
                    <p class="mb-0">Essayez de modifier vos critères de recherche ou de vérifier l'orthographe du nom de l'agence.${providerInfo}</p>
                </div>
            `;
            return;
        }

        // Normalisation des données : s'assurer qu'on a un tableau
        if (!Array.isArray(data)) {
            data = [data];
        }

        // Filtrage des résultats valides (qui ont au moins une propriété définie)
        const validResults = data.filter(
            (item) =>
                item &&
                (item.reference ||
                    item.type ||
                    item.price ||
                    item.area ||
                    item.city ||
                    item.link)
        );

        if (validResults.length === 0) {
            resultsContainer.innerHTML = `
                <div class="alert alert-warning">
                    <h5 class="alert-heading">Données incomplètes</h5>
                    <p>Les résultats retournés ne contiennent pas d'informations exploitables.</p>
                </div>
            `;
            return;
        }

        // Affichage des résultats
        if (validResults.length === 1) {
            // Remplissage automatique des champs du formulaire si un seul résultat
            fillFormWithResult(validResults[0]);

            // Affichage du résultat unique
            const headerHtml = `<h2 class="mt-4 mb-3">Résultat trouvé</h2>`;
            const providerInfo = serverResponse.provider_used
                ? `<div class="alert alert-info"><i class="fas fa-robot"></i> Fournisseur utilisé: <strong>${getProviderDisplayName(
                      serverResponse.provider_used
                  )}</strong></div>`
                : "";

            resultsContainer.innerHTML = headerHtml + providerInfo;
            resultsContainer.appendChild(createPropertyCard(validResults[0]));
        } else {
            // Affichage du titre des résultats
            const headerHtml = `<h2 class="mt-4 mb-3">Résultats de recherche (${validResults.length})</h2>`;
            const providerInfo = serverResponse.provider_used
                ? `<div class="alert alert-info"><i class="fas fa-robot"></i> Fournisseur utilisé: <strong>${getProviderDisplayName(
                      serverResponse.provider_used
                  )}</strong></div>`
                : "";

            resultsContainer.innerHTML = headerHtml + providerInfo;

            // Affichage de plusieurs résultats
            const row = document.createElement("div");
            row.className = "row";

            validResults.forEach((property, index) => {
                const col = document.createElement("div");
                col.className = "col-md-6 mb-4";
                col.appendChild(createPropertyCard(property, index + 1));
                row.appendChild(col);
            });

            resultsContainer.appendChild(row);
        }
    }

    /**
     * Obtient le nom d'affichage d'un fournisseur
     *
     * @param {string} providerType Type du fournisseur
     * @return {string} Nom d'affichage
     */
    function getProviderDisplayName(providerType) {
        if (
            window.availableProviders &&
            window.availableProviders[providerType]
        ) {
            return window.availableProviders[providerType].name;
        }

        // Fallback pour les noms connus
        const providerNames = {
            gemini: "Google Gemini",
            openai: "OpenAI ChatGPT",
            chatgpt: "OpenAI ChatGPT",
        };

        return providerNames[providerType] || providerType;
    }

    /**
     * Remplit le formulaire avec les données d'un résultat
     *
     * @param {Object} property Les données de la propriété
     */
    function fillFormWithResult(property) {
        if (property.type) typeBienInput.value = property.type;
        if (property.price) prixInput.value = property.price;
        if (property.area) surfaceInput.value = property.area;
        if (property.reference) referenceInput.value = property.reference;
        if (property.zip_code) codePostalInput.value = property.zip_code;
        if (property.city) villeInput.value = property.city;
    }

    /**
     * Crée une carte Bootstrap pour une propriété
     *
     * @param {Object} property Les données de la propriété
     * @param {number} index Index optionnel pour numéroter les résultats
     * @return {HTMLElement} L'élément carte de la propriété
     */
    function createPropertyCard(property, index = null) {
        const card = document.createElement("div");
        card.className = "card h-100 shadow-sm";

        // Entête de la carte
        const cardHeader = document.createElement("div");
        cardHeader.className = "card-header bg-primary text-white";

        let headerTitle = property.type || "Bien immobilier";
        if (property.city) {
            headerTitle += ` - ${property.city}`;
        }
        if (index) {
            headerTitle = `#${index} ${headerTitle}`;
        }

        cardHeader.innerHTML = `<h5 class="mb-0">${headerTitle}</h5>`;
        card.appendChild(cardHeader);

        // Corps de la carte
        const cardBody = document.createElement("div");
        cardBody.className = "card-body";

        // Liste des détails
        const detailsList = document.createElement("ul");
        detailsList.className = "list-group list-group-flush mb-3";

        // Ajout des détails disponibles dans un ordre logique
        const detailsOrder = [
            { key: "reference", label: "Référence", format: (value) => value },
            { key: "type", label: "Type de bien", format: (value) => value },
            {
                key: "price",
                label: "Prix",
                format: (value) =>
                    `${parseInt(value).toLocaleString("fr-FR")} €`,
            },
            { key: "area", label: "Surface", format: (value) => `${value} m²` },
            {
                key: "nb_rooms",
                label: "Nombre de pièces",
                format: (value) => value,
            },
            { key: "city", label: "Ville", format: (value) => value },
            { key: "zip_code", label: "Code postal", format: (value) => value },
        ];

        detailsOrder.forEach((detail) => {
            if (property[detail.key]) {
                addPropertyDetail(
                    detailsList,
                    detail.label,
                    detail.format(property[detail.key])
                );
            }
        });

        cardBody.appendChild(detailsList);

        // Pied de carte avec le lien
        if (property.link) {
            const cardFooter = document.createElement("div");
            cardFooter.className = "card-footer text-center";

            const linkElement = document.createElement("a");
            linkElement.href = property.link;
            linkElement.className = "btn btn-primary btn-sm";
            linkElement.target = "_blank";
            linkElement.rel = "noopener noreferrer";
            linkElement.innerHTML =
                '<i class="fas fa-external-link-alt"></i> Voir l\'annonce';

            cardFooter.appendChild(linkElement);
            card.appendChild(cardFooter);
        }

        cardBody.appendChild(detailsList);
        card.appendChild(cardBody);

        return card;
    }

    /**
     * Ajoute un détail à la liste des détails d'une propriété
     *
     * @param {HTMLElement} list L'élément liste des détails
     * @param {string} label Le libellé du détail
     * @param {string|number} value La valeur du détail
     */
    function addPropertyDetail(list, label, value) {
        const item = document.createElement("li");
        item.className =
            "list-group-item d-flex justify-content-between align-items-center";

        const labelSpan = document.createElement("span");
        labelSpan.className = "fw-bold text-muted";
        labelSpan.textContent = label;

        const valueSpan = document.createElement("span");
        valueSpan.className = "text-dark";
        valueSpan.textContent = value;

        item.appendChild(labelSpan);
        item.appendChild(valueSpan);

        list.appendChild(item);
    }

    // Vérification initiale des champs
    checkRequiredFields();
});

// Fonctions globales pour la configuration des fournisseurs d'IA
function setAsDefault(providerType) {
    fetch("ai_config.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            action: "set_default",
            provider: providerType,
        }),
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                // Recharger la page pour mettre à jour l'interface
                window.location.reload();
            } else {
                alert(
                    "Erreur: " +
                        (data.error ||
                            "Impossible de définir le fournisseur par défaut")
                );
            }
        })
        .catch((error) => {
            console.error("Erreur:", error);
            alert("Erreur lors de la configuration du fournisseur par défaut");
        });
}

function saveAIConfig() {
    const config = {};

    // Parcourir tous les fournisseurs disponibles
    if (window.availableProviders) {
        Object.keys(window.availableProviders).forEach((providerType) => {
            const enabledCheckbox = document.getElementById(
                `provider_${providerType}`
            );
            const modelSelect = document.getElementById(
                `model_${providerType}`
            );

            if (enabledCheckbox && modelSelect) {
                config[providerType] = {
                    enabled: enabledCheckbox.checked,
                    model: modelSelect.value,
                };
            }
        });
    }

    fetch("ai_config.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            action: "save_config",
            config: config,
        }),
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                // Fermer le modal et recharger la page
                const modal = bootstrap.Modal.getInstance(
                    document.getElementById("aiConfigModal")
                );
                modal.hide();
                window.location.reload();
            } else {
                alert(
                    "Erreur: " +
                        (data.error ||
                            "Impossible de sauvegarder la configuration")
                );
            }
        })
        .catch((error) => {
            console.error("Erreur:", error);
            alert("Erreur lors de la sauvegarde de la configuration");
        });
}
