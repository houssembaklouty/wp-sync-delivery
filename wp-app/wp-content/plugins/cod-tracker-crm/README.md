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

## Réglages (Suivi COD → Réglages)
Assignation automatique à tour de rôle, agents actifs, seuil de tentatives, jours avant « colis bloqué »,
passerelle SMS (URL, GET/POST, form/JSON, `{to}` `{message}` `{sender}` `{api_key}`).

## Import d'un relevé transporteur
CSV avec en-têtes : un n° de suivi (« code », « code barre »…), un « montant » ; facultatifs : « frais », « relevé ».
Séparateur `,` `;` ou tabulation. L'aperçu classe chaque ligne (conforme / écart / non livré / inconnu / doublon /
illisible) et **rien n'est modifié avant confirmation**. Conforme → « Reversé » ; écart ou colis non livré → « Litige »
(+ tâche pour l'agent).

## Limites connues
- FirstDelivery ne fournit pas d'historique daté : délais, tournées et ancienneté des étapes ne sont mesurés
  qu'à partir des changements d'état suivis en direct.
- Le suivi Colissimo lit la page publique `tracking.php` (pas d'API de suivi documentée).
- Les nouvelles chaînes ne sont pas encore traduites (français uniquement).
