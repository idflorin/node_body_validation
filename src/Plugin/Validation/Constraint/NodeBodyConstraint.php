<?php

namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'NodeBodyValidate',
  label: new TranslatableMarkup('List of items')
)]
class NodeBodyConstraint extends SymfonyConstraint {

}
