import { Controller } from '@hotwired/stimulus';
import {
    ajouterUnite,
    ajusterQuantite,
    formaterFcfa,
    libelleTva,
    lireMontantFcfa,
    manquant,
    renduMonnaie,
    suggestionsEspeces,
    totalTtc,
    tvaIncluse,
} from '../caisse/calculs.js';
import { caisseHorsLigne } from '../offline/caisse_hors_ligne.js';

/*
 * Ticket de caisse — état 100 % en mémoire.
 *
 * Pendant toute la prise de commande, **aucun appel réseau** : ajouter un article,
 * ajuster une quantité, retirer une ligne, recalculer le total et la TVA se font
 * intégralement côté client. Seul `encaisser()` sort sur le réseau, et il délègue
 * à la file de synchronisation existante (écriture durable en IndexedDB avant tout
 * appel, idempotence serveur sur l'uuid) — cette logique n'est pas touchée ici.
 *
 * Tous les montants circulent en **centimes entiers**. Aucun flottant : le
 * formatage en FCFA n'intervient qu'à l'affichage.
 */
export default class extends Controller {
    static targets = [
        'onglets', 'onglet', 'grilles', 'grille',
        'lignes', 'compte', 'sousTotal', 'tva', 'tvaLibelle', 'total',
        'reglement', 'encaisser', 'message', 'imprimer', 'impression',
        'especes', 'montantRecu', 'suggestions', 'renduLigne', 'renduLibelle', 'renduMontant',
        'recu', 'recuContenu', 'recuNumero', 'recuRendu', 'recuRenduMontant',
    ];
    static values = { ticketBase: String, apercuBase: String };

    connect() {
        this.lignes = [];
        this.reglement = null;
        /** Ce que le client a tendu, en centimes. `null` = non saisi, donc compte juste. */
        this.recu = null;
        this.rendre();
        this.actualiserCatalogue();
    }

    // ---------------------------------------------------------------- Familles

    choisirFamille(event) {
        this.afficherFamille(event.currentTarget.dataset.familleId);
    }

    afficherFamille(id) {
        this.grilleTargets.forEach((g) => { g.style.display = g.dataset.familleId === id ? 'grid' : 'none'; });
        this.ongletTargets.forEach((o) => this.marquer(o, o.dataset.familleId === id));
    }

    // ---------------------------------------------------------------- Produits

    /**
     * Mode boulangerie : un appui = une unité de plus, sans aucune étape
     * intermédiaire. C'est la contrainte de vitesse de la file du matin.
     */
    appuyerProduit(event) {
        const d = event.currentTarget.dataset;

        this.ajouter({
            articleId: d.articleId,
            nom: d.nom,
            prix: parseInt(d.prix, 10),
            tva: parseInt(d.tva, 10),
        });
    }

    ajouter(article) {
        this.lignes = ajouterUnite(this.lignes, article);
        this.rendre();
    }

    incrementer(event) {
        this.lignes = ajusterQuantite(this.lignes, event.currentTarget.dataset.articleId, 1);
        this.rendre();
    }

    decrementer(event) {
        this.lignes = ajusterQuantite(this.lignes, event.currentTarget.dataset.articleId, -1);
        this.rendre();
    }

    vider() {
        this.lignes = [];
        // Le ticket repart de zéro : le montant tendu pour l'ancien n'a plus cours.
        this.oublierRecu();
        this.rendre();
    }

    // ----------------------------------------------------------------- Calculs

    /** Total TTC, en centimes. Les calculs vivent dans `caisse/calculs.js`. */
    total() {
        return totalTtc(this.lignes);
    }

    /** TVA incluse, en centimes, au taux réel de chaque ligne. */
    tva() {
        return tvaIncluse(this.lignes);
    }

    // -------------------------------------------------------------- Règlement

    choisirReglement(event) {
        this.reglement = event.currentTarget.dataset.mode;
        this.reglementTargets.forEach((r) => this.marquer(r, r === event.currentTarget));
        this.majEspeces();
        this.majEncaisser();
    }

    // ------------------------------------------------- Espèces et rendu de monnaie

    /**
     * Le pavé « reçu / monnaie » n'a de sens qu'en espèces : sur un paiement
     * mobile, le client règle le montant exact et il n'y a rien à rendre.
     */
    majEspeces() {
        const especes = this.especes;

        this.especesTarget.classList.toggle('hidden', !especes);

        if (!especes) {
            this.oublierRecu();

            return;
        }

        this.rendreSuggestions();
        this.rendreRendu();
    }

