<?php

namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the node body against the configured rules.
 */
#[Constraint(
  id: 'NodeBodyValidate',
  label: new TranslatableMarkup('Node body validation')
)]
class NodeBodyConstraint extends SymfonyConstraint {

}
