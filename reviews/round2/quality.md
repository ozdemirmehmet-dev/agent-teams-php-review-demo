# Tur 2 — Kod Kalitesi İncelemesi (login.php, auth.php, schema.sql)

**İnceleyen:** quality
**Kapsam:** Okunabilirlik, PSR-1/PSR-12 uyumu, sorumluluk ayrımı, tip güvenliği, test edilebilirlik.
**Not:** Dosyalar değiştirilmedi, yalnızca okundu. `php -l login.php` ve `php -l auth.php` (PHP 8.2.12) → sözdizimi hatası yok. PHP 8.2 kullanıldığı için önerilerde `enum` (8.1+) ve `readonly` (8.1+) kullanılabilir.

**Genel değerlendirme:** Yeniden yazım çok iyi. İlk turun 20 bulgusunun 15'i tamamen düzeltildi, 5'i kısmen düzeltildi. Hiçbiri hiç ele alınmamış durumda değil. PSR-12 biçim kuralları (girinti, süslü parantez, boşluklar, 120 karakter sınırı, dosya sonu satırı, tab yok, satır sonu boşluğu yok) artık uyumlu. Kalan sorunlar daha çok tasarımla ilgili: giriş akışı controller'da duruyor, string dönüş kodları kullanılıyor, `verifyPassword` veritabanına yazıyor ve süper global'lere doğrudan erişiliyor.

---

## 1. İlk tur bulgularının durumu

| # | İlk tur bulgusu | Durum | Gerekçe |
|---|---|---|---|
| 1 | Sorumlulukların ayrılmaması | **Kısmen** | Veri erişimi ve yardımcılar `auth.php`'ye taşındı. Ama giriş akışı (rate-limit, doğrulama, loglama, session yazma, timing eşitleme) hâlâ `login.php:29-60` içinde. Şablon da aynı dosyada. Bkz. Y1, Y7. |
| 2 | Fonksiyon isimlendirmesi tutarsız | **Düzeldi** | Hepsi camelCase (`findUserByUsername`, `registerUser`, `getUserRoles`…). |
| 3 | Değişken isimlendirmesi | **Düzeldi** | `$Conn`/`$Password` yok, hepsi camelCase. |
| 4 | Girinti / tab karışımı | **Düzeldi** | Her yerde 4 boşluk var, tab ve satır sonu boşluğu yok (grep ile kontrol edildi). |
| 5 | Fonksiyon süslü parantezi | **Düzeldi** | PSR-12'ye uygun şekilde açılış `{` yeni satırda. |
| 6 | Parantezsiz `else` / gereksiz dallanma | **Düzeldi** | `isValidCsrfToken` doğrudan boolean ifade döndürüyor. |
| 7 | Boşluk kullanımı | **Düzeldi** | Operatör ve virgül boşlukları PSR-12'ye uygun. |
| 8 | Her fonksiyon yeni bağlantı açıyor | **Düzeldi** | `db()` istek başına tek bağlantı veriyor, fonksiyonlar `mysqli $db` parametresi alıyor. Statement'lar `close()` ediliyor. (Statik singleton notu için bkz. Y5.) |
| 9 | Hata yönetimi eksik | **Kısmen** | `MYSQLI_REPORT_STRICT` ve `try/catch` eklenmiş, kullanıcıya genel bir mesaj gösterilip `error_log` yazılıyor. Ama yalnızca `mysqli_sql_exception` yakalanıyor. `csrfToken()` try bloğunun dışında. Env eksikse uygulama hemen hata vermiyor. Bkz. Y8. |
| 10 | SQL birleştirme / tekrar | **Düzeldi** | Tüm sorgular prepared statement ile yazılmış, tekrar eden sorgu yok. |
| 11 | Gevşek karşılaştırma | **Düzeldi** | Her yerde `===` / `!==` ve `hash_equals` kullanılıyor. |
| 12 | Şifre mantığı dağınık | **Düzeldi** | Mantık tek yerde: `verifyPassword` (auth.php:98). Fonksiyonun yan etkisi için bkz. Y3. |
| 13 | Bildirim ve yan etki karışımı (PSR-1) | **Düzeldi** | `auth.php` sadece sabit ve fonksiyon tanımlıyor, yan etkisi yok. `login.php` giriş noktası. |
| 14 | `echo` ile HTML ve kaçışsız çıktı | **Düzeldi** | Alternatif sözdizimi ve `e()` kullanılıyor, `<ul>` doğru. |
| 15 | Dönüş değeri yok sayılıyor / `exit` yok | **Düzeldi** | `registerUser` sonucu kontrol ediliyor, her `header('Location')` sonrasında `exit` var. |
| 16 | `$_POST` varlık kontrolü | **Düzeldi** | `postString()` hem varlığı hem string tipini kontrol ediyor. `$_GET['msg']` için de `is_string` var. |
| 17 | Şablon ayrımı | **Kısmen** | Mantık üstte, HTML altta toplanmış (iyi). Ama ikisi hâlâ aynı dosyada ve şablon session'dan gelen tipsiz `$roles` üzerinde dönüyor. Bkz. Y7. |
| 18 | Eksik HTML yapısı | **Düzeldi** | `<!DOCTYPE>`, `lang="tr"`, `<meta charset>`, `<label for>`, `autocomplete` mevcut. Küçük not: inline `style` (Y11). |
| 19 | Sabit kodlanmış yapılandırma | **Kısmen** | Kimlik bilgileri env'den okunuyor. Ama `?: ''` geri dönüşü yüzünden eksik yapılandırma sessizce boş kullanıcıyla bağlanmayı deniyor (auth.php:18-21). Bkz. Y8. |
| 20 | Modern PHP / tipler | **Kısmen** | `declare(strict_types=1)`, parametre ve dönüş tipleri, `const` eklenmiş. Ama dönüş kodları hâlâ string (enum yok). `verifyPassword(array $user)` için shape tanımı yok. Bkz. Y2, Y4. |

