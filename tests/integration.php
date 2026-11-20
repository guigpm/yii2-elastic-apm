<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ivoglent\yii2\apm\components\ConsoleErrorHandler;
use ivoglent\yii2\apm\components\db\mysql\Command;
use ivoglent\yii2\apm\listeners\WorkerInterface;
use ivoglent\yii2\apm\Module;
use yii\base\Component;
use yii\console\Application;
use yii\console\Controller;
use yii\db\Connection;
use yii\db\Exception as DbException;

$vendor = getenv('APM_TEST_VENDOR') ?: dirname(__DIR__) . '/vendor';
require $vendor . '/autoload.php';
require $vendor . '/yiisoft/yii2/Yii.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function intake(array $exchange): array
{
    $request = $exchange['request'];
    check($request->getMethod() === 'POST', 'Expected POST');
    check((string) $request->getUri() === 'https://apm.example.invalid/intake/v2/events', 'Expected intake URL');
    check($request->getHeaderLine('Authorization') === 'Bearer example-token', 'Expected configured token');
    check($request->getHeaderLine('Content-Type') === 'application/x-ndjson', 'Expected NDJSON');
    $records = [];
    foreach (explode("\n", trim((string) $request->getBody())) as $line) {
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $records[array_key_first($record)][] = current($record);
    }
    return $records;
}

function checkSpan(array $transaction, array $span): void
{
    check($span['transaction_id'] === $transaction['id'], 'Expected transaction ID on span');
    check($span['trace_id'] === $transaction['trace_id'], 'Expected trace ID on span');
    check($span['parent_id'] === $transaction['id'], 'Expected transaction as parent');
    check($span['duration'] >= 0, 'Expected completed span');
}

class ProbeController extends Controller
{
    public function actionIndex(): int
    {
        $command = Yii::$app->db->createCommand('SELECT 42');
        $events = [];
        $command->on(Command::EVENT_BEFORE_QUERY, function () use (&$events) {
            $events[] = 'before_query';
        });
        $command->on(Command::EVENT_AFTER_QUERY, function () use (&$events) {
            $events[] = 'after_query';
        });
        check((int) $command->queryScalar() === 42, 'Expected SQLite result');
        check($events === ['before_query', 'after_query'], 'Expected both Yii events');
        return 7;
    }
}

class ProbeWorker extends Component implements WorkerInterface
{
    public function getName(): string
    {
        return 'probe-worker';
    }

    public function getResult(): string
    {
        return 'ok';
    }
}

class WebProbeController extends \yii\web\Controller
{
    public function actionIndex(): string
    {
        return (string) Yii::$app->db->createCommand('SELECT 42')->queryScalar();
    }
}

trait ReportsTestException
{
    public array $logged = [];

    public function handleException($exception)
    {
        Yii::$app->getModule('telemetry')->reportException($exception);
        parent::handleException($exception);
    }

    public function logException($exception)
    {
        $this->logged[] = $exception;
        parent::logException($exception);
    }
}

class ConsumerWebErrorHandler extends \yii\web\ErrorHandler
{
    use ReportsTestException;

    public int $responses = 0;

    public function responseException(Throwable $exception): void
    {
        ++$this->responses;
        Yii::$app->getModule('telemetry')->reportException($exception);
        $this->logException($exception);
        parent::renderException($exception);
    }
}

class ConsumerConsoleErrorHandler extends \yii\console\ErrorHandler
{
    use ReportsTestException;
}

class ConsumerWebApplication extends \yii\web\Application
{
    public function handleRequest($request)
    {
        try {
            return parent::handleRequest($request);
        } catch (\yii\web\UnauthorizedHttpException | \yii\web\NotFoundHttpException $exception) {
            // Simulate both the application and its explicit renderer reporting.
            $this->getModule('telemetry')->reportException($exception);
            $this->getErrorHandler()->responseException($exception);
            return $this->getResponse();
        }
    }
}

