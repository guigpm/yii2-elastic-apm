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
- Os handlers de erro do módulo associam a exceção à transação e encerram o
  lote. `skipExceptions` omite o evento de erro, mantendo o encerramento da
  transação. Confirme a integração se a aplicação já substitui o error handler.
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
for mode in console web asset disabled options; do
  php -d error_reporting=E_ALL tests/integration.php "$mode" || exit 1
done
```

Os testes usam SQLite em memória com PDO e Guzzle simulado. Exercitam
bootstrap, resultado do console, lifecycle da aplicação web, exclusões,
eventos SQL de sucesso e falha, correlação de span e erro no NDJSON,
encerramento de spans pendentes, um POST por lote e isolamento da próxima
transação após falha de conexão.

No WEB, mantenha o comando temporário como opção de rollback até validar o
módulo real em staging. Com PHP-FPM, o driver de banco do WEB e um APM Server
autorizado, confirme que uma requisição com consulta preserva seu resultado,
que o intake responde 2xx e que o span SQL aparece na transação esperada
no APM. Exercite uma falha SQL e a indisponibilidade do transporte para
verificar resultado e logs. Valide também os workers e o handler de erro
específicos do WEB antes de remover o comando temporário.
