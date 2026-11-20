<?php


namespace ivoglent\yii2\apm\listeners;


use Elastic\Apm\PhpAgent\Exception\RuntimeException;
use GuzzleHttp\Exception\GuzzleException;
use ivoglent\yii2\apm\Listener;
use Yii;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\web\Controller;
use yii\web\Response;

class RequestListener extends Listener
{
    public $skipActions = [];

    public function start()
    {
        parent::start();
        $this->skipActions = array_merge($this->skipActions, [
            Yii::$app->errorHandler->errorAction ?? null
        ]);
        Event::on(Response::class, Response::EVENT_AFTER_SEND, [$this, 'afterRequest']);
        register_shutdown_function(function () {
            register_shutdown_function([$this, 'afterRequest']);
        });
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, [$this, 'beforeAction']);
        if (!Yii::$app->request->isOptions) {
            $txtName = Yii::$app->request->url;
            $this->agent->startTransaction($txtName, 'http');
        }
    }

    /**
     * @throws RuntimeException
     * @throws GuzzleException
     */
    public function afterRequest(?Event $event = null): void
    {
        if (!$this->agent->isReady()) {
            return;
        }

        $response = $event?->sender ?? Yii::$app->getResponse();
        $status = $response->getStatusCode();
        $result = $status >= 400 ? (string) $status : intdiv($status, 100) . 'xx';
        $this->agent->stopTransaction($result);
    }

    /**
     * @param ActionEvent $event
     * @throws RuntimeException
     */
    public function beforeAction(ActionEvent $event) {
        Yii::info('Action start', 'apm');
        $txtName = sprintf('%s.%s', $event->action->controller->id, $event->action->id);
        if (!$this->agent->isReady()) {
            return;
        }
        if (!empty($event->action->controller->module)) {
            $txtName = $event->action->controller->module->id . '.' . $txtName;
        }
        if (!Yii::$app->request->isOptions && !$this->isSkipActions(str_replace('.', '/', $txtName))) {
            $this->agent->getTransaction()->setName($txtName);
        }

    }

    /**
     * @param $actionId
     * @return bool
     */
    private function isSkipActions($actionId) {
        return in_array($actionId, $this->skipActions);
    }

}
