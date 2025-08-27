<?php

namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that the node.
 *
 * @Constraint(
 *   id = "NodeBodyValidate",
 *   label = @Translation("List of items"),
 * )
 */
class NodeBodyConstraint extends Constraint {

}
