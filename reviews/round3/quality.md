# Tur 3 — Kod Kalitesi İncelemesi

**İnceleyen:** qual3
**Kapsam:** login.php, src/ (Config, Database, bootstrap, Auth/*, Http/functions.php), templates/login.php, templates/error.php, login.css, schema.sql, schema_rollback.sql, migrate_md5_passwords.php.
**Ölçüt:** Okunabilirlik, PSR-1 / PSR-4 / PSR-12 uyumu, tasarım, tip güvenliği, test edilebilirlik.
**Not:** Kod dosyaları değiştirilmedi, yalnızca okundu. Tüm PHP dosyalarında `php -l` (PHP 8.2.12) çalıştırıldı, sözdizimi hatası yok. Satır uzunluğu, tab, satır sonu boşluğu, BOM ve dosya sonu satırı kontrol edildi. PHP dosyalarında sorun yok. Tek aşım `schema.sql:44` (151 karakter).

**Genel değerlendirme:** Yeniden yazım 2. turun önerilerini büyük ölçüde uyguluyor. Giriş akışı `AuthService`'e taşınmış, string dönüş kodları enum olmuş, `User` değer nesnesi gelmiş, namespace ve sınıf/dosya düzeni PSR-4'e uygun, şablon ayrılmış, migration idempotent hâle gelmiş. 13 bulgunun 9'u düzeldi, 4'ü kısmen düzeldi. Yeni sorunlar daha ince: `LoginOutcome` geçersiz durumlara izin veriyor, şablon bütün yerel kapsamı görüyor, session'ın açılıp kapanması controller'da elle ve tutarsız yapılıyor, servis tembel yükleme için referansla yakalanan bir closure kullanılıyor. Yüksek önemde yeni bulgu yok.

---

## 1. Tur 2 bulgularının durumu (Y1–Y13)

| # | Tur 2 bulgusu | Durum | Gerekçe ve konum |
|---|---|---|---|
| Y1 | Giriş akışı controller'da | **Düzeldi** | Throttle, kullanıcı arama, doğrulama, hash yükseltme ve loglama `AuthService::attemptLogin` içinde (`src/Auth/AuthService.php:34-60`). Controller yalnızca sonuca göre HTTP kodu, mesaj ve session yazıyor (`login.php:63-82`). TOCTOU sözleşme notu da uygulanmış: önce sayaç artırılıyor, sonra okunuyor (`Throttle.php:22-44`). |
| Y2 | String dönüş kodları | **Düzeldi** | `LoginResult` ve `RegisterResult` enum'ları var (`src/Auth/LoginResult.php`, `RegisterResult.php`). Mesaj seçimi `default`'suz `match` ile yapılıyor, eksik case `UnhandledMatchError` verir (`login.php:79-82`, `97-108`). Sihirli `1062` sabite taşınmış (`AuthService.php:26`). |
| Y3 | `verifyPassword` gizli yan etki | **Düzeldi** | `passwordMatches` saf (`AuthService.php:142-156`). Yazma işi ayrı ve adı davranışını anlatan `upgradeHashIfNeeded`'de (`AuthService.php:158-171`). |
| Y4 | `array $user` tipsiz | **Düzeldi** | `final class User` readonly özelliklerle (`src/Auth/User.php`). Cast'ler tek yerde, `findUserByUsername` içinde (`AuthService.php:133`). |
| Y5 | Global durum, süper global erişimi | **Kısmen** | Statik `db()` gitti. `AuthService` ve `Throttle` bağlantıyı constructor'dan alıyor, `Config` ayrı. Ama `src/Http/functions.php` hâlâ `$_POST` (satır 14), `$_SERVER` (21) ve `$_SESSION`'a (57-89) doğrudan erişiyor. `mysqli_report()` her `Database::connect` çağrısında süreç geneli ayarı değiştiriyor (`Database.php:13`). Bkz. N9. |
| Y6 | Doğrulama kuralları tekrarı, `strlen` | **Düzeldi** | `CredentialRules` tek kaynak. Servis, mesaj (`login.php:98-105`) ve HTML (`templates/login.php:52, 63-70`) buradan okuyor. `mb_strlen` kullanılıyor (`CredentialRules.php:34`). Küçük bir kalıntı için bkz. N12. |
| Y7 | Şablonda tipsiz session verisi | **Düzeldi** | Şablon ayrı dosyada (`templates/login.php`). Session değerleri `is_int`/`is_string` ile kontrol ediliyor (`login.php:119-121`). Roller DB'den `list<string>` olarak geliyor. Değişkenlerin şablona aktarılma biçimi yeni bir sorun: bkz. N2. |
| Y8 | Dar hata yönetimi, geç hata verme | **Düzeldi** | Bütün istek `try { } catch (Throwable)` içinde (`login.php:35-135`). `ob_start` ile yarım sayfa temizleniyor, `(string) $e` loglanıyor. `Config::required` eksik ortam değişkeninde hemen hata veriyor (`Config.php:37-45`). Secure bayrağı `COOKIE_SECURE`'dan geliyor. |
| Y9 | Bilinmeyen `action`, sihirli string'ler | **Kısmen** | `else` dalı 400 döndürüyor (`login.php:113-116`). Ama `'login'`, `'register'`, `'logout'` action adları ve `'user_id'`, `'username'`, `'csrf_token'`, `'last_activity'` session anahtarları hâlâ dağınık string'ler (`login.php:63, 73-74, 83, 109, 119-120`; `functions.php:57, 63, 72, 77-89`; `templates/login.php:42, 48, 59`). |
| Y10 | Namespace yok, genel global isimler | **Düzeldi** | `App`, `App\Auth`, `App\Http` namespace'leri var. `e()` artık `App\Http\e`. Dizin düzeni PSR-4'e uygun (`App\` → `src/`). |
| Y11 | Inline stil, `<br>` | **Kısmen** | Inline stil ve `<br>` kaldırılmış, `login.css` ile `.stack` grid düzeni kurulmuş. Ama aynı bulguda istenen `<meta name="viewport" ...>` iki şablonda da yok (`templates/login.php:20-24`, `templates/error.php:8-11`). `error.php` CSS'i de yüklemiyor. |
| Y12 | Migration dosyası | **Kısmen** | İdempotent (`migrate_add_index`), duplicate kontrolü (`migrate_assert_unique_usernames`), geri alma dosyası ve temizlik EVENT'leri eklenmiş. Kalanlar: ilk satır hâlâ bir inceleme belgesine referans veriyor (`schema.sql:1`, "see reviews/round2/summary.md"). `login_log`, `users`, `roles`, `user_roles` tanımları dosyada yok. GRANT bloğu hâlâ yorum içinde ve `app_db` adı sabit (`schema.sql:86-94`). Dosya adı versiyonsuz. |
| Y13 | Girişte kullanıcı adı uzunluğu | **Düzeldi** | `$isWellFormed` kontrolü var (`AuthService.php:36-37`). Hatalı biçimli ad DB'de aranmıyor ve log'a `''` olarak yazılıyor (`AuthService.php:47, 50`). Formda `maxlength` var (`templates/login.php:52`). |

---

## 2. Yeni / kalan sorunlar

### Özet

| # | Konum | Konu | Önem |
|---|---|---|---|
| N1 | src/Auth/LoginOutcome.php:9-23, login.php:71-82 | `LoginOutcome` geçersiz durumlara izin veriyor; `Success` ile `user !== null` tipte bağlı değil; `match`'te Success dalı örtük | Orta |
| N2 | login.php:127, 133; templates/login.php:9-16 | Şablon `require` ile çağrılıyor ve bütün yerel kapsamı (`$config`, `$service`, `$e`…) görüyor; sözleşme yalnızca docblock'ta | Orta |
| N3 | login.php:66-69, 84, 124-125 | Session açma/kapama controller'da elle ve tutarsız yapılıyor; register yolu kapalı session üzerinde devam ediyor | Orta |
| N4 | login.php:41-50 | Tembel servis için referansla yakalanan `$service` ve closure tutan `$authService` değişkeni | Düşük |
| N5 | login.php:78-82, 96-108 | HTTP kodu ile mesaj iki ayrı dallanmada seçiliyor | Düşük |
| N6 | src/bootstrap.php:5-15 | Elle `require_once` listesi; PSR-4 dizini var ama autoloader yok | Düşük |
| N7 | templates/login.php:1-3, templates/error.php:1-3 | PSR-12: HTML içeren dosyada `declare(strict_types=1)` ilk satırda olmalı | Düşük |
| N8 | login.php:25 | PSR-1: yan etki dosyası aynı zamanda global sabit tanımlıyor | Düşük |
| N9 | src/Auth/*.php, src/Http/functions.php | Test edilebilirlik: `final` somut bağımlılıklar, arayüz yok, süper global'ler | Orta |
| N10 | AuthService.php:12-13, 150-153; migrate_md5_passwords.php:31 | Legacy md5 formatı bilgisi iki dosyaya dağılmış | Düşük |
| N11 | çeşitli | İsimlendirme: `LoginResult`/`LoginOutcome`, `$isHttps` ↔ `secureCookies`, `LOGIN_PASSWORD_MAX_BYTES` | Düşük |
| N12 | templates/login.php:68 | Şifre `maxlength`'i bayt sabitini kullanıyor, HTML ise karakter sayıyor | Düşük |
| N13 | login.php:119-123 | `$userId` daraltılmadan `getUserRoles(int)`'e veriliyor | Düşük |
| N14 | AuthService.php:140; proje | `@phpstan-assert-if-true` var ama projede PHPStan yapılandırması yok | Düşük |
| N15 | functions.php:55-73 | `enforceIdleTimeout` ile `renewSession` aynı işi farklı yapıyor | Düşük |
| N16 | Config.php:32 | Geçersiz `DB_PORT` sessizce `0` oluyor | Düşük |
| N17 | schema.sql:7-8, 44, 51, 54-63, 80, 84; schema_rollback.sql:30 | SQL okunabilirliği ve yorum doğruluğu: 151 karakterlik satır, index adı iki kez, "safe to run more than once" iddiası, adım sırası çelişkisi, `LIMIT`'li DELETE'te `ORDER BY` yok | Düşük |
| N18 | src/Auth/Throttle.php:10, 40 | Sınıf yorumu yanıltıcı ("table stays small even under attack"); `fetch_row()[0]` null kontrolü yok | Düşük |
| N19 | migrate_md5_passwords.php:25-35 | Toplu iş: koşulsuz UPDATE, parti/ilerleme çıktısı yok | Düşük |

---

### N1. `LoginOutcome` geçersiz durumlara izin veriyor (LoginOutcome.php:9-23, login.php:71-82) — **Orta**

```php
public static function failure(LoginResult $result): self
{
    return new self($result);          // failure(LoginResult::Success) de geçerli
}
```

- `LoginOutcome::failure(LoginResult::Success)` çağrılabiliyor. Sonuç `result === Success` ama `user === null` olan bir nesne. `login.php:73` bunu `$outcome->user->id` ile okuyunca null erişimi olur ve kullanıcı 500 görür. Tasarım, olmaması gereken bir durumu ifade edebiliyor.
- `user` tipi `?User`. "Success ise user dolu" kuralı hiçbir tipte yazılı değil. PHPStan seviye 8+ `login.php:73-74` için "Cannot access property $id on User|null" bildirir.
- `login.php:79-82`'deki `match`'te `Success` dalı yok. Bu yalnızca satır 75'teki `redirect()`'in `never` dönmesi sayesinde doğru. Okuyan biri `match`'in tam olup olmadığını anlamak için yukarıdaki `if`'i ve `redirect`'in imzasını bilmek zorunda. Biri `redirect`'i kaldırırsa (ör. JSON yanıtına geçerken) çalışma anında `UnhandledMatchError` alınır.

**Öneri:** Başarısız sonuçlar için adlandırılmış kurucular kullanın, başarı kontrolünü nesneye taşıyın ve tipi PHPStan'e bildirin. `match`'i tam yazın.

```php
final class LoginOutcome
{
    private function __construct(
        public readonly LoginResult $result,
        public readonly ?User $user,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(LoginResult::Success, $user);
    }

    public static function invalidCredentials(): self
    {
        return new self(LoginResult::InvalidCredentials, null);
    }

    public static function throttled(): self
    {
        return new self(LoginResult::Throttled, null);
    }

    /**
     * @phpstan-assert-if-true !null $this->user
     */
    public function isSuccess(): bool
    {
        return $this->result === LoginResult::Success;
    }
}