    get especes() {
        return 'ESPECES' === this.reglement;
    }

    /** Saisie libre : la caissière tape ce que le client lui a tendu, en FCFA. */
    saisirRecu(event) {
        this.recu = lireMontantFcfa(event.currentTarget.value);
        this.rendreRendu();
        this.majEncaisser();
    }

    /** Coupure proposée : un appui remplit le champ, la monnaie s'affiche aussitôt. */
    choisirCoupure(event) {
        const montant = parseInt(event.currentTarget.dataset.montant, 10);

        this.recu = montant;
        this.montantRecuTarget.value = String(Math.round(montant / 100));
        this.rendreRendu();
        this.majEncaisser();
    }

    oublierRecu() {
        this.recu = null;
        if (this.hasMontantRecuTarget) {
            this.montantRecuTarget.value = '';
        }
        if (this.hasRenduLigneTarget) {
            this.renduLigneTarget.classList.add('hidden');
        }
    }

    /**
     * Monnaie à rendre, calculée **ici et sans réseau**. C'est le geste suivant de
     * la caissière : elle ne peut pas attendre la réponse du serveur, et hors
     * ligne cette réponse n'arrivera qu'au retour du réseau. Le serveur recalcule
     * le même montant à la validation — l'écran ne fait foi pour personne, il
     * renseigne la caissière au moment où elle ouvre le tiroir.
     */
    rendreRendu() {
        if (null === this.recu) {
            this.renduLigneTarget.classList.add('hidden');

            return;
        }

        const total = this.total();
        const manque = manquant(this.recu, total);

        this.renduLigneTarget.classList.remove('hidden');
        this.renduLibelleTarget.textContent = manque > 0 ? 'Manque' : 'Monnaie à rendre';
        this.renduMontantTarget.textContent = this.fcfa(manque > 0 ? manque : renduMonnaie(this.recu, total));
        // Rouge tant que le compte n'y est pas : l'encaissement est bloqué, il
        // faut que ça se voie sans lire le libellé.
        this.renduMontantTarget.classList.toggle('text-red-700', manque > 0);
        this.renduMontantTarget.classList.toggle('text-amber-800', 0 === manque);
    }

    rendreSuggestions() {
        this.suggestionsTarget.innerHTML = suggestionsEspeces(this.total()).map((montant) => `
            <button type="button" data-action="ticket#choisirCoupure" data-montant="${montant}"
                    class="rounded-[10px] border border-amber-300 bg-white px-1 py-2 text-sm font-semibold tabular-nums text-amber-900 hover:bg-amber-100">
                ${montant === this.total() ? 'Juste' : Math.round(montant / 100).toLocaleString('fr-FR').replace(/ | /g, ' ')}
            </button>
        `).join('');
    }

    // ------------------------------------------------------------ Encaissement

    /**
     * Seul point de sortie réseau de l'écran. La logique d'encaissement (file
     * durable, idempotence, reprise hors ligne) est celle déjà en place.
     */
    async encaisser() {
        if (this.lignes.length === 0 || !this.reglement) {
            return;
        }

        const total = this.total();

        // Le règlement transmis est ce que le client a **réellement tendu**, pas le
        // total : c'est de cet écart que le serveur déduit le rendu, et c'est ce
        // montant-là que le Z retranche pour retrouver les espèces nettes en tiroir.
        // Champ laissé vide = compte juste, la vitesse d'origine est préservée.
        const encaisse = this.especes && null !== this.recu ? this.recu : total;
        if (encaisse < total) {
            return;
        }
        const rendu = renduMonnaie(encaisse, total);

        const uuid = this.genererUuid();
        const charge = {
            uuid,
            mode: 'BOULANGERIE',
            lignes: this.lignes.map((l) => ({
                articleId: l.articleId,
                quantite: l.quantite,
                commentaire: '',
            })),
            reglements: [{ mode: this.reglement, montant: encaisse }],
        };

        this.encaisserTarget.disabled = true;

        // 1. Durabilité avant tout appel réseau.
        try {
            await this.horsLigne.file.enfiler(uuid, charge);
        } catch (e) {
            this.encaisserTarget.disabled = false;
            this.notifier('Enregistrement impossible — appelez le gérant', true);

            return;
        }

        // 2. La vente est acquise : l'écran se libère immédiatement.
        const imprimer = this.hasImprimerTarget ? this.imprimerTarget.checked : false;
        this.lignes = [];
        this.reglement = null;
        this.reglementTargets.forEach((r) => this.marquer(r, false));
        this.oublierRecu();
        this.especesTarget.classList.add('hidden');
        this.rendre();
        this.encaisserTarget.disabled = false;

        // 3. Transmission, immédiate si le réseau est là.
        await this.horsLigne.synchroniser();

        if (await this.venteTransmise(uuid)) {
            // Le reçu s'affiche à l'écran, au format qui sortira de l'imprimante.
            await this.afficherRecu(uuid, rendu);
            if (imprimer) {
                this.imprimerTicket(uuid);
            }
        } else {
            // Hors ligne : pas de numéro de ticket tant que le serveur n'a pas
            // répondu, donc rien à afficher — on le dit franchement. La monnaie,
            // elle, se rend maintenant : elle est rappelée dans le message.
            this.notifier(
                rendu > 0
                    ? `Enregistrée hors ligne — rendre ${this.fcfa(rendu)}`
                    : 'Enregistrée — reçu disponible au retour du réseau',
                false,
            );
        }
    }

