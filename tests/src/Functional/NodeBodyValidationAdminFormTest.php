<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/tests/src/Functional/NodeBodyValidationAdminFormTest.php.
namespace Drupal\Tests\node_body_validation\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests route permissions, optional limits, range validation, and form errors.
 */
#[Group('node_body_validation')]
#[RunTestsInSeparateProcesses]
final class NodeBodyValidationAdminFormTest extends BrowserTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node_body_validation'];
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    $this->createContentType(['type' => 'no_body', 'name' => 'No body'], FALSE);
  }

  /**
   * Test admin permissions and optional limits.
   */
  public function testAdminPermissionsAndOptionalLimits(): void {
    $path = 'admin/config/content/node-body-validation';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->createUser(['access content']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->createUser(['node body validation admin control']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('content_types[no_body][min]');
    $this->submitForm([
      'content_types[article][min]' => 10,
      'content_types[article][max]' => 5,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The minimum must not exceed the maximum.');

    $this->submitForm([
      'content_types[article][min]' => 10,
      'content_types[article][max]' => '',
      'content_types[article][min-wc]' => '',
      'content_types[article][max-wc]' => 0,
      'content_types[article][exclude]' => 'spam',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $settings = $this->config('node_body_validation.settings')->get('node_body_validation_config');
    $this->assertSame(10, $settings['content_types']['article']['min']);
    $this->assertNull($settings['content_types']['article']['max']);
    $this->assertNull($settings['content_types']['article']['min-wc']);
    $this->assertNull($settings['content_types']['article']['max-wc']);
    $this->assertFalse($settings['unique']);
  }

  /**
   * Test body errors appear on node form.
   */
  public function testBodyErrorsAppearOnNodeForm(): void {
    $this->config('node_body_validation.settings')->set('node_body_validation_config', [
      'unique' => FALSE,
      'content_types' => [
        'article' => [
          'exclude' => 'spam',
          'comma' => FALSE,
          'min' => 2,
          'max' => NULL,
          'min-wc' => NULL,
          'max-wc' => NULL,
          'unique' => FALSE,
        ],
      ],
    ])->save();
    $this->drupalLogin($this->createUser(['create article content']));
    $this->drupalGet('node/add/article');
    $this->submitForm([
      'title[0][value]' => 'Body validation',
      'body[0][value]' => 'SPAM!',
    ], 'Save');
    $this->assertSession()->addressEquals('node/add/article');
    $this->assertSession()->pageTextContains('Body contains blocked characters, words, or phrases: spam.');
    $this->submitForm(['body[0][value]' => 'Useful content.'], 'Save');
    $this->assertSession()->pageTextContains('Body validation');
    $this->assertSession()->addressMatches('/node\/\d+$/');
  }

}