// login.php
if ($outcome->isSuccess()) {
    renewSession();
    $_SESSION['user_id'] = $outcome->user->id;   // PHPStan artık User biliyor
    // ...
    redirect('login.php');
}

[$status, $error] = match ($outcome->result) {
    LoginResult::Throttled => [429, 'Çok fazla başarısız deneme. Lütfen biraz sonra tekrar deneyin.'],
    LoginResult::InvalidCredentials => [401, 'Kullanıcı adı veya şifre hatalı.'],
    LoginResult::Success => throw new LogicException('Success is handled above.'),
};
http_response_code($status);
```

### N2. Şablon bütün yerel kapsamı görüyor (login.php:127, 133; templates/login.php:9-16) — **Orta**

`require __DIR__ . '/templates/login.php';` try bloğunun içinde çalıştığı için şablon `$config` (DB şifresi dahil), `$service`, `$authService`, `$outcome`, `$result`, `$action`, `$msgKey` gibi tüm değişkenleri görüyor. `error.php` de `$e`'yi görüyor (`login.php:133`).

- Şablonun girdileri yalnızca docblock'taki `@var` listesiyle belgelenmiş (`templates/login.php:9-16`). Bir değişken controller'da yeniden adlandırılırsa şablon "Undefined variable" uyarısı verir ve hiçbir araç bunu önceden yakalamaz.
- Hangi değişkenlerin şablona gittiği controller'dan okunamıyor. Örneğin `$oldUsername` ile `$loggedInUser`'ın şablon için hazırlandığını anlamak için şablonu açmak gerekiyor.
- Şablon test edilemiyor, çünkü çağıranın kapsamına bağlı.

**Öneri:** Şablonu yalıtılmış bir kapsamda, açık bir değişken listesiyle çalıştıran küçük bir `render` fonksiyonu.

```php
// src/Http/functions.php
/**
 * @param array<string, mixed> $vars
 */
