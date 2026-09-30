# Image API

Закрытое API для своих изображений. Стек: PHP 8.5, Laravel 13, PostgreSQL 16, Garage (S3), nginx. Спецификация OpenAPI доступна в браузере.

Нужен только Docker.

## Запуск

```bash
bin/generate-secrets
docker compose up -d --build
```

Скрипт один раз создаёт `.env.local` и `docker/garage/garage.toml` из примеров и записывает туда случайные секреты (в том числе `APP_KEY` и `JWT_SECRET`). Эти файлы не коммитятся. Повторный запуск их не перезаписывает; чтобы выпустить новые ключи, нужен `bin/generate-secrets --force` и чистые тома Garage.

`docker compose` собирает образ со стадией `test` (с PHPUnit и pcov). Прод-образ без dev-зависимостей:

```bash
docker build --target prod -t image-api:prod .
```

Подождите, пока контейнер `app` станет healthy (первый старт применяет миграции):

```bash
docker compose ps
```

- Корень http://localhost:8080 открывает Swagger UI
- Swagger UI: http://localhost:8080/api/doc
- OpenAPI JSON: http://localhost:8080/api/doc.json
- Liveness: http://localhost:8080/health
- Readiness (БД + Garage): http://localhost:8080/health/ready

Остановка:

```bash
docker compose down
```

## Пример

Токен — поле `token` в ответе регистрации или входа. Его передают заголовком `Authorization: Bearer <token>`. Без этого заголовка закрытые маршруты отвечают `401` и `{"error":"Unauthorized"}`. `photo.png` ниже — любой локальный PNG или JPEG.

```bash
BASE=http://localhost:8080

TOKEN=$(curl -s -X POST "$BASE/api/register" \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"password1"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

curl -s -X POST "$BASE/api/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"password1"}'

curl -s -X POST "$BASE/api/images" \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@photo.png"

curl -s "$BASE/api/images?limit=20" \
  -H "Authorization: Bearer $TOKEN"

ID=$(curl -s "$BASE/api/images" -H "Authorization: Bearer $TOKEN" \
  | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')

curl -s -o image.webp "$BASE/api/images/$ID" \
  -H "Authorization: Bearer $TOKEN"

curl -s -o /dev/null -D - -X DELETE "$BASE/api/images/$ID" \
  -H "Authorization: Bearer $TOKEN"
```

Повторная регистрация того же email отвечает `409`. Повторная загрузка тех же байтов тем же пользователем отвечает `200` и `"duplicate": true`.

Пароль от 8 до 128 байт. Перед bcrypt считается SHA-256, поэтому пароль длиннее 72 байт не обрезается. Email приводится к нижнему регистру, длиннее 180 байт отклоняется. Файл — только PNG или JPEG, не больше 5 МБ, сторона не больше 8192 px и не больше 16 777 216 пикселей. Чужие изображения не видны: чужой `id` отвечает 404.

## Ответы

Успешный JSON и ошибки описаны в OpenAPI (`/api/doc`). Ошибка всегда один объект `{"error":"..."}` без вложенного `errors`.

| Запрос | Успех | Ошибки |
| --- | --- | --- |
| `POST /api/register` | `201` `{token, user:{id, email}}` | `409` Email is already registered, `422` некорректное тело, `429` Too many attempts и `Retry-After` |
| `POST /api/login` | `200` тот же объект | `401` Invalid credentials, `422`, `429` |
| `POST /api/images` | `201` новое, `200` повтор того же пользователя. Поля изображения плюс `duplicate` | `401` Unauthorized, `413` File is larger than 5 MB, `422` тип, пустой файл, размеры, `400` Upload failed |
| `GET /api/images` | `200` `{items, nextCursor}`. В элементах нет `duplicate` | `401`, `422` Limit is invalid, Limit must not exceed 100, Cursor is invalid |
| `GET /api/images/{id}` | `200` тело файла, `304` если `If-None-Match` совпал с `ETag` | `401`, `404` Image not found |
| `DELETE /api/images/{id}` | `204` без тела | `401`, `404` Image not found |

На `200` скачивания: `Content-Type`, `Content-Length`, `Content-Disposition: inline; filename=...`, `ETag` (хеш в кавычках), `Cache-Control: max-age=3600, private`, `Vary: Authorization`, `X-Content-Type-Options: nosniff`. На `304` те же заголовки представления, без тела и без `Content-Disposition`. Неизвестный путь — `404`, неверный метод — `405`, тело по-прежнему `{"error":"..."}`. Невалидный UUID не сопоставляется с маршрутом и отвечает `404` с текстом маршрута. Существующий UUID без своей записи — `{"error":"Image not found"}`.

## Тесты

Покрытие строк `app/` — 100%. Команда запускает PHPUnit и проверяет отчёт:

```bash
docker compose exec app composer test
```

Тесты работают с отдельной базой `app_test` (её создаёт `docker/postgres/init.sql`): `tests/bootstrap.php` подменяет имя базы в `DB_URL` и переключает кеш и сессии на драйверы в памяти. Статический анализ:

```bash
docker compose exec app composer phpstan
```

## Как устроено

- Вход по JWT (HS256, свой `App\Security\JwtCodec`), заголовок `Authorization: Bearer`. Секрет короче 32 байт отклоняется при старте. Заголовок токена обязан объявлять HS256. Проверяет токен middleware `jwt` (`App\Http\Middleware\JwtAuthenticate`).
- Метаданные лежат в PostgreSQL (Eloquent, `users` / `image_blobs` / `images` с UUID-ключами). Сами байты — в Garage, бакет `images`.
- Перед записью нового содержимого файл перекодируется в WebP с качеством 90. Если WebP оказался не меньше оригинала, сохраняется исходный файл. Повторное декодирование на валидации не делается: битый файл отсекает уже компрессор.
- Одинаковые байты определяются по SHA-256 до сжатия. Если объект уже есть, повтор не трогает компрессор и Garage — добавляется только ссылка пользователя (`image_blobs.reference_count`). Повтор того же пользователя возвращает уже созданную запись. Последнее удаление стирает объект.
- Новый объект пишется в Garage до коммита (ключ — хеш, повторная запись идемпотентна). В транзакции остаётся учёт ссылок. Файл удаляется из Garage уже после коммита, когда ссылок не осталось: откат базы не сотрёт байты, на которые она ещё ссылается.
- Скачивание идёт потоком из Garage (`StreamedResponse`), поток закрывается. Ответ несёт `ETag` по content-hash, `304` на `If-None-Match`, `Cache-Control: max-age=3600, private`, `Vary: Authorization` и `X-Content-Type-Options: nosniff`.
- Список отдаётся страницами по курсору `(created_at, id)`, без `OFFSET`, чтобы сотни тысяч файлов в день не тормозили выборку. PHP-FPM не хранит файлы на диске контейнера и может масштабироваться репликами.
- Повтор одной и той же загрузки сериализуется транзакционной блокировкой PostgreSQL: первые 16 hex-символов SHA-256 приводятся к `bigint` через `substr` и `bit(64)`, без `hashtext`.
- Регистрация и логин ограничены `RateLimiter`: 5 неудачных попыток в минуту на IP и email. Успех сбрасывает счётчик. При исчерпании — 429 с `Retry-After`.
- Ошибки рендерятся в `{"error":"..."}` через `withExceptions` в `bootstrap/app.php`. Статус берётся с исключения: слишком большой файл — `FileTooLarge` со статусом 413, а не разбор текста сообщения.
- Сжатие синхронное: `201` приходит уже после записи сжатых байтов.
