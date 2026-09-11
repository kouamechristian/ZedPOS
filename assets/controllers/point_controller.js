import { Controller } from '@hotwired/stimulus';
import { formaterFcfa, formaterQuantite } from '../dotation/calculs.js';
import { calculerPoint, etatEcart, justificationRequise, traitementRequis } from '../point/calculs.js';

/*
 * Écran du point d'un stand, au doigt.
 *
 * Toucher une case (invendus, pertes, espèces remises) la rend active ; le pavé y
 * écrit. Chaque appui recalcule la ligne — vendu, montant — puis le bloc du bas :
 * attendu, rémunération, net, écart en couleur. Le calcul est celui du serveur
 * (`assets/point/calculs.js`), qui refait tout à l'enregistrement.
 *
 * Changer de période en cours de frappe ne perd rien : la saisie voyage dans
 * l'adresse du raccourci choisi.
 */
const CHIFFRES_QUANTITE = 5;
const CHIFFRES_MONTANT = 9;

export default class extends Controller {
    static targets = ['formulaire', 'cellule', 'ligne', 'vendue', 'montant', 'actif', 'totalAttendu', 'totalRemuneration',
        'totalNet', 'ecart', 'blocEcart', 'justification', 'commentaire', 'valider', 'message',
        'traitement', 'manquant', 'nomVendeur', 'vendeur'];

    static values = { produits: Array, mode: String, taux: Number, seuil: Number, brouillons: Boolean };

    connect() {
        this.actif = null;
        this.modifie = false;
        this.recalculer();
    }

    activer(event) {
        this.actif = event.currentTarget;
        this.celluleTargets.forEach((cellule) => cellule.toggleAttribute('data-active', cellule === this.actif));
        this.actifTarget.textContent = this.actif.dataset.libelle ?? '';
    }

    appuyer(event) {
        if (!this.actif) {
            this.annoncer('Touchez d\'abord une case : invendus, pertes ou espèces remises.');

            return;
        }

        const max = 'remis' === this.actif.dataset.champ ? CHIFFRES_MONTANT : CHIFFRES_QUANTITE;
        const suite = `${this.actif.value}${event.currentTarget.dataset.chiffre}`.replace(/^0+(?=\d)/, '');
        if (suite.length <= max) {
            this.ecrire(suite);
        }
    }

    reculer() {
        if (this.actif) {
            this.ecrire(this.actif.value.slice(0, -1));
        }
    }

    effacer() {
        if (this.actif) {
            this.ecrire('');
        }
    }

    /** Clavier physique ou saisie directe : chiffres seuls. */
    saisir(event) {
        event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '');
        this.modifie = true;
        this.recalculer();
    }

    choisirTraitement() {
        this.modifie = true;
        this.recalculer();
    }

    ecrire(valeur) {
        this.actif.value = valeur;
        this.modifie = true;
        this.recalculer();
    }

    /**
     * Un raccourci de période (lien) ou la date libre (petit formulaire) emporte la
     * saisie en cours plutôt que de l'effacer.
     */
    changerPeriode(event) {
        if (!this.modifie) {
            return;
        }

        event.preventDefault();
        const declencheur = event.currentTarget;
        const url = new URL(declencheur.href ?? declencheur.action, window.location.href);
        if (!declencheur.href) {
            url.searchParams.set('fin', new FormData(declencheur).get('fin') ?? '');
        }

        new FormData(this.formulaireTarget).forEach((valeur, cle) => {
            if (!['_token', 'action', 'fin'].includes(cle) && '' !== valeur) {
                url.searchParams.set(cle, valeur);
            }
        });
        // Le paramètre de présence de la saisie, même si tout est vide.
        url.searchParams.set('remis', this.remis()?.value ?? '');

        if (window.Turbo) {
            window.Turbo.visit(url.toString());
        } else {
            window.location.href = url.toString();
        }
    }

    recalculer() {
        const saisie = {};
        this.celluleTargets.forEach((cellule) => {
            const produit = cellule.dataset.produit;
            if (!produit) {
                return;
            }
            saisie[produit] ??= { invendu: 0, perdu: 0 };
            saisie[produit][cellule.dataset.champ] = /^\d+$/.test(cellule.value) ? Number.parseInt(cellule.value, 10) : 0;
        });

        const champRemis = this.remis();
        const remis = champRemis && /^\d+$/.test(champRemis.value) ? Number.parseInt(champRemis.value, 10) * 100 : null;
        const point = calculerPoint(this.produitsValue, saisie, this.modeValue, this.tauxValue, remis);

        point.lignes.forEach((ligne, i) => {
            this.ligneTargets[i].toggleAttribute('data-erreur', ligne.erreur);
            this.vendueTargets[i].textContent = ligne.erreur ? 'trop' : formaterQuantite(ligne.vendue);
            this.montantTargets[i].textContent = ligne.erreur ? '—' : formaterFcfa(ligne.montant);
        });

        this.totalAttenduTarget.textContent = formaterFcfa(point.montantAttendu);
        this.totalRemunerationTarget.textContent = formaterFcfa(point.remuneration);
        this.totalNetTarget.textContent = formaterFcfa(point.net);

        const etat = etatEcart(point.ecart);
        this.blocEcartTarget.dataset.etat = etat ?? 'vide';
        this.ecartTarget.textContent = null === point.ecart
            ? 'Saisissez les espèces remises'
            : `${point.ecart > 0 ? '+' : ''}${formaterFcfa(point.ecart)}${'manquant' === etat ? ' manquant' : ('trop' === etat ? ' trop-perçu' : ' — juste')}`;

        // Manquant : dette ou perte, à choisir. Rien n'est coché d'office.
        const aTraiter = traitementRequis(point.ecart);
        const choix = this.traitementChoisi();
        if (this.hasTraitementTarget) {
            this.traitementTarget.hidden = !aTraiter;
            this.manquantTarget.textContent = aTraiter ? formaterFcfa(-point.ecart) : '';
            if (this.hasVendeurTarget && this.vendeurTarget.selectedIndex > 0) {
                this.nomVendeurTarget.textContent = this.vendeurTarget.selectedOptions[0].textContent.trim();
            }
        }

        const justifier = justificationRequise(point.ecart, this.seuilValue, aTraiter ? choix : null);
        this.justificationTarget.hidden = !justifier;
        this.commentaireTarget.required = justifier;

        let raison = '';
        if (point.erreurs > 0) {
            raison = 'Une ligne retourne plus qu\'il n\'a été confié.';
        } else if (this.brouillonsValue) {
            raison = 'Des dotations de la période sont en brouillon.';
        } else if (null === remis) {
            raison = 'Saisissez les espèces remises, même zéro.';
        } else if (aTraiter && null === choix) {
            raison = 'Il manque de l\'argent : choisissez de l\'imputer en dette ou de le passer en perte.';
        }

        this.validerTarget.disabled = '' !== raison;
        this.validerTarget.title = raison;
        this.annoncer(raison);
    }

    /** 'DETTE' | 'PERTE' | null */
    traitementChoisi() {
        if (!this.hasTraitementTarget) {
            return null;
        }

        return this.traitementTarget.querySelector('input[name="traitement"]:checked')?.value ?? null;
    }

    remis() {
        return this.celluleTargets.find((cellule) => 'remis' === cellule.dataset.champ);
    }

    annoncer(texte) {
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = texte;
        }
    }
}