function render(string $template, array $vars): void
{
    (static function (string $__template, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        require $__template;
    })($template, $vars);
}

// login.php
render(__DIR__ . '/templates/login.php', [
    'notice' => $notice,
    'error' => $error,
    'loggedInUser' => $loggedInUser,
    'roles' => $roles,
    'oldUsername' => $oldUsername,
    'csrfToken' => $csrfToken,
]);
// catch bloğunda:
render(__DIR__ . '/templates/error.php', []);
```

### N3. Session açma/kapama elle ve tutarsız (login.php:66-69, 84, 124-125) — **Orta**

Controller session kilidini üç yerde elle yönetiyor:

| Yol | Ne oluyor |
|---|---|
| login (66-69) | `session_write_close()` → bcrypt → `session_start()` ile yeniden açılıyor |
| register (84) | `session_write_close()`, ama **yeniden açılmıyor** |
| tüm yollar (124-125) | `csrfToken()` `$_SESSION`'a yazabiliyor, sonra `session_write_close()` |

Register başarısız olunca akış satır 124'e kapalı session ile ulaşıyor. `csrfToken()` token yoksa yenisini üretir ama session kapalı olduğu için kaydedilmez. Satır 125 de kapalı bir session'ı tekrar kapatıyor. Bu şu an zararsız, çünkü CSRF doğrulandıysa token zaten var. Ama doğruluk, kodda yazılı olmayan bir varsayıma dayanıyor. Ayrıca iki POST yolu aynı işi farklı yapıyor ve okuyanın "register neden yeniden açmıyor?" diye düşünmesine yol açıyor. `session_start()` ikinci kez çağrıldığında `startSession()`'daki ayarlar yerine o anki ini değerleri geçerli, bu da başka bir örtük bağımlılık.

**Öneri:** "Kilidi bırak, işi yap, yeniden aç" kalıbını tek bir yardımcıya alın ve iki yolda da kullanın.

```php
// src/Http/functions.php
/**
 * Runs $work with the session lock released, so slow work (bcrypt) doesn't block other tabs.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function withoutSessionLock(callable $work): mixed
{
    session_write_close();
    try {
        return $work();
    } finally {
        session_start();
    }
}

// login.php
$outcome = withoutSessionLock(
    static fn () => $services->auth()->attemptLogin($oldUsername, $password, clientIp())
);
// register de aynı şekilde
```

### N4. Referansla yakalanan `$service` ve closure tutan `$authService` (login.php:41-50) — **Düşük**

```php
$service = null;
$authService = static function () use ($config, &$service): AuthService { ... };
// kullanım: $authService()->attemptLogin(...)
```

- `&$service` ile closure dışarıdaki bir değişkeni değiştiriyor. `$service` controller kapsamında görünür kalıyor, bu yüzden biri yanlışlıkla `$service->...` yazarsa (null olabilir) hata alır. Önbellek durumu iki ayrı değişkene dağılmış.
- `$authService` adı bir servis nesnesini anlatıyor ama değer bir closure. `$authService()->attemptLogin()` okunurken ilk bakışta yanlış anlaşılıyor.

Tembel bağlantı fikri doğru (yorumda da açıklanmış). Sorun yalnızca ifade biçiminde.

**Öneri:** Önbelleği küçük bir sınıfa kapatın.

```php
// src/Services.php
namespace App;

use App\Auth\AuthService;
use App\Auth\Throttle;

/**
 * Builds services on first use, so plain GET requests never open a database connection.
 */
