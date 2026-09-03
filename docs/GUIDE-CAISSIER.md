# ZedPOS — Guide de la caisse

> À imprimer et afficher près de la caisse.

## 1. Ouvrir la caisse (le matin)

Écran noir, chiffres de 1 à 9 : **tapez votre code à 4 chiffres.** Comptez ensuite
l'argent déjà dans le tiroir, tapez le montant, touchez **Ouvrir la caisse**.
Sans cela, impossible de vendre.

## 2. Encaisser

Produits à gauche, ticket au milieu, paiements à droite. En haut, un bandeau de
couleur et les boutons **Dépense · Ticket X · Clôture Z**.

```
🟢 Synchronisé
┌──────────────────┬──────────────┬────────────┐
│ Pains  Boissons… │ Ticket       │ Espèces    │
│ ┌────┐┌────┐     │ Baguette  ×2 │ Wave       │
│ │Bag.││Pain│     │ Pain      ×1 │ Orange M.  │
│ └────┘└────┘     │ TOTAL        │ MTN MoMo   │
│ ┌────┐┌────┐     │  1 200 FCFA  │ [Encaisser]│
└──────────────────┴──────────────┴────────────┘
```

1. Touchez le produit — **un appui = 1 article** (3 appuis = 3 baguettes).
2. Touchez le paiement (**Espèces**, Wave, Orange Money…). Chaque bouton porte la
   couleur de son réseau — bleu Wave, orange Orange, jaune MTN — et **se remplit de
   cette couleur** une fois choisi : d'un coup d'œil vous voyez ce qui est retenu.
3. Touchez le grand bouton vert **Encaisser**. Le ticket s'imprime.

## 2 bis. La monnaie à rendre

Quand vous choisissez **Espèces**, un cadre s'ouvre sous les paiements :

```
Reçu  [        2 000 ] FCFA
[ Juste ][ 2 000 ][ 5 000 ][10 000]
Monnaie à rendre      800 FCFA
```

- Le client vous tend un billet : **touchez le montant** dans la rangée de boutons,
  ou tapez-le. La monnaie s'affiche en dessous, en gros.
- **Le compte est juste ?** Ne touchez à rien — laissez le champ vide et encaissez.
- **Le chiffre est en rouge (« Manque ») ?** Le client n'a pas donné assez :
  **Encaisser** ne fonctionne pas tant que le compte n'y est pas.
- La monnaie est **calculée sur la tablette**, même Internet coupé : elle s'affiche
  tout de suite, vous n'attendez jamais.
- Elle est aussi **imprimée sur le ticket**, ligne **Rendu** — le client peut donc
  vérifier sa monnaie après coup.

**Fast-food** : touchez **Fast-food** en haut. Un panneau s'ouvre pour la quantité
et les précisions (Sur place, Piment…), puis **Ajouter au ticket**.

## 3. Corriger, et annuler

**Avant d'encaisser**, tout se corrige : **−** enlève un article, **✕** enlève la
ligne, **Vider** efface le ticket, **Mise en attente** met le client de côté.

**Juste après avoir encaissé**, le reçu reste affiché à l'écran. Vous vous êtes
trompée, le client se ravise ? Touchez **Annuler ce ticket**, en bas du reçu :

```
┌─────────────────────────────┐
│ [ Imprimer ][Nouveau ticket]│
│ [    Annuler ce ticket     ]│
└─────────────────────────────┘
      ↓
│ Pourquoi annuler ce ticket ?│
│ [Erreur de saisie][Client…] │
│ [Article indispo.][Erreur…] │
│ [ Autre motif…            ] │
│ [ Revenir ][Annuler le tic.]│
```

1. Touchez le **motif** qui correspond (ou tapez-le dans « Autre motif »).
2. Touchez **Annuler le ticket**.

Trois choses à savoir :

- **Ce n'est que le tout dernier ticket.** Dès que vous avez encaissé le client
  suivant, c'est fini : **appelez le gérant**, lui seul peut encore annuler.
- **Il faut le réseau.** Internet coupé, l'annulation est refusée — appelez le
  gérant.
- **La direction est prévenue** de chaque annulation, avec le motif que vous avez
  choisi. Ce n'est pas un reproche : c'est ce qui permet que vous puissiez le
  faire vous-même sans appeler personne.

Ne refaites jamais une vente pour « corriger » : cela compterait deux fois.

## 4. Quand Internet est coupé

| Bandeau | Ce que ça veut dire |
|---|---|
| 🟢 **Synchronisé** | Tout est enregistré. |
| 🟠 **Hors ligne — 3 ventes en attente** | Pas de réseau : **continuez à vendre**. |
| 🔵 **Synchronisation…** | Le réseau est revenu, les ventes partent seules. |
| 🔴 **N ventes à vérifier** | Prévenez le gérant. Rien n'est perdu. |

Vos ventes sont gardées dans la tablette et repartent seules. Seul le ticket papier
attend le retour du réseau.

## 4 bis. La pastille « Matériel »

À côté du bandeau ci-dessus, elle dit si l'afficheur client, l'imprimante à tickets
et le tiroir sont pilotés depuis la caisse.

| Pastille | Ce que ça veut dire |
|---|---|
| 🟢 **Matériel connecté** | Le client voit les montants, le ticket sort tout seul. |
| ⚪ **Matériel non connecté** | **Vendez normalement.** Le ticket s'imprime par le navigateur, comme avant. |

Si le câble vient d'être rebranché, **touchez la pastille** : elle revérifie.

## 5. Sortir de l'argent du tiroir

Touchez **Dépense** → *Dépense* ou *Sortie de caisse*, la catégorie, le montant, un
commentaire. **Toute somme non saisie manquera ce soir.**

## 6. Fermer la caisse (le soir)

- **Ticket X** : simple point d'étape, **ne ferme rien**, vous pouvez continuer.
- **Clôture Z** (bouton orange) : la vraie fermeture.
  1. L'écran affiche le montant **théorique** attendu.
  2. **Comptez l'argent réel** et tapez ce que vous avez compté.
  3. S'il y a une différence, **écrivez un commentaire** — c'est obligatoire.
  4. Touchez **Clôturer la caisse (Z)**. Le rapport s'imprime.

> Après la clôture, la journée est **fermée définitivement**. Ne clôturez qu'en fin
> de service. En cas de doute, notez l'heure et prévenez le gérant.
