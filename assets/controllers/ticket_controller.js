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
import { EFFACER, MONNAIE, PRIX, TOTAL, pos } from '../js/pos-agent.js';

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
/**
 * Durée d'affichage de la monnaie sur l'afficheur client, avant retour au repos.
 * Assez long pour compter les billets devant le client, assez court pour que le
 * suivant ne lise pas ce qu'on a rendu à son voisin.
 */
const DUREE_MONNAIE = 15000;

export default class extends Controller {
    static targets = [
        'onglets', 'onglet', 'grilles', 'grille',
        'lignes', 'compte', 'sousTotal', 'tva', 'tvaLibelle', 'total',
        'reglement', 'encaisser', 'message', 'imprimer', 'impression',
        'especes', 'montantRecu', 'suggestions', 'renduLigne', 'renduLibelle', 'renduMontant',
        'recu', 'recuContenu', 'recuNumero', 'recuRendu', 'recuRenduMontant',
        'actionsRecu', 'annulation', 'motif', 'motifLibre', 'confirmerAnnulation',
    ];
    static values = { ticketBase: String, apercuBase: String, annulerBase: String, materielBase: String };

    connect() {
        this.lignes = [];
        this.reglement = null;
        /** Ce que le client a tendu, en centimes. `null` = non saisi, donc compte juste. */
        this.recu = null;
        /** Motif d'annulation en cours de choix. `null` = rien de retenu. */
        this.motif = null;
        // `rendre()` remet du même coup l'afficheur client au repos : il garde
        // sinon le dernier montant reçu, et rouvrir la caisse le laisserait sur le
        // total du client de la veille.
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
        // Choisir un règlement, c'est ouvrir l'encaissement : à partir d'ici le
        // client ne suit plus l'ajout des articles, il regarde ce qu'il doit. Le
        // mode « total » le dit à l'afficheur, qui le met en avant autrement.
        this.majAfficheur();
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

        // La monnaie part à l'afficheur client dans tous les cas, hors ligne
        // compris : elle se rend maintenant, au comptoir, et ne dépend d'aucune
        // réponse du serveur.
        this.annoncerMonnaie(rendu);

        if (await this.venteTransmise(uuid)) {
            // Le reçu s'affiche à l'écran, au format qui sortira de l'imprimante.
            await this.afficherRecu(uuid, rendu);
            if (imprimer) {
                await this.imprimerMateriel(uuid, true);
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
     * Charge le fragment 58 mm de la vente et l'affiche. C'est le seul appel
     * réseau *après* l'encaissement ; la vente est déjà enregistrée, un échec
     * d'affichage ne remet donc rien en cause.
     */
    async afficherRecu(uuid, rendu = 0) {
        // La monnaie est répétée en grand au-dessus du fragment : c'est le geste
        // qui suit, la caissière ne doit pas avoir à la relire en corps 9 sur les
        // 58 mm du reçu.
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
            // Réimpression demandée à la main : le tiroir ne se rouvre pas, il
            // s'est déjà ouvert quand l'argent est entré.
            this.imprimerMateriel(this.uuidRecu, false);
        }
    }

    fermerRecu() {
        // Le panneau se rouvrira sur la vente suivante : il doit repartir de ses
        // deux boutons, jamais du choix de motif laissé en plan.
        this.fermerAnnulation();
        this.recuTarget.classList.add('hidden');
        this.recuContenuTarget.innerHTML = '';
        this.uuidRecu = null;
        // Une nouvelle vente commence : l'afficheur ne doit plus montrer la
        // monnaie du client précédent.
        this.majAfficheur();
    }

    // ------------------------------------------- Annulation du dernier ticket

    /**
     * Le caissier n'annule que le ticket qu'il vient d'encaisser — le serveur le
     * vérifie (`VenteVoter`), l'écran ne fait que l'offrir là où c'est utile.
     *
     * Jamais en un seul appui : la vente n'est pas supprimée mais son statut ne se
     * reprend pas, et l'annulation part au journal d'audit **et** en notification
     * à la dirigeante. Choisir un motif tient lieu de confirmation.
     */
    ouvrirAnnulation() {
        this.motif = null;
        this.motifTargets.forEach((m) => this.marquer(m, false));
        this.motifLibreTarget.value = '';
        this.actionsRecuTarget.classList.add('hidden');
        this.annulationTarget.classList.remove('hidden');
        this.majConfirmerAnnulation();
    }

    fermerAnnulation() {
        this.annulationTarget.classList.add('hidden');
        this.actionsRecuTarget.classList.remove('hidden');
    }

    choisirMotif(event) {
        this.motif = event.currentTarget.dataset.motif;
        this.motifTargets.forEach((m) => this.marquer(m, m === event.currentTarget));
        // Les deux saisies diraient deux motifs différents : la dernière l'emporte.
        this.motifLibreTarget.value = '';
        this.majConfirmerAnnulation();
    }

    saisirMotif(event) {
        this.motif = event.currentTarget.value.trim() || null;
        this.motifTargets.forEach((m) => this.marquer(m, false));
        this.majConfirmerAnnulation();
    }

    majConfirmerAnnulation() {
        this.confirmerAnnulationTarget.disabled = null === this.motif;
    }

    async confirmerAnnulation() {
        if (!this.uuidRecu || null === this.motif) {
            return;
        }

        this.confirmerAnnulationTarget.disabled = true;

        let reponse;
        try {
            reponse = await fetch(this.annulerBaseValue.replace('__UUID__', this.uuidRecu), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ motif: this.motif }),
            });
        } catch {
            // Une annulation ne part **pas** dans la file de synchronisation.
            // Rejouée au retour du réseau, elle porterait sur un ticket que
            // d'autres ventes auront entre-temps dépassé : le serveur la
            // refuserait, et la caissière croirait son ticket annulé depuis une
            // heure. Mieux vaut le dire au moment où elle appuie.
            this.confirmerAnnulationTarget.disabled = false;
            this.notifier('Annulation impossible hors ligne — appelez le gérant', true);

            return;
        }