final class Services
{
    private ?AuthService $auth = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function auth(): AuthService
    {
        if ($this->auth === null) {
            $db = Database::connect($this->config);
            $this->auth = new AuthService($db, new Throttle($db));
        }

        return $this->auth;
    }
}

// login.php
$services = new Services($config);
$outcome = $services->auth()->attemptLogin(...);
```

### N5. HTTP kodu ile mesaj iki ayrı dallanmada (login.php:78-82, 96-108) — **Düşük**

```php
http_response_code($outcome->result === LoginResult::Throttled ? 429 : 401);
$error = match ($outcome->result) { ... };
```

Aynı enum iki kez dallandırılıyor: bir kez ternary ile koda, bir kez `match` ile mesaja. Yeni bir case eklendiğinde (ör. `AccountDisabled`) `match` hata verir ama ternary sessizce 401'e düşer. Register'da da aynı durum var (`login.php:96`, yeni bir case 422 alır). İkisini tek `match`'te birleştirin (bkz. N1'deki `[$status, $error] = match (...)` örneği).

### N6. Elle `require_once` listesi (src/bootstrap.php:5-15) — **Düşük**

Namespace ve dizin düzeni PSR-4'e tam uyuyor (`App\Auth\User` → `src/Auth/User.php`), ama yükleme elle yapılıyor. Yeni bir sınıf eklendiğinde listeye eklemeyi unutmak "Class not found" hatası verir. Ayrıca her istek, kullanılmasa bile tüm sınıfları yüklüyor. Composer kullanılmıyorsa da birkaç satırlık bir PSR-4 autoloader yeter. Fonksiyon dosyası autoload edilemez, o yüzden elle kalmalı (Composer'da da `"files"` girdisi olur).

```php
<?php

declare(strict_types=1);

// PSR-4: App\Foo\Bar → src/Foo/Bar.php. With Composer, replace this with "autoload": {"psr-4": {"App\\": "src/"}}.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Functions can't be autoloaded (Composer: "files").
require_once __DIR__ . '/Http/functions.php';
```

### N7. PSR-12: şablonlarda `declare` ilk satırda değil (templates/login.php:1-3, templates/error.php:1-3) — **Düşük**

PSR-12 bölüm 3: PHP etiketleri dışında HTML içeren dosyalarda strict types bildirimi "dosyanın ilk satırında olmalı ve açılış etiketi, bildirim ve kapanış etiketini içermelidir". Her iki şablonda `<?php`, boş satır, `declare(...)` sırası kullanılıyor.

```php
<?php declare(strict_types=1) ?>
<?php

use App\Auth\CredentialRules;

use function App\Http\e;

/**
 * @var string|null $notice
 * ...
 */
?>
<!DOCTYPE html>
```

`error.php` hiç PHP kodu çalıştırmıyor. Orada `declare` satırı tamamen kaldırılabilir ya da dosya `error.html` olarak `readfile` ile verilebilir.

### N8. PSR-1: yan etki dosyası sabit tanımlıyor (login.php:25) — **Düşük**

PSR-1 2.3: bir dosya ya sembol (sınıf, fonksiyon, **sabit**) tanımlamalı ya da yan etkili mantık çalıştırmalı, ikisini birden yapmamalı. `login.php` header/session/çıktı üreten bir giriş noktası ve aynı zamanda global `IDLE_TIMEOUT_SECONDS` sabitini tanımlıyor. Bu, `Config`'e ya da yerel bir değişkene taşınmalı:

```php
// Config.php
public readonly int $idleTimeoutSeconds,   // fromEnv: (int) (getenv('IDLE_TIMEOUT') ?: 1800)

// login.php
enforceIdleTimeout($config->idleTimeoutSeconds);
```

### N9. Test edilebilirlik: `final` somut bağımlılıklar ve süper global'ler (src/Auth/*.php, src/Http/functions.php) — **Orta**

`AuthService` artık bağımlılıklarını constructor'dan alıyor, bu büyük bir ilerleme. Ama birim testi hâlâ zor:

- `AuthService` somut `mysqli` ve `final class Throttle` istiyor (`AuthService.php:28-31`). PHPUnit `final` sınıfları mock'layamaz, `Throttle` için arayüz yok. "Throttled dönünce kullanıcı aranmıyor mu?" gibi bir testi yazmak için gerçek bir MySQL gerekiyor.
- Kullanıcı arama ve loglama da `AuthService` içinde ham SQL. Bir `UserRepository` olmadığı için iş kuralı ile veri erişimi aynı sınıfta.
- Zaman SQL'deki `NOW()`'dan geliyor, `enforceIdleTimeout` ise `time()` kullanıyor. Pencere/süre testleri saat enjekte edilemediği için yazılamıyor.
- `src/Http/functions.php`'deki `postString`, `clientIp`, `csrfToken`, `isValidCsrfToken`, `enforceIdleTimeout` süper global'leri doğrudan okuyor (Y5'ten kalan kısım).

**Öneri (en küçük adım):** `Throttle` için arayüz çıkarın. Kullanıcı sorgularını bir repository'ye alın. Süper global okuyan fonksiyonlara diziyi parametre olarak verin.

```php
namespace App\Auth;

interface RateLimiter
{
    /** Records one hit and returns the number of hits in the current window, including this one. */
    public function hit(string $key, int $windowSeconds): int;

    public function clear(string $key): void;
}

final class Throttle implements RateLimiter { /* mevcut kod */ }

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RateLimiter $throttle,
    ) {
    }
}

