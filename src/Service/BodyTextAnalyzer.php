<?php

declare(strict_types=1);

// web/modules/custom/node_body_validation/src/Service/BodyTextAnalyzer.php.
namespace Drupal\node_body_validation\Service;

use Drupal\Component\Utility\Html;

/**
 * Extracts text and evaluates literal, Unicode-aware blocklist rules.
 */
final readonly class BodyTextAnalyzer {

  private const BLOCK_ELEMENTS = [
    'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
    'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
    'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
  ];

  /**
   * Removes markup without joining text across paragraphs or splitting words.
   */
  public function plainText(string $html): string {
    $document = Html::load($html);
    $body = $document->getElementsByTagName('body')->item(0);
    return $body === NULL ? '' : $this->normalizeWhitespace($this->extractText($body));
  }

  /**
   * Counts Unicode letter/number words, retaining contractions and hyphens.
   */
  public function wordCount(string $text): int {
    return preg_match_all("/[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?:['\x{2019}-][\p{L}\p{N}][\p{L}\p{M}\p{N}]*)*/u", $text) ?: 0;
  }

  /**
   * Finds each blocked term once, using whole-word boundaries for longer terms.
   */
  public function blockedTerms(string $text, string $exclude, bool $comma): array {
    $terms = preg_split('/[,\r\n]+/u', $exclude, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($comma) {
      $terms[] = ',';
    }

    $findings = [];
    $seen = [];
    foreach ($terms as $term) {
      $term = $this->normalizeWhitespace($term);
      $key = mb_strtolower($term, 'UTF-8');
      if ($term === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $found = mb_strlen($term, 'UTF-8') === 1
        ? mb_stripos($text, $term, 0, 'UTF-8') !== FALSE
        : preg_match('/(?<![\p{L}\p{M}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{M}\p{N}_])/iu', $text) === 1;
      if ($found) {
        $findings[] = $term;
      }
    }
    return $findings;
  }

  /**
   * Collapses Unicode whitespace to one ordinary space.
   */
  private function normalizeWhitespace(string $text): string {
    return trim(preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $text) ?? $text);
  }

  /**
   * Traverses text nodes and inserts boundaries around block elements.
   */
  private function extractText(\DOMNode $node): string {
    if ($node instanceof \DOMText) {
      return $node->nodeValue ?? '';
    }
    if (!$node instanceof \DOMElement) {
      return '';
    }
    $tag = strtolower($node->tagName);
    if (in_array($tag, ['script', 'style', 'template', 'noscript'], TRUE)) {
      return '';
    }
    if ($tag === 'br') {
      return ' ';
    }

    $text = '';
    foreach ($node->childNodes as $child) {
      $text .= $this->extractText($child);
    }
    return in_array($tag, self::BLOCK_ELEMENTS, TRUE) ? ' ' . $text . ' ' : $text;
  }

}
