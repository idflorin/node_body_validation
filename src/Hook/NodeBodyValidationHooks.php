<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Hook/NodeBodyValidationHooks.php.
namespace Drupal\node_body_validation\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Attaches body constraints through Drupal 11 attribute-based hooks.
 */
final readonly class NodeBodyValidationHooks {

  public function __construct(
    private TranslationInterface $translation,
  ) {}

  /**
   * Implements hook_entity_base_field_info_alter().
   */
  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    $this->addConstraint($fields, $entity_type);
  }

  /**
   * Implements hook_entity_bundle_field_info_alter().
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    $this->addConstraint($fields, $entity_type);
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): array {
    if ($route_name !== 'help.page.node_body_validation') {
      return [];
    }
    return [
      '#markup' => '<p>' . $this->translation->translate('Configure body character limits, word limits, blocked terms, and uniqueness rules under Configuration > Content authoring > Node body validation. Length and blocklist rules evaluate text without HTML markup.') . '</p>',
      '#cache' => [
        'tags' => [],
        'contexts' => ['languages:language_interface'],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Attaches the constraint only to supported node body fields.
   */
  private function addConstraint(array &$fields, EntityTypeInterface $entity_type): void {
    if ($entity_type->id() !== 'node' || !isset($fields['body'])) {
      return;
    }
    if (in_array($fields['body']->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
      $fields['body']->addConstraint('NodeBodyValidate', []);
    }
  }

}
