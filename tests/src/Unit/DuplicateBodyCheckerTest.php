<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/tests/src/Unit/DuplicateBodyCheckerTest.php.
namespace Drupal\Tests\node_body_validation\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\node_body_validation\Service\DuplicateBodyChecker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access checking, uniqueness scope, bounded queries, and edit exclusion.
 */
#[Group('node_body_validation')]
final class DuplicateBodyCheckerTest extends UnitTestCase {

  /**
   * Verifies access checking, query limits, and edit exclusion.
   */
  #[DataProvider('scopeProvider')]
  public function testQueryScope(bool $global, bool $new, array $matches): void {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('accessCheck')->with(TRUE)->willReturnSelf();
    $query->expects($this->once())->method('range')->with(0, 25)->willReturnSelf();
    $query->expects($this->once())->method('sort')->with('nid')->willReturnSelf();
    $query->method('condition')->willReturnCallback(function ($field, $value, $operator = '=') use (&$conditions, $query) {
      $conditions[] = [$field, $value, $operator ?? '='];
      return $query;
    });
    $query->expects($this->once())->method('execute')->willReturn($matches);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('access')->with('view')->willReturn(TRUE);
    $existing = $this->createMock(NodeInterface::class);
    $existing->method('access')->with('view')->willReturn(TRUE);
    $existing->method('get')->with('body')->willReturn($field);
    if ($matches !== []) {
      $storage->expects($this->once())->method('loadMultiple')->with($matches)->willReturn([99 => $existing]);
    }
    else {
      $storage->expects($this->never())->method('loadMultiple');
    }
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->once())->method('getStorage')->with('node')->willReturn($storage);
    $node = $this->createMock(NodeInterface::class);
    $node->method('isNew')->willReturn($new);
    $node->method('bundle')->willReturn('article');
    $node->method('id')->willReturn(17);

    $this->assertSame($matches !== [], (new DuplicateBodyChecker($manager))->exists($node, '<p>body</p>', $global));
    $expected = [['body.value', '<p>body</p>', '=']];
    if (!$global) {
      $expected[] = ['type', 'article', '='];
    }
    if (!$new) {
      $expected[] = ['nid', 17, '<>'];
    }
    $this->assertSame($expected, $conditions);
  }

  /**
   * Checks later matches after a full batch of bodies with denied field access.
   */
  public function testSkipsDeniedFieldsAndContinuesToNextBatch(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(TRUE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->with('nid')->willReturnSelf();
    $offsets = [];
    $query->expects($this->exactly(2))->method('range')->willReturnCallback(function ($offset, $length) use (&$offsets, $query) {
      $offsets[] = [$offset, $length];
      return $query;
    });
    $query->method('execute')->willReturnOnConsecutiveCalls(range(1, 25), [26]);
    $hidden_field = $this->createMock(FieldItemListInterface::class);
    $hidden_field->method('access')->willReturn(FALSE);
    $visible_field = $this->createMock(FieldItemListInterface::class);
    $visible_field->method('access')->willReturn(TRUE);
    $hidden = $this->createMock(NodeInterface::class);
    $hidden->method('access')->willReturn(TRUE);
    $hidden->method('get')->willReturn($hidden_field);
    $visible = $this->createMock(NodeInterface::class);
    $visible->method('access')->willReturn(TRUE);
    $visible->method('get')->willReturn($visible_field);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->exactly(2))->method('loadMultiple')
      ->willReturnOnConsecutiveCalls(array_fill_keys(range(1, 25), $hidden), [26 => $visible]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $node = $this->createMock(NodeInterface::class);
    $node->method('isNew')->willReturn(TRUE);
    $this->assertTrue((new DuplicateBodyChecker($manager))->exists($node, 'body', TRUE));
    $this->assertSame([[0, 25], [25, 25]], $offsets);
  }

  /**
   * Scope provider.
   */
  public static function scopeProvider(): array {
    return [
      'new, per type' => [FALSE, TRUE, [99]],
      'edit, per type' => [FALSE, FALSE, []],
      'new, global' => [TRUE, TRUE, []],
      'edit, global' => [TRUE, FALSE, [99]],
    ];
  }

}
