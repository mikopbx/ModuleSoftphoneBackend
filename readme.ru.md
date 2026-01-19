# ModuleSoftphoneBackend

REST API бэкэнд для мобильных приложений SIP телефонии с правильной JWT аутентификацией.

## Описание

ModuleSoftphoneBackend предоставляет безопасный REST API для приложений softphone с:
- **JWT Bearer Token аутентификацией** (соответствует RFC 6750)
- **Отдельные Access и Refresh токены**
- **HMAC-SHA256 подписанием токенов**
- **Логирование безопасности**
- **Правильные HTTP статус коды**

## Заметки по безопасности

- **Bearer token**: отправляйте токен через заголовок `Authorization: Bearer <token>`.
- **Типы токенов**: access для вызова API, refresh для обновления access.
- **Статус-коды**: `200`, `400`, `401`, `403`, `500`.

## API Эндпоинты

### Аутентификация

#### Вход
```
POST /pbxcore/api/module-softphone-backend/v1/auth/login

Content-Type: application/json

Запрос:
{
  "username": "user@example.com",
  "password": "secure_password"
}

Ответ (200):
{
  "success": true,
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### Обновление токена
```
POST /pbxcore/api/module-softphone-backend/v1/auth/refresh

Authorization: Bearer {refresh_token}

Ответ (200):
{
  "success": true,
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### Выход
```
POST /pbxcore/api/module-softphone-backend/v1/auth/logout

Authorization: Bearer {access_token}

Ответ (200):
{
  "success": true,
  "message": "Logged out successfully"
}
```

### Защищённые ресурсы

#### Получить профиль пользователя
```
GET /pbxcore/api/module-softphone-backend/v1/profile

Authorization: Bearer {access_token}

Ответ (200):
{
  "success": true,
  "user": {
    "id": 1,
    "username": "user@example.com",
    "role": "user"
  }
}
```

### Открытые эндпоинты

#### Проверка здоровья
```
GET /pbxcore/api/module-softphone-backend/v1/health

Ответ (200):
{
  "success": true,
  "status": "ok",
  "timestamp": 1234567890
}
```

## Конфигурация

### Время истечения токенов

Отредактируйте в `Lib/SoftphoneBackendConf.php`:
```php
public const ACCESS_TOKEN_EXPIRY = 3600;      // 1 час
public const REFRESH_TOKEN_EXPIRY = 2592000;  // 30 дней
```

### Управление секретным ключом

Модуль генерирует и хранит секретный ключ подписи в каталоге данных модуля:

- `modules/ModuleSoftphoneBackend/db/secret.key`

Этот путь сохраняется между перезагрузками (в отличие от `/var/etc`).

## Примеры CLI (Linux, curl)

```bash
# Base URL
BASE_URL="http://127.0.0.1/pbxcore/api/module-softphone-backend/v1"

# 1) Вход и получение токенов (сохраняем полный ответ)
LOGIN_RES="$(curl -sS -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"201","password":"b749601f329a635a19c4a87bc359be3d"}')"
echo "$LOGIN_RES"

# 2) Извлечь токены (jq не нужен)
# Вариант A (sed -E):
ACCESS_TOKEN="$(printf '%s\n' "$LOGIN_RES" | sed -nE 's/.*"access_token":"([^"]+)".*/\1/p')"
REFRESH_TOKEN="$(printf '%s\n' "$LOGIN_RES" | sed -nE 's/.*"refresh_token":"([^"]+)".*/\1/p')"

# 3) Вызов защищённого эндпоинта
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# 4) Обновление access токена
curl -sS -X POST "$BASE_URL/auth/refresh" \
  -H "Authorization: Bearer $REFRESH_TOKEN"

# 5) Выход
curl -sS -X POST "$BASE_URL/auth/logout" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# 6) Проверка здоровья (публичный эндпоинт)
curl -sS "$BASE_URL/health"

# 7) Внутренний эндпоинт проверки JWT, используется nginx (вернёт 200/401)
curl -sS "$BASE_URL/check-media-access" \
  -H "Authorization: Bearer $ACCESS_TOKEN"

# Примеры ошибок:
# 401 Unauthorized
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer invalid_token"

# 403 Forbidden (refresh токен вместо access)
curl -sS "$BASE_URL/profile" \
  -H "Authorization: Bearer $REFRESH_TOKEN"

# 400 Bad Request (пустые креды)
curl -sS -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"","password":""}'

# Nchan endpoints (users-state)
# Publisher (без авторизации, только localhost)
curl -sS -X POST "$BASE_URL/pub/users-state" \
  -H "Content-Type: application/json" \
  -d '{"event":"users-state-update"}'

# Subscriber (нужна авторизация; для WS/EventSource токен обычно передаётся параметром)
curl -sS "$BASE_URL/sub/users-state?authorization=$ACCESS_TOKEN"
```

## Установка

1. Поместите модуль в `/Volumes/projects/pbx_miko/modules/ModuleSoftphoneBackend`
2. Запустите установку модуля
3. Модуль будет доступен в веб-интерфейсе

## Логирование

События безопасности логируются в:
```
/storage/usbdisk1/mikopbx/log/ModuleSoftphoneBackend/security.log
```

Логируемые события:
- `AUTH_SUCCESS` - Успешный вход
- `AUTH_FAILED` - Неудачная попытка входа
- `LOGOUT` - Выход пользователя

## Лицензия

Собственность и конфиденциально. Все права защищены.

## Поддержка

Email: help@miko.ru

