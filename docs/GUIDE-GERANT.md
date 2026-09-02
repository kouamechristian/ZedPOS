# ZedPOS — Guide du gérant

Vous gérez le back-office : le catalogue, le stock, les pertes et les rapports.
Connectez-vous sur `/login` avec votre e-mail et votre mot de passe ; vous arrivez
sur le **tableau de bord**.

Le menu de gauche est votre point de départ :

```
[logo]  VOTRE ENSEIGNE
        Back-office
┌────────────────────┐
│ ▸ Tableau de bord  │   CA du jour, ventes, alertes
│ ▸ Ventes           │   tickets, recherche par numéro
│ ▸ Articles         │   catalogue, photos, coût / marge
│ ▸ Stock            │   matières premières
│ ▸ Inventaire       │   feuille de comptage et écarts
│ ▸ Production       │   fiches techniques et marges
│ ▸ Pertes           │   saisie et synthèse
│ ▸ Clôtures         │   rapports Z et écarts de caisse
│ ▸ Comptabilité     │   écritures SYSCOHADA, exports
│ ▸ Utilisateurs     │   comptes
│ ▸ Paramètres       │   nom, logo, adresse, pied de ticket
└────────────────────┘
```

En haut du menu, **le nom et le logo de votre établissement** — pas ceux du
logiciel. Ils viennent de **Paramètres** (voir § 7 bis) et se retrouvent à l'identique
sur les tickets et sur l'écran de la dirigeante.

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
| Seuil d'alerte | En dessous, le bandeau ambre s'affiche partout, y compris en caisse |
| Fournisseur | Pour savoir qui rappeler |

> **Le stock actuel ne se modifie pas ici.** Il ne figure que dans le formulaire de
> *création*, pour saisir la quantité de départ. Ensuite il se **compte**, par un
> inventaire (§ 3) : écrire directement la quantité ne laissait aucune trace, et
> l'historique des mouvements finissait par ne plus correspondre au stock affiché.

Réglez le seuil sur **ce qu'il vous faut pour tenir jusqu'à la prochaine livraison**,
pas sur zéro : une alerte qui arrive quand le sac est vide ne sert à rien.

### Les fiches techniques

Une fiche technique, c'est la **recette** d'un produit : ce qu'il faut de chaque
matière première pour en fabriquer **une unité**.

**Où la composer :** **Articles** → bouton **Fiche technique** sur la ligne du
produit. Choisissez la matière, la quantité et le pourcentage de perte, puis
**Ajouter**.

> **Il n'y a pas de « nouvelle fiche technique » à créer.** La fiche naît toute
> seule quand vous ajoutez sa première matière première. Si vous cherchez un
> bouton pour la créer, c'est pour cela que vous ne le trouvez pas.

> **La quantité est celle d'une seule unité.** Pour une baguette, c'est la farine
> d'**une** baguette, pas d'une fournée.

**Production** dans le menu ne fait que **consulter** : elle montre, pour chaque
recette, le coût matières et la marge. Le bouton **Modifier la fiche** de chaque
ligne ramène à l'onglet de l'article.

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

## 2 bis. Les photos des touches

**Articles** → **Modifier** → **Photo de la touche**. La photo apparaît en caisse,
au-dessus du nom du produit : la caissière reconnaît un pain de mie avant d'avoir
lu son libellé.

- JPEG, PNG ou WebP. **Inutile de redimensionner** : ZedPOS réduit l'image tout
  seul. Une photo prise au téléphone convient.
- **Cadrez serré sur le produit.** La touche est petite : un plan large ne donne
  rien. L'aperçu à droite du formulaire montre exactement ce que verra la caisse.
- Le nom reste écrit **sous** la photo, sur la couleur de la touche — jamais
  par-dessus l'image, où il deviendrait illisible selon la photo.
- Pour revenir à une touche de couleur : cochez **Retirer la photo**.
- Les photos fonctionnent **hors ligne** : elles sont mises en réserve sur la
  tablette avec le reste de la caisse.

---

## 2 ter. Importer des articles en masse

**Articles** → bouton **Importer**. Pour garnir un catalogue au démarrage ou ajouter
une gamme entière : soixante articles saisis un par un prennent une matinée, et ils
sont déjà dans votre tableur.

**Le fichier** : une ligne par article, deux colonnes — le **nom**, puis le **prix
de vente en FCFA**. Depuis Excel : *Enregistrer sous* → **CSV**. Le bouton
**Télécharger un modèle à remplir** vous donne le bon squelette.

```
Nom;Prix de vente (FCFA)
Baguette;150
Pain au chocolat;300
Sandwich poulet;1500
```

**Ce qu'il faut savoir avant de cliquer :**

- **Un article déjà au catalogue est ignoré**, jamais modifié. Vous pouvez repasser
  le même fichier corrigé sans rien créer en double, et sans toucher aux prix en place.
