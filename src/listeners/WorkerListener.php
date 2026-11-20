<?php

namespace ivoglent\yii2\apm\listeners;

use Elastic\Apm\PhpAgent\Model\Transaction;
use ivoglent\yii2\apm\Listener;
use WeakMap;
use yii\base\Event;

class WorkerListener extends Listener
{
    /** @var WeakMap<WorkerInterface, Transaction> */
    private WeakMap $transactions;

    public function start()
    {
        parent::start();
        $this->transactions = new WeakMap();
        Event::on(WorkerInterface::class, WorkerInterface::EVENT_BEFORE_EXECUTE, [$this, 'beforeExecute']);
        Event::on(WorkerInterface::class, WorkerInterface::EVENT_AFTER_EXECUTE, [$this, 'afterExecute']);
    }

    public function beforeExecute(Event $event)
    {
        if (!$this->agent->isReady()) {
            $worker = $event->sender;
            $this->agent->startTransaction($worker->getName(), 'worker');
            $this->transactions[$worker] = $this->agent->getTransaction();
        }
    }

    public function afterExecute(Event $event)
    {
        $worker = $event->sender;
        $transaction = $this->transactions[$worker] ?? null;
        unset($this->transactions[$worker]);
        if ($this->agent->isReady() && $transaction === $this->agent->getTransaction()) {
            $this->agent->stopTransaction($worker->getResult());
        }
    }
}
