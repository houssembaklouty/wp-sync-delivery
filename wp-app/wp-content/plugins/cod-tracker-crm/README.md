# COD Tracker CRM

Suivi des colis / commandes COD pour WooCommerce, branché sur **MultiCarrier Sync TN** (≥ 2.10.5).

| Module | Où | Quoi |
|---|---|---|
| Timeline | metabox « Suivi COD » d'une commande | statuts, états transporteur datés, contacts, tâches, assignations, cash |
| Tentatives | automatique | tournées / échecs de livraison, risque de retour au seuil (défaut 3) |
| Agents | Suivi COD → Mes suivis | rôle « Agent suivi COD », file de travail, appel / WhatsApp / SMS tracés, rappels |
| Scanner | Suivi COD → Scanner | « Emballée » et « Remis au transporteur » par code-barres / QR (caméra ou douchette) |
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
| SMS | passerelle HTTP générique, envois automatiques, test |
| WhatsApp | modèles de messages (jusqu'à 5) |

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
