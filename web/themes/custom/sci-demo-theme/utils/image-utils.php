<?php

use Drupal\node\NodeInterface;


/**
 * Generate image URL for a field on a node.
 *
 * @param \Drupal\node\NodeInterface $node
 *   The node object.
 * @param string $image_field_name
 *   The field name for the image.
 *
 * @return string
 *   The absolute URL for the image, or an empty string if no image is found.
 */
function generateImageUrl(NodeInterface $node, string $image_field_name)
{
  // Load the FileUrlGenerator service.
  $file_url_generator = \Drupal::service('file_url_generator');
  // Check if the field exists and is not empty.
  if ($node->hasField($image_field_name) && !$node->get($image_field_name)->isEmpty()) {
    $image_file = $node->get($image_field_name)->entity;
    if ($image_file) {
      return $file_url_generator->generateAbsoluteString($image_file->getFileUri());
    }
  }
  return '';
}
