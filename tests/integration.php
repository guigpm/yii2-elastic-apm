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
    $app->errorHandler->errorException = $error;
    $app->errorHandler->trigger(ConsoleErrorHandler::EVENT_ON_ERROR);
}
$agent->stopTransaction();
check(count($history) === 2, 'Expected one POST for exception');
$records = intake($history[1]);
$transaction = $records['transaction'][0];
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