- **Les prix ne sont repris que si c'est la dirigeante qui importe.** Fixer un prix
  de vente lui est réservé, ici comme dans le formulaire. Si vous importez, les
  articles sont créés **inactifs à 0 FCFA** : ils n'apparaissent pas en caisse
  jusqu'à ce qu'elle fixe leur prix. L'écran vous le rappelle avant le dépôt.
- **Le prix s'écrit en FCFA entiers.** « 1500 », « 1 500 » et « 1.500 » passent ;
  un montant à centimes (« 1500,50 ») est refusé — le franc CFA ne circule pas en
  centimes, et le logiciel n'arrondit jamais un montant à votre place.
- Les articles arrivent en **« pièce »**, sans famille ni TVA. Complétez-les ensuite
  sur leur fiche, ou laissez-les inactifs le temps de le faire.

**Après l'import**, un compte rendu vous donne le nombre d'articles créés, ceux qui
existaient déjà, et **chaque ligne refusée avec sa raison et son numéro**. Corrigez
ces lignes dans votre fichier et repassez-le : rien ne sera créé en double.

> Un accent qui ressort en « Ã© » ? Cela n'arrive plus : les fichiers Excel
> francophones sont convertis automatiquement.

---

## 3. L'inventaire

Entrée **Inventaire**. C'est le **seul** endroit où un stock se corrige : le champ
« stock actuel » a disparu de la fiche matière, parce qu'y écrire ne laissait
aucune trace.

**Le déroulé, en quatre temps :**

1. **+ Nouvel inventaire.** ZedPOS fige l'état de tout ce qui est suivi en stock —
   matières premières et boissons revendues telles quelles.
2. **Imprimer la feuille**, et compter en réserve. La feuille **ne montre pas les
   quantités attendues** : lire « 42 » avant de compter suffit à en trouver 42.
3. **Reporter les quantités** à l'écran. Vous pouvez enregistrer et revenir plus
   tard, la feuille vous attend.
4. **Valider l'inventaire.**

**Trois choses à savoir :**

- **Une case laissée vide n'est pas comptée** ; un **zéro** veut dire qu'il n'en
  reste aucun. Ne mettez jamais de zéro pour « je n'ai pas eu le temps ».
- **Un écart exige un commentaire.** Écrivez ce que vous croyez être arrivé, pas
  « écart ». Dans trois mois c'est la seule chose qui restera.
- **La validation ne s'annule pas.** Elle écrit dans le stock, comme la clôture de
  caisse. Tant que vous n'avez pas validé, vous pouvez tout abandonner : rien
  n'aura bougé.

Chaque écart devient un **mouvement de stock** et une **entrée au journal d'audit**,
à votre nom. L'écart total vous est chiffré en FCFA.

Pour un écart que vous savez expliquer sur le moment (produit jeté, casse),
**préférez la saisie d'une perte** : elle est valorisée par motif et ressort dans
la synthèse mensuelle. L'inventaire est là pour ce qu'on ne sait pas expliquer.

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

**Vous annulez n'importe quelle vente encaissée, à tout moment de la journée.**
Le caissier, lui, ne peut annuler que **le tout dernier ticket** qu'il vient
d'encaisser, tant qu'il n'a pas servi le client suivant — au-delà, il vous appelle.

Une vente n'est **jamais supprimée** : elle passe au statut *Annulée*, ses montants
restent visibles, et **le motif saisi est conservé**. Toute annulation génère
automatiquement une **alerte à la dirigeante**, avec le nom de son auteur et le
motif — la vôtre comme celle d'un caissier.

Trois règles à connaître :

- **Le motif est obligatoire.** Écrivez ce qui s'est passé, pas « erreur ».
- **Après la clôture Z, plus rien n'est annulable** sur cette journée. Si un ticket
  doit être annulé, faites-le **avant** que le caissier ne clôture.
- **Les annulations des caissiers se relisent** dans le journal d'audit
  (`/pilotage/audit`, dirigeante) et dans le rapport Z de la session. Un caissier
  qui en accumule mérite une question, pas une sanction automatique : c'est
  souvent une touche mal placée sur la grille.

---

## 6. La comptabilité

Entrée **Comptabilité** dans la barre de gauche. Vous y trouvez, pour la période de
votre choix, les **écritures au plan SYSCOHADA** produites à partir des ventes, des
dépenses et des pertes — c'est ce que le cabinet attend.

- Les **raccourcis** en haut (mois en cours, mois précédent, depuis le 1er janvier)
  évitent de saisir deux dates.
- Les **cinq contrôles** en bas de l'écran rapprochent l'application et les
  écritures (CA TTC, TVA, espèces, mouvements de caisse, écarts). S'ils sont
  conformes, le fichier est bon à transmettre.
