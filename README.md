# Image API

Закрытое API для своих изображений. Стек: PHP 8.5, Symfony 8.1, PostgreSQL 16, Garage (S3), nginx. Спецификация OpenAPI доступна в браузере.

Нужен только Docker.

## Запуск

```bash
bin/generate-secrets
docker compose up -d --build
```

Скрипт один раз создаёт `.env.local` и `docker/garage/garage.toml` из примеров и записывает туда случайные секреты. Эти файлы не коммитятся. Повторный запуск их не перезаписывает; чтобы выпустить новые ключи, нужен `bin/generate-secrets --force` и чистые тома Garage.

`docker compose` собирает образ со стадией `test` (с PHPUnit и pcov). Прод-образ без dev-зависимостей:

```bash
docker build --target prod -t image-api:prod .
```

Подождите, пока контейнер `app` станет healthy (первый старт ставит зависимости и применяет миграции):

```bash
docker compose ps
```

- API: http://localhost:8080
- Swagger UI: http://localhost:8080/api/doc
- OpenAPI JSON: http://localhost:8080/api/doc.json
- Liveness: http://localhost:8080/health
- Readiness (БД + Garage): http://localhost:8080/health/ready

Остановка:

```bash
docker compose down
```

## Пример

```bash
curl -s -X POST http://localhost:8080/api/register \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"password1"}'

curl -s -X POST http://localhost:8080/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"password1"}'

curl -s -X POST http://localhost:8080/api/images \
  -H "Authorization: Bearer ТОКЕН" \
  -F "file=@photo.png"

curl -s http://localhost:8080/api/images \
  -H "Authorization: Bearer ТОКЕН"

curl -s -o image.webp http://localhost:8080/api/images/ID \
  -H "Authorization: Bearer ТОКЕН"

curl -s -X DELETE http://localhost:8080/api/images/ID \
  -H "Authorization: Bearer ТОКЕН"
```

Пароль от 8 до 128 символов. Файл — только PNG или JPEG, не больше 5 МБ. Чужие изображения не видны: чужой `id` отвечает 404.

## Тесты

Покрытие строк `src/` — 100%. Команда запускает PHPUnit и проверяет отчёт:

```bash
docker compose exec app composer test
```

## Как устроено

- Вход по JWT (HS256), заголовок `Authorization: Bearer`.
- Метаданные лежат в PostgreSQL. Сами байты — в Garage, бакет `images`.
- Перед записью файл перекодируется в WebP с качеством 82. Если WebP оказался не меньше оригинала, сохраняется исходный файл.
- Одинаковые байты определяются по SHA-256 до сжатия: повторная загрузка того же пользователя не трогает CPU. Объект в Garage один, у каждого пользователя своя ссылка. Последнее удаление стирает объект.
- Скачивание идёт потоком из Garage (`StreamedResponse`), с `ETag` по content-hash. В проде можно вынести отдачу на presigned URL Garage и полностью убрать байты из PHP.
- Список отдаётся страницами по курсору `(created_at, id)`, без `OFFSET`, чтобы сотни тысяч файлов в день не тормозили выборку. PHP-FPM не хранит файлы на диске контейнера и может масштабироваться репликами.
- Повтор одной и той же загрузки сериализуется блокировкой PostgreSQL по хешу содержимого.
- Регистрация и логин ограничены rate limiter (5 попыток в минуту на IP+email).
- Для роста нагрузки асинхронное сжатие через Messenger — следующий шаг, при ~1.2 rps синхронной обработки достаточно.
