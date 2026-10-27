# Node Body Validation

The **Node Body Validation** module helps site builders enforce body field validation rules for nodes. It ensures consistency in content entry by restricting length, filtering characters or words, and validating uniqueness across content types.

## Features

* **Blacklist characters/words**
  Prevent authors from saving content with disallowed characters or words in the body.

* **Length restrictions**
  Define minimum and maximum character counts.
  Define minimum and maximum word counts.

* **Unique validation**
  Enforce unique node body values per content type or across all content types.

* **Admin configuration form**
  Provides a configuration interface under module settings for easy management of rules.

## Requirements

* Drupal core: ^11.1 (uses OOP `#[Hook]` implementations)
* Dependency: Node module (`drupal:node`)

## Installation

1. Place the module in your `modules/custom` directory:

   ```bash
   /modules/custom/node_body_validation
   ```
2. Enable the module:

   ```bash
   drush en node_body_validation
   ```

   or via the **Extend** UI in Drupal.

## Configuration

1. Go to **Configuration → Content authoring → Node body validation**.
2. For each content type, set:

   * Blacklisted characters/words
   * Minimum/maximum characters
   * Minimum/maximum word count
   * Uniqueness rules
3. Save settings. They will be enforced on node save.

## Example Use Cases

* Prevent the use of certain slang or prohibited terms in article bodies.
* Ensure blog posts are at least 200 words long.
* Require each "News" node to have a unique body field to avoid duplicates.

## Changelog

### 3.1.0

* Validation now runs on the visible text (HTML stripped, entities decoded).
* Fixed duplicate blacklist errors and stray comma checks leaking into other settings.
* Blacklist matching is case-insensitive and whole-word.
* Empty limits no longer fail every body; unique check uses an entity query.
* Added config schema; fixed update hook (`update_10301`) and `hook_help`.
* Requires Drupal ^11.1 (OOP hooks); `.module` file removed.

### 3.0.0

* Updated for **Drupal ^10.4 || ^11**.
* Migrated to **PHP 8 attributes** for constraints.
* Updated configuration key from `node_body_validation.node_body_validation_settings` to `node_body_validation.settings`.
* Added strict return types for methods.
* Added explicit dependency on `drupal:node`.
