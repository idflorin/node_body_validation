<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/tests/src/Kernel/NodeBodyValidationTest.php.
namespace Drupal\Tests\node_body_validation\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\node_body_validation\Service\SettingsMigration;
use Drupal\node_body_validation\Service\SettingsNormalizer;
use Drupal\node_body_validation\Form\NodeBodyValidationAdminForm;
use Drupal\Core\Form\FormState;
use Drupal\Tests\SchemaCheckTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests field constraints, settings schema, and configuration updates.
 */
#[Group('node_body_validation')]
#[RunTestsInSeparateProcesses]
final class NodeBodyValidationTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'node_body_validation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'node_body_validation']);
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'cardinality' => -1,
    ])->save();
    foreach (['article', 'page'] as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => ucfirst($bundle)])->save();
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Body',
      ])->save();
    }
  }

  /**
   * Test text limits and error paths.
   */
  public function testTextLimitsAndErrorPaths(): void {
    $this->setRules(['min' => 11, 'max' => 11, 'min-wc' => 2, 'max-wc' => 2]);
    $node = $this->node('<p>hel<strong>lo</strong>&nbsp;world</p>');
    $this->assertCount(0, $node->get('body')->validate());
    $node->set('body', [
      ['value' => '<p>hello world</p>', 'format' => 'plain_text'],
      ['value' => '<p>short</p>', 'format' => 'plain_text'],
    ]);
    $violations = $node->get('body')->validate();
    $this->assertCount(2, $violations);
    foreach ($violations as $violation) {
      $this->assertSame('1.value', $violation->getPropertyPath());
    }

    $this->setRules(['min' => 1, 'max' => 1, 'min-wc' => 1, 'max-wc' => 1]);
    $this->assertCount(0, $this->node('0')->get('body')->validate());
    $this->assertCount(0, $this->node('')->get('body')->validate());
    $this->assertCount(2, $this->node('<p>&nbsp;</p>')->get('body')->validate());
    $this->assertCount(0, $this->node('x', 'page')->get('body')->validate());
  }

  /**
   * Test blocked terms use text and whole words.
   */
  public function testBlockedTermsUseTextAndWholeWords(): void {
    $this->setRules(['exclude' => "art\r\nbuy now", 'comma' => TRUE]);
    $node = $this->node('<p title="art">Artistry wins</p>');
    $this->assertCount(0, $node->get('body')->validate());
    $node->set('body', ['value' => '<p>ART! Buy <strong>now</strong>, please.</p>', 'format' => 'plain_text']);
    $violations = $node->get('body')->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('0.value', $violations[0]->getPropertyPath());
    $this->assertSame('Body contains blocked characters, words, or phrases: art, buy now, ,.', (string) $violations[0]->getMessage());
  }

  /**
   * Test uniqueness scopes and editing.
   */
  public function testUniquenessScopesAndEditing(): void {
    $this->setRules(['unique' => TRUE]);
    $first = $this->node('<p>Original body</p>');
    $first->save();
    $this->assertCount(0, $first->get('body')->validate());
    $other_type = $this->node('<p>Original body</p>', 'page');
    $other_type->save();
    $this->assertCount(0, $first->get('body')->validate());
    $this->assertCount(1, $this->node('<p>Original body</p>')->get('body')->validate());
    $this->assertCount(0, $this->node('<p>Different body</p>')->get('body')->validate());

    $duplicate = $this->node('<p>Original body</p>');
    $duplicate->save();
    $violations = $first->get('body')->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('Another accessible node already uses this body.', (string) $violations[0]->getMessage());

    $this->setRules([], TRUE);
    $this->assertCount(1, $this->node('<p>Original body</p>', 'page')->get('body')->validate());
    $this->assertCount(0, $this->node('<p>Original <strong>body</strong></p>', 'page')->get('body')->validate());
  }

  /**
   * Verifies that uniqueness queries respect the validating account's access.
   */
  public function testUniquenessRespectsNodeAccess(): void {
    $this->setRules([], TRUE);
    $hidden = $this->node('<p>Hidden body</p>');
    $hidden->setUnpublished()->save();
    Role::create(['id' => 'reader', 'label' => 'Reader'])
      ->grantPermission('access content')->save();
    $reader = User::create(['uid' => 2, 'name' => 'Reader', 'roles' => ['reader']]);
    $reader->save();
    $this->container->get('current_user')->setAccount($reader);
    $this->assertCount(0, $this->node('<p>Hidden body</p>')->get('body')->validate());
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));
    $this->assertCount(1, $this->node('<p>Hidden body</p>')->get('body')->validate());
  }

  /**
   * Test configuration schema.
   */
  public function testConfigurationSchema(): void {
    $this->setRules(['min' => '200', 'max' => '', 'min-wc' => '40', 'comma' => '1']);
    $config = $this->config('node_body_validation.settings');
    $typed = $this->container->get('config.typed')->createFromNameAndData($config->getName(), $config->getRawData());
    $this->assertCount(0, $typed->validate());
    $this->assertConfigSchema($this->container->get('config.typed'), $config->getName(), $config->getRawData());
    $form = NodeBodyValidationAdminForm::create($this->container)->buildForm([], new FormState());
    $this->assertSame(0, $form['#cache']['max-age']);
    $this->assertContains('user.permissions', $form['#cache']['contexts']);
    $this->assertContains('config:node_body_validation.settings', $form['#cache']['tags']);
    foreach (['node_type', 'field_config', 'field_storage_config'] as $entity_type) {
      $tags = $this->container->get('entity_type.manager')->getDefinition($entity_type)->getListCacheTags();
      foreach ($tags as $tag) {
        $this->assertContains($tag, $form['#cache']['tags']);
      }
    }
  }

  /**
   * Test legacy migration preserves settings and title module.
   */
  public function testLegacyMigrationPreservesSettingsAndTitleModule(): void {
    $factory = $this->container->get('config.factory');
    // Seed configuration in the format of an existing installation.
    $storage = $this->container->get('config.storage');
    $storage->write('node_body_validation.node_body_validation_settings', [
      'node_body_validation_config' => ['min-page' => '100', 'max-page' => '500', 'unique' => '1'],
    ]);
    $storage->write('node_body_validation_config.node_body_validation_settings', [
      'node_body_validation_config' => ['min-article' => '5', 'max-article' => '800'],
    ]);
    $storage->write('node_body_validation.settings', [
      'node_body_validation_config' => ['min-article' => '250', 'min-wc-article' => '50', 'unique' => '0'],
    ]);
    $storage->write('node_title_validation.settings', ['node_title_validation_config' => ['unique' => TRUE]]);
    $storage->write('node_title_validation.node_title_validation_settings', ['sentinel' => 'keep']);
    $factory->reset();
    $this->container->get('module_handler')->loadInclude('node_body_validation', 'install');
    node_body_validation_update_110001();
    $migration = new SettingsMigration($factory, new SettingsNormalizer());
    $saved = $factory->get('node_body_validation.settings')->get('node_body_validation_config');
    $this->assertSame(250, $saved['content_types']['article']['min']);
    $this->assertSame(800, $saved['content_types']['article']['max']);
    $this->assertSame(50, $saved['content_types']['article']['min-wc']);
    $this->assertSame(100, $saved['content_types']['page']['min']);
    $this->assertSame(500, $saved['content_types']['page']['max']);
    $this->assertFalse($saved['unique']);
    $this->assertTrue($factory->getEditable('node_body_validation.node_body_validation_settings')->isNew());
    $this->assertTrue($factory->getEditable('node_body_validation_config.node_body_validation_settings')->isNew());
    $this->assertTrue($factory->get('node_title_validation.settings')->get('node_title_validation_config.unique'));
    $this->assertSame('keep', $factory->get('node_title_validation.node_title_validation_settings')->get('sentinel'));
    $migration->migrate();
    $this->assertSame($saved, $factory->get('node_body_validation.settings')->get('node_body_validation_config'));
  }

  /**
   * Stores normalized rules for the article content type.
   */
  private function setRules(array $rules, bool $global = FALSE): void {
    $settings = (new SettingsNormalizer())->normalize([
      'unique' => $global,
      'content_types' => ['article' => $rules],
    ]);
    $this->config('node_body_validation.settings')->set('node_body_validation_config', $settings)->save();
  }

  /**
   * Creates an unsaved node with a formatted body value.
   */
  private function node(string $body, string $bundle = 'article'): Node {
    return Node::create([
      'type' => $bundle,
      'title' => 'Validation test',
      'uid' => 1,
      'status' => 1,
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ]);
  }

}
