import { Controller } from '@hotwired/stimulus';
import { caisseHorsLigne } from '../offline/caisse_hors_ligne.js';

/*
 * Caisse tactile ZedPOS.
 *
 * Tout l'état du ticket vit en mémoire côté client : aucun rechargement pendant
 * la prise de commande.
 *
 * L'encaissement suit un ordre non négociable :
 *   1. écriture durable de la vente en IndexedDB (avec son uuid) ;
 *   2. libération de l'écran ;
 *   3. transmission au serveur, immédiate si le réseau est là, différée sinon.
 * Le réseau n'intervient qu'à l'étape 3 : une coupure ne peut donc pas faire
 * perdre une vente, et l'idempotence de POST /api/vente empêche les doublons.
 */
export default class extends Controller {
    static targets = [
        'modeBtn', 'onglet', 'onglets', 'grille', 'grilles', 'ticket', 'total', 'reglement',
        'encaisser', 'attente', 'panneau', 'panneauNom', 'panneauQte',
        'panneauCommentaire', 'confirmation', 'imprimer', 'impression',
    ];
    static values = { mode: String, ticketBase: String };

    connect() {
        this.lignes = [];        // { cle, articleId, nom, prix, quantite, commentaire }
        this.enAttente = [];     // tickets mis de côté
        this.reglement = null;
        this.horsLigne = caisseHorsLigne();
        this.rendreTicket();

        // Le catalogue mémorisé prime sur le rendu serveur : hors ligne, la page
        // peut venir du cache du Service Worker et dater un peu.
        this.actualiserCatalogue();
    }

    // ----- Familles -----
    choisirFamille(event) {
        this.afficherFamille(event.currentTarget.dataset.familleId);
    }

    afficherFamille(id) {
        this.grilleTargets.forEach((g) => { g.style.display = g.dataset.familleId === id ? 'grid' : 'none'; });
        this.ongletTargets.forEach((o) => this.marquer(o, o.dataset.familleId === id));
    }

    // ----- Catalogue hors ligne -----

    /**
     * Reconstruit les touches produits à partir du catalogue stocké en IndexedDB.
     * Le rendu serveur reste le premier affichage ; on ne le remplace que si un
     * catalogue mémorisé existe.
     */
    async actualiserCatalogue() {
        const catalogue = await this.horsLigne.catalogueMemorise();
        if (catalogue?.familles?.length) {
            this.rendreCatalogue(catalogue);
        }

        // Puis, si le réseau répond, on rafraîchit et on rerend avec la version du jour.
        const frais = await this.horsLigne.rafraichirCatalogue();
        if (frais?.familles?.length) {
            this.rendreCatalogue(frais);
        }
    }

    rendreCatalogue(catalogue) {
        if (!this.hasOngletsTarget || !this.hasGrillesTarget) {
            return;
        }

        const familleActive = this.ongletTargets.find((o) => o.hasAttribute('data-actif'))?.dataset.familleId
            ?? String(catalogue.familles[0].id);

        this.ongletsTarget.innerHTML = catalogue.familles.map((famille) => `
            <button type="button"
                    class="onglet-famille rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                    data-action="caisse#choisirFamille" data-famille-id="${famille.id}"
                    data-caisse-target="onglet">${this.esc(famille.nom)}</button>
        `).join('');

        this.grillesTarget.innerHTML = catalogue.familles.map((famille) => `
            <div data-caisse-target="grille" data-famille-id="${famille.id}" class="gap-2"
                 style="display: none; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));">
                ${famille.articles.map((article) => `
                    <button type="button"
                            class="touche-produit flex flex-col justify-between rounded-xl p-2 text-left text-white font-semibold active:scale-95 transition"
                            style="min-height: 92px; background: ${this.esc(article.couleur || '#64748b')};"
                            data-action="caisse#appuyerProduit"
                            data-article-id="${article.id}"
                            data-nom="${this.esc(article.nom)}"
                            data-prix="${article.prix}"
                            data-tva="${article.tva}">
                        <span class="text-sm leading-tight">${this.esc(article.nom)}</span>
                        <span class="text-sm font-bold">${this.fcfa(article.prix)}</span>
                    </button>
                `).join('')}
            </div>
        `).join('');

        // Stimulus recâble les cibles au prochain tick : on rétablit ensuite la sélection.
        requestAnimationFrame(() => {
            const existe = this.ongletTargets.some((o) => o.dataset.familleId === familleActive);
            this.afficherFamille(existe ? familleActive : String(catalogue.familles[0].id));
        });
    }

    // ----- Mode de vente -----
    changerMode(event) {
        this.modeValue = event.currentTarget.dataset.mode;
        this.modeBtnTargets.forEach((b) => this.marquer(b, b.dataset.mode === this.modeValue));
    }

