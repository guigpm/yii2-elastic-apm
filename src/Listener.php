<?php


namespace ivoglent\yii2\apm;


use yii\base\Component;

class Listener extends Component
{
    /**
     * @var Agent
     */
    protected $agent;

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function start()
    {
        $this->agent ??= \Yii::$app->get('apmAgent');
    }
}