- Trois **téléchargements** : *Écritures* (tableur), *FEC* (pour le logiciel du
  cabinet), *Balance* (contrôle d'un coup d'œil).

**C'est un espace de lecture seule.** Rien de ce que vous y faites ne modifie une
vente : une erreur se corrige en caisse, par une annulation motivée (§ 5), jamais
dans les écritures.

---

## 7. Les comptes

Entrée **Utilisateurs**. Vous créez les comptes de l'équipe, vous les **modifiez**
et vous les désactivez — un caissier qui part, un remplaçant qui arrive.

- **Caissier** : vous lui donnez un code à 4 chiffres pour le pavé de la caisse.
- **Gérant** : mot de passe, 6 caractères minimum.
- Le rôle **Dirigeante** ne vous est pas proposé, et le compte de la dirigeante n'a
  ni **Modifier** ni **Désactiver** : ce sont ses accès, pas les vôtres.
- Vous ne pouvez ni désactiver votre propre compte, ni changer votre propre rôle.

### Modifier un compte

Bouton **Modifier** sur la ligne. Vous corrigez le nom, l'e-mail, le rôle.

- **Un caissier a oublié son code ?** Tapez-lui-en un nouveau et enregistrez.
- **Vous ne changez que le nom ?** Laissez le mot de passe et le code PIN **vides** :
  ils ne bougent pas. C'est le cas normal.
- **Vous changez le rôle ?** Le nouveau rôle ne se connecte pas de la même façon :
  il faut donc lui donner son nouveau secret. Un caissier qui devient gérant a
  besoin d'un mot de passe — son ancien code PIN est effacé et **ne lui ouvre plus
  la caisse**.

Créations, modifications et désactivations sont **tracées au journal d'audit**, à
votre nom. Les mots de passe et codes PIN, eux, n'y figurent jamais.

---

## 7 bis. Les informations de votre établissement

**Paramètres** dans le menu. C'est le seul écran dont une erreur s'imprime des
milliers de fois : ce que vous tapez ici sort en tête et en pied de **chaque
ticket** et de **chaque rapport de clôture**. Vérifiez-le avant la mise en service.

| Champ | Où il sort |
|---|---|
| **Raison sociale** | nom légal, obligatoire — il figure sur chaque ticket |
| **Enseigne** | le nom de la devanture, s'il diffère du nom légal. **Renseigné, c'est lui qui s'affiche partout** |
| **Logo** | en tête de ticket, dans ce menu et sur l'écran de la dirigeante |
| Adresse, ville, téléphone, e-mail | en-tête du ticket |
| NCC, RCCM | mentions légales, imprimées si renseignées |
| Pied de ticket | la phrase de fin (remerciement, horaires…) |

**Le logo.** *Parcourir*, choisissez une image, *Enregistrer*. JPEG, PNG ou WebP,
5 Mo au plus — **inutile de la redimensionner**, elle est réduite toute seule. Un
logo sur fond transparent (PNG) donne le meilleur résultat sur le papier.
Pour revenir en arrière, cochez **Retirer le logo** ; la case n'apparaît que
lorsqu'il y en a un à retirer.

Les tickets **déjà imprimés ne changent pas** : seuls les suivants reprennent vos
modifications.

> Le logo ne sort pas encore sur une imprimante thermique branchée en direct — il
> s'imprime quand le ticket passe par la fenêtre d'impression du navigateur, ce qui
> est le cas aujourd'hui.

---

## 8. Chercher dans un tableau

Chaque écran à tableau porte une **barre de recherche** en haut. Tapez trois
lettres, ce n'est pas la peine d'écrire le mot entier.

Ce que chaque écran cherche : le **numéro de ticket ou le nom de la caissière**
dans Ventes, le **fournisseur autant que la matière** dans Stock, la **matière
d'une fiche** dans Production (« quelles fiches utilisent du beurre ? » avant une
hausse de prix). La recherche **s'ajoute** au filtre déjà posé : chercher dans les
pertes d'un mois donné reste dans ce mois. **Réinitialiser** l'annule.

---

## 9. Ce que vous ne pouvez pas faire

| Action | Qui |
|---|---|
| **Fixer ou changer un prix de vente** | La dirigeante uniquement — le champ n'apparaît pas dans votre formulaire |
| Créer un compte **Dirigeante** ou **Comptable** | La dirigeante uniquement — ces rôles ne vous sont pas proposés |
| Désactiver le compte de la dirigeante | Elle seule |
| Supprimer une vente | Personne, jamais |
| Modifier le journal d'audit | Personne, jamais |

Si vous créez un article sans pouvoir lui donner de prix, il est enregistré
**inactif** : il n'apparaîtra en caisse qu'une fois son prix fixé par la dirigeante.
