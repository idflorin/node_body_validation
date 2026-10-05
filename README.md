# Node Body Validation

Node Body Validation 4.0.0 validates the node field named `body` through Drupal's entity validation API. It applies character limits, word limits, literal blocklists, and duplicate-body rules per content type.

This update draws on the configuration structure and validation features of Node Title Validation 3.0.1. It implements body-specific text handling and does not require Node Title Validation.

## Requirements

- Drupal 11.1 or later within the Drupal 11 release series.
- PHP 8.4 or later.
- Core Node and Text modules.
- A node field named `body` with the `text`, `text_long`, or `text_with_summary` field type.

Version 4 drops Drupal 10 support. The attribute-based hook implementation requires Drupal 11.1 or later.

## Install or update

1. Replace the entire existing `node_body_validation` module directory with the directory from this release. This removes the obsolete procedural `.module` file and duplicate install configuration.
2. From the target site's Composer project root, run the database updates and rebuild caches. Select the site's URI when the project serves multiple sites.

```bash
# Run from the target site's Composer project root.
./vendor/bin/drush updatedb -y
./vendor/bin/drush cache:rebuild
```

For a new installation, enable the module instead:

```bash
# Run from the target site's Composer project root.
./vendor/bin/drush en node_body_validation -y
./vendor/bin/drush cache:rebuild
```

Open **Configuration > Content authoring > Node body validation**, or visit `/admin/config/content/node-body-validation`. The existing `node body validation admin control` permission still controls this form.

## Configure the rules

The form lists content types with supported body fields. For each type, configure:

- **Blocked entries:** Comma-separated or newline-separated characters, words, or phrases.
- **Block commas:** A separate checkbox because commas delimit blocklist entries.
- **Minimum and maximum characters:** Optional independent limits.
- **Minimum and maximum words:** Optional independent limits.
- **Unique body within this content type:** Compare other accessible nodes of that type.

The global **Require a unique body across all content types** setting takes precedence over per-type uniqueness settings. Leave a numeric limit blank, or enter `0`, to disable it. The form accepts a minimum without a maximum and rejects an enabled minimum that exceeds its enabled maximum.

**Example:** Set Minimum words to `200`, leave Maximum words blank, and add `buy now` to the blocklist. Authors must supply at least 200 words in a non-empty body, and the validator rejects that phrase regardless of letter case.

## Text and matching behavior

Length and blocklist rules use plain text extracted from stored HTML:

- Paragraphs, list items, table cells, and line breaks separate text.
- Inline markup preserves word continuity. `hel<strong>lo</strong>` counts as `hello`.
- HTML entities decode before evaluation. Non-breaking spaces and repeated whitespace normalize to ordinary spaces.
- Attributes, HTML comments, and script, style, template, and noscript content do not count.
- Character counts use UTF-8 code points. Combining characters can count separately.
- Word counts recognize Unicode letters and numbers; apostrophes and hyphens can join a word. Punctuation-only tokens do not count. Languages without word separators do not receive linguistic segmentation.
- Single-character entries match anywhere. Longer entries use Unicode whole-word boundaries and ignore letter case. The blocklist treats entries as literal text, not regular expressions.

The validator does not execute text-format filters or evaluate CSS visibility. It checks every body item and reports errors on that item's value. A genuinely empty optional field remains optional; configure the field's Required setting if authors must supply a body. Markup-only input must meet enabled minimum limits. The literal value `0` counts as text.

## Duplicate-body behavior

Uniqueness compares stored body values using the database's comparison semantics. Different HTML markup can produce different stored values even when the text looks identical. Database collation can affect case sensitivity.

The checker excludes the current node during edits. It uses `accessCheck(TRUE)`, verifies node view access and body-field view access, and stops when it finds an accessible match. It processes matches in batches of 25 so inaccessible matches cannot hide a later accessible duplicate. It never includes another node's body, title, ID, or URL in the error message.

These rules apply when Drupal validates an entity, including node forms. Programmatic callers must call `$node->validate()` and handle violations before `$node->save()`. Entity storage does not automatically perform validation. These application rules do not provide a database uniqueness lock for concurrent writes.

