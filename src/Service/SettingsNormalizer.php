<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Service/SettingsNormalizer.php.
namespace Drupal\node_body_validation\Service;

/**
 * Converts legacy flat rules and form values to typed, nested settings.
 */
final readonly class SettingsNormalizer {

  public const DEFAULT_RULES = [
    'exclude' => '',
    'comma' => FALSE,
    'min' => NULL,
    'max' => NULL,
    'min-wc' => NULL,
    'max-wc' => NULL,
    'unique' => FALSE,
  ];

  /**
   * Normalizes nested or flat settings, optionally preserving sparse rules.
   */
  public function normalize(mixed $data, bool $include_defaults = TRUE): array {
    $data = is_array($data) ? $data : [];
    $types = is_array($data['content_types'] ?? NULL) ? $data['content_types'] : [];
    foreach ($data as $key => $value) {
      if (preg_match('/^(exclude|comma|min-wc|max-wc|min|max|unique)-(.+)$/D', (string) $key, $matches) === 1) {
        if (!isset($types[$matches[2]]) || !is_array($types[$matches[2]])) {
          $types[$matches[2]] = [];
        }
        if (!array_key_exists($matches[1], $types[$matches[2]])) {
          $types[$matches[2]][$matches[1]] = $value;
        }
      }
    }

    $normalized = [
      'unique' => filter_var($data['unique'] ?? FALSE, FILTER_VALIDATE_BOOLEAN),
      'content_types' => [],
    ];
    foreach ($types as $bundle => $rules) {
      if (!is_array($rules)) {
        continue;
      }
      $normalized['content_types'][$bundle] = $this->normalizeRules($rules, $include_defaults);
    }
    ksort($normalized['content_types']);
    return $normalized;
  }

  /**
   * Converts one content type to correctly typed validation rules.
   */
  public function normalizeRules(array $rules, bool $include_defaults = TRUE): array {
    $normalized = $include_defaults ? self::DEFAULT_RULES : array_intersect_key(self::DEFAULT_RULES, $rules);
    foreach ($normalized as $key => $default) {
      $value = $rules[$key] ?? $default;
      $normalized[$key] = match ($key) {
        'comma', 'unique' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        'exclude' => is_string($value) || is_numeric($value) ? trim((string) $value) : '',
        default => $this->normalizeLimit($value),
      };
    }
    return $normalized;
  }

  /**
   * Returns a positive integer or NULL for a disabled limit.
   */
  private function normalizeLimit(mixed $value): ?int {
    $limit = filter_var($value, FILTER_VALIDATE_INT);
    if ($limit !== FALSE) {
      return $limit > 0 ? $limit : NULL;
    }
    // Preserve whole values that legacy number widgets stored as strings.
    if ((is_string($value) || is_float($value)) && is_numeric($value)) {
      $number = (float) $value;
      if (is_finite($number) && $number > 0 && $number < PHP_INT_MAX && floor($number) === $number) {
        return (int) $number;
      }
    }
    return NULL;
  }

}