    /**
     * Charge le fragment 80 mm de la vente et l'affiche. C'est le seul appel
     * réseau *après* l'encaissement ; la vente est déjà enregistrée, un échec
     * d'affichage ne remet donc rien en cause.
     */
    async afficherRecu(uuid, rendu = 0) {
        // La monnaie est répétée en grand au-dessus du fragment : c'est le geste
        // qui suit, la caissière ne doit pas avoir à la relire en corps 9 sur les
        // 80 mm du reçu.
        this.recuRenduTarget.classList.toggle('hidden', rendu <= 0);
        this.recuRenduMontantTarget.textContent = this.fcfa(rendu);

        try {
            const reponse = await fetch(this.apercuBaseValue.replace('__UUID__', uuid), {
                credentials: 'same-origin',
            });
            if (!reponse.ok) {
                throw new Error(String(reponse.status));
            }

            this.recuContenuTarget.innerHTML = await reponse.text();
            this.recuNumeroTarget.textContent = this.numeroAffiche();
            this.recuTarget.classList.remove('hidden');
            this.uuidRecu = uuid;
        } catch {
            // L'aperçu est un confort, pas une garantie : on n'immobilise pas la
            // caisse s'il échoue.
            this.notifier('Vente encaissée', false);
        }
    }

    /** Numéro lu dans le fragment, pour l'afficher en tête du panneau. */
    numeroAffiche() {
        const texte = this.recuContenuTarget.textContent ?? '';
        const trouve = texte.match(/Ticket\s*:\s*(\S+)/);

        return trouve ? `n° ${trouve[1]}` : '';
    }

    imprimerRecu() {
        if (this.uuidRecu) {
            this.imprimerTicket(this.uuidRecu);
        }
    }

    fermerRecu() {
        this.recuTarget.classList.add('hidden');
        this.recuContenuTarget.innerHTML = '';
        this.uuidRecu = null;
    }

    async venteTransmise(uuid) {
        const entrees = await this.horsLigne.depot.toutes();

        return !entrees.some((entree) => entree.uuid === uuid);
    }

    imprimerTicket(uuid) {
        if (this.hasImpressionTarget) {
            this.impressionTarget.src = `${this.ticketBaseValue.replace('__UUID__', uuid)}?auto=1`;
        }
    }

