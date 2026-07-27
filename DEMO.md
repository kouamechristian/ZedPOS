# Démonstration ZedPOS — 10 minutes

Déroulé pour présenter le logiciel à un client : boulangerie + fast-food à Abengourou.

Le fil rouge : **on encaisse, on perd le réseau, on continue à vendre, on clôture,
et la dirigeante voit tout depuis son téléphone à Abidjan.**

---

## Avant de commencer (5 minutes, à faire la veille)

```bash
# 1. Remettre la base dans l'état de démonstration (DESTRUCTIF, demande confirmation)
php bin/console app:demo:reset

# 2. Compiler les assets
php bin/console tailwind:build
php bin/console asset-map:compile

# 3. Lancer le serveur
symfony serve            # ou : php -S localhost:8000 -t public/ public/index.php
```

La commande affiche à la fin les comptes et les deux anomalies injectées. **Relisez
cet encadré** : il contient le numéro exact du ticket annulé, que vous montrerez à
l'étape 6.

### Ce que `app:demo:reset` a préparé

| Élément | État |
|---|---|
| Historique | 30 jours de ventes réalistes (pics 5h-9h et 18h-21h) |
| Stock | Réapprovisionné, **aucune rupture** — pour que les seules anomalies soient les nôtres |
| Caisse de **Fatou Traoré** | **Ouverte**, journée en cours, ~55 ventes déjà passées |
| Caisse de **Yao Kouassi** | **Clôturée** avec un **écart de −2 500 FCFA** |
| Ticket `V…-00107` | **Encaissé puis annulé** par le gérant |

### Comptes

| Rôle | Identifiant | Secret | Page d'arrivée |
|---|---|---|---|
| Dirigeante | `aya.kone@zedpos.ci` | `dirigeante123` | `/pilotage` |
| Gérant | `koffi.nguessan@zedpos.ci` | `gerant123` | `/admin` |
| Comptable | `cabinet@zedpos.ci` | `comptable123` | `/comptabilite` |
| Caissière | Fatou Traoré | PIN **1234** | `/caisse` |
| Caissier | Yao Kouassi | PIN **5678** | `/caisse` |

### Préparer les deux écrans

- **Écran 1 — la caisse.** Navigateur en plein écran (F11) sur `/caisse/login`.
  Idéalement une tablette tactile ; sinon le navigateur en mode responsive.
- **Écran 2 — le téléphone de la dirigeante.** Un vrai téléphone sur le même
  réseau, ou les DevTools en mode mobile (Ctrl+Maj+M, format iPhone), sur `/login`.

> **Important pour l'étape 3 (mode hors ligne)** : le Service Worker n'est actif
> qu'en `localhost` ou en HTTPS. Si vous démontrez depuis une tablette qui accède
> au serveur par `http://192.168.x.x`, la mise en cache de la page ne fonctionnera
> pas. Faites l'étape hors ligne depuis le poste `localhost`, ou servez le site
> en HTTPS.

**Chargez `/caisse` une fois en ligne avant la démonstration** : c'est cette visite
qui met la page et le catalogue en cache.

---

## Le déroulé (10 minutes)

### 1. Vendre en boulangerie — 1 min 30

> « Le matin, c'est du pain. La caissière doit encaisser en trois secondes. »

1. Écran de caisse → PIN **1234**.
2. Le mode **Boulangerie** est déjà actif.
3. Touchez **Baguette** trois fois, **Pain au lait** deux fois.
   → Chaque appui ajoute une unité, sans aucune étape intermédiaire.
4. Touchez **Espèces**, puis **Encaisser**.

**À dire :** le total est affiché en très gros, les touches font plus de 92 px, et
tout l'état du ticket vit dans le navigateur — aucun rechargement pendant la
commande. Le ticket 80 mm s'imprime automatiquement.

---

### 2. Vendre en fast-food — 1 min 30

> « Le soir, c'est du poulet braisé. Là, il faut préciser la commande. »

1. En haut, basculez sur **Fast-food**.
2. Ouvrez la famille **Grillades** et touchez un plat
   (**Poulet braisé (¼)**, **Attiéké poisson**…).
   → Un panneau s'ouvre : quantité, et des variantes en un appui.
