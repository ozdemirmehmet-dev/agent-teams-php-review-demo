# login.php / auth.php / schema.sql — Güvenlik İncelemesi (2. tur)

**İnceleyen:** security-2
**Dosyalar:** `login.php` (137 satır), `auth.php` (203 satır), `schema.sql` (24 satır)
**Not:** Bu dosya, eski `security` ajanının bu turda yazdığı taslakla birleştirilmiş tek rapordur. Taslaktaki maddeler kodda yeniden doğrulandı (bkz. son bölüm).
**Genel değerlendirme:** Büyük ilerleme var. İlk turun kritik açıklarının (SQL injection, md5/düz metin karşılaştırma, gömülü root parolası, cookie'de hash, XSS) hepsi kodda kapatılmış. Kalan sorunlar artık "kimlik doğrulama atlatma" seviyesinde değil. Ağırlıkla rate-limit mantığı (yarış durumu, hesap kilitleme DoS), kayıt uç noktasının korumasız olması ve dağıtım ortamına bağlı sertleştirme eksikleri kalıyor. **Kritik bulgu yok.**

---

## 1. İlk tur bulgularının durumu

| # | 1. tur bulgusu | Durum | Gerekçe |
|---|---|---|---|
| 1 | SQL Injection | **Düzeltildi** | Tüm sorgular prepared statement + `bind_param` kullanıyor (auth.php:77, 89, 123, 140, 153, 188). String birleştirmeyle kurulan SQL kalmadı. |
| 2 | md5 + düz metin + `==` | **Düzeltildi** (kalan risk var) | `password_hash`/`password_verify` ve `password_needs_rehash` var (auth.php:98-118). Düz metin karşılaştırması ve `==` kaldırıldı, md5 `hash_equals` ile karşılaştırılıyor. Kalan risk: hiç giriş yapmayan kullanıcıların md5 hash'leri DB'de duruyor (Y4) ve legacy yol bir timing farkı yaratıyor (Y5). |
| 3 | Gömülü `root/root123` | **Kısmen** | Kodda kimlik bilgisi yok, `getenv` kullanılıyor (auth.php:17-22). Ancak sınırlı yetkili kullanıcı schema.sql:19-24'te yalnızca **yorum satırı**; `root123` parolasının değiştirildiği koddan doğrulanamaz. `getenv` boşsa `''` değerine düşülüyor (Y8). |
| 4 | Cookie'de parola hash'i | **Düzeltildi** | `remember_user` cookie'si tamamen kaldırıldı; tarayıcılarda kalan eski cookie'ler artık okunmuyor. |
| 5 | XSS | **Düzeltildi** | Tüm çıktılar `e()` (`htmlspecialchars`, `ENT_QUOTES`, UTF-8) üzerinden geçiyor. `msg` sabit bir anahtarla eşleniyor (login.php:17-18), form `action` sabit, `PHP_SELF` yok. CSP hâlâ yok (Y9). |
| 6 | SQL / DB hata sızıntısı | **Düzeltildi** (küçük boşluk) | `mysqli_sql_exception` loglanıyor, kullanıcıya genel mesaj gidiyor (login.php:77-81). Diğer hata türleri yakalanmıyor (Y7). |
| 7 | Brute-force koruması yok | **Kısmen** | `tooManyFailedAttempts` ve index'ler eklendi. Ancak kontrol ile log arasında yarış durumu var (Y1), hesaplar uzaktan kilitlenebiliyor (Y2), kayıt uç noktası korumasız (Y3). `login_log` temizliği yalnızca yorum olarak duruyor (schema.sql:16-17). |
| 8 | Session fixation / session'da hash | **Düzeltildi** | `session.use_strict_mode=1` + `session_regenerate_id(true)` (login.php:44); session'a sadece `id`, `username` ve `roles` yazılıyor. CSRF token yenilenmiyor (Y6). |
| 9 | CSRF yok | **Düzeltildi** | 32 byte'lık rastgele token + `hash_equals`, iki formda da var (auth.php:56-70, login.php:26). |
| 10 | Kayıt race condition / doğrulama | **Düzeltildi** | UNIQUE index (schema.sql:7) + 1062 yakalama (auth.php:194), regex/e-posta/min. 8 kontrolü. Not: tabloda tekrar eden kullanıcı adı varsa `ALTER` başarısız olur, önce temizlenmeli. |
| 11 | `header()` sonrası `exit` yok | **Düzeltildi** | Her iki yönlendirmede `exit` var (login.php:50, 71); URL'de kullanıcı girdisi yok. |
| 12 | Cookie/session bayrakları | **Kısmen** | `httponly`, `samesite=Lax`, strict mode var. Ancak `secure` bayrağı `$_SERVER['HTTPS']`'e bağlı (auth.php:43, 49); proxy arkasında düşüyor, HSTS yok (Y9). |
| 13 | `type="text"` parola | **Düzeltildi** | `type="password"` + doğru `autocomplete` değerleri (login.php:119, 129). |
| 14 | Kullanıcı adı numaralandırma | **Kısmen** | Login mesajı genel, olmayan kullanıcı için `password_hash` ile süre eşitleniyor (login.php:53-56). Ancak kayıt `'taken'` ile kullanıcı adını hâlâ ve **sınırsız** sızdırıyor (Y3); md5 hesaplar timing ile ayırt edilebiliyor (Y5). |

---

## 2. Yeni veya kalan açıklar

| # | Konum | Sorun | Ciddiyet |
|---|---|---|---|
| Y1 | login.php:36-58, auth.php:120-146 | Rate-limit kontrolü ile log yazma arasında yarış durumu (TOCTOU), paralel isteklerle limit aşılıyor | **Orta** |
| Y2 | auth.php:126, login.php:36 | `username OR ip` kilidi: herkes herhangi bir hesabı kilitleyebilir (hesap kilitleme DoS) | **Orta** |
| Y3 | login.php:61-75, auth.php:177-203 | Kayıtta rate limit yok: sınırsız kullanıcı adı numaralandırma, toplu hesap açma, bcrypt CPU yükü | **Orta** |
| Y4 | auth.php:110-115 | Giriş yapmamış kullanıcıların md5 hash'leri DB'de süresiz kalıyor | **Orta** |
| Y5 | auth.php:102-117, login.php:53-56 | Timing farkı: legacy md5 hesaplar ayırt edilebiliyor | **Düşük** |
| Y6 | login.php:44, auth.php:56-63 | CSRF token login sonrası yenilenmiyor | **Düşük** |
| Y7 | login.php:77, 85; login.php:7 | Yalnızca `mysqli_sql_exception` yakalanıyor | **Düşük** |
| Y8 | auth.php:18-21, schema.sql:19-24 | Eksik env için sessiz `''` varsayılanı; en az yetki uygulanmamış | **Düşük** |
| Y9 | auth.php:43-52 | `Secure` proxy arkasında düşer; HSTS, CSP, X-Frame-Options yok | **Düşük** (HTTP'de servis ediliyorsa **Yüksek**) |
| Y10 | auth.php:179-181, login.php:31-32 | Uzunluk sınırları: bcrypt 72 byte kesmesi, login'de sınırsız username | **Düşük** |
| Y11 | login.php:47, 83-84 | Roller session'da cache'leniyor; zaman aşımı ve logout yok | **Düşük** |
| Y12 | auth.php:124-127 | Başarılı giriş, önceki başarısız sayacı sıfırlamıyor | **Düşük** |

---

### Y1. Rate limit yarış durumu (TOCTOU) — ORTA

**Konum:** login.php:36 (kontrol) → login.php:42 (doğrulama) → login.php:58 (log); auth.php:120-146

Akış "say → doğrula → yaz" sırasında. Aynı anda gönderilen 100 isteğin hepsi `tooManyFailedAttempts`'i sayaç 0-4 iken geçer, çünkü hiçbirinin başarısız kaydı henüz yazılmamıştır. Bu yüzden 15 dakikalık pencerede 5 yerine paralel istek sayısı kadar parola denenebilir. bcrypt'in yavaşlığı pencereyi büyütüyor, çünkü her istek doğrulama süresince "görünmez" kalıyor. Ek olarak kilit aktifken gelen denemeler hiç loglanmıyor (login.php:36-38), saldırının kapsamı denetim izinde görünmüyor.

**Düzeltme (önce yaz, sonra say; başarıda güncelle):**

```php
// auth.php
function beginLoginAttempt(mysqli $db, string $username, string $ip): int
{
    $stmt = $db->prepare(
        'INSERT INTO login_log (username, ip, success, created_at) VALUES (?, ?, 0, NOW())'
    );
    $stmt->bind_param('ss', $username, $ip);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();

    return $id;
}

function markLoginSuccess(mysqli $db, int $attemptId): void
{
    $stmt = $db->prepare('UPDATE login_log SET success = 1 WHERE id = ?');
    $stmt->bind_param('i', $attemptId);
    $stmt->execute();
    $stmt->close();
}

// login.php
$attemptId = beginLoginAttempt($db, $username, $ip);   // önce kaydet
if (tooManyFailedAttempts($db, $username, $ip)) {       // kendi kaydı da sayılır → eşik: > MAX
    http_response_code(429);
    // ...
} else {
    $user = findUserByUsername($db, $username);
    if ($user !== null && verifyPassword($db, $user, $password)) {
        markLoginSuccess($db, $attemptId);
        // ... session_regenerate_id, yönlendirme
    }
}
```

Bu yöntem `login_log`'da `id` (AUTO_INCREMENT) olduğunu varsayar. Uygulama kullanıcısına `login_log` üzerinde `UPDATE` yetkisi de verilmeli (schema.sql:24). Kilitli denemeleri de loglamak, log şişmesini Y10'daki uzunluk sınırı ve periyodik temizlik ile dengelemek kaydıyla kabul edilebilir.

**İndeks ve temizlik (performance-2 ile birlikte):** Kilit sorgusu her giriş denemesinde çalışıyor. Yavaşlarsa yük altında DoS kolaylaşır. Mevcut indeksler (schema.sql:12-14) `success`'i içermiyor ve `OR` sorgusu index_merge'e bağımlı. Kapsayan indeksler ve yorum satırı olmaktan çıkarılmış bir temizlik işi önerilir:

```sql
ALTER TABLE login_log
    ADD INDEX idx_login_log_user_succ_created (username, success, created_at),
    ADD INDEX idx_login_log_ip_succ_created   (ip, success, created_at),
    ADD INDEX idx_login_log_created           (created_at);

-- Uygulama kullanıcısıyla DEĞİL, ayrı bir bakım kullanıcısıyla, tekrar eden küçük partiler hâlinde:
DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY LIMIT 10000;
```

Uygulama kullanıcısına `login_log` üzerinde `DELETE` yetkisinin verilmemesi (schema.sql:24) doğru. Saldırı anında denetim izinin silinebilmesini engeller.

---

### Y2. Hesap kilitleme DoS — ORTA

**Konum:** auth.php:126 `AND (username = ? OR ip = ?)`

Kilit kullanıcı adına bağlı. Kurbanın kullanıcı adını bilen herkes (Y3 ile toplanabiliyor) 15 dakikada bir 5 yanlış denemeyle hesabı süresiz kilitli tutabilir. Doğru parola da reddedilir, çünkü login.php:36 doğrulamadan önce çalışıyor. Admin hesabı hedef alınabilir. Ayrıca NAT veya proxy arkasında aynı `REMOTE_ADDR`'ı paylaşan kullanıcılar birbirini kilitler.

**Düzeltme:** Kesin engeli "IP + kullanıcı adı" çiftine ve yüksek bir IP eşiğine bağlayın. Saf kullanıcı adı sayısı kilit yerine CAPTCHA/gecikme tetiklesin:

```php
function loginThrottleState(mysqli $db, string $username, string $ip): string
{
    $minutes = LOCKOUT_MINUTES;
    $stmt = $db->prepare(
        'SELECT
            COALESCE(SUM(username = ? AND ip = ?), 0),
            COALESCE(SUM(ip = ?), 0),
            COALESCE(SUM(username = ?), 0)
         FROM login_log
         WHERE success = 0
           AND (username = ? OR ip = ?)
           AND created_at > NOW() - INTERVAL ? MINUTE'
    );
    $stmt->bind_param('ssssssi', $username, $ip, $ip, $username, $username, $ip, $minutes);
    $stmt->execute();
    [$pair, $ipFails, $userFails] = array_map('intval', $stmt->get_result()->fetch_row());
    $stmt->close();

    if ($pair >= 5 || $ipFails >= 50) {
        return 'block';          // bu IP'den engel (50: NAT payı)
    }
    if ($userFails >= 20) {
        return 'challenge';      // hesabı kilitleme; CAPTCHA / artan gecikme iste
    }
    return 'ok';
}
```

---

### Y3. Kayıt uç noktası korumasız — ORTA

**Konum:** login.php:61-75, auth.php:177-203, mesaj login.php:14

- `'taken'` yanıtı kullanıcı adının var olduğunu doğrudan söylüyor. Login tarafındaki numaralandırma önlemlerinin (genel mesaj, timing eşitleme, rate limit) hepsi bu yolla **sınırsız** aşılabiliyor. Toplanan liste Y2 (kilitleme) ve parola püskürtme için girdi oluyor.
- Hiç limit yok: toplu bot hesabı açılabiliyor. Her istek bir `password_hash` (bcrypt) çalıştırdığı için ucuz bir CPU tüketme vektörü de var.

**Düzeltme:** En iyisi "alınmış" bilgisini gizlemek (e-posta doğrulamalı akış). En azından hash'ten önce IP limiti konmalı:

```php
// login.php — register dalı
} elseif ($action === 'register') {
    $db = db();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (tooManyRegistrations($db, $ip)) {          // password_hash'ten ÖNCE
        http_response_code(429);
        $error = 'Çok fazla deneme. Lütfen daha sonra tekrar deneyin.';
    } else {
        logRegistrationAttempt($db, $ip);
        $result = registerUser($db, trim(postString('new_username')),
            postString('new_password'), trim(postString('email')));
        // ...
    }
}

// auth.php  (register_log(ip, created_at) tablosu + (ip, created_at) index)
function tooManyRegistrations(mysqli $db, string $ip): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM register_log
         WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return $count >= 5;
}

function logRegistrationAttempt(mysqli $db, string $ip): void
{
    $stmt = $db->prepare('INSERT INTO register_log (ip, created_at) VALUES (?, NOW())');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}
```

---

### Y4. md5 hash'ler DB'de süresiz kalıyor — ORTA

**Konum:** auth.php:110-115

Geçiş "ilk girişte yükselt" (lazy) modelinde. Uzun süre giriş yapmayan hesapların tuzsuz md5 hash'leri süresiz kalıyor. DB sızarsa bunlar rainbow table ile hızla kırılır ve başka sitelerde de kullanılabilir. md5 doğrulama dalı da süresiz açık kalıyor.

**Düzeltme:** Tek seferlik betikle tüm md5 hash'leri `password_hash(md5)` ile sarın, doğrulamada sarılmış formatı tanıyın:

```php
// tek seferlik migration (CLI)
$db  = db();
$res = $db->query("SELECT id, password FROM users WHERE password REGEXP '^[a-fA-F0-9]{32}$'");
$upd = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
while ($row = $res->fetch_assoc()) {
    $wrapped = 'md5$' . password_hash(strtolower($row['password']), PASSWORD_DEFAULT);
    $id = (int) $row['id'];
    $upd->bind_param('si', $wrapped, $id);
    $upd->execute();
}
$upd->close();

// auth.php verifyPassword() — md5 dalının yerine:
if (str_starts_with($stored, 'md5$')
    && password_verify(md5($password), substr($stored, 4))) {
    updatePasswordHash($db, (int) $user['id'], $password);
    return true;
}
```

Migration bittikten sonra düz md5 dalı (auth.php:110-115) silinmeli. Bu, Y5'i de kökten kapatır.

---

### Y5. Timing farkıyla legacy hesap tespiti — DÜŞÜK

**Konum:** auth.php:102-117, login.php:53-56

- Olmayan kullanıcı: `password_hash` → **yavaş**.
- bcrypt'li kullanıcı, yanlış parola: `password_verify` → **yavaş**.
- md5'li kullanıcı, yanlış parola: `password_verify` geçersiz formatta anında `false` döner, ardından `md5` gelir → **çok hızlı**.

Hâlâ md5'te olan, yani sızıntıda en kolay kırılacak hesaplar ve varlıkları dışarıdan tespit edilebiliyor.

**Düzeltme:** Kalıcı çözüm Y4. Geçici olarak md5 dalında ve olmayan kullanıcıda aynı sabit hash ile `password_verify` çağırın:

```php
// Bir kez `php -r "echo password_hash('dummy', PASSWORD_DEFAULT);"` ile üretip yapıştırın.
// Uydurma/geçersiz bir hash KULLANMAYIN: password_verify anında false döner ve amaç bozulur.
const DUMMY_HASH = '<üretilen 60 karakterlik $2y$... değeri>';

// login.php:53-56
if ($user === null) {
    password_verify($password, DUMMY_HASH);
}

// auth.php md5 dalı
if ($isLegacyMd5) {
    password_verify($password, DUMMY_HASH);   // maliyeti eşitle
    if (hash_equals(strtolower($stored), md5($password))) { /* rehash + true */ }
}
```

---

### Y6. CSRF token login sonrası yenilenmiyor — DÜŞÜK

**Konum:** login.php:44, auth.php:56-63

`session_regenerate_id` session verisini korur, giriş öncesi token girişten sonra da geçerli kalır. Giriş öncesi session'ı kurbana yerleştirebilen bir saldırgan token'ı bilir ve girişten sonra kurbanın oturumunda CSRF yapabilir. Bunun için alt alan adından cookie enjeksiyonu gibi bir yol gerekir; `use_strict_mode` yalnızca *uydurma* ID'leri engeller, saldırganın sunucudan aldığı geçerli ID'yi engellemez.

```php
session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['user_id'] = (int) $user['id'];
```

---

### Y7. Hata yönetimi yalnızca mysqli istisnalarını kapsıyor — DÜŞÜK

**Konum:** login.php:77; try dışında kalanlar: login.php:7 (`startSecureSession`), login.php:85 (`csrfToken` → `random_bytes`)

Diğer hatalar (`Exception`, `TypeError`, `ValueError`) yakalanmıyor. `display_errors` açık kalırsa dosya yolu ve stack trace kullanıcıya gösterilir.

```php
// login.php başı
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    error_log('login.php: ' . $e);
    http_response_code(500);
    echo 'Bir hata oluştu, lütfen daha sonra tekrar deneyin.';
});

// mevcut catch
} catch (Throwable $e) {
    error_log('login.php: ' . $e->getMessage());
    http_response_code(500);
    $error = 'Bir hata oluştu, lütfen daha sonra tekrar deneyin.';
}
```

---

### Y8. Ortam değişkenleri ve en az yetki — DÜŞÜK

**Konum:** auth.php:18-21, schema.sql:19-24

- `getenv('DB_USER') ?: ''`: yapılandırma eksikse uygulama sessizce boş kullanıcıyla bağlanmayı dener. Eski kurulumlarda anonim MySQL hesabı varsa bu bağlantı başarılı olur. PHP-FPM `clear_env=yes` varsayılanı yüzünden bu durum sık yaşanır.
- `app_login` kullanıcısı ve GRANT'ler yalnızca yorum; uygulamanın hâlâ `root` ile çalışmadığı garanti değil.

```php
function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing required environment variable: $name");
    }
    return $value;
}

$conn = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    requiredEnv('DB_USER'),
    requiredEnv('DB_PASS'),
    requiredEnv('DB_NAME')
);
```

GRANT'leri gerçekten uygulayın, `root123`'ü değiştirin, `root`'un uzaktan erişimini kapatın. Env değerleri `phpinfo()` çıktısında görünür, üretimde böyle bir sayfa bırakmayın.

---

### Y9. Taşıma katmanı ve tarayıcı sertleştirmesi — DÜŞÜK (HTTP'de servis ediliyorsa YÜKSEK)

**Konum:** auth.php:43-52

- TLS bir proxy/load balancer'da sonlanıyorsa `$_SERVER['HTTPS']` boş gelir ve cookie `Secure` olmadan gider. Site HTTP'den de erişilebiliyorsa parola ve session ID açık metin taşınır.
- HSTS yok (SSL stripping). `frame-ancestors`/`X-Frame-Options` yok, giriş formu iframe'e alınabilir (clickjacking). CSP yok, XSS'e karşı ikinci savunma hattı eksik.

```php
function startSecureSession(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,            // üretimde sabit; HTTP isteklerini HTTPS'e yönlendirin
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('__Host-sid');        // Secure + path=/ + domain'siz cookie'yi zorunlu kılar
    session_start();

    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
```

Not: login.php:101'deki inline `style="color: red"` katı CSP ile engellenir; CSS sınıfına taşıyın. `__Host-` öneki, alt alan adından cookie enjeksiyonunu da engeller (Y6'yı zayıflatır).

---

### Y10. Girdi uzunluk sınırları — DÜŞÜK

**Konum:** auth.php:180 (üst sınır yok), login.php:31-32

- bcrypt 72 byte'tan sonrasını sessizce yok sayar. `strlen` byte saydığı için çok baytlı UTF-8 karakterlerde bu sınıra daha az karakterde ulaşılır.
- Login'de `username` uzunluğu sınırsız ve doğrudan `login_log`'a yazılıyor. Kolon kısa ve strict mode açıksa INSERT hata verir, istek 500'e düşer ve deneme loglanmaz. Kolon uzunsa log şişer.

```php
// registerUser
$isValid = preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) === 1
    && strlen($password) >= 8
    && strlen($password) <= 72
    && strlen($email) <= 254
    && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

// login.php
$username = trim(postString('username'));
if (strlen($username) > 32 || strlen($password) > 1024) {
    $error = 'Kullanıcı adı veya şifre hatalı.';
} else {
    // mevcut akış
}
```

---

### Y11. Session yaşam döngüsü — DÜŞÜK

**Konum:** login.php:47, 83-84

- Roller girişte session'a kopyalanıyor; admin bir rolü kaldırsa da oturum boyunca eski yetki geçerli kalır. Yetki kararlarında session'daki `roles` kullanılırsa bu bir yetki iptali sorununa dönüşür.
- Idle/absolute zaman aşımı ve logout yok.

```php
const SESSION_IDLE_SECONDS = 1800;
if (isset($_SESSION['user_id'])) {
    if (time() - ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = time();
    }
}
// Yetki kontrolünde rolleri getUserRoles($db, $_SESSION['user_id']) ile DB'den okuyun.
```

---

### Y12. Başarılı giriş sayacı sıfırlamıyor — DÜŞÜK

**Konum:** auth.php:124-127 (quality-2 ve eski `security` taslağı 2.7'den; kodda doğrulandı)

Sayım son başarılı girişi dikkate almıyor. Örnek: 4 hata, ardından başarılı giriş, sonra 1 hata → meşru kullanıcı 15 dakika kilitlenir. Y2 ile birlikte DoS'u kolaylaştırır.

```php
'SELECT COUNT(*) FROM login_log l
 WHERE l.success = 0
   AND l.username = ? AND l.ip = ?
   AND l.created_at > NOW() - INTERVAL ? MINUTE
   AND l.created_at > COALESCE(
         (SELECT MAX(s.created_at) FROM login_log s
           WHERE s.username = ? AND s.success = 1), \'1970-01-01\')'
```

---

## 3. Öncelikli aksiyon listesi

1. Rate limit'i "önce kaydet, sonra say" yapısına çevir (Y1). Kilidi IP + kullanıcı adı çiftine bağla, başarıda sayacı sıfırla (Y2, Y12).
2. Kayıt uç noktasına hash'ten önce IP limiti koy; mümkünse "alınmış" bilgisini gizle (Y3).
3. Kalan md5 hash'leri tek seferde sar, ardından düz md5 dalını sil (Y4, Y5).
4. Üretimde HTTPS'i zorla: `secure` sabit `true`, HSTS/CSP/`frame-ancestors` ekle (Y9).
5. GRANT'leri gerçekten uygula, `root123`'ü değiştir, env için fail-fast ekle (Y8).
6. Login'de CSRF token'ı yenile, `Throwable` yakala, uzunluk sınırları ve session zaman aşımı ekle (Y6, Y7, Y10, Y11).

---

## Takım tartışmasından notlar

**Gönderdiklerim:** quality-2 ve performance-2'ye birer mesaj gönderdim (ilk denemede eski turun `performance`/`quality` ajanlarına gitmişti; onlar 2. turu incelemediklerini söyledi). Mesajlarda şunlar vardı: TOCTOU (Y1), `OR` sorgusunun index_merge'e bağımlılığı, kayıt ve olmayan kullanıcı yolundaki bcrypt maliyeti, `Throwable` yakalanmaması, rol cache'i ve zaman aşımı, inline style ile CSP çakışması.

**Gelen notlar ve değerlendirmem:**

- **quality-2 (6 not):** Hepsi kodda doğrulandı ve raporda var: hesap kilitleme ve sayacın sıfırlanmaması (Y2, Y12), bcrypt 72 byte / üst sınır yok (Y10), md5 yolunda timing farkı (Y5), `csrfToken()`'ın try dışında olması ve token'ın yenilenmemesi (Y7, Y6), `db()` fail-fast değil (Y8), `'taken'` ile numaralandırma (Y3).
- **performance-2:** Kilitleme DoS, md5 timing, kayıtta limit ve log yok, kilitliyken log yok notları doğrulandı (Y2, Y5, Y3, Y1). İndeks önerileri `(username, success, created_at)` / `(ip, success, created_at)` ve `created_at` + `LIMIT`li batch `DELETE` Y1'e eklendi: kilit sorgusunun yavaşlaması kendi başına DoS yüzeyi. Uygulama kullanıcısına `DELETE` verilmemesi ve temizliğin ayrı bir bakım kullanıcısıyla yapılması destekleniyor.
- **Eski `performance` ajanı (1. tur):** 2. turu incelemedi, ama önerilerime dosyaları açmadan görüş verdi. "Önce INSERT, sonra COUNT" yapısının TOCTOU'yu kapattığını ve yazma sayısını artırmadığını onayladı. Başarıdaki UPDATE'in `insert_id` ile PK üzerinden yapılması gerektiğini vurguladı (Y1 kodu buna göre). `OR` yerine iki COUNT ya da UNION ALL kullanılmasını ve EXPLAIN ile doğrulamayı önerdi.
- **Eski `security` ajanının bu turdaki taslağı (10 madde):** Bu dosyaya önce o ajan yazmıştı. Maddeler tek tek kodda doğrulandı ve birleştirildi: 2.1 → Y2, 2.2 → Y3, 2.3 → Y5, 2.4 (kilitliyken log yok) → Y1, 2.5 → Y10, 2.6 → Y6, 2.7 (sayaç sıfırlanmıyor) → Y12, 2.8 → Y8, 2.9 → Y9, 2.10 → Y3. Taslakta 1. tur durum tablosu ile Y1 (TOCTOU), Y4, Y7, Y9'un başlık kısmı ve Y11 yoktu; bunlar bu raporda eklendi.
- **Ciddiyet farkı:** Taslak, kayıtta numaralandırmayı (2.10) "kabul edilebilir trade-off" sayıyordu. Kayıt rate limit'i eklenirse buna katılıyorum. Mevcut kodda hiç limit olmadığı ve login tarafındaki numaralandırma önlemlerini tamamen boşa çıkardığı için Orta (Y3) olarak bıraktım.
- **Ortak sonuç:** Üç inceleyici de kalan en önemli açıkların rate limit tasarımında (TOCTOU, `username OR ip` kilidi, kayıt limitsiz) toplandığında birleşiyor. Bu düzeltmeler hem güvenlik (brute-force, hesap kilitleme) hem performans (bcrypt CPU DoS, indeks) sorunlarını birlikte çözüyor.
