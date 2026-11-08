<?php

namespace Drupal\node_body_validation\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Node Body Validation.
 */
class NodeBodyValidationHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.node_body_validation') {
      return NULL;
    }
    $output = '<p>' . $this->t('Validates the node body with minimum/maximum character and word counts, blacklisted characters and words, and unique body values.') . '</p>';
    $output .= '<p>' . $this->t('Validate the node body by:') . '</p>';
    $output .= '<ul>';
    $output .= '<li>' . $this->t('Blacklisted characters and words.') . '</li>';
    $output .= '<li>' . $this->t('Length (minimum and maximum characters and words).') . '</li>';
    $output .= '<li>' . $this->t('Unique node body (per content type or across all).') . '</li>';
    $output .= '</ul>';
    return $output;
  }

  /**
   * Implements hook_entity_bundle_field_info_alter().
   *
   * Body is a configurable field, so it only shows up in the bundle hook.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function bundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'node' || empty($fields['body'])) {
      return;
    }
    if (!array_key_exists('NodeBodyValidate', $fields['body']->getConstraints())) {
      $fields['body']->addConstraint('NodeBodyValidate', []);
    }
  }

}
