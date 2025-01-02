## **Index**

- [Change theme from terminal](#change-theme-from-terminal)
- [Template hierarchy](#template-hierarchy)
- [Drupal Preprocess Hooks](#drupal-preprocess-hooks)
  - [1. `hook_theme()`](#1-hook_theme)
  - [2. `hook_preprocess_page()`](#2-hook_preprocess_page)
  - [3. `hook_preprocess_region()`](#3-hook_preprocess_region)
  - [4. `hook_preprocess_block()`](#4-hook_preprocess_block)
  - [5. `hook_preprocess_node()`](#5-hook_preprocess_node)
  - [6. `hook_preprocess_field()`](#6-hook_preprocess_field)
  - [7. `hook_preprocess_menu()`](#7-hook_preprocess_menu)
  - [8. `hook_preprocess_views_view()`](#8-hook_preprocess_views_view)
  - [9. `hook_preprocess_views_view_field()`](#9-hook_preprocess_views_view_field)
  - [10. `hook_preprocess_block_view()`](#10-hook_preprocess_block_view)
  - [11. `hook_preprocess_html()`](#11-hook_preprocess_html)
  - [12. `hook_preprocess_views_view_unformatted()`](#12-hook_preprocess_views_view_unformatted)
  - [13. `hook_preprocess_views_view_grid()`](#13-hook_preprocess_views_view_grid)
  - [14. `hook_preprocess_node_view()`](#14-hook_preprocess_node_view)
  - [Specific Template Overrides](#specific-template-overrides)
  - [Common Theme Suggestion Hooks in Drupal](#common-theme-suggestion-hooks-in-drupal)

## **Change theme from terminal**

Switch theme from terminal

```shell
./vendor/bin/drush config:set system.theme default olivero -y
```

## Template hierarchy

```
page.html.twig
|__ region.html.twig
|   |__ block.html.twig
|       |__ block--[block-type].html.twig
|__ node.html.twig
|   |__ node--[content-type].html.twig
|   |   |__ field.html.twig
|   |       |__ field--[field-name].html.twig
|__ menu.html.twig
|   |__ menu--[menu-name].html.twig
|__ views-view.html.twig
|   |__ views-view--[view-name].html.twig
|   |   |__ views-view-unformatted.html.twig
|   |       |__ views-view-unformatted--[view-name].html.twig
|   |           |__ node--view.html.twig
|   |               |__ node--view--[content-type].html.twig
|   |                   |__ node--view--[view-name].html.twig
|   |                       |__ field--node.html.twig
|   |                           |__ field--node--[view-name].html.twig
|   |   |__ views-view-grid.html.twig
|   |       |__ views-view-grid--[view-name].html.twig
|   |   |__ views-view-table.html.twig
|           |__ views-view-table--[view-name].html.twig
html.html.twig

```

## Drupal Preprocess Hooks

In Drupal, preprocess hooks are used to modify variables before they are rendered in the templates. For your custom theme `sci_demo`, the following are the main preprocess hooks that are commonly used:

### 1\. `hook_theme()`

This hook is used to define custom templates for your theme. It's not a preprocess hook itself but is part of the theme's setup.

- **Example**:

```php
  function sci_demo_theme(){  
    // Custom preprocessing for page templates.
  }
```

### 2\. `hook_preprocess_page()`

- **Purpose**: Preprocess variables for the page template (`page.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_page(array &$variables) {  
    // Custom preprocessing for page templates.
  }
```

### 3\. `hook_preprocess_region()`

- **Purpose**: Preprocess variables for region templates (`region.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_region(array &$variables) {  
    // Custom preprocessing for region templates.
  }
```

### 4\. `hook_preprocess_block()`

- **Purpose**: Preprocess variables for block templates (`block.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_block(array &$variables) {  
    // Custom preprocessing for block templates.
  }
```

### 5\. `hook_preprocess_node()`

- **Purpose**: Preprocess variables for node templates (`node.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_node(array &$variables) {  
    // Custom preprocessing for node templates.
  }
```

### 6\. `hook_preprocess_field()`

- **Purpose**: Preprocess variables for field templates (```
field.html.twig`).
- **Example**:

```php

 function sci_demo_preprocess_field(array &$variables) {  
   // Custom preprocessing for field templates.
 }
```

### 7\. `hook_preprocess_menu()`

- **Purpose**: Preprocess variables for menu templates (`menu.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_menu(array &$variables) {  
    // Custom preprocessing for menu templates.
  }
```

### 8\. `hook_preprocess_views_view()`

- **Purpose**: Preprocess variables for view templates (`views-view.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_views_view(array &$variables) {  
    // Custom preprocessing for views view templates.
  }
```

### 9\. `hook_preprocess_views_view_field()`

- **Purpose**: Preprocess variables for individual view fields (`views-view-field.html.twig`).
- **Example**:

```php
 function sci_demo_preprocess_views_view_field(array &$variables) {  
   // Custom preprocessing for views view field templates.
 }
```

### 10\. `hook_preprocess_block_view()`

- **Purpose**: Preprocess variables for block view templates (`block--[block-type].html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_block_view(array &$variables) {  
    // Custom preprocessing for block view templates.
  }
```

### 11\. `hook_preprocess_html()`

- **Purpose**: Preprocess variables for HTML templates (`html.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_html(array &$variables) {  
    // Custom preprocessing for HTML templates.
  }
```

### 12\. `hook_preprocess_views_view_unformatted()`

- **Purpose**: Preprocess variables for the unformatted view templates (`views-view-unformatted.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_views_view_unformatted(array &$variables) {  
    // Custom preprocessing for unformatted views templates.
  }
```

### 13\. `hook_preprocess_views_view_grid()`

- **Purpose**: Preprocess variables for grid view templates (`views-view-grid.html.twig`).
- **Example**:

```php
  function sci_demo_preprocess_views_view_grid(array &$variables) {  
    // Custom preprocessing for grid views templates.
  }
```

### 14\. `hook_preprocess_node_view()`

- **Purpose**: Preprocess variables for specific node view templates (`node--[content-type].html.twig`).
- **Example**:

```php
function sci_demo_preprocess_node_view(array &$variables) {  
  // Custom preprocessing for node view templates.
}
```

### Specific Template Overrides:

For templates like `node--view--[view-name].html.twig`, you can create a preprocess function with a more specific name to handle custom logic only for that template:

```php
/**
 * Implements hook_preprocess_node() for node--view--blogs-page.html.twig.
 */
function sci_demo_preprocess_node_view_blogs_page(array &$variables) {
  // Custom preprocessing logic for the blogs page view.
}

```

### Common Theme Suggestion Hooks in Drupal

`hook_theme_suggestions_page_alter()`

- **Purpose**: Used to alter the list of theme suggestions for pages based on conditions like path, content type, etc.
- **Example**: If you want to alter the suggestion based on the path /about-us, you can add page\_\_about_us to the list of suggestions.

```php

function sci_demo_theme_suggestions_page_alter(array &$suggestions, array $variables) {
  $current_path = \Drupal::service('path_alias.manager')->getAliasByPath(\Drupal::service('path.current')->getPath());
  if ($current_path === '/about-us') {
    $suggestions[] = 'page__about_us';
  }
}
```

Similarly we can use:

- `hook_theme_suggestions_page_alter()`
- `hook_theme_suggestions_block_alter()`
- `hook_theme_suggestions_node_alter()`
- `hook_theme_suggestions_field_alter()`
- `hook_theme_suggestions_views_view_alter()`
- `hook_theme_suggestions_menu_alter()`
