<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Joomla;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class JoomlaInstrumentation
{
    public const NAME = 'joomla';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.joomla',
            null,
            'https://opentelemetry.io/schemas/1.24.0'
        );

        $apps = array("Joomla\CMS\Application\CMSApplication"); // For all subclasses of CMSApplication like SiteApplication, AdministratorApplication and ApiApplication
        $app_methods = array("doExecute", "render", "route", "dispatch", "respond");

        foreach ($apps as $appClass) {
            foreach ($app_methods as $method) {

                hook(
                    class: $appClass,
                    function: $method,
                    pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                        $name = $object->getName();

                        $span = self::builder($instrumentation, $name . ' ' . $function, $function, $class, $filename, $lineno)
                            ->setSpanKind(SpanKind::KIND_CLIENT)
                            ->startSpan();
                        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                    },
                    post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                        self::end($exception);
                    }
                );

            }
        }

        self::_hook($instrumentation, 'Joomla\Platform\JController', 'execute', 'DEPRECATED Joomla.JController.package.execute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'JController', 'execute', 'DEPRECATED Joomla.JController.execute', SpanKind::KIND_INTERNAL);


        //WebApplicationInterface execute method execute once and initiates all actions and plugins
        hook(
            class: 'Joomla\Application\WebApplicationInterface',
            function: 'execute',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s %s', $request->getMethod(), $request->getUri()->getPath()))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::URL_FULL, (string)$request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::CLIENT_PORT, $request->getUri()->getPort())
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {

                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $object->getResponse()->getStatusCode());
                self::end($exception);
            }
        );


        /**
         * Controller execute registration
         */
        hook(
            class: 'Joomla\CMS\MVC\Controller\ControllerInterface',
            function: 'execute',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $task = $params[0] ?? 'undefined';
                $controllerName = get_class($object);

                $span = self::builder($instrumentation, $controllerName, $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute("joomla.controller.task", $task)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        if (Sdk::isInstrumentationDisabled(JoomlaInstrumentation::NAME . "-db")  === false) {
            /**
             * Create a span for every db query. This can get noisy, so could be turned off via config?
             */
            hook(
                class: 'Joomla\Database\DatabaseInterface',
                function: 'execute',
                pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                    $span = self::builder($instrumentation, 'db.execute', $function, $class, $filename, $lineno)
                        ->setSpanKind(SpanKind::KIND_CLIENT)
                        ->setAttribute(TraceAttributes::DB_STATEMENT, $object->getQuery(false)->__toString())
                        ->setAttribute(TraceAttributes::DB_SYSTEM, $object->getServerType())
                        ->startSpan();
                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                },
                post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                    self::end($exception);
                }
            );
        }
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name, int $spanKind): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name, $spanKind) {
                $span = self::builder($instrumentation, $name, $function, $class, $filename, $lineno)
                    ->setSpanKind($spanKind)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function builder(
        CachedInstrumentation $instrumentation,
        string                $name,
        ?string               $function,
        ?string               $class,
        ?string               $filename,
        ?int                  $lineno,
    ): SpanBuilderInterface
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
    }

    private static function end(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}