---

## 2. Yeni / kalan sorunlar

### Özet

| # | Konum | Konu | Önem |
|---|---|---|---|
| Y1 | login.php:29-60 | Giriş iş akışı controller'da; register ile asimetrik, test edilemez | Yüksek |
| Y2 | auth.php:174-203, login.php:69-74 | `'ok'/'invalid'/'taken'` string dönüş kodları; eşleşmeyen anahtar uyarı verir | Orta |
| Y3 | auth.php:95-118 | `verifyPassword` adı salt okuma gibi, ama DB'ye yazıyor (gizli yan etki) | Orta |
| Y4 | auth.php:98, login.php:45-46 | `array $user` tipsiz; docblock ile cast'ler çelişiyor | Düşük |
| Y5 | auth.php:11-27, 34-39, 56-70 | Global durum: statik bağlantı ve süper global'lere doğrudan erişim | Orta |
| Y6 | login.php:13, auth.php:179-181, login.php:130 | Doğrulama kuralları üç yerde tekrar ediliyor; `strlen` bayt sayıyor | Orta |
| Y7 | login.php:83-84, 104-110 | Şablon session'dan tipsiz veri okuyor; mantık ve şablon aynı dosyada | Düşük |
| Y8 | login.php:77-85, auth.php:18-21, 43-49 | Hata yönetimi dar; yapılandırma eksikse hemen hata verilmiyor; Secure çerez bayrağı istekten tahmin ediliyor | Orta |
| Y9 | login.php:24-75 | Bilinmeyen `action` sessizce yok sayılıyor; sihirli string'ler | Düşük |
| Y10 | auth.php (tüm dosya) | Namespace yok; `e()`, `db()` gibi çok genel global isimler | Düşük |
| Y11 | login.php:101, 117-132 | Inline `style`, düzen için `<br>` | Düşük |
| Y12 | schema.sql:7, 1-24 | Migration idempotent değil, duplicate kontrolü yok, geri alma adımı yok; log temizliği sadece yorumda | Orta |
| Y13 | login.php:31, auth.php:137-146 | Giriş formunda kullanıcı adı uzunluğu sınırlanmıyor; uzun girdi `login_log` INSERT'inde 1406 → 500 | Orta |

---

### Y1. Giriş akışı controller'da (login.php:29-60) — **Yüksek**

Kayıt mantığı `registerUser()` içinde kapsüllenmiş. Giriş ise 30 satır boyunca controller'da adım adım kurgulanıyor: rate-limit kontrolü, kullanıcı arama, doğrulama, başarılı/başarısız loglama, timing eşitleme için sahte `password_hash` (login.php:53-56) ve session yazma. Sonuçları:
- İki akış asimetrik. Okuyan biri "giriş kuralları nerede?" diye iki dosyaya bakmak zorunda kalıyor.
- Timing eşitleme bir güvenlik kuralı. Controller'da durduğu için başka bir giriş noktası (API, admin paneli) eklendiğinde unutulur.
- HTTP ve session olmadan birim testi yazılamıyor.

**Öneri:** Akışı `auth.php`'de tek bir fonksiyona taşıyın, sonucu enum ile döndürün. Controller sadece sonuca göre HTTP kodu ve mesaj seçsin.

