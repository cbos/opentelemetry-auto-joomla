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

        self::_hook($instrumentation, 'Joomla\CMS\Application\SiteApplication', 'execute', 'Joomla.SiteApplication.execute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\SiteApplication', 'doExecute', 'Joomla.SiteApplication.doExecute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\SiteApplication', 'render', 'Joomla.SiteApplication.render', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\SiteApplication', 'route', 'Joomla.SiteApplication.route', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\SiteApplication', 'dispatch', 'Joomla.SiteApplication.dispatch', SpanKind::KIND_INTERNAL);

        self::_hook($instrumentation, 'Joomla\CMS\Application\AdministratorApplication', 'execute', 'Joomla.AdministratorApplication.execute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\AdministratorApplication', 'doExecute', 'Joomla.AdministratorApplication.doExecute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\AdministratorApplication', 'render', 'Joomla.AdministratorApplication.render', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\AdministratorApplication', 'route', 'Joomla.AdministratorApplication.route', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\AdministratorApplication', 'dispatch', 'Joomla.AdministratorApplication.dispatch', SpanKind::KIND_INTERNAL);

        //self::_hook($instrumentation, 'Joomla\CMS\Application\CMSApplication', 'execute', 'Joomla.CMSApplication.execute', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\CMSApplication', 'render', 'Joomla.CMSApplication.render', SpanKind::KIND_INTERNAL);
        self::_hook($instrumentation, 'Joomla\CMS\Application\CMSApplication', 'respond', 'Joomla.CMSApplication.respond', SpanKind::KIND_INTERNAL);


        //CMSApplication execute method execute once and initiates all actions and plugins
        hook(
            class: 'Joomla\CMS\Application\CMSApplication',
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

                foreach ($object->getHeaders() as $header) {
                    if ('status' == strtolower($header['name'])) {
                        $status = (int)$header['value'];
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $status);
                    }
                }
                self::end($exception);
            }
        );

        /**
         * Create a span for every db query. This can get noisy, so could be turned off via config?
         */
        hook(
            class: 'Joomla\Database\DatabaseInterface',
            function: 'execute',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::builder($instrumentation, 'Joomla.database.execute', $function, $class, $filename, $lineno)
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