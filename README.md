# Dynamic Metafield

Plugin WordPress open-source pour lier les blocs Gutenberg natifs aux champs **Secure Custom Fields** (API compatible ACF), via la **Block Bindings API**, un panneau **InspectorControls**, et un rendu serveur `render_block` (contexte Query Loop).

## Prérequis

- WordPress **6.7+**
- Plugin **Secure Custom Fields** (ou ACF exposant `get_field` / `acf_get_field_groups`)
- Node.js 18+ (pour compiler les assets éditeur)

## Installation

1. Copier ce dossier dans `wp-content/plugins/dynamic-metafield`
2. Installer et compiler les assets :

```bash
npm install
npm run build
```

3. Activer le plugin dans l’admin WordPress

En développement : `npm start` (watch).

## Utilisation

### Préfixe / suffixe (Avant / Après)

Sur une liaison, tu peux ajouter du texte **avant** et **après** la valeur du champ.

Exemple : champ = `github.com/pseudo/app`

- Avant : `https://`
- Après : `/releases.atom`

→ résultat : `https://github.com/pseudo/app/releases.atom`

Utile pour un bouton, un paragraphe, un flux **RSS**, ou un **embed YouTube**.

### Blocs supportés

- Paragraphe, Titre, Image, Bouton
- **RSS** (`feedURL`)
- **Contenu embarqué / YouTube** (`core/embed`)


1. Ouvrir un contenu dans l’éditeur de blocs
2. Sélectionner un bloc **Paragraphe**, **Titre**, **Image** ou **Bouton**
3. Dans la colonne latérale, panneau **Liaison SCF** :
   - Choisir le **contexte** : publication, options SCF, utilisateur (auteur), ou terme
   - Choisir le **champ** (y compris sous-champs de groupes / chemins repeater)
   - Optionnel : activer **Masquer si vide**
4. Enregistrer et prévisualiser le front

La liaison est stockée dans `metadata.bindings` (portable, sans meta propriétaire).

### Boucle Repeater / Flexible — bloc **SCF Field Loop**

1. Insérer le bloc **SCF Field Loop** (`dynamic-metafield/loop`)
2. Choisir un champ **repeater** ou **flexible content**
3. Placer à l’intérieur des blocs liés aux **sous-champs** (chemins relatifs)
4. Sur le front, le conteneur itère chaque ligne et rend les enfants

Filtre optionnel `layout` pour ne garder qu’un layout Flexible Content.

### Query Loop

Le filtre `render_block` lit `$block['context']['postId']` : les liaisons fonctionnent correctement dans une boucle de requêtes.

## Structure

```
dynamic-metafield.php
includes/          # PHP (bindings, render, REST, assets, loop)
blocks/loop/       # block.json du conteneur
src/               # React / Gutenberg (source)
build/             # JS compilé (généré)
```

## API REST

- `GET /wp-json/dynamic-metafield/v1/fields?scf_context=post&post_id=123&post_type=post`
- `GET /wp-json/dynamic-metafield/v1/values?scf_context=option`

Capability requise : `edit_posts`.

## Licence

GPL-2.0-or-later
