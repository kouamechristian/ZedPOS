import { Controller } from '@hotwired/stimulus';

/*
 * Caisse tactile ZedPOS.
 * Tout l'état du ticket vit en mémoire côté client : aucun rechargement pendant
 * la prise de commande. Seul l'encaissement effectue un appel réseau.
 */
export default class extends Controller {
    static targets = [
        'modeBtn', 'onglet', 'grille', 'ticket', 'total', 'reglement',
        'encaisser', 'attente', 'panneau', 'panneauNom', 'panneauQte',
        'panneauCommentaire', 'confirmation', 'imprimer', 'impression',
    ];
    static values = { mode: String, encaisserUrl: String, ticketBase: String, token: String };

    connect() {
        this.lignes = [];        // { cle, articleId, nom, prix, quantite, commentaire }
        this.enAttente = [];     // tickets mis de côté
        this.reglement = null;
        this.rendreTicket();
    }

    // ----- Familles -----
    choisirFamille(event) {
        const id = event.currentTarget.dataset.familleId;
        this.grilleTargets.forEach((g) => { g.style.display = g.dataset.familleId === id ? 'grid' : 'none'; });
        this.ongletTargets.forEach((o) => this.marquer(o, o.dataset.familleId === id));
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

        const charge = {
            mode: this.modeValue,
            reglementMode: this.reglement,
            _token: this.tokenValue,
            lignes: this.lignes.map((l) => ({
                articleId: l.articleId,
                quantite: l.quantite,
                commentaire: l.commentaire,
            })),
        };

        this.encaisserTarget.disabled = true;
        try {
            const reponse = await fetch(this.encaisserUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(charge),
            });
            const data = await reponse.json();
            if (data.ok) {
                this.notifier(`Vente ${data.numero} encaissée`, false);
                if (this.imprimerTarget.checked && data.uuid) {
                    this.imprimerTicket(data.uuid);
                }
                this.reinitialiser();
            } else {
                this.notifier(data.erreur || 'Erreur lors de l\'encaissement', true);
            }
        } catch (e) {
            this.notifier('Erreur réseau', true);
        } finally {
            this.encaisserTarget.disabled = false;
        }
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
