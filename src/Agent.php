<?php

namespace ivoglent\yii2\apm;

use Elastic\Apm\PhpAgent\Agent as BaseAgent;
use Elastic\Apm\PhpAgent\Model\Context\SpanContext;
use Elastic\Apm\PhpAgent\Model\Span;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Yii;

class Agent extends BaseAgent
{
    public $transactionStarted = false;

    private array $pendingSpans = [];
    private bool $exportFailed = false;

    public function startTransaction(string $name, string $type, ?string $id = null)
    {
        if ($this->isReady()) {
            return;
        }

        if ($this->exportFailed) {
            // An unsent batch belongs to the previous transaction.
            parent::__construct($this->getConfig());
            $this->exportFailed = false;
        }
        parent::startTransaction($name, $type, $id);
        $this->transactionStarted = true;
    }

    public function startTrace(string $name, string $type): Span
    {
        $span = parent::startTrace($name, $type);
        $this->pendingSpans[$span->getId()] = true;
        return $span;
    }

    public function stopTrace(?string $id = null, ?SpanContext $context = null)
    {
        $id ??= array_key_last($this->pendingSpans);
        parent::stopTrace($id, $context);
        unset($this->pendingSpans[$id]);
    }

    public function notifyException(Throwable $throwable)
    {
        if ($this->isReady()) {
            parent::notifyException($throwable);
        }
    }

    public function stopTransaction(?string $result = null): void
    {
        if (!$this->isReady()) {
            return;
        }

        $this->transactionStarted = false;
        while ($this->pendingSpans) {
            $this->stopTrace();
        }
        parent::stopTransaction($result);
    }

    protected function setDefaultRequestOptions(array &$options)
    {
        $options += ['connect_timeout' => 0.1, 'timeout' => 0.1];
    }

    public function send(?RequestInterface $request = null, array $options = []): bool
    {
        $this->setDefaultRequestOptions($options);

        try {
            $sent = parent::send($request, $options);
            $this->exportFailed = false;
            if (!$sent) {
                Yii::warning('APM intake returned a non-success response.', 'apm');
            }
            return $sent;
        } catch (GuzzleException $e) {
            $this->exportFailed = true;
            Yii::warning('APM export failed (' . get_class($e) . ').', 'apm');
            return false;
        }
    }

    public function isReady()
    {
        return $this->transactionStarted;
    }
}
