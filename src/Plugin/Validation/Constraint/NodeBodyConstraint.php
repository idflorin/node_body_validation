<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Plugin/Validation/Constraint/NodeBodyConstraint.php.
namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates configurable node body rules.
 */
#[Constraint(
  id: 'NodeBodyValidate',
  label: new TranslatableMarkup('Node body validation'),
)]
final class NodeBodyConstraint extends SymfonyConstraint {

  /**
   * Blocked terms message.
   */
  public string $blockedTermsMessage = 'Body contains blocked characters, words, or phrases: @terms.';
  /**
   * Min characters message.
   */
  public string $minCharactersMessage = 'Body must contain at least @limit characters.';
  /**
   * Max characters message.
   */
  public string $maxCharactersMessage = 'Body must contain no more than @limit characters.';
  /**
   * Min words message.
   */
  public string $minWordsMessage = 'Body must contain at least @limit words.';
  /**
   * Max words message.
   */
  public string $maxWordsMessage = 'Body must contain no more than @limit words.';
  /**
   * Duplicate message.
   */
  public string $duplicateMessage = 'Another accessible node already uses this body.';

}
