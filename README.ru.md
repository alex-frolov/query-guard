# query-guard

[![CI](https://github.com/alex-frolov/query-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/alex-frolov/query-guard/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/alex-frolov/query-guard.svg)](https://packagist.org/packages/alex-frolov/query-guard)
[![PHP](https://img.shields.io/packagist/php-v/alex-frolov/query-guard.svg)](composer.json)
[![License](https://img.shields.io/packagist/l/alex-frolov/query-guard.svg)](LICENSE)

*[English version](README.md)*

Расширение PHPUnit, которое смотрит на запросы, уже уходящие из ваших тестов, и находит
за ними проблемы производительности — прежде всего N+1.

Поставили, добавили шесть строк в `phpunit.xml`, прогнали тесты как обычно. Никаких
ассертов в тестах, никакой отдельной команды, никаких правок кода.

> **Статус: до релиза.** Всё описанное ниже работает и покрыто тестами (121 тест
> на PHPUnit 10.5–13, Doctrine ORM 2–3, DBAL 3–4, MySQL и PostgreSQL). API может
> ещё поменяться до 1.0.

## Как это выглядит

```
query-guard
  тестов с трассой: 404, запросов: 25736 (в setUp: 0)

  находок: 207

  * [error] n-plus-one — App\Tests\Controller\TimesheetControllerTest::testExportAction
    App\Entity\Timesheet::$tags — ленивая загрузка связи, 10 запросов
    src/Entity/Timesheet.php:418

  * [warning] n-plus-one — App\Tests\Controller\TimesheetControllerTest::testSaveRates
    50 одинаковых по форме запросов из одного места, значения разные: SELECT r0_.id AS id_0, ...
    src/Repository/TimesheetRepository.php:810
```

Обе находки настоящие: цифры выше — с прогона query-guard по контроллерным тестам
[Kimai](https://github.com/kimai/kimai). 207 срабатываний в 21 месте кода, включая три
запроса ставок на каждый таймшит при каждом flush.

## Почему не статический анализ

**N+1 — не свойство запроса. Это свойство последовательности запросов.**

Это не рассуждение, а измерение. На MySQL, где разбор планов работает полностью, план
каждого отдельного запроса в учебном N+1 **безупречен**: `access_type: const`,
`key: PRIMARY`, одна строка, ноль замечаний. Проблема в том, что их пятьдесят. Никакой
`EXPLAIN` об этом не скажет.

Не скажет и анализатор по AST: ленивая загрузка случается при обращении к свойству,
динамические билдеры собираются в рантайме, и ни того ни другого в исходниках не видно.

| Инструмент | Зона | Пересечение с query-guard |
|---|---|---|
| [phpstan-dba](https://github.com/staabm/phpstan-dba) | **Статический SQL**: типы результата, синтаксис, плейсхолдеры, выводимые строки запросов | Нет. Он читает код, мы смотрим живой прогон. Стоит использовать оба |
| [phpstan-doctrine](https://github.com/phpstan/phpstan-doctrine) | Корректность DQL без базы | Нет |
| [phpunit-query-count-assertions](https://github.com/mattiasgeniar/phpunit-query-count-assertions) | Счётчики запросов, дубли, EXPLAIN — через трейт и ручные ассерты | Ближайший сосед, см. ниже |
| **query-guard** | **Рантайм-трасса**: что реально выполнилось, в каком порядке и откуда | — |

### О ближайшем соседе

Проверено на живых проектах, а не вычитано из README:

- дубли он ищет по совпадению SQL **и байндов**, поэтому настоящий N+1 — где значения
  по определению разные — даёт **ноль** дублей и зелёный тест;
- детект ленивой загрузки требует Laravel; на Doctrine `assertNoLazyLoading()` возвращает
  зелёное, ничего не проверив;
- тайминги на Doctrine недоступны (переиспользуется штатный логирующий middleware,
  который пишет запрос **до** выполнения), поэтому `assertMaxQueryTime(0.001)` проходит
  на шести реальных запросах;
- на PostgreSQL анализ планов молча выключается и рапортует зелёным при нуле
  проанализированных запросов.

query-guard пишет собственный DBAL-middleware (поэтому тайминги есть), считает фингерпринт
SQL с вырезанными значениями (поэтому N+1 виден) и говорит вслух, когда правило не может
судить, вместо того чтобы показать зелёное.

## Установка

```bash
composer require --dev alex-frolov/query-guard
```

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="QueryGuard\Extension">
        <parameter name="mode" value="report"/>
    </bootstrap>
</extensions>
```

Дальше — адаптер под вашу ORM: одна строка для Doctrine, ничего для Eloquent.

### Doctrine

Middleware обязан оказаться в конфигурации соединения **до** того, как соединение создано,
поэтому расширение не может поставить его за вас:

```yaml
# config/services_test.yaml
QueryGuard\Adapter\Doctrine\Middleware:
    tags: ['doctrine.middleware']
```

Работает с Doctrine ORM 2 и 3, DBAL 3 и 4.

### Eloquent

Делать нечего: Laravel сам находит `QueryGuardServiceProvider`. Провайдер подписывается
на диспетчер событий, поэтому видны все соединения — в том числе созданные позже,
и в том числе запросы из `setUp()`.

### Что-нибудь другое

Шов один — коллектор. Кормите его из декоратора PDO, другой ORM, откуда угодно:

```php
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

QueryGuard::collector()->record(new QueryEvent(
    sql: 'SELECT * FROM users WHERE id = ?',
    params: [42],
    durationMs: 0.4,
    stack: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
));
```

Вне прогона под PHPUnit коллектор — заглушка: этот код ничего не делает и ничего не стоит.

## Правила

### Ярус 1 — работает в день установки, reference-база не нужна

| Правило | Что значит | По умолчанию |
|---|---|---|
| `n-plus-one` | Одна форма запроса, одно место в коде, разные значения | включено, порог 3 |
| `duplicate-query` | Тот же запрос с теми же значениями, повторно | включено, порог 5 |
| `query-in-loop` | Много запросов **разных** форм из одного места | включено, порог 5 |
| `no-limit` | `SELECT` без `LIMIT` по таблице, помеченной как крупная | молчит без `large-tables` |
| `select-star` | `SELECT *` | выключено |
| `query-count` | Бюджет запросов на тест | молчит без `max-queries` |

Три правила молчат, пока их не настроят, и это сделано намеренно. `select-star` сработал бы
на каждом запросе Eloquent (там это режим по умолчанию), «крупную таблицу» на фикстурной
базе в три строки определить нечем, а бюджет запросов — политика проекта, а не константа.

`n-plus-one` смотрит только на чтения, требует, чтобы значения повторов различались,
и не считает пакетные выборки (`IN (?, ?, ?)`) — это **лекарство** от N+1, а не болезнь.

### Ярус 2 — правила плана, нужна база с настоящими данными

Включается `tier2="true"`. Выполняет `EXPLAIN` по разу на уникальную форму запроса,
на том же соединении и в той же транзакции.

| Правило | MySQL / MariaDB | PostgreSQL |
|---|---|---|
| `no-possible-index` | ✅ | — платформа не сообщает индексы-кандидаты |
| `table-scan` | ✅ | ✅ |
| `filesort` | ✅ | ✅ |
| `temporary-table` | ✅ | — аналога, о котором стоит предупреждать, нет |

Где правило не работает, сводка **говорит об этом**. Зелёный отчёт и «мы не смотрели»
не должны выглядеть одинаково.

**Ярусу 2 нужен объём.** Правила плана молчат, пока в таблице меньше `min-rows` строк
(по умолчанию 1000): на маленькой таблице врёт сама оценка оптимизатора — мы видели,
как конкурент выдал `error: Full table scan` на таблице из пяти строк. Исключение одно —
`no-possible-index`: отсутствие индекса это факт схемы, верный и на пустой таблице.

## Baseline

Наведите query-guard на существующий проект — получите сотни находок. Для этого и нужен
baseline:

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
```

```xml
<parameter name="baseline" value="query-guard-baseline.json"/>
```

Известное молчит, новое видно. Файл коммитится в репозиторий.

Ключ находки — `правило|файл|фингерпринт`, намеренно **без** номера строки и **без** имени
теста: и то и другое съезжает по безобидным причинам и обнуляло бы baseline на ровном
месте. Пути внутри относительные, поэтому файл переживает переезд в CI.

Сводка всегда сообщает, сколько находок baseline заглушил.

## Точечные исключения

```php
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;

#[AllowQueries(50)]
#[IgnoreRule('n-plus-one')]
public function testImportsLargeFile(): void
```

Оба атрибута работают и на классе, и на методе.

## Параметры

| Параметр | Значение | По умолчанию |
|---|---|---|
| `mode` | `report` — печатать сводку; `strict` — валить прогон | `report` |
| `baseline` | Путь к файлу baseline | не задан |
| `n-plus-one-threshold` | Со скольких повторов считать N+1 | `3` |
| `duplicate-threshold` | Со скольких повторов считать дублем | `5` |
| `query-in-loop-threshold` | Со скольких запросов из одного места | `5` |
| `max-queries` | Бюджет запросов на тест | не задан, правило молчит |
| `large-tables` | Таблицы для `no-limit`, через запятую | не задан, правило молчит |
| `select-star` | Включить `select-star` | `false` |
| `tier2` | Включить правила плана | `false` |
| `min-rows` | Размер таблицы, ниже которого правила плана не судят | `1000` |

Режим `strict` валит **прогон** (код возврата 1), а не отдельный тест: событийная система
PHPUnit не даёт расширению пометить тест упавшим.

## Требования

PHP 8.2+, PHPUnit 10.5 / 11 / 12 / 13.
Doctrine ORM 2 / 3 с DBAL 3 / 4 либо Laravel 11+.
Ярус 2: MySQL 8 / MariaDB или PostgreSQL.

## Разработка

Локальный PHP не нужен, всё работает в контейнерах:

```bash
./dev.sh composer install
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/phpunit
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/phpstan analyse --memory-limit=1G
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/php-cs-fixer fix
```

Ярус 2 проверяется на синтетическом стенде со 100 000 строк:

```bash
docker compose -f tools/stand/docker-compose.yml up -d
tools/stand/capture.sh          # пересобрать эталонные планы в tests/Fixture/Explain
tools/stand/run-tier2-tests.sh  # прогнать ярус 2 против живых MySQL и PostgreSQL
docker compose -f tools/stand/docker-compose.yml down -v
```

Ожидаемые флаги плана в этих тестах выставлены руками по прочитанному глазами выводу
`EXPLAIN`, а не сгенерированы нашим же парсером: иначе ошибка в понимании плана попала бы
разом и в код, и в его тест.

## Лицензия

MIT, см. [LICENSE](LICENSE).
