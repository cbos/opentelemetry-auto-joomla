<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Joomla\JoomlaInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(JoomlaInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Joomla auto-instrumentation', E_USER_WARNING);

    return;
}

JoomlaInstrumentation::register();