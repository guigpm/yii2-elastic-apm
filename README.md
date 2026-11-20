# Elastic APM para Yii2

O fork mantém o pacote Composer `ivoglent/yii2-elastic-apm` e o namespace
`ivoglent\yii2\apm`. A branch `codex/php-8.5` requer PHP `~8.5.13`,
Yii2 `^2.0.55` e o agente `ivoglent/elastic-apm-agent-v2:dev-codex/php-8.5`.
O agente requer cURL, JSON, Guzzle `^7.9` e Ramsey UUID `^4.7`.

## Composer do WEB

Mescle este trecho no `composer.json` raiz após publicar a branch do módulo.
O Composer resolve repositórios declarados na raiz. A ordem também importa se
o WEB já declara as origens anteriores destes pacotes.

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/guigpm/yii2-elastic-apm"},
    {"type": "vcs", "url": "https://github.com/guigpm/elastic-apm-agent-v2"},
    {"type": "composer", "url": "https://asset-packagist.org"}
  ],
  "require": {
    "yiisoft/yii2": "2.0.55",
    "ivoglent/yii2-elastic-apm": "dev-codex/php-8.5",
    "ivoglent/elastic-apm-agent-v2": "dev-codex/php-8.5#c7fa8de1e56be75e2fa3d57fbcb12022849f08e0"
  },
  "config": {
    "allow-plugins": {
      "yiisoft/yii2-composer": true
    }
  }
}
```

Confirme as origens e os dois `source.reference` no lock do WEB. A restrição
`#SHA` na raiz fixa o código instalado, mas o Composer ainda usa os metadados
da branch durante a resolução. Se a ponta de uma branch mudar, revise a
divergência antes de atualizar o lock.

## Configuração Yii2

Exemplo de variáveis para o ambiente de teste.

```dotenv
APM_ENABLED=true
APM_SERVICE_NAME=web-staging
APM_SERVER_URL=https://apm.example.invalid:8200
APM_TOKEN=token-ficticio
```

Mescle as entradas de banco com a configuração existente da conexão.

```php
$apmEnabled = filter_var(getenv('APM_ENABLED'), FILTER_VALIDATE_BOOLEAN);

return [
    'bootstrap' => ['log', 'apm'],
    'modules' => [
        'apm' => [
            'class' => \ivoglent\yii2\apm\Module::class,
            'enabled' => $apmEnabled,
            'configs' => [
                'agent' => [
                    'name' => getenv('APM_SERVICE_NAME'),
                    'serverUrl' => getenv('APM_SERVER_URL'),
                    'token' => getenv('APM_TOKEN') ?: null,
                ],
                'skipExceptions' => [\yii\web\NotFoundHttpException::class],
                'skipCommands' => ['rabbitmq/consume'],
            ],
        ],
    ],
    'components' => [
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'mysql:host=db.example.invalid;dbname=web_test',
            'username' => 'web_test',
            'password' => 'senha-ficticia',
            'charset' => 'utf8mb4',
            'commandClass' => $apmEnabled
                ? \ivoglent\yii2\apm\components\db\mysql\Command::class
                : \yii\db\Command::class,
        ],
    ],
];
```

`configs.agent.client` aceita opcionalmente um `GuzzleHttp\ClientInterface`
compatível com Guzzle 7. `serverUrl` deve incluir o esquema. O agente recebe
`YII_ENV`, a versão da aplicação e os metadados do Yii.

## Preservar o handler de erro da aplicação

Em `modules.apm`, defina `replaceErrorHandler` como `false` e mantenha o
componente `errorHandler` configurado pela aplicação. O padrão `true`
mantém a instalação dos handlers do módulo. Com APM desativado, o handler
da aplicação permanece registrado.

```php
'bootstrap' => ['log', 'apm'],
'modules' => [
    'apm' => [
        'class' => \ivoglent\yii2\apm\Module::class,
        'enabled' => filter_var(getenv('APM_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'replaceErrorHandler' => false,
        'configs' => [
            'agent' => [
                'name' => 'web-staging',
                'serverUrl' => 'https://apm.example.invalid:8200',
                'token' => 'token-ficticio',
            ],
            'skipExceptions' => [],
        ],
    ],
],
```

No handler do consumidor, chame `reportException()` com a exceção original
antes de delegar ao fluxo existente. O exemplo cobre o ponto de entrada do
Yii para exceções não tratadas e o caminho que renderiza diretamente 401 e 404.
No console, use a mesma chamada em `handleException()` do handler de console.

