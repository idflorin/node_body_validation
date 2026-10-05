<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Form/NodeBodyValidationAdminForm.php.
namespace Drupal\node_body_validation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node_body_validation\Service\SettingsNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures optional body-validation rules for applicable content types.
 */
final class NodeBodyValidationAdminForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly SettingsNormalizer $normalizer,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('node_body_validation.settings_normalizer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'node_body_validation_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['node_body_validation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('node_body_validation.settings');
    $settings = $this->normalizer->normalize($config->get('node_body_validation_config'));
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Character and word limits evaluate text without HTML markup. Leave a limit blank or enter 0 to disable it. Required field settings control whether authors must provide a body.') . '</p>',
    ];
    $form['content_types'] = ['#tree' => TRUE];
    foreach ($this->bodyContentTypes() as $bundle => $type) {
      $rules = $settings['content_types'][$bundle] ?? SettingsNormalizer::DEFAULT_RULES;
      $form['content_types'][$bundle] = [
        '#type' => 'details',
        '#title' => $type->label(),
        '#open' => FALSE,
      ];
      $form['content_types'][$bundle]['exclude'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Blocked characters, words, or phrases'),
        '#default_value' => $rules['exclude'],
        '#description' => $this->t('Separate entries with commas or new lines. Matching ignores letter case. Single characters match anywhere; words and phrases use whole-word boundaries.'),
      ];
      $form['content_types'][$bundle]['comma'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Block commas'),
        '#default_value' => $rules['comma'],
      ];
      foreach ([
        'min' => $this->t('Minimum characters'),
        'max' => $this->t('Maximum characters'),
        'min-wc' => $this->t('Minimum words'),
        'max-wc' => $this->t('Maximum words'),
      ] as $key => $label) {
        $form['content_types'][$bundle][$key] = [
          '#type' => 'number',
          '#title' => $label,
          '#min' => 0,
          '#step' => 1,
          '#default_value' => $rules[$key] ?? '',
        ];
      }
      $form['content_types'][$bundle]['unique'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Require a unique body within this content type'),
        '#default_value' => $rules['unique'],
      ];
    }
    if (count($form['content_types']) === 1) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('No content types currently have a supported body field.') . '</p>'];
    }
    $form['unique'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require a unique body across all content types'),
      '#default_value' => $settings['unique'],
      '#description' => $this->t('Check identical stored body values among nodes the validating account can access. This global rule takes precedence over content type uniqueness rules.'),
    ];
    $tags = $config->getCacheTags();
    foreach (['node_type', 'field_config', 'field_storage_config'] as $entity_type) {
      $tags = array_merge($tags, $this->entityTypeManager->getDefinition($entity_type)->getListCacheTags());
    }
    $form['#cache'] = [
      'tags' => array_values(array_unique($tags)),
      'contexts' => ['user.permissions', 'languages:language_interface'],
      'max-age' => 0,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($this->bodyContentTypes() as $bundle => $type) {
      $rules = $form_state->getValue(['content_types', $bundle], []);
      foreach (['min', 'max', 'min-wc', 'max-wc'] as $key) {
        $value = $rules[$key] ?? NULL;
        if ($value !== NULL && $value !== '' && (filter_var($value, FILTER_VALIDATE_INT) === FALSE || (int) $value < 0)) {
          $form_state->setErrorByName('content_types][' . $bundle . '][' . $key, $this->t('Enter a non-negative whole number.'));
        }
      }
      foreach (['min' => 'max', 'min-wc' => 'max-wc'] as $min => $max) {
        if (($rules[$min] ?? 0) > 0 && ($rules[$max] ?? 0) > 0 && $rules[$min] > $rules[$max]) {
          $form_state->setErrorByName('content_types][' . $bundle . '][' . $min, $this->t('The minimum must not exceed the maximum.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('node_body_validation.settings');
    $settings = $this->normalizer->normalize($config->get('node_body_validation_config'));
    foreach ($this->bodyContentTypes() as $bundle => $type) {
      $settings['content_types'][$bundle] = $this->normalizer->normalizeRules(
        $form_state->getValue(['content_types', $bundle], []),
      );
    }
    $settings['unique'] = (bool) $form_state->getValue('unique', FALSE);
    $config->set('node_body_validation_config', $this->normalizer->normalize($settings))->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Returns content types that currently have a supported body field.
   */
  private function bodyContentTypes(): array {
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($types as $bundle => $type) {
      $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      if (!isset($fields['body']) || !in_array($fields['body']->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
        unset($types[$bundle]);
      }
    }
    return $types;
  }

}
