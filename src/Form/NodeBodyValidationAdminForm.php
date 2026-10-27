<?php

namespace Drupal\node_body_validation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for the node body validation rules.
 */
class NodeBodyValidationAdminForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
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
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('node_body_validation.settings')->get('node_body_validation_config');
    $config = is_array($config) ? $config : [];

    $form['types'] = ['#type' => 'container'];

    $first = TRUE;
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $id = $type->id();
      $form['types'][$id] = [
        '#type' => 'details',
        '#title' => $type->label(),
        '#open' => $first,
      ];
      $first = FALSE;

      $form['types'][$id]['exclude-' . $id] = [
        '#type' => 'textarea',
        '#title' => $this->t('Blacklist characters/words'),
        '#default_value' => $config['exclude-' . $id] ?? '',
        '#description' => $this->t("Comma or line separated characters or words that are not allowed in the body. Example: !,@,#,have,has,aren't. Matching ignores case; words match whole words only."),
      ];
      $form['types'][$id]['comma-' . $id] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Add comma to blacklist.'),
        '#default_value' => $config['comma-' . $id] ?? 0,
      ];
      $form['types'][$id]['min-' . $id] = [
        '#type' => 'number',
        '#title' => $this->t('Minimum characters'),
        '#required' => TRUE,
        '#description' => $this->t('Minimum number of characters (visible text, without HTML).'),
        '#min' => 1,
        '#max' => 99999,
        '#default_value' => $config['min-' . $id] ?? 1,
      ];
      $form['types'][$id]['max-' . $id] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum characters'),
        '#required' => TRUE,
        '#description' => $this->t('Maximum number of characters (visible text, without HTML).'),
        '#min' => 1,
        '#max' => 99999,
        '#default_value' => $config['max-' . $id] ?? 99999,
      ];
      $form['types'][$id]['min-wc-' . $id] = [
        '#type' => 'number',
        '#title' => $this->t('Minimum word count'),
        '#required' => TRUE,
        '#min' => 1,
        '#max' => 4999,
        '#default_value' => $config['min-wc-' . $id] ?? 1,
      ];
      $form['types'][$id]['max-wc-' . $id] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum word count'),
        '#description' => $this->t('Leave empty for no limit.'),
        '#min' => 1,
        '#default_value' => $config['max-wc-' . $id] ?? 4999,
      ];
      $form['types'][$id]['unique-' . $id] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Unique node body for @type content type', ['@type' => $type->label()]),
        '#default_value' => $config['unique-' . $id] ?? 0,
      ];
    }

    $form['unique'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unique node body for all content types'),
      '#default_value' => $config['unique'] ?? 0,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple()) as $id) {
      $min = $form_state->getValue('min-' . $id);
      $max = $form_state->getValue('max-' . $id);
      if ($min !== '' && $max !== '' && (int) $min > (int) $max) {
        $form_state->setErrorByName('min-' . $id, $this->t('Minimum length should not be more than maximum length.'));
      }
      $min_wc = $form_state->getValue('min-wc-' . $id);
      $max_wc = $form_state->getValue('max-wc-' . $id);
      if ($min_wc !== '' && $max_wc !== '' && (int) $min_wc > (int) $max_wc) {
        $form_state->setErrorByName('max-wc-' . $id, $this->t('Minimum word count should not be more than maximum word count.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = [];
    foreach (array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple()) as $id) {
      $values['comma-' . $id] = (int) $form_state->getValue('comma-' . $id);
      $values['exclude-' . $id] = trim((string) $form_state->getValue('exclude-' . $id));
      $values['min-' . $id] = $form_state->getValue('min-' . $id);
      $values['max-' . $id] = $form_state->getValue('max-' . $id);
      $values['min-wc-' . $id] = $form_state->getValue('min-wc-' . $id);
      $values['max-wc-' . $id] = $form_state->getValue('max-wc-' . $id);
      $values['unique-' . $id] = (int) $form_state->getValue('unique-' . $id);
    }
    $values['unique'] = (int) $form_state->getValue('unique');

    $this->configFactory->getEditable('node_body_validation.settings')
      ->set('node_body_validation_config', $values)
      ->save();

    $this->messenger()->addStatus($this->t('Node body validation configuration saved.'));
  }

}
