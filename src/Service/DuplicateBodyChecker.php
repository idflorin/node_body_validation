<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Service/DuplicateBodyChecker.php.
namespace Drupal\node_body_validation\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Checks matching bodies without exposing inaccessible nodes or fields.
 */
final readonly class DuplicateBodyChecker {

  private const BATCH_SIZE = 25;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns whether another accessible node has the same stored body.
   */
  public function exists(NodeInterface $node, string $body, bool $global): bool {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('body.value', $body)
      ->sort('nid');
    if (!$global) {
      $query->condition('type', $node->bundle());
    }
    if (!$node->isNew()) {
      $query->condition('nid', $node->id(), '<>');
    }
    $offset = 0;
    do {
      $ids = $query->range($offset, self::BATCH_SIZE)->execute();
      if ($ids === []) {
        return FALSE;
      }
      foreach ($storage->loadMultiple($ids) as $existing) {
        if ($existing->access('view') && $existing->get('body')->access('view')) {
          return TRUE;
        }
      }
      $offset += self::BATCH_SIZE;
    } while (count($ids) === self::BATCH_SIZE);
    return FALSE;
  }

}
