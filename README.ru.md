# Предпроверка URL для IndexNow — `indexnowkit/verify`

Один GET каждого URL перед отправкой Яндексу, Bing и остальным поисковикам с поддержкой [IndexNow](https://yandex.ru/support/webmaster/ru/indexing-options/index-now).
Страница с `noindex`, запрещённая в `robots.txt`, с другим каноническим URL, с редиректом или недоступная на origin
не отправляется: каждая становится пропущенным `Result` со стабильной причиной `Reason` (`noindex`,
`robots_disallowed`, `non_canonical`, `redirected`, `origin_error`) в логе, у слушателей, в событиях PSR-14 и в
хранилище отправок. Движки видят только URL, которые стоит обходить: объявить `noindex`-страницу или редирект — в
лучшем случае потратить квоту, в худшем — дать движку повод меньше доверять ключу.

**Что никогда не блокируется: 404 и 410.** URL с ответом 404 или 410 отправляется как есть — это сигнал удаления,
который IndexNow принимает, и ради него удалённую страницу и отправляют. IndexNow — уведомление, не индексация:
обходить ли и когда — решает поисковик.

**Выключено по умолчанию.** Установка пакета ничего не меняет, пока не задано `verify.enabled: true`.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/verify)](https://packagist.org/packages/indexnowkit/verify)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/verify)](https://packagist.org/packages/indexnowkit/verify)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![Coverage](https://img.shields.io/badge/coverage-%E2%89%A5%2090%25%20enforced-brightgreen)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/verify)](LICENSE)

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Установка

```bash
composer require indexnowkit/verify         # тянет indexnowkit/core; больше ничего
```

С адаптером фреймворка (`indexnowkit/symfony-bundle`, `laravel`, `yii2`) этого достаточно: адаптер находит пакет,
добавляет блок `verify` в свою конфигурацию и при `verify.enabled: true` оборачивает свой сабмиттер — воркеры
очереди, Messenger и yii2-queue тоже проверяют, потому что получают тот же сабмиттер. `check` печатает строку о
пакете в любом случае (`verify: installed, disabled (verify.enabled: false)`).

```yaml
# Symfony: config/packages/indexnowkit.yaml          # Laravel: config/indexnow.php 'verify' => [...]
indexnowkit:                                         # Yii2: 'verify' => [...] компонента
    verify:
        enabled: true
        redirect: skip           # skip | follow
        non_canonical: skip      # skip | replace
        origin_error: skip       # skip | send
```

## Что решает один GET

| URL отвечает | Решение |
|---|---|
| `200` с `X-Robots-Tag: noindex` / `none` (без префикса бота или с ботом движка IndexNow; `googlebot:` — другой движок) либо `<meta name="robots" content="noindex">` в `<head>` | пропуск, `Reason::Noindex` |
| `200`, а `robots.txt` хоста запрещает путь для `*` или бота движка (проверяется до GET) | пропуск, `Reason::RobotsDisallowed` |
| `200` с `Link: <…>; rel="canonical"` или `<link rel="canonical">` на другой URL (после нормализации ядра: трекинг-параметры, слэш) | `non_canonical: skip` → пропуск, `Reason::NonCanonical`; `replace` → вместо него отправляется канонический, если его хост — ваш |
| `3xx` | `redirect: skip` → пропуск, `Reason::Redirected`; `follow` → цепочка проходится (`max_redirects`, только http(s), только ваши хосты) и цель проверяется; после `301`/`308` отправляются оба URL (страница переехала), после `302`/`303`/`307` — только исходный |
| `404`, `410` | **отправляется** — удаление |
| `401`, `403`, `5xx`, любой другой `4xx`, таймаут или обрыв соединения | `origin_error: skip` → пропуск, `Reason::OriginError` (retryable: очередь может повторить); `send` → отправка с warning. `401`/`403` на защищённом окружении означает: там `verify.enabled: false` |

GET настоящий (не HEAD: многие origin отвечают на HEAD иначе), без следования редиректам, с `verify.timeout` (5 с) и
собственным `User-Agent` (`verify.user_agent`). Для сигналов читается только `<head>` первых 256 КиБ; тело не
`text/html` / `application/xhtml+xml` даёт только заголовки. Комментарии, порядок атрибутов и регистр не важны.

`robots.txt` запрашивается один раз на хост за процесс и хранится в PSR-16-кэше за `debounce.store`
(`verify.robots_cache_ttl`, час). Недоступный `robots.txt` (не `200` и не `404`) ничего не блокирует: один warning на
хост, все пути считаются разрешёнными — недоступный `robots.txt` не повод молчать движкам.

**Время.** Один блокирующий GET на URL, последовательно, `verify.time_budget` (60 с) на всю пачку: после него остаток уходит
без проверки с одним warning, чтобы задание очереди не пережило свой visibility timeout и не досталось второму воркеру.

**Пачки.** Пачка больше `verify.max_batch` (100) URL уходит без проверки с одним warning: это команда `sitemap`, где
сайт сам перечислил свои URL (`indexnow:sitemap --no-verify` говорит это явно). `submit --dry-run` выполняет
предпроверку (GET безвреден) и показывает, что было бы отсечено.

## `dispatch: sync` и `verify.delay`

При `dispatch: sync` GET-ы выполняются внутри веб-запроса, изменившего страницу: N URL — N обращений к собственному
origin, а на однопроцессном dev-сервере (`symfony server:start` без воркеров, `php artisan serve`, `php yii serve`)
запрос к самому себе висит до таймаута. `check` предупреждает об этом (`verify.dispatch`). Используйте `dispatch:
queue` / `messenger` или держите `verify.enabled: false` вне production.

`verify.delay` (секунды, не больше 30) — пауза перед первым GET пачки, чтобы воркер очереди сразу после коммита не
получил страницу из кэша со старой версией; действует только вне веб-запроса, при `dispatch: sync` игнорируется.

## `check --sample`

```bash
bin/console indexnow:check --sample=https://www.example.com/blog/post-1 --sample-class='App\Entity\Post'
php artisan indexnow:check --sample=https://www.example.com/blog/post-1 --sample-class='App\Models\Post:42'
php yii indexnow/check --sample=https://www.example.com/blog/post-1,https://www.example.com/    # Yii2: через запятую, запятая внутри URL не поддерживается
```

Каждый сэмпл запрашивается и печатается одной строкой (`verify sample https://…: HTTP 200, index, canonical: self,
robots: allowed`, код `verify.sample`, хост URL). `--sample-class` принимает класс с `#[IndexNow]` (`<FQCN>` — до трёх
его объектов, `<FQCN>:<id>` — один) и резолвит их URL так же, как отправка. `noindex`, запрет, другой canonical,
редирект, `4xx`/`5xx` или недоступный origin — **warning, никогда не error**: production может быть недоступен из CI,
и отчёт не должен ронять деплой. Без пакета `--sample` — ошибка (`check --sample needs indexnowkit/verify`); `check` без
сэмплов печатает строку `no sample given` (код `verify.sample`).

## Чистый PHP

```php
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;

$core = Config::fromEnv();
$verify = VerifyConfig::fromArray(['enabled' => true, 'redirect' => 'follow']);
$indexNow = IndexNowKit::create($core);
$submitter = new VerifyingSubmitter(
    $indexNow->submitter,
    TransportFactory::lazy($verify->transportConfig($core), null, ['User-Agent' => $verify->userAgent()]),
    $verify,
    StaticKeyProvider::fromConfig($core),
    UrlNormalizerFactory::fromConfig($core),
);
$results = $submitter->submit(['https://www.example.com/blog/post-1']);
```

`VerifyingSubmitter` реализует `SubmitterInterface`; слушатели, зарегистрированные на нём, видят каждый результат
(внутренние и пропущенные) ровно один раз; передайте ему тот же диспетчер PSR-14 и хранилище отправок, что и
внутреннему сабмиттеру, — тогда пропущенные результаты дойдут и до них. Строки лога — фиксированные, их grep'ят
операторы ([docs/operations.md](docs/operations.md)); опции — в [docs/configuration.md](docs/configuration.md).

## Требования

PHP 8.2+, `indexnowkit/core ^0.11`; `indexnowkit/console ^0.4` для опций `--sample` команды `check` (есть у каждого
адаптера).

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/verify` (дополнение к `indexnowkit/core`). С адаптером фреймворка ничего не проводится вручную: `verify.enabled: true` в конфигурации адаптера оборачивает сабмиттер; воркеры очереди тоже проверяют.
- Минимальный полный сниппет (все `use` включены):

```php
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;

$core = Config::fromEnv();
$verify = VerifyConfig::fromArray(['enabled' => true]);
$indexNow = IndexNowKit::create($core);
$submitter = new VerifyingSubmitter($indexNow->submitter, TransportFactory::lazy($verify->transportConfig($core), null, ['User-Agent' => $verify->userAgent()]), $verify, StaticKeyProvider::fromConfig($core), UrlNormalizerFactory::fromConfig($core));
$submitter->submit(['https://www.example.com/page']);    // пропущенные Result несут Reason::Noindex, RobotsDisallowed, NonCanonical, Redirected, OriginError
```

- Проверка: `bin/console indexnow:check --sample=https://www.example.com/page`, `php artisan indexnow:check --sample=…`, `php yii indexnow/check --sample=…` — строка на сэмпл, только warning; `check` сам по себе печатает строку `verify:` (installed / disabled / not installed).
- Ловушки:
  - `verify.enabled` по умолчанию `false`; установка ничего не меняет. При `dispatch: sync` GET-ы идут внутри веб-запроса (`check` предупреждает): нужна очередь.
  - 404 и 410 проходят намеренно (удаления); `verify.redirect: follow` отправляет оба URL после 301/308 и только исходный после 302/303/307.
  - Canonical или цель редиректа на хосте без ключа в `hosts` / `base_url` — пропуск, никогда не отправка: страница не может заставить объявить чужой сайт.
  - Недоступный robots.txt (500, таймаут) разрешает всё с одним warning; 404 — нормальное «robots.txt нет».
  - Пачки больше `verify.max_batch` (100) уходят без проверки с warning — это команда `sitemap`; `indexnow:sitemap --no-verify` говорит это явно.
  - `indexnow:check --sample` требует этот пакет; без него опция — ошибка со строкой установки.
  - `dispatch: auto` есть в Symfony и Yii2, **нет** в Laravel; локали — `router.locales` (Laravel), `router.languages` (Yii2), `framework.enabled_locales` (Symfony).

## Версионирование

SemVer; до 1.0 минорные версии могут содержать ломающие изменения, они перечислены в [CHANGELOG.md](CHANGELOG.md).
Что покрывает обещание совместимости: [docs/bc.md](docs/bc.md).

MIT. IndexNow — торговая марка её владельца; проект независим и не связан с Microsoft, Яндексом или indexnow.org.