    // ----- Produits -----
    appuyerProduit(event) {
        const d = event.currentTarget.dataset;
        const article = { articleId: d.articleId, nom: d.nom, prix: parseInt(d.prix, 10) };

        if (this.modeValue === 'FASTFOOD') {
            this.ouvrirPanneau(article);
        } else {
            this.ajouter(article, 1, '');
        }
    }

    ajouter(article, quantite, commentaire) {
        const cle = article.articleId + '|' + commentaire;
        const existante = this.lignes.find((l) => l.cle === cle);
        if (existante) {
            existante.quantite += quantite;
        } else {
            this.lignes.push({ cle, ...article, quantite, commentaire });
        }
        this.rendreTicket();
    }

    // ----- Panneau variantes (fast-food) -----
    ouvrirPanneau(article) {
        this.articleEnCours = article;
        this.panneauQuantite = 1;
        this.panneauNomTarget.textContent = article.nom;
        this.panneauQteTarget.textContent = '1';
        this.panneauCommentaireTarget.value = '';
        this.panneauTarget.classList.remove('hidden');
    }

    fermerPanneau() {
        this.panneauTarget.classList.add('hidden');
    }

    panneauPlus() {
        this.panneauQuantite += 1;
        this.panneauQteTarget.textContent = String(this.panneauQuantite);
    }

    panneauMoins() {
        if (this.panneauQuantite > 1) {
            this.panneauQuantite -= 1;
            this.panneauQteTarget.textContent = String(this.panneauQuantite);
        }
    }

    ajouterChip(event) {
        const texte = event.currentTarget.dataset.texte;
        const champ = this.panneauCommentaireTarget;
        champ.value = champ.value ? `${champ.value}, ${texte}` : texte;
    }

    confirmerPanneau() {
        this.ajouter(this.articleEnCours, this.panneauQuantite, this.panneauCommentaireTarget.value.trim());
        this.fermerPanneau();
    }

    // ----- Lignes du ticket -----
    incrementer(event) {
        const ligne = this.ligneDe(event);
        if (ligne) { ligne.quantite += 1; this.rendreTicket(); }
    }

    decrementer(event) {
        const ligne = this.ligneDe(event);
        if (!ligne) { return; }
        ligne.quantite -= 1;
        if (ligne.quantite <= 0) { this.lignes = this.lignes.filter((l) => l !== ligne); }
        this.rendreTicket();
    }

    supprimer(event) {
        const cle = event.currentTarget.dataset.cle;
        this.lignes = this.lignes.filter((l) => l.cle !== cle);
        this.rendreTicket();
    }

    vider() {
        this.lignes = [];
        this.rendreTicket();
    }

    ligneDe(event) {
        return this.lignes.find((l) => l.cle === event.currentTarget.dataset.cle);
    }

    total() {
        return this.lignes.reduce((somme, l) => somme + l.quantite * l.prix, 0);
    }

    // ----- Règlement -----
    choisirReglement(event) {
        this.reglement = event.currentTarget.dataset.mode;
        this.reglementTargets.forEach((r) => this.marquer(r, r === event.currentTarget));
    }

    async encaisser() {
        if (this.lignes.length === 0) { return; }
        if (!this.reglement) {
            this.notifier('Choisissez un mode de règlement', true);
            return;
        }

        const uuid = this.genererUuid();
        const charge = {
            uuid,
            mode: this.modeValue,
            lignes: this.lignes.map((l) => ({
                articleId: l.articleId,
                quantite: l.quantite,
                commentaire: l.commentaire,
            })),
            // Règlement du montant exact : la caisse ne saisit pas encore le
            // montant remis, il n'y a donc jamais de rendu à cette étape.
            reglements: [{ mode: this.reglement, montant: this.total() }],
        };

        this.encaisserTarget.disabled = true;

        // --- 1. Durabilité AVANT tout appel réseau -------------------------------
        try {
            await this.horsLigne.file.enfiler(uuid, charge);
        } catch (e) {
            // Rien n'a été enregistré : on garde le ticket à l'écran pour que le
            // caissier puisse réessayer. C'est le seul cas où l'on refuse la vente.
            this.encaisserTarget.disabled = false;
            this.notifier("Enregistrement local impossible — ne validez pas, appelez le gérant", true);

            return;
        }

        // --- 2. La vente est acquise : on libère l'écran -------------------------
        const imprimer = this.imprimerTarget.checked;
        this.reinitialiser();
        this.encaisserTarget.disabled = false;

        // --- 3. Transmission, immédiate ou différée ------------------------------
        await this.horsLigne.synchroniser();

        if (await this.venteTransmise(uuid)) {
            this.notifier('Vente encaissée', false);
            if (imprimer) {
                this.imprimerTicket(uuid);
            }
        } else {
            this.notifier('Vente enregistrée — en attente de réseau', false);
        }
    }

