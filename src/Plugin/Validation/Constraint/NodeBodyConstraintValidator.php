<?php

namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the NodeBodyValidate constraint.
 */
class NodeBodyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    if ($items->isEmpty()) {
      return;
    }
    $value_body = (string) $items->value;
    if (trim($value_body) === '') {
      return;
    }

    $node = $items->getEntity();
    if (!$node instanceof NodeInterface) {
      return;
    }
    $type = $node->bundle();

    $config = $this->configFactory
      ->get('node_body_validation.settings')
      ->get('node_body_validation_config');
    if (empty($config) || !is_array($config)) {
      return;
    }

    // All rules work on the visible text, not on the stored HTML.
    $text = $this->toPlainText($value_body);

    // Blacklisted characters / words.
    $exclude = $this->parseBlacklist((string) ($config['exclude-' . $type] ?? ''));
    if (!empty($config['comma-' . $type])) {
      $exclude[] = ',';
    }
    if ($exclude) {
      $findings = $this->findExcluded($text, $exclude);
      if ($findings) {
        $this->context->addViolation((string) $this->t('These characters/words are not permitted in the body: @findings', [
          '@findings' => implode(' ', $findings),
        ]));
      }
    }

    // Character count.
    $length = mb_strlen($text);
    $min = $this->limit($config, 'min-' . $type);
    if ($min !== NULL && $length < $min) {
      $this->context->addViolation((string) $this->t('Body should have a minimum of @count character(s).', ['@count' => $min]));
    }
    $max = $this->limit($config, 'max-' . $type);
    if ($max !== NULL && $length > $max) {
      $this->context->addViolation((string) $this->t('Body should not exceed @count character(s).', ['@count' => $max]));
    }

    // Word count.
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $word_count = count($words);
    $min_wc = $this->limit($config, 'min-wc-' . $type);
    if ($min_wc !== NULL && $word_count < $min_wc) {
      $this->context->addViolation((string) $this->t('Body should have a minimum word count of @count.', ['@count' => $min_wc]));
    }
    $max_wc = $this->limit($config, 'max-wc-' . $type);
    if ($max_wc !== NULL && $word_count > $max_wc) {
      $this->context->addViolation((string) $this->t('Body should not exceed a word count of @count.', ['@count' => $max_wc]));
    }

    // Uniqueness.
    $unique_all = !empty($config['unique']);
    $unique_type = !empty($config['unique-' . $type]);
    if ($unique_all || $unique_type) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition($items->getFieldDefinition()->getName() . '.value', $value_body);
      if (!$unique_all) {
        $query->condition('type', $type);
      }
      if (!$node->isNew()) {
        $query->condition('nid', $node->id(), '<>');
      }
      $ids = $query->range(0, 1)->execute();
      if ($ids) {
        $this->context->addViolation((string) $this->t('The body must be unique. Content item @id already uses the same body.', [
          '@id' => reset($ids),
        ]));
      }
    }
  }

  /**
   * Converts body HTML to normalized plain text.
   */
  protected function toPlainText(string $html): string {
    // Keep words in neighbouring block elements apart.
    $html = preg_replace('/<\/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|blockquote)\b[^>]*>/i', ' ', $html) ?? $html;
    $text = Html::decodeEntities(strip_tags($html));
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
  }

  /**
   * Splits the blacklist textarea into lowercase, unique entries.
   */
  protected function parseBlacklist(string $raw): array {
    $items = preg_split('/[,\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $items = array_map(fn($item) => mb_strtolower(trim($item)), $items);
    return array_values(array_unique(array_filter($items, fn($item) => $item !== '')));
  }

  /**
   * Returns the blacklist entries present in the text, quoted for display.
   */
  protected function findExcluded(string $text, array $exclude): array {
    $lower = mb_strtolower($text);
    $findings = [];
    foreach ($exclude as $item) {
      if (mb_strlen($item) === 1) {
        $found = mb_strpos($lower, $item) !== FALSE;
      }
      else {
        // Whole-word match, so "have" does not match "haven" but does match
        // "have," or "have.".
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($item, '/') . '(?![\p{L}\p{N}_])/u';
        $found = (bool) preg_match($pattern, $lower);
      }
      if ($found) {
        $findings[] = '"' . $item . '"';
      }
    }
    return $findings;
  }

  /**
   * Reads a numeric limit from config; NULL when unset or empty.
   */
  protected function limit(array $config, string $key): ?int {
    $value = $config[$key] ?? NULL;
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }
    return (int) $value;
  }

}
