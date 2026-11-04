# MagratheaApiControl — API Controller Base

**File:** `src/MagratheaApiControl.php`
**Namespace:** `Magrathea`

Base class for all API controllers. Provides HTTP request parsing, JWT authentication helpers, CRUD stubs, and caching utilities.

---

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$model` | `?string` | Associated model class name |
| `$service` | `?object` | Optional service/helper object |
| `$userInfo` | `?object` | Decoded JWT payload after token validation |
| `$jwtEncodeType` | `string` | JWT algorithm (default: `"HS256"`) |

---

## Defining a Controller

```php
<?php
namespace App\Api;

use Magrathea\MagratheaApiControl;
use Magrathea\Exceptions\MagratheaApiException;
use App\Controls\ProductControl;

class ProductApiControl extends MagratheaApiControl {

    // Read(), Create(), Update() and Delete() work on this model as they are (see CRUD Methods)
    protected $model = \App\Models\Product::class;

    // List() calls $this->service->GetAll(): without a service, override it
    public function List(): array {
        return ProductControl::GetAll();
    }

    // Override one to change it, e.g. a 404 instead of the model's exception
    public function Read($params = false) {
        if (!$params) return $this->List();
        $id = (new \App\Models\Product())->ValidateId($params["id"]); // malformed id: HTTP 400
        $product = ProductControl::GetRowWhere(["id" => $id]);
        if (!$product) throw new MagratheaApiException("Product not found", 404);
        return $product; // the model itself: its public properties are encoded, timestamps included
    }
}
```

Return models (or arrays of them) from actions, not `ToArray()` (no timestamps) or `ToJson()` (a
`{object, id, created_at, updated_at, fields}` wrapper).

---

## HTTP Request Methods

### `GetAllHeaders(): array<string, string>`
Returns all HTTP request headers as an associative array.

```php
$headers = $this->GetAllHeaders();
echo $headers["Content-Type"];
```

### `GetPost(): ?array`
Returns the decoded POST body. Handles both `application/json` and `application/x-www-form-urlencoded`.

```php
$data = $this->GetPost();
$name  = $data["name"]  ?? "";
$email = $data["email"] ?? "";
```

### `GetPut(): ?array`
Returns the decoded PUT (or PATCH) request body (reads from `php://input`).

```php
$data = $this->GetPut();
$newPrice = $data["price"] ?? null;
```

### `GetPatch(): ?array`
Alias of `GetPut()` — returns the decoded PATCH (or PUT) request body.

```php
$data = $this->GetPatch();
```

### `GetPhpInput(): mixed`
Returns the raw body of the current request (any method).

```php
$raw = $this->GetPhpInput();
```

---

## JWT Authentication Methods

### `GetSecret(): string`
Returns the JWT secret: `jwt_key` from the active environment's config. Override it only to read
another key.

```php
// In your AuthControl:
public function GetSecret(): string {
    return Config::Instance()->Get("api_jwt_secret");
}
```

### `jwtEncode(mixed $payload): string`
Encodes a payload into a JWT token string.

```php
$token = $this->jwtEncode([
    "id"   => 42,        // the user's id: GetUserId() reads "id"
    "role" => "admin",
    "exp"  => time() + 3600,
]);
```

### `jwtDecode(string $token): object`
Decodes a JWT token and returns the payload as an object.

```php
$payload = $this->jwtDecode($token);
echo $payload->id;
```

Decode failures from the underlying `firebase/php-jwt` library are translated into
`MagratheaApiException`, not left to propagate as raw library exceptions:
- An expired token (`exp` in the past) throws code `4010`, with the token's `exp` claim
  attached as `expiredAt` (`$ex->GetData()["expiredAt"]`) — the same code
  `MagratheaApiAuth::CheckExpire()` uses for its own expiry check.
- Anything else (bad signature, malformed segments, `nbf`/`iat` in the future, unsupported
  algorithm) throws code `401`.

```php
try {
    $payload = $this->jwtDecode($token);
} catch (MagratheaApiException $ex) {
    if ($ex->getCode() === 4010) {
        $expiredAt = $ex->GetData()["expiredAt"] ?? null; // unix timestamp
    }
    throw $ex; // or handle inline
}
```

### `GetAuthorizationToken(): string`
Extracts the Bearer token from the `Authorization` header. Throws `MagratheaApiException` if missing (code 400, HTTP 401 via `SetStatus(401)`; before 2.4.0 the status was ignored and the response was HTTP 400).

```php
$token = $this->GetAuthorizationToken();
```

### `GetTokenInfo(string|false $token = false): object|false`
Decodes the current request's Bearer token. Returns `false` only when no token was found at
all (no `Authorization` header, no matching cookie). If a token *was* found but fails to
decode (expired, bad signature, malformed), it throws `MagratheaApiException` — via
`jwtDecode()`, see above — rather than returning `false`.

```php
$info = $this->GetTokenInfo();
if (!$info) {
    throw new MagratheaApiException("Invalid token", 401); // no token was present at all
}
```

### `GetUserInfo(): ?object`
Returns the decoded user payload set during authorization.

```php
$user = $this->GetUserInfo();
echo $user->role;
```

### `GetUserId(): int|null`
Returns the decoded token's `id` claim, or its `user_id` claim when there's no `id`, or `null`. Put
the user's id in the payload as `"id"`, as `MagratheaApiAuth::ResponseLogin()` does. (Before 3.0,
only `id` was read: a `"user_id"` token got `null`.)

```php
$userId = $this->GetUserId();
```

---

## CRUD Methods

