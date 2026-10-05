<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Plugin/Validation/Constraint/NodeBodyConstraintValidator.php.
namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\node_body_validation\Service\BodyTextAnalyzer;
use Drupal\node_body_validation\Service\DuplicateBodyChecker;
use Drupal\node_body_validation\Service\SettingsNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Applies body rules to every item and reports errors on its value property.
 */
final class NodeBodyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SettingsNormalizer $normalizer,
    private readonly BodyTextAnalyzer $analyzer,
    private readonly DuplicateBodyChecker $duplicateChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('node_body_validation.settings_normalizer'),
      $container->get('node_body_validation.text_analyzer'),
      $container->get('node_body_validation.duplicate_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    if (!$constraint instanceof NodeBodyConstraint) {
      throw new UnexpectedTypeException($constraint, NodeBodyConstraint::class);
    }
    if ($items === NULL) {
      return;
    }
    if (!$items instanceof FieldItemListInterface) {
      throw new UnexpectedValueException($items, FieldItemListInterface::class);
    }
    $node = $items->getEntity();
    if (!$node instanceof NodeInterface || $items->isEmpty()) {
      return;
    }

    $settings = $this->normalizer->normalize($this->configFactory
      ->get('node_body_validation.settings')->get('node_body_validation_config'));
    $rules = $settings['content_types'][$node->bundle()] ?? SettingsNormalizer::DEFAULT_RULES;
    $has_limits = $rules['min'] !== NULL || $rules['max'] !== NULL || $rules['min-wc'] !== NULL || $rules['max-wc'] !== NULL;
    if (!$settings['unique'] && !$rules['unique'] && !$rules['comma'] && $rules['exclude'] === '' && !$has_limits) {
      return;
    }
    foreach ($items as $delta => $item) {
      $raw = $item->getValue()['value'] ?? NULL;
      if ($raw === NULL || $raw === '') {
        continue;
      }
      $raw = (string) $raw;
      $text = $this->analyzer->plainText($raw);
      $path = $delta . '.value';
      $findings = $this->analyzer->blockedTerms($text, $rules['exclude'], $rules['comma']);
      if ($findings !== []) {
        $this->context->buildViolation($constraint->blockedTermsMessage)
          ->setParameter('@terms', implode(', ', $findings))
          ->atPath($path)->addViolation();
      }

      $characters = mb_strlen($text, 'UTF-8');
      $words = $this->analyzer->wordCount($text);
      foreach ([
        'min' => [$characters, $constraint->minCharactersMessage, TRUE],
        'max' => [$characters, $constraint->maxCharactersMessage, FALSE],
        'min-wc' => [$words, $constraint->minWordsMessage, TRUE],
        'max-wc' => [$words, $constraint->maxWordsMessage, FALSE],
      ] as $key => [$count, $message, $minimum]) {
        $limit = $rules[$key];
        if ($limit !== NULL && ($minimum ? $count < $limit : $count > $limit)) {
          $this->context->buildViolation($message)
            ->setParameter('@limit', (string) $limit)
            ->atPath($path)->addViolation();
        }
      }

      if ($text !== '' && ($settings['unique'] || $rules['unique']) && $this->duplicateChecker->exists($node, $raw, $settings['unique'])) {
        $this->context->buildViolation($constraint->duplicateMessage)
          ->atPath($path)->addViolation();
      }
    }
  }

}