    /** La vente a-t-elle quitté la file, c'est-à-dire été confirmée par le serveur ? */
    async venteTransmise(uuid) {
        const entrees = await this.horsLigne.depot.toutes();

        return !entrees.some((entree) => entree.uuid === uuid);
    }

    genererUuid() {
        if (globalThis.crypto?.randomUUID) {
            return globalThis.crypto.randomUUID();
        }

        // Repli pour les contextes non sécurisés : uuid v4 à partir de valeurs aléatoires.
        const octets = new Uint8Array(16);
        globalThis.crypto.getRandomValues(octets);
        octets[6] = (octets[6] & 0x0f) | 0x40;
        octets[8] = (octets[8] & 0x3f) | 0x80;
        const hex = [...octets].map((o) => o.toString(16).padStart(2, '0')).join('');

        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    reinitialiser() {
        this.lignes = [];
        this.reglement = null;
        this.reglementTargets.forEach((r) => this.marquer(r, false));
        this.rendreTicket();
    }

    // Charge le ticket (en mode auto-impression) dans l'iframe caché : celui-ci
    // déclenche window.print() une fois rendu — sans quitter l'écran de caisse.
    imprimerTicket(uuid) {
        this.impressionTarget.src = this.ticketBaseValue.replace('__UUID__', uuid) + '?auto=1';
    }

    // ----- Mise en attente -----
    mettreEnAttente() {
        if (this.lignes.length === 0) { return; }
        this.enAttente.push(this.lignes);
        this.lignes = [];
        this.reglement = null;
        this.reglementTargets.forEach((r) => this.marquer(r, false));
        this.rendreAttente();
        this.rendreTicket();
    }

    reprendre(event) {
        const index = parseInt(event.currentTarget.dataset.index, 10);
        const ticket = this.enAttente.splice(index, 1)[0];
        if (!ticket) { return; }
        if (this.lignes.length > 0) { this.enAttente.push(this.lignes); }
        this.lignes = ticket;
        this.rendreAttente();
        this.rendreTicket();
    }

    // ----- Rendu -----
    rendreTicket() {
        if (this.lignes.length === 0) {
            this.ticketTarget.innerHTML = '<div class="p-8 text-center text-slate-400">Ticket vide — touchez un produit</div>';
        } else {
            this.ticketTarget.innerHTML = this.lignes.map((l) => `
                <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-100">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-slate-800 truncate">${this.esc(l.nom)}</div>
                        ${l.commentaire ? `<div class="text-xs text-slate-500 truncate">${this.esc(l.commentaire)}</div>` : ''}
                        <div class="text-xs text-slate-400">${this.fcfa(l.prix)}</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" class="h-11 w-11 rounded-lg bg-slate-100 text-xl font-bold hover:bg-slate-200" data-action="caisse#decrementer" data-cle="${l.cle}">−</button>
                        <span class="w-8 text-center text-lg font-bold tabular-nums">${l.quantite}</span>
                        <button type="button" class="h-11 w-11 rounded-lg bg-slate-100 text-xl font-bold hover:bg-slate-200" data-action="caisse#incrementer" data-cle="${l.cle}">+</button>
                    </div>
                    <div class="w-24 text-right font-bold tabular-nums text-slate-900">${this.fcfa(l.prix * l.quantite)}</div>
                    <button type="button" class="h-11 w-9 text-red-400 hover:text-red-600 text-lg" data-action="caisse#supprimer" data-cle="${l.cle}">✕</button>
                </div>
            `).join('');
        }
        this.totalTarget.textContent = this.fcfa(this.total());
    }

    rendreAttente() {
        this.attenteTarget.innerHTML = this.enAttente.map((ticket, index) => {
            const total = ticket.reduce((s, l) => s + l.quantite * l.prix, 0);
            const articles = ticket.reduce((s, l) => s + l.quantite, 0);
            return `<button type="button" class="shrink-0 rounded-lg bg-slate-700 hover:bg-slate-600 px-3 py-1.5 text-xs font-semibold" data-action="caisse#reprendre" data-index="${index}">En attente · ${articles} art. · ${this.fcfa(total)}</button>`;
        }).join('');
    }

    // ----- Utilitaires -----
    marquer(element, actif) {
        if (actif) { element.setAttribute('data-actif', ''); } else { element.removeAttribute('data-actif'); }
    }

    notifier(message, erreur) {
        const banniere = this.confirmationTarget;
        banniere.textContent = message;
        banniere.classList.remove('hidden', 'bg-slate-900', 'bg-red-600');
        banniere.classList.add(erreur ? 'bg-red-600' : 'bg-slate-900');
        clearTimeout(this.timerNotif);
        this.timerNotif = setTimeout(() => banniere.classList.add('hidden'), 2500);
    }

    fcfa(centimes) {
        return Math.round(centimes / 100).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' FCFA';
    }

    esc(texte) {
        return String(texte || '').replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }
}
