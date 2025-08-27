<?php

namespace Drupal\node_body_validation\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Validates the NodeBodyValidate constraint.
 */
class NodeBodyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

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
   * Constructs a new NodeBodyConstraintValidator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   Config factory service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ConfigFactory $configFactory, TranslationInterface $stringTranslation) {
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($items, Constraint $constraint) {
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    // Make sure the field is not empty.
    if ($items->isEmpty()) {
      return;
    }

    // Get the user entered body.
    $value_body = $items->value;
    if (empty($value_body)) {
      return;
    }

    // Get host node.
    $node = $items->getEntity();
    if (!$node instanceof NodeInterface) {
      return;
    }

    // Get host node type.
    $node_type = $node->getType();
    if (empty($node_type)) {
      return;
    }

    // Check if module config exists.
    $node_body_validation_config = $this->configFactory
      ->getEditable('node_body_validation.settings')
      ->get('node_body_validation_config');
    if (empty($node_body_validation_config)) {
      return;
    }

    $body = explode(' ', $value_body);
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Add a comma if comma is blacklist.
    $exclude_comma = [];
    if (!empty($node_body_validation_config['comma-' . $node_type])) {
      $exclude_comma[] = ',';
    }
    // Get exclude values for current content type.
    $type_exclude = isset($node_body_validation_config['exclude-' . $node_type]) ? $node_body_validation_config['exclude-' . $node_type] : '';

    if (!empty($type_exclude) || $exclude_comma) {
      // Replace \r\n with comma.
      $type_exclude = str_replace("\r\n", ',', $type_exclude);
      // Store into array.
      $type_exclude = explode(',', $type_exclude);

      $type_exclude = array_merge($type_exclude, $exclude_comma);

      // Find any exclude value found in node body.
      $findings = _node_body_validation_search_excludes_in_body($value_body, $type_exclude);

      if ($findings) {
        $message = $this->t("This characters/words are not allowed to enter in the body - @findings", ['@findings' => implode(', ', $findings)]);
        $this->context->addViolation($message);
      }
    }

    $include_comma = [];
    foreach ($node_body_validation_config as $config_key => $config_value) {
      if ($config_value && $config_key == 'comma-' . $node_type) {
        $include_comma[] = ',';
      }
      if ($config_key == 'exclude-' . $node_type || $include_comma) {
        if (!empty($config_value)) {
          $config_values = array_map('trim', explode(',', $config_value));
          $config_values = array_merge($config_values, $include_comma);
          $findings = [];
          foreach ($body as $body_value) {
            if (in_array(trim($body_value), $config_values)) {
              $findings[] = $body_value;
            }
          }
          if ($findings) {
            $message = $this->t("These characters/words are not permitted in the body - @findings", ['@findings' => implode(', ', $findings)]);
            $this->context->addViolation($message);
          }
        }
      }
      if ($config_key == 'min-' . $node_type) {
        //if (mb_strlen($value_body) < $config_value) {
        if (_rip_tags($value_body) < $config_value) {
          $message = $this->t("Body should have a minimum @config_value character(s)", ['@config_value' => $config_value]);
          $this->context->addViolation($message);
        }
      }
      if ($config_key == 'max-' . $node_type) {
        //if (mb_strlen($value_body) > $config_value) {
        if (_rip_tags($value_body) > $config_value) {
          $message = $this->t("Body should not exceed @config_value character(s)", ['@config_value' => $config_value]);
          $this->context->addViolation($message);
        }
      }
      if ($config_key == 'min-wc-' . $node_type) {
        if (count(explode(' ', $value_body)) < $config_value) {
          $message = $this->t("Body should have a minimum word count of @config_value", ['@config_value' => $config_value]);
          $this->context->addViolation($message);
        }
      }
      if ($config_key == 'max-wc-' . $node_type) {
        if (count(explode(' ', $value_body)) > $config_value) {
          $message = $this->t("Body should not exceed a word count of @config_value", ['@config_value' => $config_value]);
          $this->context->addViolation($message);
        }
      }
      if ($config_key == 'unique-' . $node_type || $config_key == 'unique') {
        if ($config_value == 1) {
          // Unique node body for all content types('unique')
          $properties = ['body' => $value_body];
          if ($config_key == 'unique-' . $node_type) {
            // Unique node body for one content type('unique-')
            $properties['type'] = $node_type;
          }
          $nodes = $nodeStorage->loadByProperties($properties);
          // Remove current node form list.
          if (isset($nodes[$node->id()])) {
            unset($nodes[$node->id()]);
          }
          // Show error.
          if (!empty($nodes)) {
            $message = $this->t("The body must be unique. Other content is already using this body: @body", ['@body' => $value_body]);
            $this->context->addViolation($message);
          }
        }
      }
    }
  }

}

/**
 * Helper function to find any exclude values in node body.
 */
function _node_body_validation_search_excludes_in_body($input, array $find) {
  $input = strtolower($input);
  $findings = [];
  // Finding characters in the node body.
  foreach ($find as $char) {
    // Check for single character.
    if (mb_strlen(trim($char)) == 1) {
      if (strpos($input, trim($char)) !== FALSE) {
        $characters = $char == ',' ? '<b>,</b>' : trim($char);
        $findings[] = $characters;
      }
    }
  }

  // Finding words in the node body.
  $words = explode(' ', $input);
  if (!empty($find)) {
    $find = array_map('trim', $find);
  }
  foreach ($words as $word) {
    if (mb_strlen(trim($word)) > 1) {
      if (in_array($word, $find)) {
        $findings[] = $word;
      }
    }
  }

  return $findings;
}

//https://www.php.net/manual/en/function.strip-tags.php#110280
function _rip_tags($string) {
   
    // ----- remove HTML TAGs -----
    $string = preg_replace ('/<[^>]*>/', ' ', $string);
    // ----- remove control characters -----
    $string = str_replace("\r", '', $string);    // --- replace with empty space
    $string = str_replace("\n", ' ', $string);   // --- replace with space
    $string = str_replace("\t", ' ', $string);   // --- replace with space
   
    // ----- remove multiple spaces -----
    $string = trim(preg_replace('/ {2,}/', ' ', $string));
   
    return mb_strlen($string);

}