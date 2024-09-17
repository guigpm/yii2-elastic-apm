# yii2-elastic-apm
Elastic Agent for Yii2 Framework

## Installation
Let install this module via composer

composer require --prefer-dist --profile -vvv ivoglent/yii2-elastic-apm
## Configuration
```php
'apm' => [
    'class' => 'ivoglent\yii2\apm\Module',
    'configs' => [
        'agent' => [
            'serverUrl' => 'localhost:8200', //Host and port of APM server 
            'name' => 'Service name', //Service name 
            'token' => APM_TOKEN, //Token
        ],
        'skipExceptions' => [
            //List of exceptions which you want to ignore
            \yii\web\NotFoundHttpException::class,
            \yii\web\UnauthorizedHttpException::class,
        ],
        'skipCommands' => [
            //List of command you dont want to track
            'rabbitmq/consume',
        ],
    ],
    'enabled' => true // or false,
]
```

To enable database monitoring, let config the DB command like :
```php
'components' => [
    'db' => [
        'class' => 'yii\db\Connection',
        'dsn' => '',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
        'commandClass' => APM_ENABLED ? 'ivoglent\yii2\apm\components\db\mysql\Command' : '\yii\db\Command',
    ]
]
```
NOTE Rememeber add apm module to bootstrap section:
```php
'bootstrap' => ['log', 'apm'],
```
## Transaction
This module will auto start new transaction after BEFORE_REQUEDT event. But you can manual start new transaction on Console application like consumer ..etc
```php
$transactionId = Uuid::uuid4()->toString();
$txtName = sprintf('consumer.%s', str_replace('-', '.', $queue->getName()));
Yii::$app->getModule('apm')->getAgent()->startTransaction($txtName, 'consumer', App::$app->getRequestId());
Yii::$app->getModule('apm')->getAgent()->setTransactionId($transactionId);
```
and stop
```php
Yii::$app->getModule('apm')->getAgent()->stopTransaction();
```

## Trace
Start new span
```php
$span = Yii::$app->getModule('apm')->getAgent()->startTrace('Process::'.$reflect->getShortName(), 'process');
```
and stop :
```php
Yii::$app->getModule('apm')->getAgent()->stopTrace($span->getId());
```

## Error / exception notify

```php
try {

} catch (\Exception $throwable) {
    Yii::$app->getModule('apm')->getAgent()->notifyException($throwable);
}
```
