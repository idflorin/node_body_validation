<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Service/SettingsMigration.php.
namespace Drupal\node_body_validation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Migrates only body-validation configuration; current settings take priority.
 */
final readonly class SettingsMigration {

  private const LEGACY_NAMES = [
    'node_body_validation_config.node_body_validation_settings',
    'node_body_validation.node_body_validation_settings',
  ];

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private SettingsNormalizer $normalizer,
  ) {}

  /**
   * Merges supplied rules before deleting obsolete body configuration.
   */
  public function migrate(): void {
    $current = $this->configFactory->getEditable('node_body_validation.settings');
    $merged = ['unique' => FALSE, 'content_types' => []];
    foreach ([...self::LEGACY_NAMES, 'node_body_validation.settings'] as $name) {
      $config = $this->configFactory->getEditable($name);
      $raw = $config->get('node_body_validation_config');
      if (!is_array($raw)) {
        continue;
      }
      $normalized = $this->normalizer->normalize($raw, FALSE);
      if (array_key_exists('unique', $raw)) {
        $merged['unique'] = $normalized['unique'];
      }
      elseif ($config->get('unique') !== NULL) {
        $merged['unique'] = filter_var($config->get('unique'), FILTER_VALIDATE_BOOLEAN);
      }
      foreach ($normalized['content_types'] as $bundle => $rules) {
        $merged['content_types'][$bundle] = array_replace($merged['content_types'][$bundle] ?? [], $rules);
      }
    }
    $current->set('node_body_validation_config', $this->normalizer->normalize($merged))->clear('unique')->save();
    foreach (self::LEGACY_NAMES as $name) {
      $config = $this->configFactory->getEditable($name);
      if (!$config->isNew()) {
        $config->delete();
      }
    }
  }

}
