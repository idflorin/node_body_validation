<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/tests/src/Unit/SettingsNormalizerTest.php.
namespace Drupal\Tests\node_body_validation\Unit;

use Drupal\node_body_validation\Service\SettingsNormalizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests backward compatibility and typed, optional configuration values.
 */
#[Group('node_body_validation')]
final class SettingsNormalizerTest extends UnitTestCase {

  /**
   * Test legacy settings preserve all rules.
   */
  public function testLegacySettingsPreserveAllRules(): void {
    $settings = (new SettingsNormalizer())->normalize([
      'unique' => '1',
      'exclude-news_article' => '1',
      'comma-news_article' => '0',
      'min-news_article' => '200',
      'max-news_article' => '0',
      'min-wc-news_article' => '40',
      'max-wc-news_article' => '',
      'unique-news_article' => '1',
    ]);
    $this->assertSame([
      'unique' => TRUE,
      'content_types' => [
        'news_article' => [
          'exclude' => '1',
          'comma' => FALSE,
          'min' => 200,
          'max' => NULL,
          'min-wc' => 40,
          'max-wc' => NULL,
          'unique' => TRUE,
        ],
      ],
    ], $settings);
  }

  /**
   * Test nested settings take precedence even when disabled.
   */
  public function testNestedSettingsTakePrecedenceEvenWhenDisabled(): void {
    $normalizer = new SettingsNormalizer();
    $settings = $normalizer->normalize([
      'content_types' => ['article' => ['min' => NULL, 'unique' => FALSE]],
      'min-article' => 500,
      'unique-article' => TRUE,
      'max-article' => '9000',
    ]);
    $this->assertNull($settings['content_types']['article']['min']);
    $this->assertFalse($settings['content_types']['article']['unique']);
    $this->assertSame(9000, $settings['content_types']['article']['max']);
    $this->assertSame($settings, $normalizer->normalize($settings));
  }

  /**
   * Preserves whole-number representations that legacy number widgets accepted.
   */
  public function testLegacyWholeNumberRepresentations(): void {
    $normalizer = new SettingsNormalizer();
    foreach (['00200', '200.0', '2e2', 200.0] as $value) {
      $this->assertSame(200, $normalizer->normalizeRules(['min' => $value])['min']);
    }
    $this->assertNull($normalizer->normalizeRules(['min' => '200.5'])['min']);
    $this->assertNull($normalizer->normalizeRules(['min' => PHP_FLOAT_MAX])['min']);
  }

  /**
   * Test empty and malformed configuration.
   */
  public function testEmptyAndMalformedConfiguration(): void {
    $normalizer = new SettingsNormalizer();
    foreach ([NULL, '', FALSE, []] as $data) {
      $this->assertSame(['unique' => FALSE, 'content_types' => []], $normalizer->normalize($data));
    }
    $rules = $normalizer->normalizeRules([
      'min' => '-1',
      'max' => '1.5',
      'min-wc' => 'false',
      'max-wc' => [],
      'unique' => 'false',
      'comma' => 'true',
    ]);
    $this->assertNull($rules['min']);
    $this->assertNull($rules['max']);
    $this->assertNull($rules['min-wc']);
    $this->assertNull($rules['max-wc']);
    $this->assertFalse($rules['unique']);
    $this->assertTrue($rules['comma']);
    $settings = $normalizer->normalize(['content_types' => ['article' => 'invalid'], 'min-article' => 5]);
    $this->assertSame(5, $settings['content_types']['article']['min']);
  }

}
