# Guide du magasin

Ce guide est pour **la gérante**, **la dirigeante** et **le magasinier**. Il suit
le chemin de la marchandise, de la livraison du fournisseur jusqu'au comptage
en réserve.

Le magasin est **séparé** de la caisse : une vente au comptoir ne change rien au
stock du magasin. Ici, on suit la farine, le sucre, les œufs, les emballages…
de leur arrivée jusqu'à leur départ vers l'atelier ou la boutique.

---

## Qui fait quoi

| | Magasinier | Gérante | Dirigeante |
|---|---|---|---|
| Voir le stock, les réceptions, les sorties, l'analyse | oui | oui | oui |
| Recevoir une livraison, faire une sortie | non | oui | oui |
| Créer un produit, un emplacement | non | oui | oui |
| Voir et saisir les **prix d'achat**, voir les valeurs | non | non | oui |
| Valider un inventaire qui a un **écart** | non | non | oui |

Le magasinier se connecte avec son e-mail et son mot de passe sur la page de
connexion habituelle. Il arrive directement sur le tableau de bord du magasin.

---

## Avant de commencer : produits et emplacements

**Magasin → Produits → Nouveau produit.** Pour chaque produit, indiquez :

- l'**unité de stock** : celle dans laquelle on pèse ou on compte (kg, L, pièce) ;
- l'**unité d'achat** et sa **contenance** : comment le fournisseur livre
  (« sac » de 50 kg, « plaquette » de 30 œufs, « carton » de 10 kg) ;
- le **seuil d'alerte** : en dessous ou à ce niveau, le logiciel vous prévient.

Partout ensuite, les quantités s'affichent dans les deux unités :
« 20 sacs (1 000 kg) ».

**Magasin → Emplacements** : la « Réserve principale » existe d'office. Ajoutez
une « Chambre froide » si vous rangez le beurre et les œufs à part. Tant qu'il n'y
a qu'un emplacement, le logiciel ne vous pose jamais la question.

---

## Étape 1 — Réception : ce qu'annonce le bon de livraison

**Magasin → Réceptions → Nouvelle réception.**

1. Choisissez le fournisseur, la date, et notez le numéro du bon de livraison.
2. Ajoutez une ligne par produit, avec la quantité **écrite sur le bon**, en
   unité d'achat : « 20 » pour 20 sacs.
3. Enregistrez. La réception reçoit un numéro (REC-2026-0001).

Rien n'entre encore en stock.

## Étape 2 — Contrôle : ce qu'il y a vraiment

Comptez les sacs, cartons, plaquettes, et tapez ce que vous avez **compté**.
Le bouton « Tout est conforme au bon » recopie l'annonce d'un appui.

Si le compté **diffère** du bon (19 sacs au lieu de 20), la ligne se colore et
un **commentaire est obligatoire** : expliquez l'écart (« un sac manquant,
signalé au livreur »).

## Étape 3 — Inspection : dans quel état

Pour chaque produit, indiquez ce qui est **rejeté** (sac éventré, œufs cassés,
date dépassée) et choisissez le **motif**. L'accepté se calcule tout seul :
compté moins rejeté. Si tout est bon, validez sans rien taper.

Ce qui est rejeté **n'entre jamais en stock** : c'est rendu au fournisseur.

## Étape 4 — Stockage : la marchandise entre en stock

Choisissez où ranger chaque produit (si vous avez plusieurs emplacements), puis
**« Stocker — entrer en stock »**. C'est la seule étape qui fait entrer de la
marchandise.

La dirigeante peut saisir ici le **prix d'achat** (par sac, par carton) : le
coût moyen du produit se met à jour.

**Imprimez le bon de réception** depuis la fiche : il liste ce qui était annoncé,
compté, rejeté et pourquoi. C'est la pièce à garder avec le bon du fournisseur.

> Une étape ne se saute pas, et ne se refait plus une fois la suivante faite.
> Une erreur sur une réception stockée se corrige en **l'annulant** (motif
> obligatoire) : la marchandise ressort du stock. Si une partie est déjà partie à
> l'atelier, l'annulation est refusée.

---

## Les sorties : ce qui part vers l'atelier, la cuisine, la boutique

**Magasin → Sorties → Nouvelle sortie.**

1. Touchez la **destination** (Atelier, Cuisine, Boutique, Autre).
2. Choisissez le **motif** : production (le cas normal), perte / avarie, retour
   fournisseur.
3. Notez qui demande (« Yao, boulanger »).
4. Une ligne par produit : la quantité, **en sacs ou en kilos**, au choix.
   Sous chaque ligne, le logiciel rappelle ce qui est **disponible**.
5. **« Valider la sortie »** : la marchandise sort du stock tout de suite.
   Ou « Enregistrer le brouillon » pour finir plus tard.

Si vous demandez plus que ce qui reste (30 sacs alors qu'il en reste 18), la
sortie est **refusée** et gardée en brouillon, avec le message.

Imprimez le **bon de sortie** pour le faire signer par celui qui emporte.

Une sortie validée par erreur s'**annule** (motif obligatoire) : la marchandise
revient dans son emplacement.

---

## Étape 5 — Analyse des entrées et sorties

**Magasin → Analyse.** Choisissez la période (aujourd'hui, la semaine, le mois,
ou des dates). Pour chaque produit :

**Stock au début + Entrées − Sorties ± Ajustements = Stock à la fin**

Les sorties sont détaillées par motif : on voit tout de suite ce qui est parti
en production et ce qui est parti à la poubelle.

Touchez le nom d'un produit pour voir sa **fiche de stock** : chaque entrée et
chaque sortie, dans l'ordre, avec le stock après chacune, le bon d'origine et
qui l'a fait. Les deux écrans s'impriment en **PDF**.

---

## Étape 6 — Inventaire : compter la réserve

**Magasin → Inventaires → Ouvrir la feuille.**

1. **Imprimez la feuille de comptage.** Elle ne montre pas les quantités
   attendues : on compte ce qu'on voit, sans être influencé.
2. Comptez en réserve, notez sur la feuille.
3. Revenez à l'écran et tapez les quantités, en sacs ou en kilos. L'écran montre
   l'**écart** de chaque ligne.
   - Une case **vide** veut dire « pas compté » : la ligne ne change pas.
   - **0** veut dire « il n'en reste plus ».
4. **Validez.**
   - Pas d'écart : la gérante valide, le stock ne bouge pas.
   - Un écart : écrivez un **commentaire**. La validation revient à la
     **dirigeante** ; le comptage est gardé, elle n'a plus qu'à relire et valider.

Le stock est corrigé de l'écart. Si une sortie a été faite pendant le comptage,
elle n'est pas effacée.

> **Le tout premier inventaire sert de stock de départ** : comptez tout ce qui
> est en réserve, validez, et le magasin démarre avec ces quantités.

---

## Le tableau de bord et les alertes

**Magasin → Tableau de bord** montre la journée en six cartes, dans l'ordre :
Réception, Contrôle, Inspection, Stockage, Entrées / sorties, Inventaire. Chaque
carte dit ce qui a été fait aujourd'hui et ce qui **attend** (« 2 à contrôler »).

Quand un produit **atteint son seuil d'alerte**, un bandeau rouge apparaît en
haut de chaque écran — pour la gérante, la dirigeante (jusque sur le pilotage)
et le magasinier — et un chiffre rouge s'affiche à côté de « Stock magasin ».
Le bandeau disparaît tout seul dès qu'une réception fait remonter le produit.