## Existing settings and migration

Update `node_body_validation_update_110001()` converts flat keys such as `min-article` and `min-wc-article` into typed rules under:

`node_body_validation.settings:node_body_validation_config.content_types.article`

The migration reads the current configuration and both legacy body configuration names. It preserves global uniqueness, merges supplied settings field by field, and gives current settings priority. It saves the new configuration before removing obsolete body configuration objects. Running the migration again produces the same result.

The repaired historical update `8001` uses the same body-only migration. Neither update reads, writes, or deletes Node Title Validation configuration.

To roll back to version 3, restore the prior module files and pre-update active configuration together. Version 3 cannot read the nested version 4 settings.

## Architecture

- `src/Hook/NodeBodyValidationHooks.php`: Attribute-based hooks attach the field constraint.
- `src/Plugin/Validation/Constraint/`: The constraint and its container-injected validator coordinate validation.
- `src/Service/BodyTextAnalyzer.php`: HTML text extraction, word counting, and blocklist matching.
- `src/Service/DuplicateBodyChecker.php`: Access-aware duplicate lookup and edit exclusion.
- `src/Service/SettingsNormalizer.php`: Typed settings and legacy-key normalization.
- `src/Service/SettingsMigration.php`: Configuration migration.
- `src/Form/NodeBodyValidationAdminForm.php`: A ConfigFormBase form with explicit cache metadata.
- `config/schema/node_body_validation.schema.yml`: Configuration schema and positive-limit constraints.

The existing route and permission names remain stable. Dependency injection supplies runtime services. Only the procedural database-update entry points use Drupal's static configuration factory.

## Tests

The test suite covers:

- HTML text boundaries, entities, Unicode, punctuation, and literal blocklists.
- Legacy and nested settings, disabled limits, and typed values.
- Bounded duplicate queries, edit exclusion, and matches after inaccessible fields.
- Real Drupal field constraints, multi-value error paths, and unpublished-node access.
- Configuration schema, form cache metadata, migration precedence, idempotence, and title-setting isolation.
- Admin permissions, optional limits, invalid ranges, and node-form validation.

Release verification: **49 tests passed** on Drupal 11.4.8, PHP 8.4.26, PHPUnit 11.5.57, and SQLite: 41 unit tests, 6 kernel tests, and 2 browser tests. PHP syntax, YAML parsing, Composer metadata, Drupal coding standards, and whitespace checks also passed.

Install the matching `drupal/core-dev` dependencies in a Drupal development project. Supply a dedicated test database and base URL for browser tests, then run from the Drupal web root:

```bash
# <Drupal web root>/modules/custom/node_body_validation/tests/
../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/node_body_validation/tests
```

Adjust the relative vendor path if the project uses a different layout. Run coding standards with Drupal Coder installed:

```bash
# <Composer project root>/web/modules/custom/node_body_validation/phpcs.xml.dist
./vendor/bin/phpcs --standard=web/modules/custom/node_body_validation/phpcs.xml.dist web/modules/custom/node_body_validation
```

The PHPCS ruleset excludes only the Drupal.org auto-generated-version warning because this custom GitHub module maintains its own version metadata.

## Changelog

### 4.0.0

- Target Drupal 11.1+ and PHP 8.4+.
- Replace procedural runtime hooks with PHP attributes.
- Add typed nested settings, configuration schema, and safe legacy migration.
- Make all numeric limits optional and remove arbitrary form ceilings.
- Count extracted HTML text consistently with Unicode-aware word matching.
- Fix comma-only blocking, punctuation handling, duplicate messages, and the `0` value.
- Apply uniqueness rules across types, exclude the edited node, and verify node and field access.
- Add unit, kernel, and browser regression tests.
- Correct module metadata and remove the obsolete duplicate install configuration.

---

## Sources Used

- [Node Title Validation](https://www.drupal.org/project/node_title_validation), including the supplied 3.0.1 source.
- [Drupal attribute-based hook API](https://www.drupal.org/node/3442349).
- [Drupal ConfigFormBase API](https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Form!ConfigFormBase.php/11.x).
