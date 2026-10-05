<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/tests/src/Unit/BodyTextAnalyzerTest.php.
namespace Drupal\Tests\node_body_validation\Unit;

use Drupal\node_body_validation\Service\BodyTextAnalyzer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Unicode, HTML boundaries, word counts, and literal blocklists.
 */
#[Group('node_body_validation')]
final class BodyTextAnalyzerTest extends UnitTestCase {

  /**
   * Verifies text extraction from HTML.
   */
  #[DataProvider('textProvider')]
  public function testPlainText(string $html, string $expected): void {
    $this->assertSame($expected, (new BodyTextAnalyzer())->plainText($html));
  }

  /**
   * Text provider.
   */
  public static function textProvider(): array {
    return [
      'paragraph boundaries' => ['<p>one</p><p>two</p>', 'one two'],
      'inline boundaries' => ['<p>hel<strong>lo</strong> world</p>', 'hello world'],
      'entities and Unicode spaces' => ["<p>A&nbsp;B&#x202F;C &amp; D</p>", 'A B C & D'],
      'tabs and new lines' => ["<p>one\t two\nthree</p>", 'one two three'],
      'line breaks and cells' => ['<table><tr><td>one</td><td>two<br>three</td></tr></table>', 'one two three'],
      'ignore non-text content' => [
        '<p>one</p><script>bad</script><style>bad</style><template>bad</template><!--bad--><p>two</p>',
        'one two',
      ],
      'escaped markup remains text' => ['<p>&lt;script&gt; &quot;quoted&quot;</p>', '<script> "quoted"'],
      'zero remains text' => ['<p>0</p>', '0'],
      'empty markup' => ['<p>&nbsp;</p>', ''],
      'malformed HTML' => ['<p>one <strong>two', 'one two'],
      'attributes do not count' => ['<p title="blocked">fine</p>', 'fine'],
    ];
  }

  /**
   * Verifies Unicode-aware word counting.
   */
  #[DataProvider('wordProvider')]
  public function testWordCount(string $text, int $expected): void {
    $this->assertSame($expected, (new BodyTextAnalyzer())->wordCount($text));
  }

  /**
   * Word provider.
   */
  public static function wordProvider(): array {
    return [
      ['', 0],
      ['!!!', 0],
      ['0', 1],
      ["one  two\tthree\nfour", 4],
      ['one,two.three', 3],
      ["don't well-known 2026", 3],
      ["naïve café România", 3],
      ["e\u{0301}cole", 1],
      ['日本語 中文', 2],
    ];
  }

  /**
   * Verifies literal and case-insensitive blocklist matching.
   */
  #[DataProvider('blocklistProvider')]
  public function testBlockedTerms(string $text, string $exclude, bool $comma, array $expected): void {
    $this->assertSame($expected, (new BodyTextAnalyzer())->blockedTerms($text, $exclude, $comma));
  }

  /**
   * Blocklist provider.
   */
  public static function blocklistProvider(): array {
    return [
      ['ART! Artistry party.', 'art', FALSE, ['art']],
      ['Artistry and party.', 'art', FALSE, []],
      ['Café, café.', "CAFÉ\ncafé", FALSE, ['CAFÉ']],
      ['Please buy now.', "buy now\r\nspam", FALSE, ['buy now']],
      ["Don't do it.", "don't", FALSE, ["don't"]],
      ['Symbols * and (here).', '*,(here)', FALSE, ['*', '(here)']],
      ['Literal .* pattern.', '.*', FALSE, ['.*']],
      ['anything matches no regex', '.*', FALSE, []],
      ['a,b,c', '', TRUE, [',']],
      ['0 plus 1', '0', FALSE, ['0']],
      ['No blocked terms.', ",\n", FALSE, []],
      ['ALERT and alert!', "alert, ALERT\rthird", FALSE, ['alert']],
    ];
  }

}