class ThrowingWebController extends \yii\web\Controller
{
    public function actionIndex(): never
    {
        throw match ($this->request->get('status')) {
            '401' => new \yii\web\UnauthorizedHttpException('consumer-probe'),
            '404' => new \yii\web\NotFoundHttpException('consumer-probe'),
            default => new RuntimeException('consumer-probe'),
        };
    }
}

class ThrowingConsoleController extends Controller
{
    public function actionIndex(): never
    {
        throw new RuntimeException('console-consumer-probe');
    }
}

function checkRegistered(object $handler): void
{
    $registeredException = set_exception_handler(static function () {});
    restore_exception_handler();
    $registeredError = set_error_handler(static fn () => false);
    restore_error_handler();
    check($registeredException === [$handler, 'handleException'], 'Expected preserved PHP exception handler');
    check($registeredError === [$handler, 'handleError'], 'Expected preserved PHP error handler');
}

function checkReportedError(array $exchange, string $result): void
{
    $records = intake($exchange);
    check(count($records['transaction']) === 1, 'Expected one transaction');
    $transaction = $records['transaction'][0];
    check($transaction['result'] === $result, 'Expected final response or exit status');
    check(count($records['error']) === 1, 'Expected exactly one error');
    check($records['error'][0]['transaction_id'] === $transaction['id'], 'Expected matching transaction ID');
    check($records['error'][0]['trace_id'] === $transaction['trace_id'], 'Expected matching trace ID');
}

