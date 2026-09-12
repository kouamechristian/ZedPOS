# Agent matériel local — contrat d'impression

Document destiné à **qui maintient l'agent** qui tourne sur le poste de caisse.
L'agent ne fait pas partie de ce dépôt : ZedPOS ne fait que lui parler, en HTTP,
sur `http://127.0.0.1:9100`.

> **Le matériel est un agrément, jamais une dépendance.** La plupart des postes
> n'ont pas d'agent — tablette de secours, navigateur de la dirigeante. Sur
> ceux-là, ZedPOS retombe seul sur l'impression navigateur. Un agent absent,
> arrêté ou en erreur ne doit jamais empêcher une vente.

---

## Les quatre routes

| Route | Corps | Rôle |
|---|---|---|
| `GET /status` | — | l'agent est-il là ? Toute réponse 2xx suffit |
| `POST /display` | `{ amount, mode }` | afficheur client (`price`, `total`, `change`, `clear`) |
| `POST /print` | voir plus bas | ticket 58 mm |
| `POST /drawer` | **aucun** | impulsion d'ouverture du tiroir |

`POST /drawer` part **sans en-tête ni corps**, délibérément : un
`Content-Type: application/json` n'est pas sur la liste sûre du CORS, le
navigateur ferait précéder le POST d'un **préflight `OPTIONS`**, et un agent qui
n'expose que `POST /drawer` n'y répondrait pas — le `fetch` échouerait avant
d'avoir rien envoyé. **Répondre à `OPTIONS` et renvoyer
`Access-Control-Allow-Origin` sur toutes les routes** évite bien des surprises :
le symptôme est déroutant, `Invoke-RestMethod` depuis un terminal ouvre le tiroir
(pas de CORS dans un shell) quand le même appel depuis la caisse ne fait rien.

---

## `POST /print` — la charge utile

```jsonc
{
  "logo":       "data:image/png;base64,…",  // ou null
  "logoEscpos": "HXYwADAAgAA…",             // ou null — base64 d'une commande GS v 0
  "header":     ["LES DELICES DU CAMPUS", "Bondoukou", "Ticket : V260912-00007", "…"],
  "lines":      [{ "label": "Baguette", "qty": "2", "price": 300 }],
  "total":      1500,
  "paid":       2000,
  "change":     500,
  "footer":     ["TVA 0% : 0 FCFA (base 1500)", "Especes : 2000 FCFA", "Merci !"],
  "openDrawer": true
}
```

- **Les montants sont des FCFA entiers**, pas des centimes : ZedPOS a déjà fait la
  conversion, l'agent les imprime tels quels.
- **Les textes sont déjà translittérés en ASCII.** Les têtes thermiques
  interprètent volontiers l'UTF-8 comme du GBK et sortent des idéogrammes à la
  place des accents.
- **La remise voyage en ligne négative** (`price` négatif), faute de champ dédié :
  sans elle, la somme des lignes ne tomberait pas sur `total` et le client aurait
  un ticket qui ne s'additionne pas.
- **`openDrawer` est décidé par le serveur** : vrai seulement s'il y a des espèces.
  L'écran peut le refuser, jamais l'imposer. Une réimpression le reçoit toujours à
  faux.
- **Ordre d'impression** : le logo, **puis** `header`, `lines`, les totaux, `footer`.

---

## Le logo — c'est la partie à brancher

ZedPOS envoie le logo de l'établissement (`/admin/parametres`) **déjà prêt pour la
tête** : recadré sur le dessin, ramené à 44 × 16 mm, seuillé en noir et blanc pur,
posé sur **384 points de large exactement** — toute la largeur imprimable d'une
tête 58 mm à 203 dpi — et **déjà centré**.

> ⚠ **Ne pas le redimensionner, ne pas le recentrer, ne pas le convertir.**
> Il fait déjà la largeur de la tête : le redimensionner, c'est le rendre flou et
> lui faire perdre le rapport « un point d'image = un point imprimé ».

Deux formats, au choix — **prendre celui qui demande le moins de dépendances** :

### 1. `logoEscpos` — rien à décoder *(recommandé)*

Le contenu est une commande ESC/POS `GS v 0` complète, en base64 : en-tête,
dimensions et trame. Il n'y a **rien à calculer**, juste à écrire sur la tête,
avant tout le reste :

```js
if (payload.logoEscpos) {
    printer.raw(Buffer.from(payload.logoEscpos, 'base64'));  // ou socket.write(…)
}
```

C'est tout. Aucune bibliothèque d'image, aucun décodage PNG, aucune mise à
l'échelle — donc aucune occasion de se tromper.

### 2. `logo` — si l'agent sait déjà imprimer une image

PNG à deux couleurs, en `data:` URI. Avec `node-thermal-printer` :

```js
if (payload.logo) {
    printer.printImageBuffer(Buffer.from(payload.logo.split(',')[1], 'base64'));
}
```

### Ce qu'il faut savoir dans les deux cas

- **Les deux clés sont toujours présentes**, et valent `null` quand la boutique
  n'a pas de logo : inutile de tester leur existence.
- **Les deux représentent le même dessin, au point près** — c'est vérifié par
  `LogoBoutiqueTest::testLaTrameEscPosEtLePngAllumentLesMemesPoints`. Elles ne
  diffèrent que par l'emballage.
- **Un logo ne doit jamais empêcher un ticket de sortir.** Fichier disparu, format
  illisible, image uniforme : ZedPOS envoie `null` et le ticket part sans logo.
  Côté agent, même discipline — entourer l'impression du logo plutôt que de la
  laisser interrompre la vente.

### Détail de la commande `GS v 0`, pour qui écrit la sortie à la main

```
1D 76 30 00   GS v 0 m       m = 0 : ni double largeur, ni double hauteur
30 00         xL xH          largeur en OCTETS par ligne = 48  (48 × 8 = 384 points)
80 00         yL yH          hauteur en lignes de points ≤ 128
…             trame          48 × hauteur octets, 1 bit par point,
                             le point le plus à gauche dans le bit de poids fort,
                             1 = point chauffé
0A            LF             referme la commande
```

---

## Vérifier sans imprimante

La charge utile exacte d'une vente se lit en HTTP, avec la session d'un compte qui
a le droit de voir cette vente :

```
GET /caisse/ticket/{uuid}/materiel
```

C'est **le même objet** que celui envoyé à `/print` — construit par le même
service (`App\Service\TicketMateriel`) —, à ceci près que `openDrawer` y est
toujours faux : une réimpression ne fait pas entrer d'argent.

Pour regarder le logo tel qu'il sortira, sans monter l'agent :

```bash
php -r '$t = json_decode(file_get_contents("ticket.json"), true)["ticket"];
        file_put_contents("logo.png", base64_decode(explode(",", $t["logo"])[1]));'
```

---

## Ce qui reste ouvert

`App\Service\ImpressionService` produit une **autre** mise en page thermique, en
ESC/POS texte, exposée par `GET /caisse/ticket/{uuid}/escpos`. Elle n'a aucun
consommateur (écart n° 3 de `CLAUDE.md`) et **ne porte pas le logo**. Deux issues,
à trancher un jour : soit l'agent apprend à consommer cette trame complète — et
ZedPOS reprend la main sur la mise en page au caractère près —, soit
`ImpressionService` est retiré. En attendant, **les deux mises en page doivent
être modifiées ensemble**.