        if (!reponse.ok) {
            // Refus du serveur : ticket dépassé par un autre, caisse clôturée,
            // vente déjà annulée. Son message est plus précis que le nôtre.
            const erreur = await reponse.json().catch(() => ({}));

            this.confirmerAnnulationTarget.disabled = false;
            this.notifier(erreur.erreur ?? 'Annulation refusée — appelez le gérant', true);

            return;
        }

        // Le stock déstocké à la vente est remonté par le serveur (mouvements
        // inverses) : il n'y a rien à rejouer ici, l'écran se contente de repartir
        // sur un ticket vierge.
        const numero = this.recuNumeroTarget.textContent.trim();
        this.fermerRecu();
        this.notifier(numero ? `Ticket ${numero} annulé` : 'Ticket annulé', false);
    }

    async venteTransmise(uuid) {
        const entrees = await this.horsLigne.depot.toutes();

        return !entrees.some((entree) => entree.uuid === uuid);
    }

    /**
     * Impression du ticket, par l'agent matériel s'il est là, par le navigateur
     * sinon.
     *
     * **Jamais les deux** : sur un poste équipé, laisser aussi partir l'iframe
     * `window.print()` sortirait deux tickets par vente — l'un par la tête
     * thermique, l'autre par l'imprimante par défaut de Windows. Le repli n'est
     * donc pris que si l'agent n'a rien imprimé, ce qui couvre du même coup le
     * poste sans agent, l'agent arrêté et l'agent qui refuse.
     *
     * @param {boolean} tiroir ouvrir le tiroir-caisse. Faux en réimpression : le
     *                         tiroir s'est ouvert quand l'argent est entré.
     */
    async imprimerMateriel(uuid, tiroir) {
        if (await pos.available()) {
            const ticket = await this.chargerTicketMateriel(uuid);

            // `openDrawer` est décidé par le serveur (espèces ou non) ; l'écran ne
            // peut que le refuser, jamais l'imposer.
            if (ticket && await pos.print({ ...ticket, openDrawer: ticket.openDrawer && tiroir })) {
                return;
            }
        }

        this.imprimerTicket(uuid);
    }

    /**
     * Charge la charge utile `/print` de la vente. C'est le même objet que la clé
     * `ticket` de la réponse d'encaissement, construit par le même service côté
     * serveur — mais la file de synchronisation ne rend pas le corps des réponses
     * qu'elle transmet (elle le jette dès que le serveur a confirmé), donc on le
     * redemande ici plutôt que de toucher à ce code, qui porte la garantie
     * « aucune vente perdue, aucune vente dupliquée ».
     */
    async chargerTicketMateriel(uuid) {
        try {
            const reponse = await fetch(this.materielBaseValue.replace('__UUID__', uuid), {
                credentials: 'same-origin',
            });

            return reponse.ok ? (await reponse.json()).ticket : null;
        } catch {
            // Réseau coupé : rien à imprimer sur la tête thermique, l'appelant
            // retombe sur l'impression navigateur.
            return null;
        }
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
        this.majAfficheur();
    }

    /**
     * Afficheur client, s'il y en a un — un seul endroit décide de ce qu'il
     * montre, sinon deux appels partis de deux méthodes différentes se
     * contrediraient et le dernier arrivé gagnerait.
     *
     * Ticket vide : état de repos, et non « 0 FCFA », qui se lit comme un article
     * gratuit. Ticket en cours : le sous-total, en mode « prix ». Règlement
     * choisi : le même montant, mais en mode « total » — c'est ce que le client
     * doit sortir de sa poche, l'afficheur le met en avant autrement.
     */
    majAfficheur() {
        // Le ticket bouge : la monnaie du client précédent n'a plus à attendre
        // la fin de son délai, elle est effacée tout de suite. Sans cette
        // annulation, le compte à rebours effacerait un sous-total bien vivant.
        clearTimeout(this.timerAfficheur);

        if (this.lignes.length === 0) {
            this.afficher(0, EFFACER);

            return;
        }

        this.afficher(this.total(), this.reglement ? TOTAL : PRIX);
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

    // ------------------------------------------------------- Afficheur client

    /**
     * Envoie un montant à l'afficheur client, **sans jamais attendre**.
     *
     * Aucun `await` : c'est appelé depuis des méthodes synchrones, à chaque appui
     * sur une touche produit, et la cadence de saisie ne doit dépendre d'aucun
     * périphérique. `pos.display()` ne rejette jamais (voir `js/pos-agent.js`),
     * il n'y a donc pas de rejet à capturer — sur un poste sans agent, la ligne
     * ci-dessous ne fait rien du tout.
     *
     * Le montant part en **FCFA entiers** : l'afficheur écrit ce qu'on lui donne.
     * Troncature et non arrondi, pour dire exactement le même nombre que le
     * ticket, où le serveur applique `intdiv`.
     */
    afficher(centimes, mode) {
        pos.display(Math.trunc(centimes / 100), mode);
    }

    /**
     * Monnaie affichée au client, puis retour au repos.
     *
     * Le délai laisse le temps de compter les billets sous les yeux du client —
     * c'est là tout l'intérêt d'un afficheur — sans laisser le montant en place
     * devant la personne suivante. Toute activité sur le ticket l'interrompt
     * avant l'heure (voir `majAfficheur()`).
     */
    annoncerMonnaie(rendu) {
        clearTimeout(this.timerAfficheur);

        if (rendu <= 0) {
            this.afficher(0, EFFACER);

            return;
        }

        this.afficher(rendu, MONNAIE);
        this.timerAfficheur = setTimeout(() => this.afficher(0, EFFACER), DUREE_MONNAIE);
    }

    // -------------------------------------------------------------- Formatage

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