$history = [];
$client = new Client(['handler' => $stack = HandlerStack::create(new MockHandler([
    new Response(202),
    new Response(202),
    new ConnectException('Simulated offline APM', new Request('POST', 'https://apm.example.invalid')),
    new Response(202),
]))]);
$stack->push(Middleware::history($history));
$config = [
    'id' => 'apm-test',
    'basePath' => __DIR__,
    'bootstrap' => ['telemetry'],
    'modules' => [
        'telemetry' => [
            'class' => Module::class,
            'enabled' => true,
            'configs' => ['agent' => [
                'name' => 'test-service',
                'serverUrl' => 'https://apm.example.invalid/',
                'token' => 'example-token',
                'client' => $client,
            ]],
        ],
    ],
    'components' => [
        'db' => [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
            'commandClass' => Command::class,
        ],
    ],
];
$mode = $argv[1] ?? 'console';
if (str_starts_with($mode, 'preserve-')) {
    $isConsole = $mode === 'preserve-console';
    $isDisabled = $mode === 'preserve-disabled';
    $isFiltered = $mode === 'preserve-filtered';
    $isOffline = $mode === 'preserve-offline';
    $isUnhandled = $mode === 'preserve-unhandled';
    $status = match ($mode) {
        'preserve-web-401', 'preserve-offline' => 401,
        'preserve-unhandled' => 500,
        default => 404,
    };
    $handler = null;
    array_unshift($config['bootstrap'], static function ($app) use (&$handler) {
        $handler = $app->getErrorHandler();
    });
    $config['modules']['telemetry']['replaceErrorHandler'] = false;
    $config['modules']['telemetry']['enabled'] = !$isDisabled;
    $config['modules']['telemetry']['configs']['skipExceptions'] = $isFiltered
        ? [\yii\web\NotFoundHttpException::class]
        : [];
    $config['components']['errorHandler'] = $isConsole
        ? ['class' => ConsumerConsoleErrorHandler::class]
        : [
            'class' => ConsumerWebErrorHandler::class,
            'discardExistingOutput' => false,
            'silentExitOnException' => true,
        ];
    if ($isConsole) {
        $app = new Application($config + ['controllerMap' => ['probe' => ThrowingConsoleController::class]]);
        $app->request->setParams(['probe/index']);
        check($app->getErrorHandler() === $handler, 'Expected original console handler');
        checkRegistered($handler);
        $app->getModule('telemetry')->reportException(new RuntimeException('no active transaction'));
        check($history === [], 'Expected no export without an active transaction');
        register_shutdown_function(function () use (&$history, $handler) {
            check(count($handler->logged) === 1, 'Expected consumer console log');
            check(count($history) === 1, 'Expected one POST at console shutdown');
            checkReportedError($history[0], 'Failed(1)');
            echo "Preserved console handler passed.\n";
        });
        $app->run();
        throw new RuntimeException('Expected console handler to exit');
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/index.php?r=probe/index';
    $_GET = ['r' => 'probe/index', 'status' => (string) $status];
    $config['components']['request'] = [
        'class' => \yii\web\Request::class,
        'scriptUrl' => '/index.php',
    ];
    $config['components']['response'] = [
        'class' => \yii\web\Response::class,
        'format' => \yii\web\Response::FORMAT_RAW,
    ];
    if ($isOffline) {
        $mock = new MockHandler([
            new ConnectException('Simulated offline APM', new Request('POST', 'https://apm.example.invalid')),
            new Response(202),
        ]);
        $stack->setHandler($mock);
    }
    $app = new ConsumerWebApplication($config + [
        'controllerMap' => ['probe' => ThrowingWebController::class],
    ]);
    check($app->getErrorHandler() === $handler, 'Expected original web handler');
    checkRegistered($handler);
    ob_start();
    try {
        $app->run();
    } catch (RuntimeException $exception) {
        check($isUnhandled && $exception->getMessage() === 'consumer-probe', 'Expected controller exception');
        $app->getErrorHandler()->handleException($exception);
    }
    $body = ob_get_clean();
    check($app->response->getStatusCode() === $status, 'Expected original HTTP status');
    $expectedBody = $isUnhandled ? 'An internal server error occurred.' : 'consumer-probe';
    check(str_contains($body, $expectedBody), 'Expected handler response body');
    check(count($handler->logged) === 1, 'Expected consumer log');
    check($handler->responses === ($isUnhandled ? 0 : 1), 'Expected explicit renderer only for handled errors');

    if ($isDisabled) {
        check($history === [], 'Expected no export with APM disabled');
    } else {
        $app->requestListener->afterRequest();
        check(count($history) === 1, 'Expected exactly one POST');
        if ($isFiltered) {
            $records = intake($history[0]);
            check($records['transaction'][0]['result'] === '404', 'Expected filtered transaction to finish');
            check(!isset($records['error']), 'Expected filtered error to be omitted');
        } elseif ($isOffline) {
            check($history[0]['error'] instanceof ConnectException, 'Expected simulated connection failure');
            $agent = $app->apmAgent;
            $agent->startTransaction('next-request', 'http');
            $agent->stopTransaction('2xx');
            check(count($history) === 2, 'Expected clean next export');
            $records = intake($history[1]);
            check(count($records['transaction']) === 1, 'Expected only the next transaction');
            check($records['transaction'][0]['name'] === 'next-request', 'Expected next transaction name');
            check(!isset($records['error']), 'Expected previous error to stay out of the next batch');
        } else {
            checkReportedError($history[0], (string) $status);
        }
    }
    echo "Preserved web handler ($mode) passed.\n";
    exit;
}

if (in_array($argv[1] ?? '', ['web', 'asset', 'disabled', 'options'], true)) {
    $mode = $argv[1];
    $_SERVER['REQUEST_METHOD'] = $mode === 'options' ? 'OPTIONS' : 'GET';
    $_SERVER['REQUEST_URI'] = $mode === 'asset' ? '/style.css?v=1' : '/index.php?r=probe/index';
    $_GET['r'] = 'probe/index';
    $config['modules']['telemetry']['enabled'] = $mode !== 'disabled';
    $config['components']['request'] = [
        'class' => \yii\web\Request::class,
        'scriptUrl' => '/index.php',
        'cookieValidationKey' => 'test-only',
    ];
    $app = new \yii\web\Application($config + [
        'controllerMap' => ['probe' => WebProbeController::class],
    ]);
    ob_start();
    $status = $app->run();
    $body = ob_get_clean();
    check($status === 0 && $body === '42', 'Expected Yii web response');
    if ($mode === 'web') {
        check(count($history) === 1, 'Expected exactly one web POST');
        $records = intake($history[0]);
        $transaction = $records['transaction'][0];
        check($transaction['type'] === 'http' && $transaction['result'] === '2xx', 'Expected HTTP result');
        checkSpan($transaction, $records['span'][0]);
        $app->requestListener->afterRequest();
        check(count($history) === 1, 'Expected fallback to skip a completed request');
    } else {
        check(count($history) === 0, 'Expected no export on ' . $mode);
    }
    echo "Web application ($mode) passed.\n";
    exit;
}

$app = new Application($config + ['controllerMap' => ['probe' => ProbeController::class]]);
$app->request->setParams(['probe/index']);
$module = $app->getModule('telemetry');
$agent = $module->getAgent();
check($module->isReady() && $agent === $app->apmAgent, 'Expected successful bootstrap');
check($agent->getConfig()->getClient() === $client, 'Expected Guzzle client injection');
check($app->run() === 7, 'Expected console exit code');
check(count($history) === 1, 'Expected exactly one console POST');
$records = intake($history[0]);
$transaction = $records['transaction'][0];
check($transaction['type'] === 'console' && $transaction['result'] === 'Failed(7)', 'Expected console result');
check($records['metadata'][0]['service']['name'] === 'test-service', 'Expected configured service name');
checkSpan($transaction, $records['span'][0]);
check($records['span'][0]['context']['db']['statement'] === 'SELECT 42', 'Expected SQL statement');

$agent->startTransaction('error-probe', 'console');
$invalid = $app->db->createCommand('SELECT abs(-9223372036854775808)');
$after = 0;
$invalid->on(Command::EVENT_AFTER_QUERY, function () use (&$after) {
    ++$after;
});
try {
    $invalid->queryScalar();
    throw new RuntimeException('Expected SQLite error');
} catch (DbException $error) {
    check($after === 1, 'Expected after_query on failure');
    $agent->startTrace('pending', 'test');
    $module->reportException($error);
    $app->errorHandler->errorException = $error;
    $app->errorHandler->trigger(ConsoleErrorHandler::EVENT_ON_ERROR);
}
$agent->stopTransaction('Failed(1)');
check(count($history) === 2, 'Expected one POST for exception');
$records = intake($history[1]);
$transaction = $records['transaction'][0];
checkReportedError($history[1], 'Failed(1)');
check(count($records['span']) === 2, 'Expected failed SQL and pending span');
foreach ($records['span'] as $span) {
    checkSpan($transaction, $span);
}
check($records['span'][0]['context']['db']['statement'] === 'SELECT abs(-9223372036854775808)', 'Expected failing SQL');
check($records['span'][1]['name'] === 'pending', 'Expected pending span to be flushed');
check($records['error'][0]['transaction_id'] === $transaction['id'], 'Expected error to match transaction');
check($records['error'][0]['trace_id'] === $transaction['trace_id'], 'Expected error to match trace');

$agent->startTransaction('offline', 'console');
$app->db->createCommand('SELECT 99')->queryScalar();
$agent->stopTransaction();
$agent->stopTransaction();
check(!$agent->isReady() && count($history) === 3, 'Expected one transport failure and an idle agent');
check($history[2]['error'] instanceof ConnectException, 'Expected simulated connection failure');

$worker = new ProbeWorker();
$worker->trigger(WorkerInterface::EVENT_BEFORE_EXECUTE);
check($agent->isReady(), 'Expected worker transaction to start');
$app->db->createCommand('SELECT 1')->queryScalar();
$worker->trigger(WorkerInterface::EVENT_AFTER_EXECUTE);
check(count($history) === 4, 'Expected worker POST following failure');
$records = intake($history[3]);
check(count($records['transaction']) === 1 && count($records['span']) === 1, 'Expected a fresh worker batch');
check($records['transaction'][0]['type'] === 'worker', 'Expected worker transaction');
check($records['span'][0]['context']['db']['statement'] === 'SELECT 1', 'Expected fresh SQL');
checkSpan($records['transaction'][0], $records['span'][0]);

echo "Console, SQLite, exception, pending spans, transport failure and worker passed.\n";