// Http
function isValidCsrfToken(array $session, string $token): bool { ... }
```

### N10. Legacy md5 formatı iki dosyaya dağılmış (AuthService.php:12-13, 150-153; migrate_md5_passwords.php:31) — **Düşük**

`legacy-md5:<bcrypt(md5(password))>` formatı şöyle bölünmüş: önek `AuthService`'te public sabit, sarma kuralı (`strtolower` + `password_hash`) migration script'inde, doğrulama kuralı (`md5()` + `password_verify`) `AuthService::passwordMatches`'te. Biri formatı değiştirirse iki dosyayı birlikte güncellemesi gerekiyor. Migration script'i de yalnızca bir sabit için koca servise bağımlı. Formatı tek sınıfta toplayın:

```php
namespace App\Auth;

/**
 * Old md5 hashes wrapped in bcrypt by migrate_md5_passwords.php: "legacy-md5:<bcrypt(md5(password))>".
 */
final class LegacyMd5Hash
{
    private const PREFIX = 'legacy-md5:';

    public static function wrap(string $md5Hex): string
    {
        return self::PREFIX . password_hash(strtolower($md5Hex), PASSWORD_DEFAULT);
    }

    public static function isLegacy(string $storedHash): bool
    {
        return str_starts_with($storedHash, self::PREFIX);
    }

