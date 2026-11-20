<?php

namespace ivoglent\yii2\apm\listeners;

use ivoglent\yii2\apm\components\ConsoleErrorHandler;
use ivoglent\yii2\apm\components\WebErrorHandler;
use ivoglent\yii2\apm\events\AppErrorEvent;
use ivoglent\yii2\apm\Listener;
use Throwable;
use Yii;
use yii\base\Event;
use yii\base\ExitException;

class ExceptionListener extends Listener
{
    public $skipExceptions = [];

    public function start()
    {
        parent::start();
        Event::on(ConsoleErrorHandler::class, ConsoleErrorHandler::EVENT_ON_ERROR, [$this, 'onError']);
        Event::on(WebErrorHandler::class, WebErrorHandler::EVENT_ON_ERROR, [$this, 'onError']);
        Event::on(WebErrorHandler::class, WebErrorHandler::EVENT_ON_APP_ERROR, [$this, 'onError']);
    }

    public function onError(Event $event): void
    {
        $exception = ($event instanceof AppErrorEvent ? $event->exception : null)
            ?? $event->sender->errorException;
        if ($exception instanceof Throwable) {
            $this->reportException($exception);
        }
    }

    public function reportException(Throwable $exception): void
    {
        if (!$this->agent->isReady() || $exception instanceof ExitException) {
            return;
        }

        foreach ($this->skipExceptions as $type) {
            if ($exception instanceof $type) {
                return;
            }
        }

        try {
            $this->agent->notifyException($exception);
        } catch (Throwable $captureError) {
            Yii::warning('APM exception capture failed (' . get_class($captureError) . ').', 'apm');
        }
    }
}