```php
// auth.php
enum LoginResult
{
    case Success;
    case InvalidCredentials;
    case TooManyAttempts;
}

/**
 * @return array{0: LoginResult, 1: array{id: int, username: string}|null}
 */
function attemptLogin(mysqli $db, string $username, string $password, string $ip): array
{
    if (tooManyFailedAttempts($db, $username, $ip)) {
        return [LoginResult::TooManyAttempts, null];
    }

    $user = findUserByUsername($db, $username);

    if ($user === null) {
        // Kullanıcı yokken de aynı süreyi harca (timing ile kullanıcı adı tahmini engellenir).
        password_hash($password, PASSWORD_DEFAULT);
    } elseif (verifyPassword($db, $user, $password)) {
        logLoginAttempt($db, $username, $ip, true);

        return [LoginResult::Success, ['id' => (int) $user['id'], 'username' => (string) $user['username']]];
    }

    logLoginAttempt($db, $username, $ip, false);

    return [LoginResult::InvalidCredentials, null];
}

function loginSession(mysqli $db, array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['roles'] = getUserRoles($db, $user['id']);
}
```

```php
// login.php
} elseif ($action === 'login') {
    $db = db();
    $oldUsername = trim(postString('username'));
    [$result, $user] = attemptLogin($db, $oldUsername, postString('password'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    match ($result) {
        LoginResult::Success => (function () use ($db, $user): never {
            loginSession($db, $user);
            header('Location: login.php');
            exit;
        })(),
        LoginResult::TooManyAttempts => [http_response_code(429), $error = 'Çok fazla başarısız deneme. Lütfen ' . LOCKOUT_MINUTES . ' dakika sonra tekrar deneyin.'],
        LoginResult::InvalidCredentials => $error = 'Kullanıcı adı veya şifre hatalı.',
    };
}
```
(`match` yerine basit bir `if/switch` daha okunur olabilir. Asıl önemli olan iş kuralının `attemptLogin` içinde toplanması.)

**Sözleşme notu (security-2):** Mevcut sırada önce limit kontrol ediliyor (`login.php:36`), deneme ancak sonradan loglanıyor (`login.php:43/58`). Bu yüzden aynı anda gelen paralel istekler COUNT'u birlikte geçip limiti aşabiliyor (TOCTOU). Doğru sıra "önce denemeyi kaydet, sonra say, başarı olursa işaretle" şeklinde olmalı. Bunun için fonksiyonların sözleşmesi de değişiyor: `tooManyFailedAttempts` + `logLoginAttempt` ikilisinin yerini `beginLoginAttempt(): int` (eklenen satırın id'si) ve `markLoginSuccess(int $attemptId)` alıyor. Akış zaten Y1'deki gibi `attemptLogin()` içinde toplanırsa bu değişiklik controller'a dokunmadan tek bir yerde yapılabilir. Bu da Y1'in önemini artıran bir neden.

### Y2. String dönüş kodları (auth.php:174-203, login.php:69-74) — **Orta**

`registerUser` `'ok' | 'invalid' | 'taken'` döndürüyor ve bu yalnızca docblock'ta (`@return string 'ok', 'invalid' or 'taken'`) belgelenmiş. Bunun sakıncaları:
- Yazım hatası (`'Ok'`, `'exists'`) statik analizde yakalanmaz.
- `login.php:74` `$registerErrors[$result]` için varsayılan bir değer yok. Yeni bir kod eklenip haritaya eklenmezse "Undefined array key" uyarısı çıkar ve `$error` `null` olur, yani kullanıcı hiçbir mesaj görmez.
- `'ok'` ile hata kodları aynı tipte, bu yüzden başarı kontrolü string karşılaştırmasına kalıyor.

**Öneri:** Backed enum kullanın, mesajı da enum'a bağlayın. Bir case'in mesajı unutulursa `match` `UnhandledMatchError` fırlatır, böylece hata sessiz kalmaz.

```php
// auth.php
enum RegisterResult: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case Taken = 'taken';

    public function message(): ?string
    {
        return match ($this) {
            self::Ok => null,
            self::Invalid => 'Kullanıcı adı 3-32 karakter (harf, rakam, _), şifre en az 8 karakter ve geçerli bir e-posta olmalı.',
            self::Taken => 'Bu kullanıcı adı zaten kullanılıyor.',
        };
    }
}

function registerUser(mysqli $db, string $username, string $password, string $email): RegisterResult
{
    if (!isValidRegistration($username, $password, $email)) {
        return RegisterResult::Invalid;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $hash, $email);

    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === MYSQL_ER_DUP_ENTRY) {
            return RegisterResult::Taken;
        }
        throw $e;
    } finally {
        $stmt->close();
    }

    return RegisterResult::Ok;
}
```

