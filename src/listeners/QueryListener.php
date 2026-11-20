<?php

namespace ivoglent\yii2\apm\listeners;

use Elastic\Apm\PhpAgent\Model\Context\DbContext;
use Elastic\Apm\PhpAgent\Model\Context\SpanContext;
use ivoglent\yii2\apm\components\db\mysql\Command;
use ivoglent\yii2\apm\Listener;
use WeakMap;
use yii\base\Event;

class QueryListener extends Listener
{
    private WeakMap $queries;

    public function start()
    {
        parent::start();
        $this->queries = new WeakMap();
        Event::on(Command::class, Command::EVENT_BEFORE_QUERY, [$this, 'beforeQuery']);
        Event::on(Command::class, Command::EVENT_AFTER_QUERY, [$this, 'afterQuery']);
    }

    public function beforeQuery(Event $event)
    {
        if (!$this->agent->isReady()) {
            return;
        }

        $command = $event->sender;
        $span = $this->agent->startTrace($command->getName(), 'query');
        $queries = $this->queries[$command] ?? [];
        $queries[] = [
            $this->agent->getTransaction(),
            $span->getId(),
            new SpanContext([
                'db' => new DbContext([
                    'type' => 'sql',
                    'statement' => $command->getQuery(),
                ]),
            ]),
        ];
        $this->queries[$command] = $queries;
    }

    public function afterQuery(Event $event)
    {
        $command = $event->sender;
        if (empty($this->queries[$command])) {
            return;
        }

        $queries = $this->queries[$command];
        [$transaction, $id, $context] = array_pop($queries);
        $this->queries[$command] = $queries;
        if ($this->agent->isReady() && $transaction === $this->agent->getTransaction()) {
            $this->agent->stopTrace($id, $context);
        }
    }
}