    public static function verify(string $password, string $storedHash): bool
    {
        return password_verify(md5($password), substr($storedHash, strlen(self::PREFIX)));
    }
}
```

### N11. İsimlendirme (çeşitli) — **Düşük**

| Konum | Sorun | Öneri |
|---|---|---|
| `LoginResult.php` / `LoginOutcome.php` | "Result" ve "Outcome" eş anlamlı. Hangisinin enum, hangisinin taşıyıcı nesne olduğu adlardan anlaşılmıyor. `register()` ise doğrudan enum döndürüyor. | `LoginStatus` (enum) + `LoginResult` (nesne), ya da enum'u `LoginOutcome::$status` olarak adlandırın. |
| `functions.php:24` + `login.php:37` | `sendSecurityHeaders(bool $isHttps)` çağrıda `$config->secureCookies` alıyor. Aynı ayar hem çerez bayrağını hem HSTS'yi yönetiyor ama adı yalnızca çerezi anlatıyor. | Config alanını `$httpsOnly` (env: `HTTPS_ONLY`) yapın ve iki fonksiyona da bu adla verin. |
| `CredentialRules.php:17-19` | `PASSWORD_MAX_BYTES` (kayıt) ile `LOGIN_PASSWORD_MAX_BYTES` (giriş) yan yana. Hangisinin kayıt için olduğu adından anlaşılmıyor. | `NEW_PASSWORD_MAX_BYTES` / `LOGIN_PASSWORD_MAX_BYTES`. `isValidNewPassword` ile de uyumlu olur. |
| `login.php:55` | `$oldUsername`: "eski kullanıcı adı" gibi okunuyor, kastedilen "formda geri gösterilecek değer". | `$submittedUsername`. |
| `functions.php:7` | `e()` tek harfli. Namespace içinde olduğu için çakışma riski kalmadı, ama kodu ilk kez okuyan biri için anlamsız. | Kabul edilebilir (şablon kısaltması olarak yaygın). Docblock ekleyin: `/** HTML-escapes a value for text and attribute context. */`. |

### N12. Şifre `maxlength` bayt sabitini kullanıyor (templates/login.php:68) — **Düşük**

`maxlength="<?= CredentialRules::PASSWORD_MAX_BYTES ?>"` (72). HTML `maxlength` bayt değil UTF-16 kod birimi sayar. Türkçe karakterli 50 karakterlik bir şifre 72 bayttan uzun olabilir: tarayıcı kabul eder, sunucu "Invalid" döndürür. Kullanıcı hangi kuralı aştığını anlamaz. `maxlength`'i kaldırın ya da hata mesajında "en fazla 72 bayt (Türkçe karakterler 2 bayt sayılır)" gibi açık bir ifade kullanın. Mesaj şu an "en fazla %d bayt" diyor (`login.php:99`), bu doğru ama son kullanıcı için anlaşılır değil.

### N13. `$userId` daraltılmadan kullanılıyor (login.php:119-123) — **Düşük**

```php
$loggedInUser = is_int($userId) && is_string($username) ? $username : null;
$roles = $loggedInUser !== null ? $authService()->getUserRoles($userId) : [];
```

`$userId`'nin `int` olduğu bilgisi `$loggedInUser !== null` kontrolüne dolaylı olarak bağlı. İnsan okuyucu için de statik analiz için de (PHPStan: "Parameter #1 $userId expects int, mixed given") zincir kopuk. `&&` ile `?:` önceliği de parantezsiz. Session okumasını tek bir yere toplayın:

```php
$currentUser = null;
if (is_int($_SESSION['user_id'] ?? null) && is_string($_SESSION['username'] ?? null)) {
    $currentUser = ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
}
$roles = $currentUser !== null ? $services->auth()->getUserRoles($currentUser['id']) : [];
```

(Daha iyisi: `User` benzeri bir `SessionUser` değer nesnesi döndüren `currentUser(array $session): ?SessionUser` fonksiyonu.)

### N14. `@phpstan-assert-if-true` kullanımı doğru ama doğrulanmıyor (AuthService.php:140; proje) — **Düşük**

`@phpstan-assert-if-true User $user` doğru yazılmış ve doğru yerde. `!$this->passwordMatches($user, ...)` dalı `return` ettiği için sonraki satırlarda `$user` PHPStan için `User` oluyor. Bu da `upgradeHashIfNeeded($user, ...)` ve `$user->username` (satır 55-59) çağrılarını tip açısından güvenli kılıyor. Ama projede `phpstan.neon` ya da `composer.json` yok. Bu yüzden annotation yalnızca belge işlevi görüyor ve yanlış olsa da kimse fark etmez. Ya PHPStan'i projeye ekleyin (`phpstan.neon`, `level: 8`, `paths: [src, login.php]`), ya da `assert($user !== null);` gibi çalışma anı kontrolüyle niyeti koda yazın. N1 ve N13'teki uyarılar da ancak PHPStan çalışırsa görünür.

### N15. `enforceIdleTimeout` ve `renewSession` aynı işi farklı yapıyor (functions.php:55-73) — **Düşük**

`enforceIdleTimeout` `$_SESSION = []; session_regenerate_id(true);` yapıyor. `renewSession` ise `session_regenerate_id(true); unset($_SESSION['csrf_token']);` yapıyor. Logout da `login.php:110-111`'de `$_SESSION = []; renewSession();` yazıyor. "Oturumu sıfırla" kavramı üç yerde üç farklı biçimde ifade ediliyor. Tek fonksiyon önerisi:

```php
/** Ends the current login: clears all session data and issues a new session ID. */
function resetSession(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function enforceIdleTimeout(int $idleSeconds): void
{
    $lastActivity = $_SESSION['last_activity'] ?? null;
    if (is_int($lastActivity) && time() - $lastActivity > $idleSeconds) {
        resetSession();
    }

    $_SESSION['last_activity'] = time();
}
// login.php logout: resetSession(); redirect(...);
```

### N16. Geçersiz `DB_PORT` sessizce `0` oluyor (Config.php:32) — **Düşük**

`(int) (getenv('DB_PORT') ?: 3306)` için `DB_PORT=abc` değeri `0` verir. Diğer ayarlar fail-fast olduğu hâlde bu hata ancak bağlantı denemesinde anlamsız bir mesajla görünür.

```php
$port = getenv('DB_PORT') ?: '3306';
if (!ctype_digit($port)) {
    throw new RuntimeException('DB_PORT must be a number');
}
// ... (int) $port
```

### N17. SQL dosyalarının okunabilirliği (schema.sql:44, 54-63; schema_rollback.sql:30) — **Düşük**

- `schema.sql:44` 151 karakter. `MESSAGE_TEXT` içine gömülü sorgu satırı okunmuyor. Mesajı kısaltın (`'Duplicate usernames found; see the query in the comment above.'`) ve sorguyu bir üst satırdaki yoruma alın.
- `migrate_add_index` çağrılarında index adı iki kez yazılıyor: bir kez `p_index` parametresinde, bir kez DDL string'inin içinde (`schema.sql:54-55, 58-59, 62-63`). İkisi uyuşmazsa varlık kontrolü yanlış ada bakar ve idempotentlik sessizce bozulur. Procedure'ün DDL'i kendisinin kurması daha güvenli:
  ```sql
  CREATE PROCEDURE migrate_add_index(
      IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_leading_column VARCHAR(64),
      IN p_kind VARCHAR(16),        -- 'INDEX' or 'UNIQUE INDEX'
      IN p_columns VARCHAR(255)     -- e.g. 'user_id, role_id'
  )
  BEGIN
      IF NOT EXISTS (...) THEN
          SET @migrate_ddl = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_kind, ' `', p_index, '` (', p_columns, ')');
          ...
  ```
- `p_leading_column` kuralı ("bu kolonla başlayan herhangi bir index varsa atla") parametre adından anlaşılmıyor. Yorumda var (`schema.sql:16-17`) ama `p_skip_if_column_leads` gibi bir ad niyeti kendi anlatır.
- `schema_rollback.sql:30` `uq_users_username`'i düşürüyor. Yeni kod yinelenen kullanıcı adını yalnızca bu index'in 1062 hatasıyla yakalıyor (`AuthService.php:82-85`). Yani kod geri alınmadan yalnızca şema geri alınırsa aynı kullanıcı adı iki kez kaydedilebilir. Rollback dosyasının başına "önce kodu geri alın" uyarısı eklenmeli.
- Y12'den kalanlar (bkz. tablo): `schema.sql:1`'deki inceleme belgesi referansı, eksik tablo tanımları, yorumdaki GRANT bloğu.
- *(perf3)* `schema.sql:1` "Safe to run more than once" diyor, ama `schema.sql:51`'deki `ALTER TABLE users MODIFY password VARCHAR(255)` koşulsuz ve her çalıştırmada tabloyu yeniden kurabiliyor. Yorum ya "tekrar çalıştırılabilir ama satır 51 her seferinde tabloyu yeniden kurar" demeli, ya da ALTER, `information_schema.columns`'taki `character_maximum_length < 255` kontrolüne bağlanmalı (`migrate_add_index` ile aynı kalıp).
- *(sec3)* Adım sırası çelişiyor: `schema.sql:7`'deki 2. adım migration script'ini "aynı DB_* değişkenleriyle" çalıştırıyor, ama app kullanıcısı ve DB_* ayarı 3. adımda (`schema.sql:8`) oluşturuluyor. Ayrıca app kullanıcısı yalnızca kendi tablolarında yetkili. Doğru sıra: script admin bilgileriyle çalışmalı ya da adım 3 adım 2'den önce gelmeli. Bu açıkça yazılmalı.
- *(perf3)* `schema.sql:80, 84`'teki `DELETE ... LIMIT 10000`'lerde `ORDER BY` yok. Hangi satırların silineceği belirsiz ve statement-based binlog "unsafe" uyarısı verir. `ORDER BY created_at` / `ORDER BY window_start` ekleyin. Ayrıca throttle satırları 1 gün tutuluyor, oysa en uzun pencere `REGISTER_WINDOW_SECONDS = 3600` (`AuthService.php:20`). Bu bağ hiçbir yerde yazılı değil. Ya süreyi pencereye göre seçip yorumla gerekçelendirin ya da sabiti adlandırın.

### N18. `Throttle` yorumu yanıltıcı, `fetch_row()` null kontrolü yok (Throttle.php:10, 40) — **Düşük**

- Sınıf docblock'u "one row per key, so the table stays small even under attack" diyor. Ama `attemptLogin` IP limiti aşılmış olsa da iyi biçimli her yeni kullanıcı adı için bir pair satırı ekliyor (`AuthService.php:40-41`, iki `hit` da limit kontrolünden önce). Tek bir IP'den farklı kullanıcı adlarıyla gelen saldırı satır sayısını doğrusal büyütür, ancak temizlik EVENT'i bunu sınırlar *(perf3)*. Yorum gerçek garantiyi yazmalı: "One row per key; rows older than a day are purged by ev_purge_login_throttle." İsterseniz pair hit'i IP limiti aşıldığında atlayın:
  ```php
  $ipHits = $this->throttle->hit('login-ip:' . $ip, self::LOGIN_WINDOW_SECONDS);
  if ($ipHits > self::LOGIN_IP_LIMIT) {
      return LoginOutcome::throttled();
  }
  $pairHits = $isWellFormed ? $this->throttle->hit($pairKey, self::LOGIN_WINDOW_SECONDS) : 0;
  ```
- `(int) $stmt->get_result()->fetch_row()[0]` satır yoksa (`null[0]`) bir uyarı verir ve sessizce `0` döner, yani "limit aşılmadı" sonucu çıkar *(sec3)*. Upsert'ten hemen sonra satırın olmaması pratikte beklenmez. Ama beklenmeyen durum sessizce "izin ver" yönüne düşmemeli:
  ```php
  $row = $stmt->get_result()->fetch_row();
  $stmt->close();
  if ($row === null) {
      throw new \RuntimeException("Throttle row missing for key {$hashedKey}");
  }

  return (int) $row[0];
  ```

### N19. Migration script'i: koşulsuz UPDATE, parti/ilerleme yok (migrate_md5_passwords.php:25-35) — **Düşük**

*(perf3)* `UPDATE users SET password = ? WHERE id = ?` okunan eski değeri kontrol etmiyor. Script çalışırken kullanıcı giriş yapıp hash'i yükseltilirse (`upgradeHashIfNeeded`), yeni bcrypt hash'in üzerine sarılmış eski md5 yazılır. Şifre aynı kaldığı için giriş bozulmaz, ama iş "yalnızca md5 olanları sar" niyetini kodda ifade etmiyor. Ayrıca tüm satırlar tek buffered sorguyla belleğe alınıyor ve ilerleme çıktısı yok, büyük tabloda saatlerce sessiz çalışır.

```php
$update = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');
$update->bind_param('sis', $newHash, $userId, $oldHash);
// ...
if ($wrapped % 1000 === 0) {
    fwrite(STDERR, "{$wrapped} wrapped...\n");
}
```
(Keyset ile partiler hâlinde okuma, `WHERE id > ? ORDER BY id LIMIT 1000`, perf3 raporunda.)

---

## 3. PSR-1 / PSR-4 / PSR-12 kontrol listesi

| Kural | Durum |
|---|---|
| `<?php` açılış etiketi, BOM yok, dosya `\n` ile bitiyor | ✔ tüm dosyalar |
| 4 boşluk girinti, tab yok, satır sonu boşluğu yok | ✔ |
| Satır uzunluğu ≤ 120 | ✔ PHP dosyaları / ✘ `schema.sql:44` (151) |
| Saf PHP dosyasında kapanış `?>` yok | ✔ |
| Başlık sırası (docblock → declare → namespace → use sınıf → use function) | ✔ (`migrate_md5_passwords.php`'de docblock'un declare'den önce olması PSR-12'ye uygun) |
| HTML içeren dosyada `declare` ilk satırda | ✘ iki şablon (N7) |
| Sınıf adları StudlyCaps, metotlar camelCase, sabitler UPPER_SNAKE, enum case'leri StudlyCaps | ✔ |
| Sembol tanımlayan dosyada yan etki yok (PSR-1 2.3) | ✔ `src/` / ✘ `login.php:25` (N8) |
| Her sınıf kendi dosyasında, namespace ↔ dizin eşleşmesi (PSR-4) | ✔ (`App\` → `src/`) |
| PSR-4 autoloader | ✘ elle `require_once` (N6) |
| Constructor property promotion, `readonly`, trailing comma biçimi | ✔ PSR-12 genişletilmiş biçimine uygun |

---

## Takım tartışmasından notlar

- **Mesajlaşma:** Bulgu özetimi yalnızca `sec3` ve `perf3`'e gönderdim (birer mesaj). İkisi de raporun ilk sürümünden sonra yanıt verdi. Yanıtlarını kodda doğruladım ve rapor güncellendi. Bu tek mesaj turuyla tartışma kapatıldı.
- **perf3'ten gelen ve rapora eklenenler:**
  - `ALTER ... MODIFY` koşulsuz, "safe to run more than once" yorumu yanıltıcı → **N17'ye eklendi** (`schema.sql:1, 51` doğrulandı).
  - `LIMIT`'li DELETE'lerde `ORDER BY` yok; throttle saklama süresi ile pencere arasındaki bağ yazılı değil → **N17'ye eklendi** (`schema.sql:80, 84`).
  - `Throttle.php:10`'daki "table stays small even under attack" yorumu, pair satırlarının IP limiti aşıldıktan sonra da eklenmesi nedeniyle tam doğru değil → **N18** (`AuthService.php:40-41` doğrulandı).
  - Migration script'inde koşulsuz UPDATE, parti ve ilerleme çıktısı yok → **N19**.
  - Bilgi olarak alınanlar (performans alanı): `hit()`'te her çağrıda yeniden prepare edilmesi ve iki statement çalışması, rol sorgusunun session kilidi tutulurken yapılması (`login.php:123-125`), `set_charset` round-trip'i, 2. turda eklenmiş olabilecek `login_log` index'lerinin drop edilmemesi (o şemanın uygulanıp uygulanmadığı koddan anlaşılamıyor).
- **sec3'ten gelen ve rapora eklenenler:**
  - Register yolunda session yeniden açılmıyor → zaten **N3**'te vardı. Teyit edildi.
  - `Throttle::hit`'te `fetch_row()[0]` null kontrolü yok → **N18'e eklendi**.
  - Migration adım sırası çelişkisi (`schema.sql:7-8`) → **N17'ye eklendi**.
  - GRANT'lerin yorumda kalması → zaten Y12 "kısmen" gerekçesinde vardı.
  - Bilgi olarak alınanlar (güvenlik alanı, kalite raporuna eklenmedi): `#[\SensitiveParameter]` eksikliği. Kodda hiçbir parametrede bu attribute yok, şifre parametreli metotlar `attemptLogin`, `register`, `passwordMatches`, `upgradeHashIfNeeded`. `login.php:131` `(string) $e` ile trace'i logladığı için `zend.exception_ignore_args=Off` ortamında şifre argümanları log'a düşebilir. Tip/imza düzeyinde bir düzeltme olduğu için kalite açısından da desteklenir. Diğerleri: hesap başına sayaç yok, proxy arkasında `REMOTE_ADDR`, düz metin parola kalıntısı ihtimali, `display_errors`, `__Host-` öneki.
