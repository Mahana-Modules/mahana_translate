# Mahana Translate (PrestaShop 9)

Mahana Translate is a free translation module for PrestaShop 9.

This plugin was developed for [Mahana-Monoi.com](https://mahana-monoi.com). It is free to use; a link back to the site is appreciated.

## Features
- Translate products, categories, CMS pages, and `anblog` blog content.
- Batch translation with progress feedback.
- Per-item translation from selected back-office edit screens.
- Supports OpenAI and Google Translate providers.
- Safe for multi-language and multi-shop contexts.

## Installation
1) Download the module as a zip where the root folder is `mahana_translate/`.
2) In PrestaShop back office: Modules -> Module Manager -> Upload a module.
3) Configure your API provider and keys.

## Usage
- Go to the module configuration page to run batch translations.
- On product, category, `anblog` blog post, and `anblog` blog category edit pages, use the "Translate" button to translate the current item.

## What Gets Translated

### Products
Batch translation and per-product translation are supported.

Translated fields:
- `name`
- `description_short`
- `description`
- `meta_title`
- `meta_description`

### Categories
Batch translation and per-category translation are supported.

Translated fields:
- `name`
- `description`
- `additional_description`
- `meta_title`
- `meta_description`

### CMS Pages
Batch translation is supported.

Translated fields:
- `meta_title`
- `meta_description`
- `content`

### anblog Blog Posts
Batch translation and per-post translation are supported, without modifying the `anblog` module itself.

Translated fields:
- `meta_title`
- `meta_description`
- `meta_keywords`
- `tags`
- `description`
- `content`

Automatically regenerated:
- `link_rewrite`

`link_rewrite` is not sent to the translation provider. It is regenerated from the translated `meta_title`.

### anblog Blog Categories
Batch translation and per-category translation are supported, without modifying the `anblog` module itself.

Translated fields:
- `title`
- `content_text`
- `meta_title`
- `meta_description`
- `meta_keywords`

Automatically regenerated:
- `link_rewrite`

`link_rewrite` is not sent to the translation provider. It is regenerated from the translated `title`.

## Translation Behavior

- Source and target languages must be different.
- Existing target values are preserved by default.
- Enable `Force overwrite` to replace existing translations.
- Empty source fields are skipped.
- Missing target language rows are created automatically when needed.
- Multi-shop context is respected: only rows linked to the current shop context are processed.

## Back Office Entry Points

### Batch
Available from the Mahana Translate configuration page for:
- Products
- Categories
- CMS pages
- Blog posts (`anblog`)
- Blog categories (`anblog`)

### Per-item translation
Available directly from these edit screens:
- Product edit page
- Category edit page
- `AdminAnblogBlogs` edit page
- `AdminAnblogCategories` edit page

## Not Translated

The module does not currently translate:
- Back-office interface strings
- `anblog` widgets
- `anblog` global settings
- `anblog` comments
- Content from other blog modules such as `smartblog`, `leoblog`, or custom blog modules

## Notes
- API keys must be added in the module settings.
- Do not store API keys in the repository.

## Credits
Developed for [Mahana-Monoi.com](https://mahana-monoi.com). If you use this module, a link to the site would be appreciated.
