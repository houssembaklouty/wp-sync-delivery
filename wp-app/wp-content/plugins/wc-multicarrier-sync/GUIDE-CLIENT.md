# MultiCarrier Sync TN — Guide d'installation

Merci pour votre achat ! Ce guide vous accompagne de l'installation jusqu'à la première synchronisation de commande. Comptez 10 minutes.

---

## 1. Prérequis

- Un site **WordPress** avec **WooCommerce installé et activé**
- PHP 7.4 ou supérieur
- Votre **clé de licence** (format `WCMCS-XXXX-XXXX-XXXX`), envoyée avec ce fichier

> Sans WooCommerce, le plugin se désactive proprement et affiche un message — rien ne casse.

## 2. Installation

1. Dans l'administration WordPress : **Extensions → Ajouter**
2. Cliquez sur **Téléverser une extension** (en haut)
3. Choisissez le fichier `wc-multicarrier-sync-XXX.zip` → **Installer maintenant** → **Activer**

## 3. Licence et mode gratuit

À l'activation, vous disposez de **3 jours d'essai illimité**. Ensuite, sans licence, le plugin reste **entièrement fonctionnel** en **mode gratuit : 3 synchronisations réussies par jour** (remises à zéro chaque nuit).

Pour synchroniser sans limite, activez votre clé :

1. Menu **WooCommerce → MultiCarrier Sync TN → onglet 🔑 Licence**
2. Collez votre clé `WCMCS-XXXX-XXXX-XXXX` → **Activer la licence**

Bon à savoir :

- **Une licence = un site** : la clé se lie automatiquement au domaine de votre boutique
- **Changer de site / de domaine ?** Cliquez d'abord sur **« Désactiver la licence (libère le domaine) »** sur l'ancien site, puis activez la clé sur le nouveau
- La licence est **à vie**, sans expiration

## 4. Configurer vos transporteurs

Toujours dans **WooCommerce → MultiCarrier Sync TN → onglet 🗺 Règles de détection** :

1. **Activez** les transporteurs souhaités (Colissimo TN, FirstDelivery…) avec les boutons ⚙ **Configurer** :
   - Renseignez vos identifiants API fournis par le transporteur
   - Vérifiez le **mapping des champs** (nom, adresse, gouvernorat, téléphone…) — les valeurs par défaut conviennent à la plupart des boutiques
2. Choisissez **comment chaque commande trouve son transporteur** (par ordre de priorité) :
   - Règle par **méthode de livraison** (ex: `flat_rate:1` → Colissimo)
   - Règle par **gouvernorat / pays** (ex: `TN:SFAX` → FirstDelivery)
   - Règle par **catégorie de produit**
   - Ou un **transporteur par défaut**

## 5. Envoyer vos commandes

- **Depuis la liste des commandes** : cochez des commandes → action groupée **📦 Synchroniser commandes**
- **Depuis une commande** : bouton **⇄ Synchroniser** dans la metabox « Synchronisation transporteur »
- Après synchronisation : **numéro de suivi** et **étiquette PDF** 📄 apparaissent sur la commande
- En cas d'échec : le lien **⚠ Voir erreur** affiche le détail, et **↺ Retry** relance la synchronisation

> Seules les commandes **payées à la livraison (COD)** sont envoyées aux transporteurs.

## 6. Support

- Email : contact via **baklouty.tn**
- Décrivez le problème + le numéro de commande + le message d'erreur visible dans « Voir erreur »
