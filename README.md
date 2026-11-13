# noopstudios/laravel-moloni

Pacote Laravel para emitir documentos fiscais no [Moloni](https://www.moloni.pt) a partir de projetos Laravel 13, com um plugin Filament v5 opcional para as definições e para a gestão dos documentos.

> Versão 0.1.0, ainda não publicada. Licença: a definir.

## O que faz

Pede-se um documento (fatura, fatura-recibo, fatura pró-forma, recibo, nota de crédito) a partir de um modelo Eloquent da aplicação. O pacote:

- **Regista primeiro, emite depois.** O pedido grava uma linha em `invoice_documents` com uma cópia congelada do que se vai faturar (cliente e linhas) e só depois um job em fila envia o documento ao Moloni. O pedido nunca espera pela rede. Se a aplicação mudar a encomenda entretanto, o documento emitido é o que foi pedido.
- **Nunca repete uma escrita cuja resposta se perdeu.** Se o envio ao Moloni é interrompido (timeout, queda de ligação, worker morto) sem se saber se o documento foi criado, o documento fica em **"por confirmar"** (`Unknown`). Nada o volta a enviar sem antes o procurar no Moloni (reconciliação). Um documento por confirmar nunca é tratado automaticamente: é uma pessoa, ou o seu código, que chama `Moloni::reconcile()`.
- **Uma fatura viva por modelo.** Cada modelo (`Invoiceable`) só pode ter, em simultâneo, uma fatura ou fatura-recibo viva (pendente, a emitir, emitida ou por confirmar). Pedir outra devolve a que já existe. Uma fatura falhada, anulada ou substituída liberta o lugar.
- **Totais iguais aos do Moloni.** O pacote calcula o total como o Moloni o calcula, para a aplicação poder cobrar exatamente esse valor (ver [Valores e totais](#valores-e-totais)).
- **Credenciais só no `.env`.** Nunca na base de dados, nunca em logs, nunca em mensagens de exceção.

## Requisitos

| | |
|---|---|
| PHP | `^8.3` |
| Laravel (`illuminate/*`) | `^13.0` |
| spatie/laravel-settings | `^3.0` |
| Filament (opcional, só para o painel) | `^5.0` |

Bases de dados: **MySQL/MariaDB** (InnoDB). Os testes do pacote usam SQLite. Os recibos e as notas de crédito concorrentes dependem dos bloqueios de linha do InnoDB (`SELECT … FOR UPDATE`); em **PostgreSQL** sob transações do anfitrião em `REPEATABLE READ` ou `SERIALIZABLE` essa proteção não é garantida, pelo que não é suportado para recibos.

Fora da base de dados, são precisos uma fila `database` e uma cache partilhada com locks atómicos (ver [Fila e infraestrutura](#fila-e-infraestrutura)).

## Instalação

```bash
composer require noopstudios/laravel-moloni
```

O Filament é opcional: o pacote instala-se e emite documentos sem ele. Só é preciso para o painel (página de definições, recurso de documentos e ações). Se o projeto ainda não tem Filament v5 e quer o painel, instale-o também:

```bash
composer require filament/filament:"^5.0"
```

As definições (empresa, séries, métodos de pagamento, etc.) ficam na tabela do `spatie/laravel-settings`. Essa tabela tem de existir antes das migrações do pacote, que inserem as linhas de definições `moloni.*`:

```bash
# 1. a migração da tabela "settings" do spatie (se o projeto ainda não a tem)
php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="migrations"

# 2. todas as migrações: a do spatie corre primeiro (tem data antiga), depois as do pacote
php artisan migrate
```

As migrações do pacote (tabelas `invoice_documents` e `invoice_document_links`, e as definições `moloni.*`) são carregadas automaticamente pelo service provider: não há nada para publicar. Também não é preciso registar `MoloniSettings` em `config/settings.php`. O único ficheiro publicável do pacote é a configuração:

```bash
php artisan vendor:publish --tag=moloni-config
```

**`schema:load`.** O dump de schema do Laravel (`schema:dump`) guarda a estrutura das tabelas e o conteúdo da tabela `migrations`, mas não as linhas da tabela `settings`. Numa base criada com `schema:load` as migrações de definições contam como executadas e as linhas `moloni.*` não existem: o spatie não consegue ler as definições e o pacote comporta-se como se não houvesse empresa configurada (`No Moloni company is configured`). Nos ambientes em que isto se aplica, crie a base com `php artisan migrate` a partir de uma base vazia e confirme com `php artisan moloni:check`.

Depois, adicione as credenciais ao `.env` e configure a empresa: no ecrã de definições do plugin Filament, se o usar, ou em código (ver [Definições e `moloni:check`](#definições-e-molonicheck)).

## Variáveis `.env`

As credenciais e as opções são lidas por `config/moloni.php`.

| Variável | Predefinição | O que faz |
|---|---|---|
| `MOLONI_CLIENT_ID` | — | `client_id` da aplicação de programador do Moloni. |
| `MOLONI_CLIENT_SECRET` | — | `client_secret` correspondente. |
| `MOLONI_EMAIL` | — | Email de login da conta Moloni; é enviado ao Moloni como `username`. |
| `MOLONI_PASSWORD` | — | Palavra-passe dessa conta. |
| `MOLONI_PREFIX` | — | Prefixo que distingue os registos desta aplicação no Moloni. Obrigatório para criar clientes: o número do cliente é `{prefix}{aa}-{chave}` (até 20 caracteres; uma chave que não caiba é recusada, nunca cortada). |
| `MOLONI_CUSTOMER_UPDATES` | `own` | Que clientes já existentes no Moloni podem ser atualizados com os dados de faturação (nome, morada, código postal, localidade, país e email). `own`: só os clientes cujo número esta aplicação gerou para esse cliente; qualquer outro cliente encontrado pelo NIF é usado tal como está. `all`: qualquer cliente encontrado pelo NIF ou pelo número. Mantenha `own`: os NIF são públicos e, com `all`, qualquer utilizador de um checkout poderia reescrever o nome e a morada de outro cliente só por escrever o NIF dele. O cliente genérico de consumidor final (9999) nunca é atualizado. |
| `MOLONI_BASE_URL` | `https://api.moloni.pt/v1/` | URL base da API do Moloni. |
| `MOLONI_TIMEOUT` | `15` | Timeout dos pedidos, em segundos, mantido entre 1 e 30 (um valor maior é reduzido; 0 nunca significa "sem timeout"). |
| `MOLONI_CONNECT_TIMEOUT` | `5` | Timeout de ligação, em segundos, mantido entre 1 e 10. |
| `MOLONI_QUEUE_CONNECTION` | `database` | Ligação de fila onde corre o job de emissão. |
| `MOLONI_PROFORMA_VALID_DAYS` | `30` | Dias de validade de uma pró-forma (a sua data de validade no Moloni). |
| `MOLONI_VIES_ENABLED` | `false` | Liga a validação de NIF intracomunitários no VIES (ver [IVA](#iva-regras-de-imposto-e-vies)). |
| `MOLONI_VIES_URL` | `https://ec.europa.eu/taxation_customs/vies/rest-api` | URL base da API REST do VIES. |
| `MOLONI_VIES_TIMEOUT` | `5` | Timeout, em segundos, dos pedidos ao VIES, mantido entre 1 e 10. |

Duas opções de `config/moloni.php` não vêm do `.env`: `tax_rule` (a classe que decide o IVA, ver abaixo) e `permissions` (ver [Permissões](#permissões)).

```dotenv
MOLONI_CLIENT_ID=
MOLONI_CLIENT_SECRET=
MOLONI_EMAIL=
MOLONI_PASSWORD=
MOLONI_PREFIX=ABC
```

## Definições e `moloni:check`

Em **Moloni settings** (página do plugin Filament) escolhe-se, com listas lidas do Moloni:

- a empresa e a zona fiscal dos impostos (`PT`, `PT-AC` ou `PT-MA`);
- a série (document set) de cada tipo de documento: pró-forma, fatura, fatura-recibo, recibo e nota de crédito;
- os métodos de pagamento: uma chave livre (a que o código usa em `PaymentData`) para cada método do Moloni;
- as datas de vencimento: dias para pagar, para cada data de vencimento do Moloni (a fatura a prazo precisa de uma para o prazo pedido; o prazo 0 é o da fatura à vista);
- categoria, unidade e produto predefinidos (necessários para criar produtos de linhas com `reference` ainda desconhecida e para linhas sem produto nem referência);
- o imposto do Moloni a usar para cada taxa de IVA (opcional: sem isto o pacote procura o único imposto de IVA dessa taxa na zona fiscal);
- os motivos de isenção de IVA e o que fazer quando o VIES não responde.

As listas vêm do Moloni e ficam em cache durante um dia. O botão **Refresh lists** (Atualizar listas) apaga essa cache e volta a ler tudo. Depois de escolher a empresa e gravar, passam a aparecer as listas que dependem dela.

**O que a gravação verifica.** Ao gravar, o ecrã guarda as definições e corre a verificação de prontidão (cerca de dez pedidos ao Moloni). Cada problema aparece numa notificação vermelha persistente; sem problemas aparece "The Moloni settings are valid.". Verifica-se que o login funciona, que a empresa existe e é acessível, que cada id configurado (séries, métodos, datas de vencimento, categoria, unidade, impostos, produto) existe no Moloni, que os valores estão bem formados, que a zona fiscal tem impostos e que os códigos de isenção existem. Exige ainda o mínimo para emitir: série de fatura e de fatura-recibo, pelo menos um método de pagamento e uma data de vencimento de 0 dias. Os outros tipos (pró-forma, recibo, nota de crédito) não são exigidos aqui: pedir um documento sem série configurada falha com um erro de validação.

O mesmo teste corre na linha de comandos, útil em deploys e em CI:

```bash
php artisan moloni:check   # código de saída 1 se houver problemas
```

**Sem Filament** não há ecrã de definições: preenchem-se em código com a classe `Noop\Moloni\Settings\MoloniSettings` (por exemplo num seeder ou num comando do projeto) e confirmam-se com `php artisan moloni:check`, que faz a mesma verificação do ecrã. Os ids são os do Moloni (empresa, séries, métodos de pagamento, datas de vencimento, etc.):

```php
use Noop\Moloni\Settings\MoloniSettings;

$settings = app(MoloniSettings::class);

$settings->company_id = 12345;
$settings->fiscal_zone = 'PT';                       // PT, PT-AC ou PT-MA
$settings->document_sets = [
    'invoice' => 101,                                // obrigatórias: invoice e invoice_receipt
    'invoice_receipt' => 102,
    'proforma' => 103,                               // as restantes só para quem as usa
    'receipt' => 104,
    'credit_note' => 105,
];
$settings->payment_methods = ['card' => 201, 'transfer' => 202];   // chave usada em PaymentData => id no Moloni
$settings->maturity_dates = [0 => 301, 30 => 302];                 // dias => id; o prazo 0 é obrigatório
$settings->default_category_id = 401;
$settings->default_unit_id = 501;
$settings->default_product_id = null;
$settings->tax_overrides = [];                       // taxa de IVA => tax_id, opcional
$settings->exemption_reasons = ['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44'];
$settings->vies_unavailable = 'charge';              // charge ou block

$settings->save();
```

## Implementar `Invoiceable`

Um `Invoiceable` é um modelo Eloquent (uma encomenda, uma reserva, um período de subscrição). Implementa três métodos:

- `toInvoiceData()`: o cliente e as linhas a faturar. É lido uma só vez, quando o documento é pedido, e fica congelado na linha do documento.
- `invoiceReference()`: a referência da aplicação (ex.: o número da encomenda). No Moloni fica como `#referência`.
- `customerKey()`: o id estável do cliente da aplicação. O pacote não o chama: o número do cliente no Moloni é construído a partir de `CustomerData::$key`, por isso passe-o aí (como no exemplo abaixo).

```php
use Illuminate\Database\Eloquent\Model;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;

class Order extends Model implements Invoiceable
{
    public function toInvoiceData(): InvoiceData
    {
        return new InvoiceData(
            customer: new CustomerData(
                key: $this->customerKey(),
                vat: $this->customer->vat,            // ex.: '123456789'; null ou vazio = consumidor final
                name: $this->customer->name,
                email: $this->customer->email,
                address: $this->customer->address,
                postalCode: $this->customer->postal_code,
                city: $this->customer->city,
                country: 'PT',                        // ISO 3166-1 alpha-2; null = Portugal
                isBusiness: $this->customer->is_business,
                createdAt: $this->customer->created_at, // o ano entra no número do cliente
            ),
            lines: $this->items->map(fn ($item) => new InvoiceLine(
                name: $item->name,
                quantity: $item->quantity,            // float > 0
                unitNetPrice: number_format($item->unit_net_cents / 100, 5, '.', ''), // sem IVA, até 5 casas
                taxRate: 23.0,                        // taxa exata do Moloni
                reference: $item->sku,                // criada como serviço no Moloni se não existir
            ))->all(),
            yourReference: $this->purchase_order,     // opcional: referência do cliente
            notes: null,
        );
    }

    public function invoiceReference(): string
    {
        return $this->number;                         // ex.: 'ORD-2026-0001'
    }

    public function customerKey(): string
    {
        return (string) $this->customer_id;
    }
}
```

Notas:

- Um cliente sem NIF (ou com o NIF genérico de consumidor final) e sem nome, morada nem email é faturado ao cliente genérico "consumidor final" do Moloni (9999).
- Uma linha precisa de `reference`, de `productId` (id de um produto Moloni) ou de um produto predefinido configurado. Uma linha com taxa 0 precisa de `exemptionReason` (código AT, ex.: `M07`); uma linha com IVA não pode ter motivo de isenção.
- `toInvoiceData()` deve ser determinístico em relação ao estado da aplicação: `reissue()` volta a chamá-lo para emitir um documento novo com os dados atuais.
- O modelo tem de estar gravado (ter chave primária) para se lhe pedirem documentos.

### Valores e totais

- Dinheiro em **cêntimos** (`int`), por exemplo em `PaymentData::$amountCents`.
- O preço unitário sem IVA é uma `string` com **até 5 casas decimais** (o Moloni guarda 5): `'12.34500'`.
- Os totais calculam-se como o Moloni, linha a linha, com arredondamento half-up a duas casas:
  - base da linha = `round(quantidade × preço, 2)`;
  - imposto da linha = `round(quantidade × preço × taxa, 2)`, calculado sobre a base **sem arredondar**;
  - total do documento = soma das bases + soma dos impostos.
- `InvoiceData` expõe `netCents()`, `taxCents()` e `totalCents()`; use `$order->toInvoiceData()->totalCents()` para cobrar exatamente o valor que o Moloni vai calcular. Se o total que o Moloni devolver for diferente, o documento fica emitido mas com `error_type = total_mismatch`.
- A taxa de cada linha tem de ser uma taxa **exata** de um imposto do Moloni da zona fiscal (diferença inferior a 0,001; ex.: `23.0`, `13.0`, `6.0`). Sem imposto com essa taxa, o documento falha com erro de validação.
- Só se emite em `EUR`; outra moeda falha com erro de validação.
- Os documentos têm sempre a data de hoje no calendário de Portugal continental (`Europe/Lisbon`), seja qual for o fuso da aplicação: nunca se envia uma data futura ao Moloni. A data do pagamento de uma fatura-recibo ou de um recibo também se conta nesse fuso.

## Operações

Todos os exemplos usam a fachada `Noop\Moloni\Facades\Moloni`. Os pedidos devolvem um `InvoiceDocument` (modelo do pacote) ainda `pending`: o documento é enviado pelo job. Ouça os [eventos](#estados-falhas-e-eventos) ou consulte o estado para saber o resultado.

```php
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Data\CreditLine;
```

### Fatura

```php
$document = Moloni::invoice($order);                 // à vista (prazo 0)
$document = Moloni::invoice($order, dueInDays: 30);  // a 30 dias
```

Precisa de uma data de vencimento configurada para o prazo pedido. Se o `$order` já tem uma fatura ou fatura-recibo viva, devolve essa.

### Fatura-recibo

```php
$payment = new PaymentData(
    method: 'card',                       // uma chave de "Payment methods" nas definições
    paidAt: now(),
    amountCents: $order->toInvoiceData()->totalCents(), // tem de ser igual ao total do documento
);

$document = Moloni::invoiceReceipt($order, $payment);
```

### Fatura pró-forma e conversão

Uma pró-forma não é um documento fiscal e um modelo pode ter várias. A mesma chave de idempotência devolve sempre a mesma pró-forma; uma nova pró-forma pede uma chave nova.

```php
$proforma = Moloni::proforma($order, 'quote-2026-05');

// Depois de emitida, converte-se em fatura (prazo em dias) ou, com pagamento, em fatura-recibo.
// A conversão usa o que a pró-forma orçamentou (dados congelados), não os dados atuais do modelo.
$invoice = Moloni::convert($proforma);
$invoiceReceipt = Moloni::convert($proforma, new PaymentData('card', now(), $proforma->expected_total_cents));
```

### Um recibo para várias faturas

Um recibo liquida faturas emitidas de **um** cliente Moloni, com um valor por fatura. As parcelas têm de somar o pagamento e caber no que cada fatura ainda deve; até 25 faturas por recibo; as faturas de consumidor final liquidam-se uma por recibo.

```php
$receipt = Moloni::receipt(
    owner: $customerAccount,                       // qualquer modelo gravado; fica como "invoiceable" do recibo
    allocations: [
        [$invoiceA, 12300],                        // [InvoiceDocument, cêntimos]
        [$invoiceB, 5000],
    ],
    payment: new PaymentData('transfer', now(), 17300),
    idempotencyKey: 'payment-8841',                // o id do pagamento
);
```

Sem chave, cada chamada regista um recibo novo. Com chave, repetir a chamada com as mesmas faturas, valores e método devolve o mesmo recibo (e reenvia-o se tinha falhado); outras faturas, valores ou método sob a mesma chave são recusados.

Em concorrência, recibos podem entrar em deadlock (também sobre faturas não relacionadas). Fora de uma transação o pacote repete a sua transação; dentro de uma transação do anfitrião a exceção chega-lhe (`DeadlockException`) com a transação já anulada: repita a unidade de trabalho completa e mantenha essa transação curta, com um recibo por transação.

### Nota de crédito

Só sobre uma fatura ou fatura-recibo emitida, ou substituída por uma reemissão depois de chegar ao Moloni (`superseded` com id do Moloni: continua válida lá). A referência é a do documento seguida de `/NC{n}`. Cada linha pode ser creditada até à sua quantidade, somando todas as notas de crédito vivas.

```php
// Tudo o que ainda for creditável, em todas as linhas:
$credit = Moloni::creditNote($invoice, idempotencyKey: 'refund-5521');

// Parcial: a posição da linha (a partir de 0) no pedido original e a quantidade a creditar.
$credit = Moloni::creditNote(
    $invoice,
    [new CreditLine(lineIndex: 0, quantity: 1.0), new CreditLine(lineIndex: 2, quantity: 0.5)],
    idempotencyKey: 'refund-5522',
);
```

Passe sempre uma chave de idempotência quando a nota de crédito reage a um evento (um reembolso, um webhook): repetir a chamada com a mesma chave e as mesmas linhas devolve a mesma nota de crédito; outras linhas sob essa chave são recusadas (`InvalidArgumentException`). Sem chave, cada chamada regista uma nota de crédito nova. A nota de crédito abate no máximo o saldo em aberto do documento (uma fatura por pagar passa a dever menos; uma já paga não é afetada).

### Anular (cancelar no Moloni)

```php
use Noop\Moloni\Exceptions\MoloniException;

try {
    Moloni::cancel($document, 'Emitida por engano');
} catch (MoloniException $e) {
    // $e->type === FailureType::Validation: o Moloni recusou, ou há documentos dependentes.
    // $e->getMessage() inclui a razão dada pelo Moloni.
}
```

- Anula-se um documento `issued` de qualquer tipo (fatura, fatura-recibo, recibo, nota de crédito, pró-forma), ou um documento `superseded` que chegou ao Moloni antes de ser reemitido (tem id do Moloni e continua válido lá). O documento passa a `voided`, deixa de contar, liberta o lugar de fatura viva e liberta o que liquidava; `DocumentVoided` dispara também para um documento substituído.
- **Não anula em cascata.** Um documento com recibos, notas de crédito ou conversões vivas é recusado sem chamar o Moloni; anule primeiro esses ou emita uma nota de crédito.
- Quando o Moloni recusa, o documento fica como estava e não se emite nada no lugar dele (por exemplo, uma nota de crédito): decida o passo seguinte.
- É **síncrono** (chama o Moloni com o lock do documento) e **não pode ser chamado dentro de uma transação de base de dados**: lança `LogicException`. Um resultado incerto é confirmado no Moloni; se não aparecer anulado, lança-se a exceção `Unknown` com o documento inalterado, e voltar a anular é seguro.
- A razão não pode estar vazia (`InvalidArgumentException`).

### Reemitir

```php
$new = Moloni::reissue($document);                       // mesmo tipo, mesmo pagamento e prazo
$new = Moloni::reissue($document, $payment, dueInDays: 15);
```

Substitui uma fatura ou fatura-recibo `issued` ou `unknown` por uma nova, construída com os dados **atuais** do modelo. O documento antigo fica `superseded`; **nada é anulado no Moloni** (um documento emitido continua emitido lá): anule-o depois com `Moloni::cancel()` ou corrija-o com uma nota de crédito, que continuam a aceitá-lo. Não se reemite uma pró-forma. Uma fatura emitida com recibos ou notas de crédito vivos é recusada (`Validation`): anule-os primeiro ou, em vez de reemitir, corrija-a com uma nota de crédito.

### Reenviar (`retry`)

```php
Moloni::retry($document);   // só para documentos "failed"
```

Reenvia um documento falhado na mesma linha, com as tentativas a zero. Um recibo ou uma nota de crédito só se reenviam enquanto ainda couberem nos documentos a que se referem.

### Reconciliar

```php
Moloni::reconcile($document);   // só para documentos "unknown"
```

Volta a procurar no Moloni um documento por confirmar (o envio pode ou não ter chegado). O job adota o documento que o Moloni tem, emite-o se não houver nada que possa ser ele, e mantém-no por confirmar, com o erro, se a resposta continuar ambígua ou não puder ser lida.

### Ligação para o PDF

```php
$url = Moloni::pdfLink($document);
```

A ligação é pedida ao Moloni de cada vez que se chama e **nunca é guardada** (as ligações do Moloni expiram). Só funciona para documentos `issued`.

## Estados, falhas e eventos

**`DocumentStatus`** (coluna `status`):

| Estado | Significado |
|---|---|
| `pending` | Registado, ainda não enviado ao Moloni. |
| `issuing` | A ser enviado ao Moloni. |
| `issued` | Inserido e fechado no Moloni. |
| `failed` | O Moloni recusou ou nunca foi enviado: não existe nada no Moloni. |
| `unknown` ("por confirmar") | Escrita cuja resposta se perdeu: pode existir no Moloni e tem de ser reconciliada antes de qualquer reenvio. |
| `superseded` | Substituído por outro documento; já não conta. |
| `voided` | Anulado; já não conta. |

Estão "vivos" `pending`, `issuing`, `issued` e `unknown`.

**`FailureType`** (`MoloniException::$type` e, para falhas do job, a coluna `error_type`):

| Tipo | Significado |
|---|---|
| `validation` | O Moloni (ou o pacote) rejeitou os dados; repetir o mesmo pedido volta a falhar. |
| `authentication` | As credenciais, o token ou o acesso à empresa foram recusados. |
| `transient` | Nada mudou no Moloni; pode repetir-se com segurança. |
| `unknown` | Uma escrita pode ou não ter sido aplicada: nunca repetir sem reconciliar. |

A coluna `error_type` pode também guardar `total_mismatch` e `readback_failed` (o documento está emitido, mas o total diferiu ou o número/total não puderam ser lidos de volta; o `moloni:recover` repara o segundo), `readback_abandoned`, `company_changed` (a empresa configurada mudou depois do pedido), `job_failed` e `ambiguous_reconciliation`. A mensagem fica em `error_message`.

**Retentativas.** Antes do envio, as falhas que podem passar (ligação, escritas de cliente ou produto incertas) repetem-se com intervalos de 60, 300 e 900 segundos, até 3 tentativas. Um envio cujo resultado é incerto nunca se repete no job: o documento fica `unknown`.

**Eventos** (`Noop\Moloni\Events`), todos com a propriedade pública `$document`:

- `DocumentIssued`: o documento foi emitido no Moloni, ou um documento que já existia no Moloni foi adotado. `error_type` pode continuar a assinalar `total_mismatch` ou `readback_failed`.
- `DocumentFailed`: o documento falhou ou ficou por confirmar (veja o `status` e `error_*`).
- `DocumentVoided`: o documento foi anulado no Moloni (é disparado por `Moloni::cancel()`, no processo de quem a chamou).

`DocumentIssued` e `DocumentFailed` disparam **no máximo uma vez** por resultado, de dentro do job de emissão, e os *listeners* corridos de forma síncrona correm dentro do job, com o lock do documento. Mantenha-os curtos ou ponha o trabalho pesado numa fila (um *listener* `ShouldQueue`). Se o processo morrer logo depois de gravar o estado, perde-se o evento, nunca o estado: reconstrua a lógica crítica a partir do `status` e não apenas dos eventos.

```php
use Illuminate\Support\Facades\Event;
use Noop\Moloni\Events\DocumentIssued;

Event::listen(DocumentIssued::class, function (DocumentIssued $event) {
    // $event->document->document_number, ->status, ->invoiceable ...
});
```

## IVA: regra de imposto e VIES

O pacote **não aplica** IVA ao emitir: usa as taxas e os motivos de isenção das linhas que a aplicação lhe der. Para decidir a taxa de um cliente antes de pedir o pagamento, o checkout usa a `Noop\Moloni\Tax\TaxRule`, resolvida do contentor (a classe configurada em `moloni.tax_rule`, por predefinição `DefaultTaxRule`; pode substituir-se o binding da interface):

```php
use Noop\Moloni\Tax\TaxRule;

$decision = app(TaxRule::class)->decide($customerData, standardRate: 23.0);

$line = new InvoiceLine(
    name: 'Consulting',
    quantity: 1,
    unitNetPrice: '100.00000',
    taxRate: $decision->rate,
    exemptionReason: $decision->exemptionReason,
);

$data = new InvoiceData($customerData, [$line], vies: $decision->vies); // a prova do VIES fica guardada
```

A regra predefinida:

- cliente em Portugal (ou sem país): a taxa normal;
- fora da UE (a Irlanda do Norte, `XI`, conta como fora): sem IVA; `isBusiness` escolhe o motivo (`non_eu_business` ou `non_eu_consumer`);
- UE, cliente particular ou sem NIF, ou VIES desligado: a taxa normal;
- UE, empresa com NIF e VIES ligado: consulta o VIES. NIF válido: isento (`eu_business`); NIF inválido: a taxa normal; VIES indisponível: depende da definição **When VIES cannot be reached** (`vies_unavailable`): `charge` cobra IVA (e guarda o resultado) e `block` lança uma `MoloniException` (`Validation`) a pedir para tentar mais tarde.

**Motivos de isenção.** Nas definições, "VAT exemptions" guarda o código AT para cada cenário. Os valores iniciais são `M40` (empresa da UE), `M40` (empresa fora da UE) e `M44` (consumidor fora da UE). **Confirme estes códigos com o seu contabilista** antes de emitir; o pacote não os valida juridicamente, apenas confirma que existem no Moloni. Se o código de um cenário estiver vazio, a `DefaultTaxRule` lança uma `MoloniException` (`Validation`) em vez de decidir sem motivo de isenção.

O VIES liga-se com `MOLONI_VIES_ENABLED=true`. A consulta nunca lança exceções nem usa cache: qualquer falha (timeout, erro HTTP, resposta de serviço indisponível) conta como "indisponível".

## Testar com `Moloni::fake()`

```php
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Facades\Moloni;

it('issues an invoice-receipt when the order is paid', function () {
    $fake = Moloni::fake();

    $order = Order::factory()->create();
    // ... o código da aplicação que chama Moloni::invoiceReceipt(...)

    $fake->assertIssued(DocumentType::InvoiceReceipt, fn ($document) => $document->invoiceable->is($order));
});

it('handles a lost answer', function () {
    $fake = Moloni::fake()->failNext(FailureType::Unknown);

    // O pedido seguinte fica "unknown" e dispara DocumentFailed.
});
```

- Os pedidos são validados, registados e congelados como de costume, e a linha é emitida de imediato: sem HTTP, sem fila, sem credenciais Moloni e sem definições configuradas (o fake traz as suas). Os eventos do pacote disparam. Os números dos documentos são `FAKE/{id}` a partir de 1000, e `pdfLink()` devolve uma ligação fictícia.
- Asserções: `assertIssued(DocumentType $type, ?Closure $callback = null)`, `assertNothingIssued()` e `assertIssuedCount(int $n)`.
- `failNext(FailureType $type)` faz o **pedido seguinte** terminar como o fluxo real o deixaria (`Unknown` fica `unknown`; os outros tipos ficam `failed`) e dispara `DocumentFailed`. Só afeta esse pedido; um pedido que devolva um documento já existente ou que seja recusado também o consome.
- Limites: o fake não fala com o Moloni, por isso não valida impostos, isenções, clientes nem produtos; `cancel()` não anula nada no Moloni; e não simula retentativas nem reconciliação real (`reconcile()` volta a emitir a linha). O fake substitui o binding de `MoloniSettings` por um objeto próprio, mas as verificações do pacote continuam a ler a tabela de definições, pelo que **a tabela `settings` do spatie tem de existir** na base de dados de testes (corra as migrações).

## Fila e infraestrutura

- **Ligação de fila `database`** (`MOLONI_QUEUE_CONNECTION`). O job de emissão é sempre enviado para essa ligação, com `afterCommit()` (só sai depois de a transação do pedido ser confirmada). Precisa da tabela `jobs` (`php artisan queue:table`) e de um worker para essa ligação: `php artisan queue:work database`.
- **`retry_after` acima de 120 s.** Em `config/queue.php`, a ligação usada deve ter `retry_after` superior ao timeout do job (120 s), por exemplo 180. Caso contrário um job ainda a correr é entregue a um segundo worker.
- **O worker tem de respeitar o timeout do job** (120 s): a extensão `pcntl` carregada e o `--timeout` do worker a não o encurtar nem ignorar. Como última guarda, o job nunca envia o documento ao Moloni se já não tiver o lock ou se já corre há mais de 110 s; a linha fica `issuing` e é reconciliada.
- **Cache partilhada com locks atómicos.** `CACHE_STORE` tem de suportar locks e ser comum a todos os workers e servidores web (`redis`, `database`, `memcached`…): nunca `array` nem `file` por servidor. O lock por documento é o que impede duas execuções simultâneas. Os tokens de acesso do Moloni também ficam nessa cache.
- **Agendamento.** O pacote regista sozinho `moloni:recover` de 5 em 5 minutos (`withoutOverlapping`) no agendador do Laravel; basta o `schedule:run` do anfitrião estar ativo (a entrada do cron habitual, `* * * * * php artisan schedule:run`). O comando volta a enviar documentos cujo job se perdeu: pendentes há mais de 5 minutos, a emitir há mais de 15, e emitidos sem o número e o total lidos de volta (durante 24 horas). **Nunca toca em documentos por confirmar.** Também se pode correr à mão: `php artisan moloni:recover`.
- **Fila `sync`.** Com a ligação `sync` dentro de uma transação, o job corre depois da confirmação e a sua exceção chega ao chamador; o documento fica `pending` e o `moloni:recover` apanha-o.

## Plugin Filament e permissões

Só se aplica se o projeto usar Filament v5 (ver [Instalação](#instalação)). Registe o plugin no painel:

```php
use Noop\Moloni\Filament\MoloniPlugin;

$panel->plugins([MoloniPlugin::make()]);
```

Acrescenta a página **Moloni settings** e o recurso de documentos (listagem e vista, só de leitura: ninguém cria, edita ou apaga documentos no painel). O plugin regista a `InvoiceDocumentPolicy` do pacote, a menos que o anfitrião já tenha registado uma política para `InvoiceDocument`.

**Permissões** (`config/moloni.php`):

```php
'permissions' => [
    'manage' => 'manage moloni',   // predefinição
    'full_admin' => null,          // predefinição: nada
],
```

Quem tiver uma das duas habilitações (verificadas com `$user->can()`) vê as definições e os documentos e corre as ações. Uma permissão `null` ou vazia nunca concede nada. A habilitação `manage moloni` tem de existir no anfitrião (um `Gate::define`, uma permissão do spatie/laravel-permission, etc.); pode apontar `full_admin` para, por exemplo, a habilitação de um super-administrador.

**Ações reutilizáveis** (`Noop\Moloni\Filament\Actions`), que também se podem usar em recursos do anfitrião; cada uma só aparece no tipo e no estado certos e só para quem gere o Moloni, nunca abre transação de base de dados, e mostra o que o gestor recusa como notificação de erro:

| Ação | Quando aparece | O que faz |
|---|---|---|
| `DownloadPdfAction` | Documento emitido | Redireciona para o PDF (ligação pedida ao Moloni de cada vez). |
| `RetryDocumentAction` | `failed` | `Moloni::retry()`. |
| `ReconcileDocumentAction` | `unknown` | `Moloni::reconcile()`, com confirmação. |
| `ConvertProformaAction` | Pró-forma emitida | `Moloni::convert()`: fatura (com prazo) ou fatura-recibo (com método de pagamento). |
| `ReissueDocumentAction` | Fatura ou fatura-recibo `issued` ou `unknown` | `Moloni::reissue()`; pede a razão quando está emitida. |
| `CreditNoteAction` | Fatura ou fatura-recibo emitida, ou substituída mas válida no Moloni | `Moloni::creditNote()` com tudo o que ainda é creditável (notas de crédito parciais fazem-se por código). |
| `CancelDocumentAction` | Qualquer documento emitido, ou substituído mas válido no Moloni | `Moloni::cancel()`, com a razão. Se o Moloni recusar anular uma fatura ou fatura-recibo, abre a nota de crédito no seu lugar. |

## Privacidade e operação

- **O Moloni exige o token (e, no login, as credenciais) no URL.** A API do Moloni só lê `access_token` e, no login, `username`, `password` e `refresh_token` da *query string*. O pacote nunca os põe em exceções nem em logs, mas qualquer ferramenta do anfitrião que grave pedidos HTTP (Telescope, Sentry, logs de HTTP, proxies, APM) tem de **filtrar os parâmetros `access_token`, `password`, `username` e `refresh_token`** dos URL.
- **Dados pessoais guardados.** A coluna `payload` de `invoice_documents` guarda o pedido congelado, incluindo os dados pessoais do cliente (nome, NIF, email, morada), de que as operações seguintes (notas de crédito, conversões, reemissões) precisam. A retenção e o apagamento desses dados (RGPD) são responsabilidade do anfitrião; o pacote não os apaga. As tabelas de ligação usam `restrictOnDelete`.
- **Morph map.** A chave que garante "uma fatura viva por modelo" (`active_key`) e a chave de cada operação são construídas com o nome morph do modelo (`getMorphClass()`), e `invoiceable_type` guarda o mesmo nome. Se alterar o morph map (ou o nome da classe, sem morph map) depois de haver documentos, os documentos antigos deixam de ser reconhecidos como as faturas vivas desse modelo e a relação `invoiceable` dos documentos antigos pode deixar de resolver. Defina o morph map antes do primeiro documento e não o mude.
- **Moeda e data.** Só `EUR`; os documentos levam sempre a data de hoje.

## Testes na sandbox do Moloni

A suite `tests/Integration/SandboxTest.php` corre o pacote contra a API real do Moloni. Fica fora da corrida normal (`vendor/bin/pest` não a inclui).

```bash
cp .env.sandbox.example .env.sandbox     # ignorado pelo git; preencha as credenciais e os dois ids
MOLONI_SANDBOX_ENV=/caminho/para/.env.sandbox vendor/bin/pest --group=sandbox
```

O ficheiro tem as quatro credenciais (`MOLONI_CLIENT_ID`, `MOLONI_CLIENT_SECRET`, `MOLONI_EMAIL`, `MOLONI_PASSWORD`) e `MOLONI_SANDBOX_COMPANY_ID` e `MOLONI_SANDBOX_DOCUMENT_SET_ID` (a empresa e a série onde se emite). Sem o ficheiro, os testes são ignorados. Pode também guardá-lo fora do repositório e apontar-lhe com `MOLONI_SANDBOX_ENV`.

> **Atenção:** cada corrida emite **documentos fiscais reais e irreversíveis** (cerca de dez) na empresa e na série indicadas, com a data de hoje. Use uma empresa de testes dedicada e uma série reservada a testes: nunca uma empresa que comunica com a Autoridade Tributária, nunca a empresa de demonstração partilhada do Moloni, nem uma série que receba documentos com data futura (ficaria bloqueada). Enquanto desenvolve, corra um teste de cada vez com `--filter`.

## Licença

Licença: a definir.
