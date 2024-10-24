# OpenTelemetry Joomla auto-instrumentation

This plugin is inspired by https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Wordpress and focussed on Joomla

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK + exporter (required to actually export traces)
* WordPress installation
* OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
OpenTelemetry depends on composer, unlike Wordpress. This extension was developed against Joomla docker

An example in Docker of extending the official Wordpress image to enable
auto-instrumentation: [example](example/)

### apache

Configure (eg via `.htaccess`) a PHP prepend file to initialize composer:

```
php_value auto_prepend_file /var/www/vendor/autoload.php
```

This will install the composer autoloader before running Wordpress. As part of composer autoloading,
scripts are executed for installed modules, importantly:
* OpenTelemetry SDK Autoloader
* this library's `_register.php` file

## Installation via composer

```bash
$ composer require cbos/opentelemetry-auto-joomla
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=joomla
```