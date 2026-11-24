# COD Tracker CRM

Suivi des colis / commandes COD pour WooCommerce, branché sur **MultiCarrier Sync TN** (≥ 2.10.5).

| Module | Où | Quoi |
|---|---|---|
| Timeline | metabox « Suivi COD » d'une commande | statuts, états transporteur datés, contacts, tâches, assignations, cash |
| Tentatives | automatique | tournées / échecs de livraison, risque de retour au seuil (défaut 3) |
| Agents | Suivi COD → Mes suivis | rôle « Agent suivi COD », file de travail, appel / WhatsApp / SMS tracés, rappels |
| Scanner | Suivi COD → Scanner | « Emballée » et « Retour reçu » par code-barres / QR (caméra ou douchette) |
| SMS | Suivi COD → Réglages | passerelle HTTP générique, envois manuels et automatiques (désactivés par défaut) |
| KPIs | Suivi COD → Tableau de bord | taux de livraison / retour, délais, colis bloqués, activité des agents |
| Cash | Suivi COD → Cash COD | attendu / encaissé / reversé, import du relevé transporteur (CSV), litiges, export |

## Installation
1. Activer **MultiCarrier Sync TN ≥ 2.10.5** puis ce plugin (les tables sont créées à l'activation).
2. Créer les agents : Utilisateurs → Ajouter → rôle « Agent suivi COD ».
3. Remplir l'historique des commandes existantes :
   ```
   wp codcrm backfill --refresh      # importe l'historique daté des transporteurs (lent : ~1,7 s / colis)
   wp codcrm repair                  # doublons de meta, étapes recalculées, lignes cash
   ```
   Ne jamais lancer deux `backfill` en même temps (un verrou l'empêche).

## Réglages (Suivi COD → Réglages) — un onglet par fonction
Chaque onglet a son formulaire : enregistrer un onglet ne modifie jamais les autres.

| Onglet | Contenu |
|---|---|
| Général | seuil de tentatives, colis bloqué après N jours, enlèvement en attente après N jours |
| Répartition & agents | mode (automatique / manuel), méthode (équitable / pourcentage), réattribution automatique, agents : actif, part %, horaires de travail |
| Règles d'attribution | règles « conditions → membres » (produit, catégorie, gouvernorat, transporteur, montant) |
| SMS | passerelle HTTP générique, envois automatiques (déclencheurs), test |
| Messages | bibliothèque de modèles FR / AR pour WhatsApp et SMS, par situation |
| Couleurs | couleur des badges de statut (étapes du colis, statuts du cash) |

## Colis retournés : réception, stock et colis perdus
Le scan d'enlèvement se fait à l'agence du transporteur (la boutique n'en a pas la visibilité) : le scanner propose donc
**« ↩ Retour reçu »** quand un colis retourné revient à la boutique. Le scan est vérifié avant validation :
retour déclaré par le transporteur → prêt (même si la commande est « Terminée ») ; colis enlevé mais retour pas encore
déclaré → accepté avec avertissement ; colis livré au client, jamais enlevé / pas encore expédié, annulé, déjà reçu,
sans code-barres ou inconnu → refusé. Une réception vaut pour un **cycle** : si le colis est réexpédié puis revient
une seconde fois, il peut être scanné de nouveau. Deux scans simultanés du même colis ne remettent le stock qu'une fois (verrou). À la validation : le colis est marqué reçu, les
**articles sont remis en stock** (uniquement les produits qui gèrent leur stock et seulement les quantités réellement
déduites : jamais de double remise en stock), la timeline et une note de commande le tracent, la ligne cash passe à
« Retourné ». Le statut WooCommerce ne change pas (aucun email client).

**Détection des colis perdus** : si N jours (Réglages → Général, défaut 5) après un retour **définitif** annoncé par le
transporteur (Retour expéditeur / définitif / payé, ou statut « Retournée ») le colis n'a pas été scanné « Retour reçu » :
une tâche « Retour non reçu » est créée pour l'agent assigné (dédoublonnée), un **email récapitulatif quotidien** part
vers l'administrateur (uniquement quand de **nouveaux** colis manquent, liste de 50 colis maximum ; les colis sans agent sont signalés « non assigné »), une **carte, une liste et une notice** apparaissent
dans le tableau de bord / l'administration, avec un bouton « Marquer reçu » pour un colis rentré sans scan. Seuls les
retours annoncés après l'activation de la fonction sont surveillés (l'historique n'est pas signalé). La carte et la liste du tableau de bord suivent les filtres transporteur / agent / gouvernorat (pas la période). Le compteur
est stocké et recalculé par le cron : aucune requête lourde à l'affichage des pages. Filtre `codcrm_returns_missing` pour ajuster la liste.

## Envois groupés (file d'attente)
Dans « Mes suivis », cochez des colis (ou « Sélectionner tous les résultats du filtre », jusqu'à 1000) puis **✉ Message groupé** :
canal, situation (modèle de la bibliothèque), langue (celle de chaque client ou FR / AR) et aperçu. L'envoi est une **tâche en file d'attente** (page
« Envois groupés », progression en direct) :
- **SMS** : traités en arrière-plan par lots (cron chaque minute ; cadence réglable dans Réglages → Général, défaut 20 SMS / minute),
  3 essais par SMS, **pause automatique** si la passerelle échoue 5 fois de suite, pause / reprise / annulation, relance des échecs.
  Jamais de double envoi : un SMS marqué « en cours » dont le traitement a été interrompu devient « état incertain » (à vérifier avant relance).
  Chaque SMS est tracé dans la chronologie de la commande (avec le numéro de la tâche).
- **WhatsApp** : pas d'envoi automatique possible (wa.me exige un clic) → liste « à contacter » : chaque clic ouvre le message prêt
  et trace le contact.
- « Tous les résultats du filtre » est préparé par tranches en arrière-plan (statut « Préparation de la liste… »).
- Un agent ne peut écrire qu'aux clients de ses propres commandes ; il ne voit que ses tâches.
- Si WP-Cron est inactif, la page de la tâche ouverte fait avancer la file (au plus toutes les 2 minutes).

La liste « Mes suivis » est lue en SQL direct (instantané, au lieu de ~50 s) quand les commandes sont dans les tables HPOS.

## Couleurs des statuts
Même logique que MultiCarrier Sync : une couleur par défaut par statut, personnalisable (sélecteur de couleur, aperçu en
direct, bouton ↺ par ligne et « Tout remettre par défaut »), texte noir ou blanc choisi automatiquement selon le
contraste. Ordre de priorité : **couleur choisie dans Réglages → Couleurs** > **couleur du statut équivalent dans
MultiCarrier Sync** (Confirmée, Colis créé, Emballée, Livré, Retour définitif… : le badge est identique à celui de la
liste des commandes et suit les personnalisations faites côté MultiCarrier Sync) > couleur par défaut du CRM.
Choisir la couleur d'origine supprime la personnalisation. S'applique à la timeline, « Mes suivis », au tableau de bord
et à la page Cash.

## Messages clients (WhatsApp et SMS, français et arabe)
Bouton **✉ Message** dans « Mes suivis » et sur la fiche commande : une seule fenêtre avec le canal (WhatsApp / SMS),
la langue (FR / AR) et la situation. La **langue** est proposée d'après le nom et l'adresse du client (arabe → AR) et le
**modèle** d'après l'étape du colis (en livraison → « Livreur en route », reporté → « Livraison reportée », livré →
« Merci », retourné → …) ; l'agent peut tout changer et modifier le texte avant l'envoi. Chaque envoi est tracé dans la
timeline avec sa situation et sa langue.

14 situations livrées en FR et AR, WhatsApp et SMS : confirmer la commande, vérifier adresse/téléphone, commande
confirmée, colis expédié, livreur en route, livreur n'a pas pu vous joindre, livraison reportée, client injoignable,
colis au dépôt, dernière tentative (risque de retour), livré (merci), colis retourné, retard d'enlèvement, message libre.
Tous les textes sont modifiables dans **Réglages → Messages** (« Rétablir » = texte d'origine).
Placeholders : `{client}` `{commande}` `{suivi}` `{transporteur}` `{montant}` `{boutique}` `{telephone_boutique}` `{date}`.
Les SMS français sont écrits avec le jeu GSM-7 (1 SMS = 160 caractères) ; les SMS arabes sont brefs (UCS-2, 70 caractères
par segment). Les SMS **automatiques** utilisent les mêmes textes, dans la langue du client ; chaque déclencheur pointe vers
une situation (`CODCRM_SMS_Gateway::trigger_context()`), ce qui permet d'en ajouter d'autres plus tard.

## Répartition automatique des commandes
Attribution **à la création de la commande** (checkout classique, blocs, formulaire COD, commandes admin/REST).
1. Les **règles** actives sont évaluées par priorité croissante ; la première qui correspond attribue la commande à ses
   membres **en service** (répartis équitablement). Conditions distinctes = ET ; valeurs d'une même condition = OU.
   Si aucun membre de la règle n'est en service, la commande **attend** (elle n'échappe pas à sa règle).
2. Sans règle correspondante : méthode globale parmi les agents actifs en service — **équitable** (le moins chargé
   aujourd'hui) ou **par pourcentage** (part de chacun).
3. **Horaires** : plages par jour (ex. 08:00–12:00 et 13:00–18:00), heure du site ; aucun horaire = toujours en service.
4. Commandes **en attente** d'un agent : reprises toutes les 5 minutes dès qu'un agent concerné prend son service.
5. **Réattribution** : si l'agent n'a ni confirmé la commande ni tracé un contact dans le délai (30 min par défaut),
   elle passe à un autre agent en service (jamais le même, 2 fois maximum). Les attributions manuelles du propriétaire
   ne sont jamais réattribuées.
Toutes les décisions (règle, méthode, attente, réattribution) apparaissent dans la timeline de la commande.

## Import d'un relevé transporteur
CSV avec en-têtes : un n° de suivi (« code », « code barre »…), un « montant » ; facultatifs : « frais », « relevé ».
Séparateur `,` `;` ou tabulation. L'aperçu classe chaque ligne (conforme / écart / non livré / inconnu / doublon /
illisible) et **rien n'est modifié avant confirmation**. Conforme → « Reversé » ; écart ou colis non livré → « Litige »
(+ tâche pour l'agent).

## Définition des taux (tableau de bord)
Les taux ne portent **que sur les colis enlevés** par la société de livraison (un état du transporteur l'atteste :
enlevé, au dépôt, en livraison, livré, retour…). Taux de livraison = livrés ÷ (livrés + retournés). Les colis créés
chez le transporteur mais jamais récupérés (« Non enlevés ») et les colis annulés / supprimés sont exclus ; les non
enlevés ont leur propre carte, une liste d'alerte au-delà de N jours (défaut 2) et une tâche quotidienne
« Enlèvement en attente » pour l'agent assigné. Le scan « Remis au transporteur » de la boutique ne compte pas comme
enlèvement : seule la confirmation du transporteur fait foi.

## Limites connues
- FirstDelivery ne fournit pas d'historique daté : délais, tournées et ancienneté des étapes ne sont mesurés
  qu'à partir des changements d'état suivis en direct.
- Le suivi Colissimo lit la page publique `tracking.php` (pas d'API de suivi documentée).
- Les nouvelles chaînes ne sont pas encore traduites (français uniquement).

## « Mes suivis » : historique des statuts et fenêtre « Modifier » (v1.6.0)
- La colonne **Statut** affiche, au survol de la pastille (ou au clic sur mobile), l'historique complet : client / agent / logo du transporteur, date et statut.
- Le bouton **✎ Modifier** ouvre une fenêtre : statut, société de livraison, agent (responsables seulement), échange, relance (crée une tâche de rappel), note privée, client (téléphone 2 inclus), produits (recherche, quantité, prix), frais de livraison et code promo.
- Une fois le colis créé chez le transporteur (commande synchronisée), client / produits / frais / transporteur sont **verrouillés côté serveur** (le transporteur n'est pas mis à jour) ; statut, agent, notes et relance restent modifiables.
- Un agent ne modifie que ses commandes ; chaque modification est tracée dans la chronologie.

### Design de la fenêtre « Modifier » (v1.6.1)
- Deux colonnes (client + produits à gauche ; commande, options, note et résumé à droite), plein écran sur mobile.
- En-tête : statut et total en direct ; « Enregistrer » actif seulement après une modification (raccourci Ctrl/Cmd + S).
- Téléphones vérifiés en direct ; erreurs affichées sous le champ concerné.
- Produits : recherche avec vignettes, attributs et stock (clavier ↑ ↓ Entrée), boutons − / +, retrait annulable.
- Après enregistrement : notification et ligne surlignée ; l'agent attribué n'est envoyé que s'il a été changé.

## Historique client et colonne Produits (v1.7.0)
- Colonne **Client** : badges « ★ Client régulier » (≥ 2 commandes avec le même numéro) ou « Nouveau client », taux de livraison 🚚 (livrées ÷ clôturées) et taux de réponse aux appels 📞 (vert ≥ 70 %, orange ≥ 40 %, rouge sinon), signalements de la liste noire.
- Au survol des badges : « Commandes totales : N » avec une carte par commande (n°, date, statut, montant, vignette) et un lien « Voir commandes ».
- Colonne **Produits** : vignettes avec pastille de quantité (3 max, puis « +N »).
- Les statistiques de toute la page sont calculées en 2 requêtes (au lieu de 2 requêtes WooCommerce par ligne).

## Commandes abandonnées (v1.8.0)
- Fiche produit : dès qu'un **téléphone valide** est saisi dans le formulaire COD, une commande au statut **« Abandonnée »** est créée, puis mise à jour pendant la saisie (nom, gouvernorat, délégation, adresse, produit/variations, quantité). Si le client clique sur « Acheter », la vraie commande est créée et l'abandonnée est absorbée (agent, appels et tâches transférés) puis supprimée.
- Une abandonnée est attribuée à un agent (mêmes règles que les autres commandes) après un délai d'inactivité (défaut 15 min), pour qu'il rappelle le client. Filtre « Commandes abandonnées » dans « Mes suivis ». Numéros de la liste noire du thème ignorés ; ni stock réduit, ni ventes, ni statistiques client.
- Réglages (Suivi COD → Général, **activé par défaut**) : activer/désactiver, délai d'attribution, statut d'une commande validée (**En attente** = on-hold par défaut, ou En cours).
- Le thème expose le filtre `wparabic_cod_order_status` (`inc/cod-form.php`). Les caches de pages sont vidés à chaque nouvelle version du plugin ; si une fiche produit reste en cache, videz-le pour qu'elle charge le script.
- Les abandonnées consomment des numéros de commande (trous dans la numérotation) ; pensez à le mentionner dans la politique de confidentialité.

### Restauration du formulaire COD (v1.8.1)
- Si le client rafraîchit la page ou revient sur la fiche produit, ses informations sont **remplies à nouveau** : coordonnées (nom, téléphone, gouvernorat, délégation, adresse, email) sur toutes les fiches ; quantité et choix de variations sur la même fiche. Stockage dans le navigateur du client (7 jours, rien n'est envoyé au serveur pour cela) ; effacé dès que la commande est validée. Les champs déjà remplis par le navigateur ne sont jamais écrasés.
- Réglage Suivi COD → Général → « Restaurer le formulaire » (**activé par défaut**), indépendant de la capture des commandes abandonnées.
- Correctif : les variations (couleur / pointure) choisies sont maintenant bien envoyées à la commande abandonnée.

### Produit et attributs des commandes abandonnées (v1.8.2)
- Même si le client n'a pas fini de choisir (ex. couleur choisie, pointure non), l'abandonnée contient le **produit** et les **attributs déjà choisis** (couleur / pointure…), visibles dans « Mes suivis » (vignette entourée en pointillé + infobulle) et dans la fenêtre « Modifier ». Dès que le choix est complet, les lignes deviennent celles des variations avec leur prix.

## Mes suivis en direct + notification sonore (v1.9.0)
- « Mes suivis » se met à jour **sans rechargement** (sondage léger toutes les 5 s, onglet visible) : nouvelles commandes (dont les abandonnées qui se complètent pendant que le client remplit le formulaire), changements de statut, d'agent, de contact ; lignes devenues hors filtre retirées. Cases cochées et fenêtre « Modifier » ouverte préservées. Indicateur « ● En direct ».
- **Son de notification** à chaque nouvelle commande (une fois par commande, même avec plusieurs onglets) : 4 sons générés par le navigateur (Clochette par défaut, Carillon, Bip, Double bip) ou aucun ; volume réglable ; aperçu « ▶ Écouter ». Réglages → Général. Le navigateur exige un premier clic sur la page pour autoriser le son (bouton « 🔔 Activer le son » sinon) ; 🔕 coupe le son sur ce navigateur.

### Son « Matricula » (v1.9.1)
Cinquième son proposé dans Réglages → Général → « Son nouvelle commande » : fichier audio `assets/sounds/matricula.mp3` (fourni par le propriétaire du site), avec aperçu « ▶ Écouter » et volume.
