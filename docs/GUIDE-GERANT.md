# ZedPOS — Guide du gérant

Vous gérez le back-office : le catalogue, le stock, les pertes et les rapports.
Connectez-vous sur `/login` avec votre e-mail et votre mot de passe ; vous arrivez
sur le **tableau de bord**.

Le menu de gauche est votre point de départ :

```
ZedPOS  Back-office
┌────────────────────┐
│ ▸ Tableau de bord  │   CA du jour, ventes, alertes
│ ▸ Ventes           │   les 100 derniers tickets
│ ▸ Articles         │   catalogue + coût / marge
│ ▸ Stock            │   matières premières
│ ▸ Production       │   fiches techniques et marges
│ ▸ Pertes           │   saisie et synthèse
│ ▸ Clôtures         │   rapports Z et écarts de caisse
│ ▸ Utilisateurs     │   comptes
└────────────────────┘
```

Un bandeau ambre apparaît en haut dès qu'une matière passe sous son seuil.

---

## 1. Le stock

### Comment il bouge tout seul

Vous ne saisissez **pas** les sorties de stock des ventes : elles sont
automatiques. À chaque vente, ZedPOS regarde la **fiche technique** de l'article
vendu et retire les matières premières correspondantes.

> Vendre 10 baguettes retire 10 × la quantité de farine, levure, sel et eau
> inscrite sur la fiche technique de la baguette — perte de production comprise.

Pour les articles sans recette (boissons, produits revendus tels quels), ZedPOS
décrémente l'article lui-même — à condition qu'il soit marqué « suivi en stock ».

> ⚠️ **Ce réglage n'est pas encore modifiable depuis le back-office.** Il est posé
> à la création des données. Pour suivre en stock un nouvel article revendu tel
> quel, demandez à votre prestataire technique.

**Le stock peut devenir négatif.** C'est volontaire : une vente n'est jamais
bloquée à cause du stock. Un stock négatif est le signe qu'une entrée n'a pas été
saisie, ou qu'une fiche technique est fausse.

### Consulter et régler les seuils

**Stock** dans le menu → la liste des matières, celles sous leur seuil en tête.
Le bouton **Fournisseurs**, en haut de cette page, mène à leur gestion.
**Modifier** sur une ligne permet de régler :

| Champ | À quoi ça sert |
|---|---|
| Coût moyen pondéré | Prix de revient au kilo/litre — sert à valoriser les pertes et calculer vos marges |
| Stock actuel | La quantité en magasin |
| Seuil d'alerte | En dessous, le bandeau ambre s'affiche partout, y compris en caisse |
| Fournisseur | Pour savoir qui rappeler |

Réglez le seuil sur **ce qu'il vous faut pour tenir jusqu'à la prochaine livraison**,
pas sur zéro : une alerte qui arrive quand le sac est vide ne sert à rien.

### Les fiches techniques

**Production** dans le menu montre, pour chaque recette, le coût matières et la
marge. Pour modifier une recette : **Articles** → l'article → onglet
**Fiche technique** → ajoutez ou retirez une matière avec sa quantité et son
pourcentage de perte.

> Une fiche technique fausse fausse **tout** : le déstockage, le coût de revient et
> la marge. C'est le réglage le plus important du logiciel.

---

## 2. Les pertes

Tout ce qui est jeté, cassé, offert ou périmé doit être saisi, sinon cela ressort
en écart de stock inexpliqué.

**Pertes → Saisir une perte** :

1. Choisissez **une matière première OU un article** (pas les deux).
2. Indiquez la quantité perdue.
3. Choisissez le motif : casse, périmé, invendu, erreur de production, personnel, offert.
4. Ajoutez un commentaire si besoin, puis **Enregistrer la perte**.

ZedPOS **valorise automatiquement** la perte : au coût moyen pondéré pour une
matière, au coût de revient pour un article ayant une fiche technique. Le stock est
décrémenté et un mouvement de stock est écrit.

