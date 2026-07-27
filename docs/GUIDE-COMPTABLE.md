# ZedPOS — Guide du comptable

Vous récupérez les écritures de la boulangerie au format **SYSCOHADA révisé**.
Connectez-vous sur `/login` avec votre e-mail et votre mot de passe : vous arrivez
directement sur l'écran d'export.

Votre accès est en **lecture seule**. Rien de ce que vous faites ici ne modifie la
caisse ; une vente erronée se corrige en boutique, où la correction laisse une trace.

---

## 1. Choisir la période

En haut de l'écran, deux dates et trois raccourcis :

```
Du [2026-06-01]  Au [2026-06-30]  [Afficher]     ( Mois en cours ) ( Mois précédent ) ( Depuis le 1er janvier )
```

En pratique, un clic sur **Mois précédent** le 1er du mois suffit.

---

## 2. Lire les contrôles avant de télécharger

C'est le bloc le plus important de l'écran. Il compare, ligne par ligne, ce que
dit la caisse et ce que disent les écritures :

| Rapprochement | Application | Écritures | Écart |
|---|---|---|---|
| Chiffre d'affaires TTC encaissé | 4 512 300 FCFA | 4 512 300 FCFA | 0 |
| TVA collectée | 218 940 FCFA | 218 940 FCFA | 0 |
| Espèces encaissées (rendu déduit) | 2 980 100 FCFA | 2 980 100 FCFA | 0 |
| Dépenses et sorties de caisse | 145 000 FCFA | 145 000 FCFA | 0 |
| Écarts de caisse constatés au Z | −2 500 FCFA | −2 500 FCFA | 0 |
| Équilibre débit / crédit | … | … | 0 |

- **Bloc vert, « Contrôles conformes »** : le fichier peut partir.
- **Bloc ambre, « Contrôles à vérifier »** : ne transmettez pas. Signalez la
  période et l'écart affiché — c'est un défaut de l'application, pas une saisie
  à rattraper de votre côté.

---

## 3. Télécharger

Trois fichiers, selon l'usage :

| Fichier | Quand s'en servir |
|---|---|
| **Écritures (CSV)** | Pour relire ou annoter dans un tableur. S'ouvre directement dans Excel. |
| **FEC (.txt)** | Pour importer dans votre logiciel de comptabilité. 18 colonnes tabulées. |
| **Balance générale (CSV)** | Pour vérifier les grandes masses d'un coup d'œil. Les contrôles sont repris en pied de fichier. |

Le nom du fichier FEC suit la convention du format :
`{NCC}FEC{AAAAMMJJ}.txt`, la date étant la fin de la période.

---

## 4. Ce que contiennent les écritures

**Trois journaux.**

| Code | Journal | Contenu | Pièce |
|---|---|---|---|
| `VE` | Ventes | Une écriture **par caisse et par journée** | `Z12` = rapport Z n° 12 |
| `CA` | Caisse | Dépenses en espèces, sorties de fonds, écarts de caisse | `CA45`, `Z12` |
| `OD` | Opérations diverses | Pertes de stock valorisées | `PE7` |

Les tickets **ne sont pas repris un par un** : un mois de boulangerie en compte
plusieurs milliers. Le journal des ventes centralise chaque journée de caisse en
une écriture, dont la pièce justificative est le **rapport Z** correspondant —
le ticket que la caissière imprime à la clôture et qui reste au classeur.
Sur demande, le détail d'une journée est consultable en boutique.

**Comptes utilisés.**

| Compte | Intitulé | Alimenté par |
|---|---|---|
| `7011` | Ventes de marchandises | Articles revendus en l'état (boissons) |
| `7021` | Ventes de produits finis | Articles fabriqués sur place (pain, viennoiseries) |
| `7019` | Rabais, remises et ristournes accordés | Remises accordées en caisse (au débit) |
| `4431` | État, TVA facturée sur ventes | TVA des tickets |
| `5711` | Caisse | Espèces, **nettes du rendu de monnaie** |
| `5521`–`5524` | Monnaie électronique | Wave, Orange Money, MTN MoMo, Moov Money |
| `4111` | Clients | Vente réglée à crédit |
| `585` | Virements de fonds | Sortie du tiroir vers le coffre ou la banque |
| `6021` `612` `624` `605` `6056` `6588` | Charges | Dépenses réglées en espèces, par catégorie |
| `4211` | Personnel, avances et acomptes | Avance au personnel (une créance, pas une charge) |
| `7588` / `6588` | Produits / charges divers | Excédent / manquant de caisse |
| `6031` `6032` `736` | Variations de stocks | Pertes valorisées, en contrepartie de `311` `321` `361` |

**Ce qui n'y figure pas :**

- les **ventes annulées** — elles ne sont ni un produit ni un encaissement ; le
  motif d'annulation reste consultable en boutique ;
- les **achats fournisseurs réglés hors caisse** — ZedPOS ne les connaît pas ;
- les **salaires, loyers, amortissements** — hors périmètre de la caisse.

---

## 5. Faire changer un compte de vente

Si votre plan diffère de celui livré, le compte se règle **par famille de
produits**, sans toucher au code : back-office → **Familles** → *Compte de vente*.
Demandez au gérant ou à la dirigeante, qui a accès à cet écran.

Tant qu'une famille reste sur « Automatique », ZedPOS tranche à l'article : ceux
qui ont une **fiche technique** sont fabriqués sur place (produits finis), les
autres sont revendus en l'état (marchandises).

---

## 6. Recevoir l'export automatiquement

L'export peut être planifié côté serveur, sans que personne ait à se connecter.
À demander à l'installateur — le 1er de chaque mois, pour le mois écoulé :

```cron
0 7 1 * * cd /var/www/zedpos && php bin/console app:export-comptable \
  --mois=$(date -d 'last month' +\%Y-\%m) --format=fec -o /tmp/zedpos.txt
```

La commande **refuse de rendre la main** si un contrôle n'est pas conforme : un
fichier ne part pas tant que les rapprochements ne sont pas justes.

---

## Questions fréquentes

**Pourquoi le compte 5711 ne correspond-il pas au total des règlements en espèces ?**
Le rendu de monnaie sort du tiroir. Le compte caisse est débité du **net**, comme
sur le rapport Z.

**Une remise apparaît au débit du 7019, pas en charge. Pourquoi ?**
Un rabais accordé diminue un produit ; il ne s'impute pas en classe 6. Seule sa
part hors taxes passe en `7019`, la part de TVA étant déjà déduite du `4431`.

**Les montants sont en FCFA avec deux décimales — le franc CFA n'en a pas.**
ZedPOS stocke les montants au centime pour ne jamais arrondir en cours de calcul.
Les décimales sont à zéro dans l'usage courant ; le format les conserve pour que
l'import ne devine pas.

**Je vois deux écritures `Z12`, une dans le journal VE et une dans CA.**
C'est normal : la même pièce (le rapport Z n° 12) porte à la fois les ventes de
la journée et l'écart de caisse constaté à la clôture.
