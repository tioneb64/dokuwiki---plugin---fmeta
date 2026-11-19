# Fmeta : en-têtes de fiches

Plugin DokuWiki (`fmeta`).

Lit l'**en-tête YAML** (frontmatter) d'une page, comme dans Obsidian, et l'affiche sous forme de fiche : statut, dates, hashtags et autres champs. Tient un **index** (`data/index/fmeta.json`) qui permet de lister les pages partageant un tag, une date, un statut…

Les plugins `fmtags`, `fmlife` et `fmtable` s'appuient sur lui.

## Fonctions

- En-tête `---` … `---` en tête de page (le `---` d'ouverture peut manquer) ; commentaires YAML (`# …`) acceptés, y compris en fin de ligne.
- Champs reconnus : `statut`/`status`, `owner`, `created`, `updated`, `revue`, `expire`, `tags` ; tous les autres champs sont conservés et affichés.
- `updated` (dernière modification) est **réécrite automatiquement à chaque enregistrement** (ajoutée si absente, juste après `created`). Les enregistrements automatiques, comme le clic sur une case du plugin `tasklist`, ne la modifient pas (drapeau `$GLOBALS['fmeta_keep_updated']`).
- `revue` (dernière relecture) est saisie à la main et n'est jamais modifiée automatiquement. Synonymes : `last-revue`, `dernière_revue`, `last-review`.
- `expire` est facultative ; synonymes : `expired`, `expires`, `expiration`, `valid-until`. Sans valeur, `fmlife` la calcule (voir `validity_months`) et le tableau l'affiche en italique.
- Les `#hashtags` du corps de la page sont ajoutés aux tags.
- Pages de liste `?do=fmlist&field=tag&value=ia` (par tag, date, statut…).
- Index mis à jour à chaque enregistrement ; page d'administration pour le reconstruire ; en ligne de commande : `php bin/plugin.php fmeta reindex`.

## Dépendances

Aucune. Fonctionne avec la syntaxe Markdown (`$conf['syntax'] = 'md'`).

## Installation

Copier le contenu de ce dépôt dans `lib/plugins/fmeta/` (le fichier `plugin.info.txt` doit se trouver à la racine de ce dossier), puis vider le cache en rendant la date de `conf/local.php` plus récente (`touch conf/local.php`).

Avec le gestionnaire d'extensions de DokuWiki : *Administration → Gestionnaire d'extensions → Installer*, en indiquant l'adresse du dépôt (archive ZIP).

## Licence

Aucune licence n'a encore été choisie : à ajouter (fichier `LICENSE`) avant de publier le dépôt. DokuWiki est sous GPL-2.0.