```php
class ApplicationErrorHandler extends \yii\web\ErrorHandler
{
    public function handleException($exception)
    {
        \Yii::$app->getModule('apm')?->reportException($exception);
        parent::handleException($exception);
    }

    public function responseException(\Throwable $exception): void
    {
        \Yii::$app->getModule('apm')?->reportException($exception);
        // Preserve os logs e as demais etapas existentes deste método.
        parent::renderException($exception);
    }
}
```

Para um handler que renderiza via outro método, faça a chamada no
`catch` da aplicação, antes de delegar a renderização. O hook é seguro
com APM desativado ou sem transação ativa. O mesmo objeto de exceção é
registrado uma única vez por transação, inclusive quando chega pelo hook
e pelo listener legado. A captura respeita `skipExceptions` e deixa o
encerramento com o ciclo de vida da aplicação. Falhas HTTP registram o
status exato em `transaction.result`, por exemplo `"401"` ou `"404"`.
Respostas abaixo de 400 mantêm o agrupamento, por exemplo `"2xx"`.

## Ciclo de vida

- HTTP abre a transação no bootstrap e fecha após o envio da resposta.
  O callback de shutdown cobre respostas encerradas antes do fluxo normal.
  OPTIONS e requisições de arquivos estáticos reconhecidos ficam fora da coleta.
- Console abre no início da ação e fecha com o código de saída, depois da
  execução. Para uma transação por job, inclua o comando do consumidor em
  `skipCommands` e emita `WorkerInterface::EVENT_BEFORE_EXECUTE` e
  `EVENT_AFTER_EXECUTE` no componente que implementa `WorkerInterface`.
  Para instrumentação manual, use `getAgent()->startTransaction($name, $type, $id)`
  e `stopTransaction($result)`. Use o ID opcional para correlacionar a
  transação. Identificadores gerados de transação e span têm 16 dígitos
  hexadecimais.
- As consultas que chegam à execução PDO disparam `before_query` e
  `after_query`, inclusive quando a execução lança. Falhas que ocorrem
  durante a preparação PDO podem chegar ao erro da transação sem span SQL.
  O contexto `span.context.db.statement` contém o SQL expandido pelo Yii.
  Use dados fictícios nos testes e revise o acesso a esse campo no APM.
- Os handlers de erro do módulo e o hook público associam a exceção à
  transação ativa. O lote web fecha após o envio da resposta. No console, o
  shutdown fecha a transação com `Failed(1)` quando o handler encerra o
  processo antes de `EVENT_AFTER_REQUEST`.
- Spans ainda abertos são fechados ao encerrar a transação. Faça novas
  chamadas de `startTrace()` durante uma transação ativa e passe
  `$span->getId()` a `stopTrace()`. Se o Guzzle lançar durante o envio, o
  módulo registra um aviso na categoria `apm` e permite um `send()` explícito
  antes da próxima transação. A próxima transação descarta o lote anterior
  pendente para preservar a correlação.
- `TraceListener` via eventos customizados e o `LogTarget` legado ficam fora
  do fluxo validado. O alias histórico de início e fim em `TracerInterface`
  tem o mesmo valor. Use a API direta de spans e a instrumentação de
  `commandClass` para o WEB.

## Validação e repasse

Ambiente CLI em Docker com PHP 8.5.13, Composer 2.11.2, Yii2 2.0.55, Guzzle
7.16.3 e Ramsey UUID 4.10.0. O lock resolve o agente no SHA
`c7fa8de1e56be75e2fa3d57fbcb12022849f08e0`.

```sh
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
composer check-platform-reqs
composer audit --locked
find src tests -name '*.php' -exec php -l {} +
sh tests/run.sh
```

Os testes usam SQLite em memória com PDO e Guzzle simulado. Exercitam
bootstrap, resultado do console, lifecycle da aplicação web, exclusões,
eventos SQL de sucesso e falha, correlação de span e erro no NDJSON,
encerramento de spans pendentes, um POST por lote e isolamento da próxima
transação após falha de conexão. A suíte verifica a identidade e o registro
PHP dos handlers preservados, as respostas 401 e 404, a exceção não tratada
em web e console, a filtragem e a ausência de transação ativa.

No WEB, mantenha o comando temporário como opção de rollback até validar o
módulo real em staging. Com PHP-FPM, o driver de banco do WEB e um APM Server
autorizado, confirme que uma requisição com consulta preserva seu resultado,
que o intake responde 2xx e que o span SQL aparece na transação esperada
no APM. Exercite uma falha SQL e a indisponibilidade do transporte para
verificar resultado e logs. Valide também os workers e o handler de erro
específicos do WEB antes de remover o comando temporário.