- **perf3'ün ikinci mesajı:** perf3 altı notumu da doğrulayıp kendi raporuna işledi. Index adının iki kez yazılması bu raporda kaldı (N17). perf3 ayrıca P10'u ekledi: `enforceIdleTimeout` her istekte `last_activity` yazdığı için session dosyası her istekte yeniden yazılıyor (`functions.php:63` doğrulandı). Bu performans alanında. Kalite açısından N15'teki oturum yardımcılarının sadeleştirilmesiyle birlikte ele alınabilir.
- **sec3'ün ikinci mesajı (6 notumun güvenlik değerlendirmesi):** sec3 altı notumu da kodda doğruladı.
  - DUMMY_HASH: sec3 raporunda N5, Düşük.
  - Şablonun `$config->dbPass`'i görmesi: şablon şu an bu değeri basmıyor, istismar yolu yok. Yalnızca savunma derinliği konusu. sec3 N2'deki `render()` önerisini destekliyor.
  - Register yolunda kapalı session: güvenlik etkisi yok.
  - `LoginOutcome::failure(Success)`: hata 500'e dönüşüyor, istismar edilemiyor (fail-closed). N1 kalite bulgusu olarak geçerli.
  - `COOKIE_SECURE` ile HSTS: davranış güvenli, varsayılan açık. Ad yanıltıcı, N11 ile aynı görüş.
  - Rollback sonrası yinelenen kullanıcı adı: sec3 bunu güvenlik açısından önemli buluyor. `findUserByUsername` ilk satırı döndürdüğü için hesaplar karışabilir. "Önce kodu geri alın" uyarısı şart. N17'deki öneri bu gerekçeyle güçlendi.