```php
// login.php
$result = registerUser(db(), trim(postString('new_username')), postString('new_password'), trim(postString('email')));

if ($result === RegisterResult::Ok) {
    header('Location: login.php?msg=registered');
    exit;
}

$error = $result->message();
```

Ek not: auth.php:194'teki `1062` sihirli bir sayı. `const MYSQL_ER_DUP_ENTRY = 1062;` gibi bir sabite taşınmalı.

### Y3. `verifyPassword` gizli yan etki içeriyor (auth.php:95-118) — **Orta**

Fonksiyonun adı bir sorgu (predicate) gibi duruyor, ama iki farklı yolda `updatePasswordHash` ile `UPDATE users` çalıştırıyor (auth.php:104 ve 112). Bu yüzden:
- Bir doğrulama fonksiyonu `mysqli` bağımlılığı istiyor. DB olmadan test edilemiyor ve "sadece kontrol edeyim" diye çağıran biri farkında olmadan yazma yapıyor.
- Command-query ayrımı bozuluyor.

**Öneri:** Doğrulamayı saf (pure) tutun, yeniden hash'leme kararını ayrı döndürün ya da adı davranışı anlatsın.

```php
/**
 * Saf fonksiyon: DB'ye dokunmaz.
 *
 * @return array{valid: bool, needsRehash: bool}
 */
function checkPassword(string $password, string $storedHash): array
{
    if (password_verify($password, $storedHash)) {
        return ['valid' => true, 'needsRehash' => password_needs_rehash($storedHash, PASSWORD_DEFAULT)];
    }

    $isLegacyMd5 = preg_match('/^[a-f0-9]{32}$/i', $storedHash) === 1;
    $valid = $isLegacyMd5 && hash_equals(strtolower($storedHash), md5($password));

    return ['valid' => $valid, 'needsRehash' => $valid];
}

function verifyAndUpgradePassword(mysqli $db, array $user, string $password): bool
{
    $check = checkPassword($password, (string) $user['password']);

    if ($check['needsRehash']) {
        updatePasswordHash($db, (int) $user['id'], $password);
    }

    return $check['valid'];
}
```

### Y4. `array $user` tipsiz, docblock ile cast'ler çelişiyor (auth.php:72-75, 98; login.php:45-46) — **Düşük**

`findUserByUsername` docblock'u `array{id: int, username: string, password: string}` diyor. `verifyPassword(mysqli $db, array $user, ...)` ise shape vermiyor ve içeride `(int) $user['id']` ve `(string) $user['password']` ile cast ediyor. `login.php:45-46` de aynı cast'leri tekrarlıyor. Ya docblock'taki tipe güvenilmeli ya da sınırda (repository'de) bir kere normalize edilmeli. Küçük bir değer nesnesi bu tekrarı bitirir:

```php
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $passwordHash,
    ) {
    }
}

function findUserByUsername(mysqli $db, string $username): ?User
{
    $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : new User((int) $row['id'], (string) $row['username'], (string) $row['password']);
}
```
Not: `fetch_assoc()` satır yoksa `null` döndürür. Mevcut `return $user ?: null;` (auth.php:83) çalışıyor ama `=== null` niyeti daha açık anlatır.

### Y5. Global durum ve süper global erişimi (auth.php:11-27, 34-39, 56-70) — **Orta**

- `db()` içindeki `static $conn` bir gizli singleton. Testte sıfırlanamıyor ya da sahte bir bağlantıyla değiştirilemiyor. Ayrıca `mysqli_report()` (auth.php:16) süreç genelindeki bir ayarı, ilk `db()` çağrısında yan etki olarak değiştiriyor. Bu ayar `db()`'den önce başka bir mysqli kullanımı olursa farklı davranışa yol açar.
- `postString()` `$_POST`'u, `csrfToken()` ve `isValidCsrfToken()` `$_SESSION`'ı doğrudan okuyor. Bu yüzden bu fonksiyonlar girdisi açık olmayan "saf olmayan" yardımcılar haline geliyor.

Bu boyuttaki bir dosya için tam DI container gerekmez. Ama süper global'leri parametre olarak almak test edilebilirliği bedavaya kazandırır:

```php
function postString(array $post, string $key): string
{
    $value = $post[$key] ?? '';

    return is_string($value) ? $value : '';
}

function isValidCsrfToken(array $session, string $token): bool
{
    return isset($session['csrf_token'])
        && $token !== ''
        && hash_equals($session['csrf_token'], $token);
}

// Bağlantı kurulumu ayrı, açık bir fonksiyon. mysqli_report bootstrap'te bir kez çağrılır.
function connectDb(array $config): mysqli
{
    $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    $conn->set_charset('utf8mb4');

    return $conn;
}
```

