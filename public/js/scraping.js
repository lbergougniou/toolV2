document
    .getElementById("scrapingForm")
    .addEventListener("submit", async (e) => {
        e.preventDefault();

        const reference = document.getElementById("reference").value.trim();
        const prix = document.getElementById("prix").value.trim();
        const localisation = document
            .getElementById("localisation")
            .value.trim();

        if (reference === "" && (prix === "" || localisation === "")) {
            alert(
                "Veuillez remplir soit la référence, soit le prix et la localisation."
            );
            return;
        }

        const formData = new FormData(e.target);
        const resultsDiv = document.getElementById("results");

        resultsDiv.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Chargement...</span>
            </div>
        </div>
    `;

        try {
            const response = await fetch(e.target.action, {
                method: "POST",
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                
                if (data.results.length === 0) {
                    resultsDiv.innerHTML = `
                    <div class="alert alert-warning" role="alert">
                        Aucune annonce trouvée pour cette recherche.
                    </div>
                `;
                    return;
                }

                let html = `
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Type</th>
                                <th>Pièces</th>
                                <th>Surface</th>
                                <th>Prix</th>
                                <th>Code Postal</th>
                                <th>Ville</th>
                                <th>Agence</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

                data.results.forEach((result) => {
                    const formattedPrice = result.prix
                        ? new Intl.NumberFormat("fr-FR", {
                              style: "currency",
                              currency: "EUR",
                              maximumFractionDigits: 0,
                          }).format(result.prix)
                        : " - ";

                    const formattedSurface = result.surface
                        ? `${result.surface} m²`
                        : " - ";

                    const formattedPieces = result.nb_pieces
                        ? `${result.nb_pieces} pièce${
                              result.nb_pieces > 1 ? "s" : ""
                          }`
                        : " - ";

                    html += `
                    <tr>
                        <td>${result.type_bien}</td>
                        <td>${formattedPieces}</td>
                        <td>${formattedSurface}</td>
                        <td>${formattedPrice}</td>
                        <td>${result.code_postal || " - "}</td>
                        <td>${result.ville || " - "}</td>
                        <td>${result.agence || " - "}</td>
                        <td>
                            <a href="${result.lien}" target="_blank" 
                               class="btn btn-primary btn-sm" 
                               title="Voir l'annonce">
                                <i class="bi bi-eye"></i> Voir
                            </a>
                        </td>
                    </tr>
                `;
                });

                html += `
                        </tbody>
                    </table>
                </div>
            `;

                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = `
                <div class="alert alert-danger" role="alert">
                    Une erreur est survenue : ${data.message}
                </div>
            `;
            }
        } catch (error) {
            resultsDiv.innerHTML = `
            <div class="alert alert-danger" role="alert">
                Une erreur est survenue lors de la requête : ${error.message}
            </div>
        `;
        }
    });