The routes `MagratheaApi::Crud()` registers. Override them in a subclass as needed; the defaults
work on the control's `$model` (a model class name; `List()` uses `$service->GetAll()`).

| Method | HTTP Method | Route | Default |
|--------|-------------|-------|---------|
| `List(): array` | GET | `resources` | `$service->GetAll()` (through `Read()` without params) |
| `Read($params): object\|array` | GET | `resource/:id` | `new $model($id)`; no params → `List()` |
| `Create($data): object` | POST | `resources` | inserts, returns the object |
| `Update(array $params): object` | PUT | `resource/:id` | updates, returns the object |
| `Delete($params): bool` | DELETE | `resource/:id` | loads, then deletes |

`Crud("resource", …)` registers the list routes as `"resource" . "s"`; `Crud(["person", "people"], …)`
names both. Routes have no leading slash.

- **`Create()`** drops `id`, `created_at` and `updated_at` from the data (the database sets them),
  inserts, and returns the object with its new id and `null` timestamps.
- **`Update()`** takes the id from the route (`:id`), or from the body's `id` when the route has
  none, and checks it for an int PK (`ValidateId()`): a malformed id (`"5abc"`) throws
  `MagratheaModelException` with code 400 before anything is written. It drops the PK,
  `created_at` and `updated_at` from the data, so a body `id` can't send the update to another row.
  Empty data throws `"Empty Data Sent"`. It returns the object it wrote: only the fields that were
  sent, and a `null` `updated_at`.

Neither reads the row back: no extra query per write. When an endpoint's response needs the row as
stored (timestamps included), reload it explicitly, one query:

```php
public function Create($data = false) {
    return parent::Create($data)->Reload();
}
public function Update($params) {
    return parent::Update($params)->Reload();
}
```

---

## Caching Methods

See [MagratheaCache](../utilities/cache.md) for how the cache works.

### `Cache(string $name, string|null $data = null): void`
Call it at the start of an action. On a cache hit it outputs the cached response and ends the script;
on a miss it returns, and the action's successful response is saved under that key. `$data` is an
optional key suffix (an id, a page number), **not** the response: the key becomes `"{$name}-{$data}"`.

In DEV (`IsDev()`) it also logs `adding cache for [name/data] from (<uri> -><action>)` to the `Logger`;
other environments don't log (before 2.4.0 it logged on every call, everywhere).

```php
public function List(): array {
    $this->Cache("products_list");          // hit: served from disk, the code below never runs
    return ProductControl::GetAll();
}

public function View($params = false): Product {
    $this->Cache("product", $params["id"]); // one file per product: product-<id>.json
    return new Product($params["id"]);
}
```

### `CacheClear(string $name, string|null $data = null): void`
Clear a specific cache entry.

```php
public function Create($data = false) {
    $product = parent::Create($data);
    $this->CacheClear("products_list");
    return $product;
}
```

### `CacheClearPattern(string $pattern): void`
Clear all cache entries matching a pattern.

```php
$this->CacheClearPattern("products_*");
```

---

## Raw Output

### `Raw(string $content): void`
Output raw (non-JSON) content and exit. Useful for file downloads, CSV exports, etc.

```php
public function ExportCsv(): void {
    header("Content-Type: text/csv");
    $this->Raw("id,name,price\n1,Widget,9.99\n");
}
```

---

## Complete Example: User Authentication API

```php
<?php
namespace App\Api;

use Magrathea\MagratheaApiAuth;
use Magrathea\Exceptions\MagratheaApiException;
use App\Controls\UserControl;

// MagratheaApiAuth (a MagratheaApiControl): BaseAuthorization() accepts only that
class AuthApiControl extends MagratheaApiAuth {

    // Called for every protected route as base authorization
    public function ValidateToken(): bool {
        $token = $this->GetAuthorizationToken();
        $info  = $this->GetTokenInfo($token);

        if (!$info || !isset($info->id)) {
            throw new MagratheaApiException("Unauthorized", 401); // code 0 would be HTTP 500
        }

        // Store for later use in other methods
        $this->userInfo = $info;
        return true;
    }

    // POST auth/login
    public function Login(): array {
        $post  = $this->GetPost();
        $email = $post["email"] ?? "";
        $pass  = $post["password"] ?? "";

        $user = UserControl::GetRowWhere(["email" => $email]);

        if (!$user || !password_verify($pass, $user->password_hash)) {
            throw new MagratheaApiException("Invalid credentials", 401);
        }

        $token = $this->jwtEncode([
            "id"    => $user->id,      // GetUserId() reads "id"
            "email" => $user->email,
            "exp"   => time() + 86400, // 24 hours
        ]);

        // never return the model itself here: it carries the password hash
        return ["token" => $token, "user" => ["id" => $user->id, "email" => $user->email]];
    }

    // GET auth/me
    public function Me(): array {
        $user = UserControl::GetRowWhere(["id" => $this->GetUserId()]);
        return ["id" => $user->id, "email" => $user->email];
    }
}
```

```php
// In the API class (or public/index.php):
$auth = new AuthApiControl();
$api->BaseAuthorization($auth, "ValidateToken");   // what `true` means below
$api->Add("POST", "auth/login", $auth, "Login");
$api->Add("GET",  "auth/me",    $auth, "Me", true);
```

---

## Notes

- `GetPost()` and `GetPut()` automatically handle both JSON and form-encoded bodies.
- The `$userInfo` property is populated by your authorization method and accessible in subsequent calls.
- Override `GetSecret()` if you want to use a config-driven or environment-variable JWT secret.