### Y6. Doğrulama kuralları tekrar ediyor; `strlen` bayt sayıyor (login.php:13, 130; auth.php:179-181) — **Orta**

"3-32 karakter, `[A-Za-z0-9_]`, şifre en az 8" kuralı üç yerde yazılı: regex/`strlen` (auth.php:179-180), hata mesajı metni (login.php:13) ve HTML `minlength="8"` (login.php:130). Kullanıcı adı alanında (login.php:127) `pattern`/`maxlength` yok, yani istemci ve sunucu kuralları da eşleşmiyor. Bir kural değişince üç yerin birlikte güncellenmesi gerekiyor.

Ayrıca `strlen($password) >= 8` bayt sayıyor. "çğüşöı" gibi Türkçe karakterler 2 bayt olduğundan 4 karakterlik bir şifre "8 karakter" kuralını geçebilir. Mesaj "8 karakter" dediği için `mb_strlen` kullanılmalı.

```php
const USERNAME_PATTERN = '[A-Za-z0-9_]{3,32}';
const PASSWORD_MIN_LENGTH = 8;

function isValidRegistration(string $username, string $password, string $email): bool
{
    return preg_match('/^' . USERNAME_PATTERN . '$/', $username) === 1
        && mb_strlen($password, 'UTF-8') >= PASSWORD_MIN_LENGTH
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
```
```php
<!-- login.php şablon -->
<input type="text" id="new_username" name="new_username" autocomplete="username"
       pattern="<?= e(USERNAME_PATTERN) ?>" maxlength="32" required>
<input type="password" id="new_password" name="new_password" autocomplete="new-password"
       minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
```
Doğrulamanın `registerUser` içinden ayrılması (`isValidRegistration`) DB olmadan test edilebilmesini de sağlar. Ayrıca hangi alanın hatalı olduğu bilgisi kaybolmaz.

### Y7. Şablonda tipsiz session verisi; mantık ve şablon aynı dosyada (login.php:83-84, 104-110) — **Düşük**

`$roles = $_SESSION['roles'] ?? [];` tipi `mixed`. Şablon bunu `foreach` ile dönüp `e($role)` çağırıyor. `strict_types=1` açık olduğundan `$role` string değilse `TypeError` fırlar. Session şemasını tek bir okuyucu fonksiyonda normalize etmek ve şablonu ayrı bir dosyaya almak (`templates/login.php`) kalan son ayrımı da tamamlar:

```php
/**
 * @return array{username: string, roles: list<string>}|null
 */
function currentUser(array $session): ?array
{
    if (!isset($session['user_id'], $session['username'])) {
        return null;
    }

    return [
        'username' => (string) $session['username'],
        'roles' => array_values(array_map('strval', (array) ($session['roles'] ?? []))),
    ];
}

// login.php
$currentUser = currentUser($_SESSION);
$csrfToken = csrfToken();
session_write_close();

require __DIR__ . '/templates/login.php';
```

### Y8. Dar hata yönetimi, geç hata verme (login.php:77-85; auth.php:18-21) — **Orta**

- `catch (mysqli_sql_exception $e)` yalnızca DB hatalarını yakalıyor. `random_bytes()` (auth.php:59) `\Random\RandomException` fırlatabilir ve `csrfToken()` (login.php:85) ve `startSecureSession()` (login.php:7) zaten try bloğunun dışında. Bu durumda kullanıcı yakalanmamış bir hata görür, yani tutarlı 500 sayfası yok.
- `getenv('DB_USER') ?: ''` gibi geri dönüşler (auth.php:19-21), yapılandırma eksikse anlamsız bir "Access denied for user ''" hatasına yol açıyor. Kökeni belli bir hata vermek daha iyi:

```php
function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing environment variable: {$name}");
    }

    return $value;
}

// login.php
} catch (Throwable $e) {
    error_log('login.php: ' . $e);   // mesaj + stack trace
    http_response_code(500);
    $error = 'Bir hata oluştu, lütfen daha sonra tekrar deneyin.';
}
```
- `startSecureSession()` içindeki `'secure' => $isHttps` (auth.php:43, 49) ortamı istek başlığından tahmin ediyor. Uygulama TLS'i sonlandıran bir reverse proxy arkasındaysa `$_SERVER['HTTPS']` boş gelir ve çerez `Secure` bayrağı olmadan gönderilir. Bu bir dağıtım ayarı olduğu için yapılandırmadan okunmalı (security-2 / security notu):