3. Touchez **Sur place**, puis **Piment**, ajustez la quantité à 2.
4. **Ajouter au ticket** → la ligne porte le commentaire.
5. **Espèces** → **Encaisser**.

**À dire :** deux métiers, deux rythmes, une seule caisse. Le commentaire suit
jusqu'au ticket de cuisine.

---

### 3. Couper le réseau et continuer à vendre — 2 min 30

> **C'est le moment fort de la démonstration.**
> « À Abengourou, la connexion tombe. Qu'est-ce qui se passe ? »

1. Faites remarquer le bandeau vert en haut : **« Synchronisé »**.
2. Coupez le réseau :
   - le plus parlant : **débranchez le câble / coupez le Wi-Fi** ;
   - sinon : DevTools (F12) → onglet **Réseau** → **Hors ligne**.
3. Le bandeau passe à l'orange : **« Hors ligne »**.
4. **Encaissez trois ventes de suite**, normalement.
   → Le bandeau compte : **« Hors ligne — 3 ventes en attente »**.
5. **Rechargez la page (F5)**, toujours hors ligne.
   → La caisse revient, avec ses produits. Le bandeau affiche toujours
   **3 ventes en attente** : rien n'a été perdu.

**À dire :** chaque vente est écrite dans le navigateur, avec son identifiant,
**avant** le moindre appel réseau. Une coupure de courant ou un onglet fermé ne
peut pas faire disparaître une vente.

---

### 4. Reconnecter — 1 min

1. Rebranchez le réseau (ou décochez **Hors ligne**).
2. Le bandeau passe au bleu **« Synchronisation… »**, puis au vert **« Synchronisé »**.
3. Connectez l'écran 2 en gérant (`koffi.nguessan@zedpos.ci` / `gerant123`)
   → **Ventes** : les trois tickets hors ligne sont là, à leur heure réelle.

**À dire :** le serveur reconnaît chaque vente à son identifiant. Si la caisse
renvoie deux fois la même — parce que la réponse s'était perdue — le serveur
répond « je l'ai déjà » au lieu de créer un doublon. **Aucune vente perdue, aucune
vente comptée deux fois.**

---

### 5. Clôturer la caisse — 2 min

1. Sur la caisse, en haut à droite : **Ticket X**.
   → Synthèse imprimable de la journée. **Elle ne clôture rien**, la caisse reste
   ouverte. Fermez l'onglet.
2. Toujours en haut à droite : **Clôture Z**.
3. L'écran affiche le calcul :
   `fond d'ouverture + espèces encaissées − dépenses − sorties = théorique`.
4. **Saisissez exactement le montant théorique affiché**, laissez le commentaire vide,
   puis **Clôturer la caisse (Z)**.
   → Écart **0 FCFA**, rapport Z imprimable.

> Saisissez bien le montant exact : votre caisse tombe alors juste, et le seul écart
> de la journée reste celui de **−2 500 FCFA** du poste de Yao Kouassi — c'est
> l'anomalie que vous montrerez à l'étape suivante.

**À dire :** la caissière ne saisit **que** ce qu'elle a compté. Le théorique est
calculé par le serveur, jamais saisi. Un écart non nul **exige** un commentaire —
et une fois le Z passé, la journée est verrouillée : plus une vente, plus une
annulation.

> **Variante si le client insiste sur les écarts :** saisissez 1 000 FCFA de moins.
> Le système refuse la clôture tant que le commentaire est vide.

---

### 6. Le téléphone de la dirigeante — 2 min

> « Elle est à Abidjan. Elle n'appelle personne. Elle regarde son téléphone. »

1. Écran 2 → `aya.kone@zedpos.ci` / `dirigeante123` → arrive sur **/pilotage**.
2. **Le CA du jour** s'affiche en très gros, avec la comparaison à la veille et au
   même jour de la semaine dernière, en vert ou en rouge.