**Pertes** (sans « saisir ») affiche la **synthèse du mois** : total valorisé,
répartition par motif, top 5 des produits les plus perdus. Changez le mois avec le
sélecteur en haut.

> Regardez cette page une fois par semaine. Une hausse des invendus se corrige en
> ajustant la production ; une hausse de la casse se corrige en formant l'équipe.

---

## 3. L'inventaire

**Aujourd'hui, ZedPOS n'a pas d'écran d'inventaire.** Voici comment procéder en
attendant, et ce que cela implique.

Pour corriger un stock après comptage : **Stock** → **Modifier** sur la matière →
changez **Stock actuel** → **Enregistrer**.

> ⚠️ **Limite importante à connaître.** Cette correction écrit directement la
> nouvelle quantité : elle **ne crée aucun mouvement de stock** et **n'apparaît pas
> au journal d'audit**. L'historique des mouvements ne correspondra donc plus au
> stock affiché, et personne ne pourra retrouver qui a corrigé quoi.
>
> En attendant un vrai module d'inventaire (voir la liste des travaux restants dans
> `CLAUDE.md`), **tenez une trace papier de vos comptages** : date, matière, stock
> compté, écart, votre nom.

Pour un écart que vous savez expliquer (produit jeté, casse), **préférez la saisie
d'une perte** : elle, est valorisée, tracée et chiffrée dans vos rapports.

---

## 4. Les rapports

### Au quotidien

| Où | Ce que vous y voyez |
|---|---|
| **Tableau de bord** | CA du jour et sur 30 jours, panier moyen, top articles, alertes stock |
| **Ventes** | Les 100 derniers tickets : numéro, heure, caissier, montant, statut |
| **Production** | Coût matières et marge de chaque recette |
| **Articles** | Colonne **Coût / Marge**, badge rouge si la marge passe sous 60 % |

### Les clôtures de caisse

**Clôtures** liste toutes les sessions, avec **fond de caisse, théorique, compté et
écart**. Un écart s'affiche en rouge s'il manque, en ambre s'il y a un excédent.

Cliquez **Rapport** sur une ligne pour le détail : CA, ventilation par mode de
règlement et par famille, remises, annulations, dépenses, et le commentaire écrit
par le caissier pour justifier son écart.

> Un écart isolé arrive. Un écart **répété sur le même poste** est un signal.
> Le commentaire du caissier est votre point de départ.

### Le rapport du soir

En ligne de commande, une synthèse courte prête à envoyer par WhatsApp :

```bash
php bin/console app:rapport-quotidien
```

Elle peut être planifiée à 21h30 (voir `CLAUDE.md`).

---

## 5. Annuler une vente

**Vous êtes le seul à pouvoir annuler une vente déjà encaissée** — un caissier ne
le peut pas.

Une vente n'est **jamais supprimée** : elle passe au statut *Annulée*, ses montants
restent visibles, et **le motif que vous saisissez est conservé**. L'annulation
génère automatiquement une **alerte à la dirigeante**, avec votre nom et le motif.

Deux règles à connaître :

- **Le motif est obligatoire.** Écrivez ce qui s'est passé, pas « erreur ».
- **Après la clôture Z, plus rien n'est annulable** sur cette journée. Si un ticket
  doit être annulé, faites-le **avant** que le caissier ne clôture.

---

## 6. Ce que vous ne pouvez pas faire

| Action | Qui |
|---|---|
| **Fixer ou changer un prix de vente** | La dirigeante uniquement — le champ n'apparaît pas dans votre formulaire |
| Désactiver un compte utilisateur | La dirigeante uniquement |
| Supprimer une vente | Personne, jamais |
| Modifier le journal d'audit | Personne, jamais |

Si vous créez un article sans pouvoir lui donner de prix, il est enregistré
**inactif** : il n'apparaîtra en caisse qu'une fois son prix fixé par la dirigeante.
