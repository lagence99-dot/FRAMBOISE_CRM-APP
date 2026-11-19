# Framboise CRM

Outils de prospection et de suivi commercial de **La Framboise Digitale** et **Les Artisans du Web**.

| Dossier | Contenu |
|---|---|
| `app/` | L'application de prospection (page claude.ai) : e-mail, WhatsApp / SMS, LinkedIn, « Gérer mes contacts clients », recherche annuaires et BNI, suivi des échanges, exports Excel et CRM WordPress. |
| `wordpress-plugin/framboise-crm/` | L'extension WordPress « Framboise CRM » (v1.2.0) : fiches clients, devis, comptes-rendus, suivi de prospection, onglet Échanges, import CSV de la prospection. |
| `docs/` | Notes techniques. |

## Application (`app/`)

- `app/index.html` : page complète, sans étape de build (HTML, CSS et JavaScript dans un seul fichier).
- `app/capabilities.json` : capacités déclarées lors de la publication sur claude.ai (base de données, connecteurs Gmail / Parallel Search / navigateur, génération IA, téléchargements).
- Publiée comme artefact claude.ai. Les données (contacts, messages, suivis, campagnes) sont stockées dans la base de l'artefact, **pas dans ce dépôt**. Hors de claude.ai, la page fonctionne en mode local (stockage du navigateur), sans IA ni connecteurs.

Sauvegarde des données : dans l'application, module WhatsApp, bandeau vert : « Historique des envois (Excel) » et « Sauvegarde complète » (JSON).

## Extension WordPress (`wordpress-plugin/framboise-crm/`)

Installation : zipper le dossier `framboise-crm`, puis WordPress › Extensions › Ajouter › Téléverser.
Tables créées au premier chargement (préfixe `wp_frcrm_`) : `clients`, `contacts`, `quotes`, `reports`, `activity`, `exchanges`.
Import de la prospection : CRM › Clients › « Importer la prospection (CSV) », avec le fichier produit par « Exporter vers le CRM WordPress » dans l'application.

Voir `docs/format-import-csv.md` pour le format d'échange entre les deux outils.