3. **Anomalie n° 1 — l'annulation.** En haut, un **encadré rouge** :

   > *Vente V…-00107 annulée (600 FCFA)*
   > Ticket encaissé par Fatou Traoré, annulé par Koffi N'Guessan.
   > Motif : Client s'est ravisé après encaissement.

   Touchez **Voir le ticket** → le détail complet, avec le nom du caissier.

   **À dire :** un gérant ne peut pas annuler une vente discrètement. La dirigeante
   est prévenue automatiquement, avec le motif et le nom de celui qui a annulé.

4. **Anomalie n° 2 — l'écart de caisse.** Descendez au bloc
   **« Points de vigilance »**, encadré ambre :

   | Ligne | Valeur |
   |---|---|
   | Annulations | 1 · 600 FCFA |
   | Écart de caisse | **−2 500 FCFA** |

   **À dire :** 2 500 FCFA manquent dans la caisse de Yao Kouassi ce soir. Elle le
   voit sans rien demander, le soir même.

5. Faites défiler : ventilation par mode de règlement (Espèces, Wave, Orange Money,
   MTN MoMo), top 10 des produits, courbe du CA sur 30 jours.

6. Onglet **Audit** → chaque action sensible est tracée : qui, quoi, quand, valeurs
   avant/après, adresse IP. **Rien ne s'y efface, jamais.**

---

## Pour terminer — le rapport du soir (30 s, optionnel)

```bash
php bin/console app:rapport-quotidien
```

Un message court, prêt à coller dans WhatsApp — planifiable à 21h30 en cron. Les
deux anomalies y figurent :

```
Vigilance :
  - 1 annulation (600 FCFA)
  - Écart de caisse : -2 500 FCFA
```

---

## Pour le client qui a un cabinet comptable (1 min, optionnel)

Connectez-vous en comptable (`cabinet@zedpos.ci` / `comptable123`) : vous arrivez
sur `/comptabilite`. Cliquez sur **Mois en cours**.

Trois choses à montrer, dans cet ordre :

1. Le bloc **« Contrôles conformes »** en vert. Le chiffre d'affaires, la TVA, les
   espèces et les mouvements de caisse du fichier sont rapprochés de ceux de la
   caisse, au franc près. *« Votre comptable n'a pas à nous croire sur parole. »*
2. La **balance générale** en bas : `7021` produits finis, `7011` marchandises,
   `4431` TVA, `5711` caisse, `5521` Wave… — du SYSCOHADA, pas un format maison.
3. Le bouton **FEC** : le fichier que le cabinet importe directement dans son
   logiciel. *« Plus de ressaisie de fin de mois. »*

> Si vous avez déjà fait la clôture de caisse à l'étape 5, l'écart de −2 500 FCFA
> apparaît en `6588` — la démonstration se boucle : l'anomalie constatée en caisse
> ressort telle quelle en comptabilité.

---

## Questions fréquentes en démonstration

| Question | Réponse |
|---|---|
| « Et si la caissière change un prix ? » | Elle ne peut pas. Seule la dirigeante fixe un prix de vente — le champ n'existe même pas dans le formulaire du gérant. |
| « Un caissier voit-il mes marges ? » | Jamais. Ni coût de revient, ni marge, ni CA global, ni les ventes de ses collègues. |
| « Et si on annule une vente pour voler ? » | Une vente n'est jamais supprimée : elle est annulée, tracée, et la dirigeante est notifiée. |
| « Ça marche sans Internet ? » | Vous venez de le voir. Et rien ne se perd au retour du réseau. |
| « Et si le courant saute pendant la vente ? » | La vente est écrite avant tout appel réseau. Elle repart à la prochaine ouverture de la caisse. |
| « Combien de temps pour former une caissière ? » | Un PIN à 4 chiffres, des touches produits, un bouton Encaisser. Une matinée. |

---

## Remise à zéro entre deux démonstrations

```bash
php bin/console app:demo:reset
```

Puis, dans le navigateur de la caisse : **DevTools → Application → Effacer les
données du site** (vide IndexedDB et le cache du Service Worker), sinon les ventes
de la démonstration précédente resteraient en file d'attente.
