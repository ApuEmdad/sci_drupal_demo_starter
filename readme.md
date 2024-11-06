# Drupal Starter Pack

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Installation from scratch

Install `Drupal`:

```
composer create-project drupal/recommended-project:10.* project_name
```

Install `Drush`:

```
composer require drush/drush
```

Install `Devel`:

```
composer require drupal/devel:^5.2
```

Install `Admin Toolbar`:

```
composer require drupal/admin_toolbar:^3.4
```

Install `Pathauto`:

```
composer require drupal/pathauto:^1.13
```

- Install `Devel`, `Admin Toolbar`, `Pathauto` from route: `/admin/modules`
- Check **Twig Development ModeL**,**Do not Cache Markup** from route: `/admin/config/development/settings`

## Installation from GitHub

Clone the repository and run the following commands in your root directory:

```
composer install
```

```
yarn
```

Import the database from the `db` in your **MySQL** server and name it `sci_drupal_demo`

## **Start (Development)**

Go to the `web` directory

```
cd web
```

Run the following command to run your drupal project:

```
php -S localhost:8888 .ht.router.php
```

Starts the server in `http://localhost:8888/`.

## **Prettier**

```
yarn prettier
```

Runs Prettier to format source files according to defined rules.