```php
function startSecureSession(bool $secureCookie): void
{
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// login.php
startSecureSession(getenv('APP_INSECURE_COOKIES') !== '1'); // varsayılan: güvenli
```

(Ayrıca `error_log` için yalnızca `getMessage()` (login.php:78) kullanmak dosya/satır bilgisini kaybettiriyor. `(string) $e` daha teşhis edicidir.)

### Y9. Bilinmeyen `action` sessiz; sihirli string'ler (login.php:24-75) — **Düşük**

`if / elseif ($action === 'login') / elseif ($action === 'register')` zincirinin `else` dalı yok. Geçersiz bir `action` hiçbir geri bildirim vermeden boş sayfa döndürüyor. `'login'`, `'register'`, `'registered'`, ve session anahtarları `'user_id'`, `'username'`, `'roles'`, `'csrf_token'` birden fazla yerde string olarak tekrar ediyor.

```php
} elseif ($action === 'register') {
    // ...
} else {
    http_response_code(400);
    $error = 'Geçersiz istek.';
}
```
Session anahtarları için `const SESSION_USER_ID = 'user_id';` gibi sabitler (veya Y7'deki tek okuyucu/yazıcı fonksiyon çifti) yazım hatası riskini kaldırır.

### Y10. Namespace yok, genel global isimler (auth.php tamamı) — **Düşük**

`e()`, `db()`, `postString()`, `MAX_FAILED_ATTEMPTS` global isim alanında. Proje büyüdüğünde ya da bir kütüphane eklendiğinde çakışma riski var (`e()` Laravel'deki yardımcıyla aynı ada sahip). PSR-1 namespace'i sınıflar için zorunlu tutuyor. Fonksiyonlar için zorunlu değil ama önerilir:

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;
use mysqli_sql_exception;

const MAX_FAILED_ATTEMPTS = 5;
// ...
```
```php
// login.php
use function App\Auth\{attemptLogin, registerUser, csrfToken, e};
```

### Y11. Inline stil ve `<br>` ile düzen (login.php:101, 117-132) — **Düşük**

`<p class="error" style="color: red">` hem sınıf hem inline stil kullanıyor. Stil `.error { color: red; }` olarak CSS'e taşınmalı. Form alanları `<br>` ile alt alta diziliyor. `<div>` sarmalayıcı ya da CSS ile düzen kurmak daha bakımı kolaydır. Mobil için `<meta name="viewport" content="width=device-width, initial-scale=1">` da eksik. Ayrıca inline `style`, `style-src 'unsafe-inline'` içermeyen katı bir Content-Security-Policy ile çakışır, yani CSP eklendiği gün hata mesajı stilsiz kalır (security-2 notu). Bu da stilin CSS sınıfına taşınması için ek bir neden.

### Y12. Migration dosyası (schema.sql) — **Orta**

- **Duplicate kontrolü yok (schema.sql:7):** `users` tablosunda aynı kullanıcı adından birden fazla varsa `ADD UNIQUE INDEX` hata verir ve migration yarıda kalır. MySQL DDL transactional olmadığı için 4. satır uygulanmış, 7. satır uygulanmamış olur. Önce bir kontrol sorgusu çalıştırılmalı:
  ```sql
  -- Çalıştırmadan önce boş dönmeli:
  SELECT username, COUNT(*) FROM users GROUP BY username HAVING COUNT(*) > 1;
  ```
- **Idempotent değil:** İkinci kez çalıştırıldığında `Duplicate key name` hatası alınır. Dosya adı versiyonsuz (`schema.sql`) ve bir migration aracı (Phinx, Doctrine Migrations, numaralı dosyalar) kullanılmıyor. `20261027_01_login_rewrite.up.sql` / `.down.sql` gibi bir adlandırma ve geri alma betiği önerilir:
  ```sql
  -- 20261027_01_login_rewrite.down.sql
  ALTER TABLE login_log DROP INDEX idx_login_log_ip_created, DROP INDEX idx_login_log_username_created;
  ALTER TABLE user_roles DROP INDEX idx_user_roles_user_role;
  ALTER TABLE users DROP INDEX uq_users_username;
  ```
- **Belirsiz bağımlılık:** Dosya `login_log` tablosunun `ip`, `success`, `created_at` kolonlarıyla var olduğunu varsayıyor ama bunu tanımlamıyor. Yeni bir ortamda kurulum için bu tablonun `CREATE TABLE IF NOT EXISTS` tanımı da dosyada olmalı.
- Dosyanın ilk satırındaki yorum `reviews/summary.md`'ye referans veriyor. İnceleme belgeleri kalıcı değildir, bu yüzden yorum değişikliğin nedenini kendisi anlatmalı.
- GRANT bloğu (schema.sql:20-24) yorum içinde ve `app_db` adı sabit. Bu belgeleme olarak iyi, ama ayrı bir `deploy/grants.sql.example` dosyası daha net olur.
- **Log temizliği hiç çalışmıyor (schema.sql:16-17):** Saklama kuralı (90 gün) yalnızca bir SQL yorumu. Ne kodda ne zamanlanmış bir görevde karşılığı var, yani `login_log` sınırsız büyür ve rate-limit sorgusu zamanla yavaşlar (performance-2 notu). Kural ya bir MySQL EVENT olarak migration'a ya da ayrı bir cron betiğine konmalı. Toplu ve küçük parçalarla silinmeli:
  ```sql
  CREATE EVENT IF NOT EXISTS ev_purge_login_log
      ON SCHEDULE EVERY 1 DAY
      DO DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY LIMIT 10000;
  ```
  (`event_scheduler=ON` gerekir. Silme sorgusunun hızlı olması için `created_at` index'i önerisi performance raporunda.)

### Y13. Giriş formunda kullanıcı adı uzunluğu sınırlanmıyor (login.php:31, auth.php:137-146) — **Orta**

Kayıtta kullanıcı adı `{3,32}` ile sınırlanıyor (auth.php:179), girişte ise `trim(postString('username'))` (login.php:31) hiçbir kontrol yapmadan `logLoginAttempt` ile `login_log`'a yazılıyor. `MYSQLI_REPORT_STRICT` ve MySQL strict modu açıkken, kolon genişliğini aşan bir girdi `1406 Data too long` hatası verir. Bu hata `mysqli_sql_exception` olarak yakalanıp 500 ile genel bir hata mesajına dönüşür. Yani sıradan bir kullanıcı hatası sunucu hatası gibi görünür ve loglar kirlenir (performance-2 notu). Kayıtla aynı kural, sorguya gitmeden önce uygulanmalı:

```php
// auth.php
function isPlausibleUsername(string $username): bool
{
    return preg_match('/^' . USERNAME_PATTERN . '$/', $username) === 1;
}

// attemptLogin() başında (Y1)
if (!isPlausibleUsername($username)) {
    // Kullanıcı yokmuş gibi davran: aynı mesaj, aynı süre.
    password_hash($password, PASSWORD_DEFAULT);

    return [LoginResult::InvalidCredentials, null];
}
```
Not: Kolonun gerçek genişliği şema dosyasında görünmüyor (`login_log` tanımı yok, Y12). Bulgu, kolonun kayıt kuralıyla uyumlu bir `VARCHAR(n)` olduğu varsayımına dayanıyor.

---

## 3. PSR-1 / PSR-12 kontrol listesi

| Kural | Durum |
|---|---|
| `<?php` açılış etiketi, BOM yok | ✔ |
| `declare(strict_types=1)` etiketten sonra tek boş satırla | ✔ (iki dosyada da) |
| 4 boşluk girinti, tab yok | ✔ |
| Satır sonu boşluğu yok, dosya `\n` ile bitiyor | ✔ |
| Satır uzunluğu ≤ 120 (soft limit) | ✔ (en uzun: login.php:13, 119 karakter) |
| Fonksiyon `{` yeni satırda, kontrol yapısı `{` aynı satırda | ✔ |
| Fonksiyon adları camelCase, sabitler UPPER_SNAKE | ✔ |
| Bildirim dosyasında yan etki yok (PSR-1 2.3) | ✔ auth.php |
| Saf PHP dosyasında kapanış `?>` yok | ✔ auth.php |
| Namespace | ✘ (öneri, Y10) |

---

## Takım tartışmasından notlar

- **Mesajlaşma:** Ekip listesinde hem eski (`security`, `performance`) hem yeni (`security-2`, `performance-2`) ajanlar göründüğü için özetimi dördüne de gönderdim. team-lead daha sonra doğru adreslerin `security-2` / `performance-2` olduğunu bildirdi. Bu iki ajanın yanıtlarının bir kısmı yanlışlıkla eski "quality"ye gitti, team-lead bunları bana iletti. Eski `security` ajanı da notlarımı teyit ederek yanıt verdi. Eski `performance` 2. tura atanmadığını söyleyip kısa bir ön değerlendirme gönderdi.
- **security tarafına işaret ettiklerim:** (1) `tooManyFailedAttempts` (auth.php:126) `username = ? OR ip = ?` ile saydığı için başkasının kullanıcı adıyla 5 hatalı deneme yapılarak o hesap kilitlenebiliyor (hesap kilitleme DoS). Başarılı giriş sayaçları sıfırlamıyor. (2) Şifrede üst sınır yok: bcrypt 72 bayttan sonrasını yok sayar. (3) Zamanlama eşitlemesi controller'da duruyor, md5 legacy yolu farklı süre alıyor. (4) `csrfToken()` try bloğunun dışında. (5) `db()` için fail-fast yok. (6) Kayıttaki "taken" mesajı kullanıcı adı numaralandırmasına izin veriyor. **Yanıt:** Eski `security` ajanı altısını da doğruladığını ve kendi `security.md` dosyasına 2.5-2.9 olarak eklediğini yazdı (hepsi Düşük). security-2'nin lead üzerinden iletilen notlarına göre o da hesap kilitleme ve kullanıcı adı numaralandırmasını bağımsız olarak bulmuş.
- **performance tarafına işaret ettiklerim:** Rate-limit sorgusundaki `OR` nedeniyle index kullanımının zayıflaması, temizlik `DELETE`'i için `created_at` index'inin olmaması, `user_roles` PK'sıyla çakışabilecek ek index ve UNIQUE öncesi duplicate kontrolünün olmaması. **Yanıt:** performance-2 ilk üçünün kendi raporunda zaten olduğunu, duplicate kontrolünü de migration notu olarak eklediğini bildirdi. Eski performance ajanı da bunları teyit etti, `(username, success, created_at)` kapsayan index önerisini ekledi.
- **performance-2'den gelen ve rapora eklenenler:**
  - Kullanıcı adı uzunluğu girişte sınırlanmıyor, strict modda 1406 → 500 → **yeni Y13** (haklı; kolon genişliği şemada görünmediği için varsayım notuyla).
  - `login_log` temizliği yalnızca SQL yorumunda, hiç çalışmıyor → **Y12'ye eklendi**.
  - schema.sql idempotent değil, duplicate temizliği yok, `schema.sql:9` olası yinelenen index → zaten Y12'de ve performance tarafına iletilmişti. Teyit edildi.
  - `login.php:74` `$registerErrors[$result]` beklenmeyen değerde tanımsız anahtar → zaten Y2'de vardı. Teyit edildi.
- **security / security-2'den gelen ve rapora eklenenler:**
  - Yalnızca `mysqli_sql_exception` yakalanıyor, `csrfToken()` içindeki `random_bytes()` try dışında; ortam değişkeni eksikse sessizce `''` kullanılıyor → zaten Y8'de vardı. Teyit edildi.
  - `'secure' => $isHttps` reverse proxy arkasında `false` olur, bu ayar config'den gelmeli → **Y8'e eklendi** (auth.php:43, 49).
  - Bilgi olarak alınanlar (güvenlik alanında, kalite raporuna eklenmedi): girişten sonra CSRF token yenilenmiyor, roller session'da önbellekleniyor (yetki değişikliği oturum bitene kadar yansımıyor), logout ve idle timeout yok, hesap kilitleme DoS, kullanıcı adı numaralandırma.
- **security-2'nin doğrudan yanıtı:** security-2 altı notumun altısını da kodda doğrulayıp kendi raporuna ekledi (kilitleme, sayacın sıfırlanmaması, 72 bayt, md5 timing, CSRF yenilenmemesi, fail-fast, 'taken'). Kalite tarafı için gönderdikleri:
  - Dar `catch`; `login.php:7` ve `:85` try dışında, `Throwable` ya da `set_exception_handler` öneriliyor → haklı. Y8'de zaten vardı, `login.php:7` eklendi.
  - Inline style katı bir CSP ile çakışır → haklı, **Y11'e eklendi**.
  - Kontrol (`login.php:36`) ile log (`:58`) arasında TOCTOU; "önce INSERT, sonra COUNT" yapısı fonksiyon sözleşmesini değiştiriyor → haklı. Güvenlik açığı security-2 raporunda, sözleşme ve tasarım boyutu **Y1'e not olarak eklendi**.
  - Rollerin session'da önbelleklenmesi, idle timeout ve logout olmaması → güvenlik alanı, bilgi olarak yukarıda listelendi.
- **Tutarlılık:** Tüm düzeltme örnekleri ilk turdaki karar doğrultusunda nesne yönelimli mysqli ile (`prepare` + `bind_param` + `execute` + `get_result`, `MYSQLI_REPORT_STRICT`) yazıldı. Tartışma iki mesaj turunda kapatıldı.