    genererUuid() {
        if (globalThis.crypto?.randomUUID) {
            return globalThis.crypto.randomUUID();
        }

        const octets = new Uint8Array(16);
        globalThis.crypto.getRandomValues(octets);
        octets[6] = (octets[6] & 0x0f) | 0x40;
        octets[8] = (octets[8] & 0x3f) | 0x80;
        const hex = [...octets].map((o) => o.toString(16).padStart(2, '0')).join('');

        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    // ------------------------------------------------------ Catalogue hors ligne

    get horsLigne() {
        return (this._horsLigne ??= caisseHorsLigne());
    }

    async actualiserCatalogue() {
        const memorise = await this.horsLigne.catalogueMemorise();
        if (memorise?.familles?.length) {
            this.rendreCatalogue(memorise);
        }

        const frais = await this.horsLigne.rafraichirCatalogue();
        if (frais?.familles?.length) {
            this.rendreCatalogue(frais);
        }
    }

    rendreCatalogue(catalogue) {
        if (!this.hasOngletsTarget || !this.hasGrillesTarget) {
            return;
        }

        const active = this.ongletTargets.find((o) => o.hasAttribute('data-actif'))?.dataset.familleId
            ?? String(catalogue.familles[0].id);

        this.ongletsTarget.innerHTML = catalogue.familles.map((famille) => `
            <button type="button" data-action="ticket#choisirFamille"
                    data-famille-id="${famille.id}" data-ticket-target="onglet"
                    class="onglet shrink-0 rounded-[10px] px-4 py-2 text-sm text-stone-600 hover:bg-stone-100">
                ${this.esc(famille.nom)}
            </button>
        `).join('');

        this.grillesTarget.innerHTML = catalogue.familles.map((famille, index) => {
            const teinte = this.teinte(index);

            return `
                <div data-ticket-target="grille" data-famille-id="${famille.id}"
                     style="display: none; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                    ${famille.articles.map((article) => `
                        <button type="button" data-action="ticket#appuyerProduit"
                                data-article-id="${article.id}" data-nom="${this.esc(article.nom)}"
                                data-prix="${article.prix}" data-tva="${article.tva}"
                                class="flex flex-col overflow-hidden rounded-[12px] text-left active:scale-[0.98] transition"
                                style="min-height: 88px; background: ${teinte.fond}; color: ${teinte.texte};">
                            ${this.vignette(article.image)}
                            <span class="flex flex-1 flex-col justify-between p-3">
                                <span class="text-[15px] font-medium leading-snug">${this.esc(article.nom)}</span>
                                <span class="text-sm" style="opacity: .72;">${this.fcfa(article.prix)}</span>
                            </span>
                        </button>
                    `).join('')}
                </div>
            `;
        }).join('');

        requestAnimationFrame(() => {
            const existe = this.ongletTargets.some((o) => o.dataset.familleId === active);
            this.afficherFamille(existe ? active : String(catalogue.familles[0].id));
        });
    }

    /**
     * Photo de la touche, ou rien.
     *
     * ⚠ Ce balisage double celui de `caisse/index.html.twig` — comme la palette
     * juste en dessous, et pour la même raison : le premier affichage vient de
     * Twig, les suivants d'IndexedDB. Une touche qui changerait d'allure au
     * rechargement, ou hors ligne, serait déroutante au comptoir.
     *
     * Le libellé n'est **jamais** posé sur la photo : son contraste dépendrait de
     * l'image téléversée, donc de personne. Il reste sur l'aplat de couleur.
     *
     * Un fichier disparu retire l'image et la touche retombe sur son aplat : une
     * icône d'image cassée en pleine grille de caisse ne rend service à personne.
     */
    vignette(image) {
        if (!image) {
            return '';
        }

        return `<img src="${this.esc(image)}" alt="" loading="lazy" onerror="this.remove()"
                     class="h-20 w-full shrink-0 object-cover">`;
    }

    /**
     * Teintes par famille, avec un texte foncé de la même teinte : jamais de noir
     * pur sur fond coloré. Palette fermée plutôt que couleur libre du catalogue —
     * c'est ce qui garantit le contraste, qui doit tenir en plein jour.
     *
     * Palette **chaude**, accordée à l'ambre du reste de l'interface : ni bleu ni
     * lavande, qui refroidissaient l'écran.
     *
     * ⚠ Ces valeurs sont dupliquées dans templates/caisse/index.html.twig
     * (variable `teintes`) : le rendu Twig du premier affichage et le rendu depuis
     * IndexedDB doivent être identiques. Modifier les deux ensemble.
     */
    teinte(index) {
        const palette = [
            { fond: '#fce3b4', texte: '#7a4708' }, // pain doré
            { fond: '#fbd7bd', texte: '#8a4321' }, // terracotta
            { fond: '#f9d3dd', texte: '#7d3350' }, // framboise
            { fond: '#e6edc4', texte: '#4d5a17' }, // olive
            { fond: '#f7d2ca', texte: '#8c3a2a' }, // brique
            { fond: '#f0e3c8', texte: '#6b5320' }, // blé
            { fond: '#eeddf0', texte: '#632f6e' }, // prune
            { fond: '#dcebd9', texte: '#3b5c34' }, // sauge chaude
        ];

        return palette[index % palette.length];
    }

    // ------------------------------------------------------------------ Rendu

    rendre() {
        this.rendreLignes();
        this.rendreTotaux();
        // Le total vient peut-être de changer : les coupures proposées et la
        // monnaie annoncée doivent suivre, sinon l'écran affiche un rendu calculé
        // sur un ticket qui n'existe plus.
        if (this.especes) {
            this.rendreSuggestions();
            this.rendreRendu();
        }
        this.majEncaisser();
    }

    rendreLignes() {
        if (this.lignes.length === 0) {
            this.lignesTarget.innerHTML =
                '<p class="px-5 py-12 text-center text-sm text-stone-400">Touchez un produit pour commencer</p>';
        } else {
            // Ambre sur les séparateurs et les touches +/− : la colonne du ticket
            // doit rester dans la même famille de couleurs que le reste de l'écran.
            this.lignesTarget.innerHTML = this.lignes.map((l) => `
                <div class="flex items-center gap-3 px-5 py-3 border-b border-amber-100">
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px] font-medium text-stone-800 truncate">${this.esc(l.nom)}</div>
                        <div class="text-sm text-stone-500">${this.fcfa(l.prix)}</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" data-action="ticket#decrementer" data-article-id="${l.articleId}"
                                aria-label="Retirer un ${this.esc(l.nom)}"
                                class="h-10 w-10 rounded-[10px] border border-amber-300 text-lg text-amber-800 hover:bg-amber-100">−</button>
                        <span class="w-9 text-center text-[15px] font-semibold tabular-nums">${l.quantite}</span>
                        <button type="button" data-action="ticket#incrementer" data-article-id="${l.articleId}"
                                aria-label="Ajouter un ${this.esc(l.nom)}"
                                class="h-10 w-10 rounded-[10px] border border-amber-300 text-lg text-amber-800 hover:bg-amber-100">+</button>
                    </div>
                    <div class="w-24 text-right text-[15px] font-semibold tabular-nums text-stone-900">
                        ${this.fcfa(l.prix * l.quantite)}
                    </div>
                </div>
            `).join('');
        }

        const articles = this.lignes.reduce((somme, l) => somme + l.quantite, 0);
        this.compteTarget.textContent = articles === 0
            ? ''
            : `${articles} article${articles > 1 ? 's' : ''}`;
    }

    rendreTotaux() {
        const total = this.total();

        this.sousTotalTarget.textContent = this.fcfa(total);
        this.tvaTarget.textContent = this.fcfa(this.tva());
        this.totalTarget.textContent = this.fcfa(total);

        // Le libellé dit la vérité du ticket : exonéré, un taux, ou plusieurs.
        this.tvaLibelleTarget.textContent = libelleTva(this.lignes);
    }

    majEncaisser() {
        // Un reçu inférieur au total bloque l'encaissement : le serveur le
        // refuserait de toute façon (« Paiement insuffisant »), autant le dire
        // avant que la caissière n'appuie.
        const insuffisant = this.especes && null !== this.recu && this.recu < this.total();

        this.encaisserTarget.disabled = this.lignes.length === 0 || !this.reglement || insuffisant;
    }

    // -------------------------------------------------------------- Utilitaires

    marquer(element, actif) {
        if (actif) {
            element.setAttribute('data-actif', '');
        } else {
            element.removeAttribute('data-actif');
        }
    }

    notifier(message, erreur) {
        const zone = this.messageTarget;
        zone.textContent = message;
        // z-40 : au-dessus du panneau de reçu (z-30), sinon un message de repli
        // passerait derrière lui sans que personne ne le voie.
        zone.className = `pointer-events-none fixed bottom-6 left-1/2 z-40 -translate-x-1/2 rounded-[10px] px-5 py-3 text-sm ${
            erreur ? 'bg-red-600 text-white' : 'bg-stone-800 text-white'
        }`;

        clearTimeout(this.timer);
        this.timer = setTimeout(() => { zone.className = 'hidden'; }, 2500);
    }

    /** Centimes → FCFA entiers, séparateur de milliers français. */
    fcfa(centimes) {
        return formaterFcfa(centimes);
    }

    esc(texte) {
        return String(texte ?? '').replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }
}
