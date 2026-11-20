<?php

namespace ivoglent\yii2\apm;


use Elastic\Apm\PhpAgent\Config;
use Elastic\Apm\PhpAgent\Model\Framework;
use Exception;
use Throwable;
use ivoglent\yii2\apm\components\ConsoleErrorHandler;
use ivoglent\yii2\apm\components\WebErrorHandler;
use ivoglent\yii2\apm\listeners\ConsoleListener;
use ivoglent\yii2\apm\listeners\QueryListener;
use ivoglent\yii2\apm\listeners\ExceptionListener;
use ivoglent\yii2\apm\listeners\RequestListener;
use ivoglent\yii2\apm\listeners\TraceListener;
use ivoglent\yii2\apm\listeners\WorkerListener;
use yii\base\Application;
use yii\console\Application as ConsoleApplication;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use Yii;

class Module extends \yii\base\Module implements BootstrapInterface
{
    /**
     * @var array
     */
    public $configs;
    /**
     * @var Agent
     */
    private $agent;

    public $enabled = false;

    public bool $replaceErrorHandler = true;

    private $ready = false;

    private ?ExceptionListener $exceptionListener = null;

    /**
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        Yii::setAlias('ivoglent/yii2/apm', __DIR__);
        if ($this->enabled && !$this->isAssetRequest()) {
            if (empty($this->configs['agent'])) {
                throw new InvalidConfigException('Missing config for APM agent');
            }
            $agentConfig = $this->configs['agent'];
            $config = new Config(
                $agentConfig['name'],
                Yii::$app->version,
                $agentConfig['serverUrl'],
                $agentConfig['token'] ?? null,
                client: $agentConfig['client'] ?? null
            );
            $framework = new Framework([
                'name' => 'Yii2',
                'version' => Yii::getVersion()
            ]);
            $config->setFramework($framework);
            $config->setEnvironment(YII_ENV);

            Yii::info('APM module init', 'apm');

            try {
                $this->agent = new Agent($config);
                if ($this->replaceErrorHandler) {
                    if (Yii::$app instanceof ConsoleApplication) {
                        Yii::$app->setComponents([
                            'errorHandler' => [
                                'class' => ConsoleErrorHandler::class,
                            ]
                        ]);
                    } else {
                        Yii::$app->setComponents([
                            'errorHandler' => [
                                'class' => WebErrorHandler::class,
                                'errorAction' => Yii::$app->errorHandler->errorAction
                            ]
                        ]);
                    }

                    Yii::$app->errorHandler->register();
                }
            } catch (Exception $e) {
                Yii::error($e->getMessage());
            }

        }

    }

    /**
     * @return false|int
     */
    private function isAssetRequest()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
            return preg_match('/\.(js|css|png|jpeg|jpg|map|mp4|avi|mp3|mov)$/i', $url);
        }
        return false;
    }

    /**
     * @return Agent
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }

    /**
     * Bootstrap method to be called during application bootstrap stage.
     * @param Application $app the application currently running
     */
    public function bootstrap($app)
    {
        if ($this->agent && !$this->ready) {
            $components = ['apmAgent' => $this->agent];
            Yii::info('APM module booting', 'apm');
            if (Yii::$app instanceof ConsoleApplication) {
                $components = array_merge($components, [
                    'consoleListener' => [
                        'class' => ConsoleListener::class,
                        'skipCommands' => isset($this->configs['skipCommands']) ? $this->configs['skipCommands'] : []
                    ]
                ]);
            } else {
                $components['requestListener'] = ['class' => RequestListener::class];
            }
            $components = array_merge($components, [
                'queryListener' => [
                    'class' => QueryListener::class
                ],
                'exceptionListener' => [
                    'class' => ExceptionListener::class,
                    'skipExceptions' => isset($this->configs['skipExceptions']) ? $this->configs['skipExceptions'] : []
                ],
                'workerListener' => [
                    'class' => WorkerListener::class
                ],
                'traceListener' => [
                    'class' => TraceListener::class
                ],
            ]);
            $app->setComponents($components);
            foreach ($components as $key => $config) {
                $component = $app->{$key};
                if ($component instanceof Listener) {
                    $component->setAgent($this->agent);
                    $component->start();
                }
            }
            $this->exceptionListener = $app->get('exceptionListener');
            $this->ready = true;
        }
    }

    /** Report an exception before the application renders it. */
    public function reportException(Throwable $exception): void
    {
        if ($this->enabled) {
            $this->exceptionListener?->reportException($exception);
        }
    }

    /**
     * @return bool
     */
    public function isReady(): bool
    {
        return $this->ready;
    }
}