- **Görüş farkı:** sec3, Y3/Y4/Y7/Y8'i "kısmen" sayıyor, bu raporda "düzeldi". Fark bakış açısından kaynaklanıyor. Bu rapor, 2. tur kalite bulgusunun kalite talebini (saf doğrulama, değer nesnesi, ayrı şablon, `Throwable`/fail-fast) karşılayıp karşılamadığına baktı. sec3'ün kalan noktaları (ör. `Config::fromEnv` hata verince error sayfasının güvenlik başlıksız gitmesi) güvenlik raporunda.
- **DUMMY_HASH:** sec3 de bağımsız olarak aynı sonuca vardı. PHP 8.4'te varsayılan bcrypt cost'u 12, sabit hash ise cost 10. Değerlendirme güvenlik raporunda.
- **sec3'e işaret ettiklerim (kodda doğrulandı):**
  1. `AuthService.php:24` `DUMMY_HASH` cost'u sabit (`$2y$10`), gerçek hash'ler `PASSWORD_DEFAULT` ile üretiliyor. Cost ya da algoritma değişirse bilinmeyen kullanıcı yolu farklı süre alır ve kullanıcı adı tahmini mümkün olur.
  2. Şablon `require` ile bütün yerel kapsamı görüyor, `$config->dbPass` dahil (N2).
  3. Register yolu kapalı session ile devam ediyor (N3). Kapalı session'ı tekrar kapatmanın zararsız olduğu (`session_write_close()` → `false`, uyarı yok) PHP 8.2.12'de denendi.
  4. `LoginOutcome::failure(LoginResult::Success)` user'sız bir "başarı" üretebiliyor (N1).
  5. `COOKIE_SECURE=0` HSTS'yi de kapatıyor, ama ad yalnızca çerezi anlatıyor (N11).
  6. Kod geri alınmadan şema geri alınırsa yinelenen kullanıcı adı kaydı mümkün (N17).
- **perf3'e işaret ettiklerim (kodda doğrulandı):** `Throttle::hit` çağrı başına iki round-trip yapıyor ve başarılı girişte toplam ~7 sorgu çalışıyor. `login.php:67-69`'daki session kapat/aç döngüsü session'ı iki kez okuyup yazıyor. perf3 bunu düzeltti: ikinci `session_start` Set-Cookie'yi yeniden göndermiyor, çünkü çerez istekte geldiği için PHP yeni çerez göndermiyor. Ek okuma da mikrosaniyeler sürüyor. Bu yüzden konu performans açısından önemsiz; N3 yalnızca okunabilirlik ve tutarlılık bulgusu olarak kalıyor. Oturumu açık her GET'te rol sorgusu çalışıyor (bilinçli tercih). `migrate_md5_passwords.php` tüm satırları buffered çekiyor ve transaction kullanmıyor. `schema.sql:51` tabloyu yeniden kuruyor. Index adı iki kez yazılıyor (N17). `bootstrap.php` her istekte tüm sınıfları yüklüyor (N6).
- Bu noktaların güvenlik ve performans etkisinin değerlendirmesi sec3 ve perf3'ün raporlarına bırakıldı. Kalite raporunda yalnızca okunabilirlik, tasarım ve bakım boyutları ele alındı.
