# Giriş sistemi — Güvenlik İncelemesi (3. tur)

**İnceleyen:** sec3
**Dosyalar:** `login.php`, `src/Config.php`, `src/Database.php`, `src/bootstrap.php`, `src/Auth/*.php`, `src/Http/functions.php`, `templates/login.php`, `templates/error.php`, `login.css`, `schema.sql`, `schema_rollback.sql`, `migrate_md5_passwords.php`
**Ortam kontrolü:** `C:\xampp\php\php` = PHP 8.2.12. Tüm dosyalar `php -l` ile hatasız. Yerel php.ini: `zend.exception_ignore_args` kapalı, `zend.exception_string_param_max_len=15`, `display_errors=1`, `expose_php=1`.

**Genel değerlendirme:** 2. turun ana sorunu olan rate-limit tasarımı doğru biçimde yeniden yazılmış. Sayaç okunmadan önce artırılıyor (TOCTOU kapandı), kilit (kullanıcı adı, IP) çiftine bağlı (uzaktan hesap kilitleme kapandı), başarıda sayaç sıfırlanıyor, kayıt hash'ten önce sınırlanıyor. md5 dalı kaldırılmış, her yol tam olarak bir bcrypt çalıştırıyor. CSRF girişte ve çıkışta yenileniyor. CSP ve başlıklar eklenmiş, `Throwable` yakalanıyor. **Kritik veya yüksek bulgu yok.**

Kalan riskler üç yerde toplanıyor:
1. Hata loglarına parola sızması (yerel ortamda doğrulandı).
2. Hesap başına üst sınır olmaması, IPv6 ve proxy yüzünden IP anahtarının zayıf olması.
3. Operasyonel adımların (migration, GRANT, event scheduler) hâlâ elle yapılması.

---

## 1. 2. tur bulgularının durumu (Y1–Y12)

| # | 2. tur bulgusu | Durum | Gerekçe (dosya:satır) |
|---|---|---|---|
| Y1 | Rate limit TOCTOU | **Düzeltildi** | `Throttle::hit` önce `INSERT … ON DUPLICATE KEY UPDATE` ile artırıyor, sonra okuyor (Throttle.php:26-40). Karar, artırılmış değerle veriliyor (AuthService.php:40-45). Paralel istekler sırayla 1, 2, 3… değerini görüyor, limit aşılamıyor. Kalan küçük nokta: artırma ve okuma iki ayrı sorgu (bkz. N6). Kilitliyken gelen denemeler hâlâ `login_log`'a yazılmıyor (bkz. N10). |
| Y2 | `username OR ip` → hesap kilitleme DoS | **Düzeltildi** | Kesin kilit artık `login-pair:<kullanıcı>\|<ip>` (5) ve `login-ip:<ip>` (50) anahtarlarında (AuthService.php:16-19, 38-43). Başka IP'den gelen saldırgan bir hesabı kilitleyemiyor. **Yan etki:** hesap başına toplam sınır kalmadı (N2), proxy arkasında Y2 geri geliyor (N3). |
| Y3 | Kayıt uç noktası korumasız | **Kısmen** | IP başına saatte 5 kayıt, `password_hash`'ten önce sayılıyor (AuthService.php:64-67). Bu, CPU tüketmeyi ve toplu hesap açmayı sınırlıyor. `UsernameTaken` yanıtı hâlâ kullanıcı adının varlığını söylüyor (login.php:106). Hız artık 5/saat/IP ile sınırlı, ancak IPv6 adres rotasyonu veya çok sayıda IP ile aşılabiliyor (N2). |
| Y4 | md5 hash'ler süresiz kalıyor | **Düzeltildi** (çalıştırılması koşuluyla) | `migrate_md5_passwords.php` tüm md5'leri `legacy-md5:` + bcrypt(md5) ile sarıyor. Düz md5 doğrulama dalı kodda yok (AuthService.php:142-156), ilk girişte normal hash'e yükseltiliyor (AuthService.php:158-171). Betiğin çalıştırıldığı koddan doğrulanamaz. 1. turdaki düz metin parolalar ele alınmıyor (N4). |
| Y5 | Timing ile legacy hesap tespiti | **Düzeltildi** | Olmayan kullanıcı (`DUMMY_HASH`), legacy ve normal hesapta tam olarak bir `password_verify` çalışıyor (AuthService.php:142-156). `DUMMY_HASH` geçerli, cost 10 bir bcrypt; PHP 8.2'de `password_needs_rehash` false dönüyor, yani maliyet eşit. Gelecekte PHP 8.4'e geçişte bu eşitlik bozulur (N5). |
| Y6 | CSRF token girişte yenilenmiyor | **Düzeltildi** | `renewSession()` girişte ve çıkışta ID'yi yeniliyor ve token'ı siliyor (functions.php:69-73; login.php:72, 111). Yeni token login.php:124'te üretiliyor. `__Host-` cookie öneki hâlâ yok (N8). |
| Y7 | Yalnızca `mysqli_sql_exception` yakalanıyor | **Kısmen** | `ob_start` + `catch (Throwable)` ve genel hata sayfası var (login.php:33, 129-135). Ancak `display_errors` kodda kapatılmıyor; uyarılar (warning) istisna olmadığı için ekrana basılabiliyor (N7). Loglanan trace'lerde parola var (N1). |
| Y8 | Env varsayılanı `''`, en az yetki yok | **Kısmen** | `Config::required` fail-fast (Config.php:37-45), `DB_PASS` boş olamıyor. GRANT'ler hâlâ yalnızca yorum satırı (schema.sql:86-94); uygulamanın `root` ile çalışmadığı koddan doğrulanamaz. |
| Y9 | Secure/HSTS/CSP/XFO yok | **Düzeltildi** (küçük eksikler) | `Secure` varsayılan açık, yalnızca `COOKIE_SECURE=0` ile kapanıyor (Config.php:33). CSP (`default-src 'self'`, `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'none'`, `object-src 'none'`), XFO, nosniff, Referrer-Policy, HSTS gönderiliyor (functions.php:24-37). Inline style kaldırılmış (login.css'e taşındı). Eksikler: HTTP→HTTPS yönlendirmesi kodda yok (dağıtıma bağlı), `__Host-` öneki yok (N8), `Config::fromEnv()` hata verirse hata sayfası güvenlik başlıkları olmadan gidiyor (N7). |
| Y10 | Girdi uzunluk sınırları | **Düzeltildi** | Yeni parola ≤ 72 bayt ve ≥ 8 karakter (`mb_strlen`), login parolası ≤ 1024 bayt, kullanıcı adı `^[A-Za-z0-9_]{3,32}$` (`/D` bayrağıyla), e-posta ≤ 254 (CredentialRules.php:12-42; AuthService.php:36-37). Biçimsiz kullanıcı adı `login_log`'a boş string olarak yazılıyor (AuthService.php:50). |
| Y11 | Roller cache'li, zaman aşımı ve logout yok | **Kısmen** | Roller her istekte DB'den okunuyor (login.php:122-123). 30 dk idle timeout (functions.php:55-64) ve POST + CSRF korumalı logout (login.php:109-112) var. Mutlak oturum süresi yok, silinmiş kullanıcının oturumu geçerli kalıyor (N8). |
| Y12 | Başarılı giriş sayacı sıfırlamıyor | **Düzeltildi** | Başarıda `throttle->clear($pairKey)` çağrılıyor (AuthService.php:56). IP sayacı bilerek sıfırlanmıyor. Bu doğru, yoksa saldırgan kendi hesabıyla girip IP sayacını temizleyebilirdi. |

---

## 2. İstenen konuların kısa değerlendirmesi

- **login_throttle sayaç mantığı:** ODKU'daki atama sırası doğru. `hits` ve `window_start` ikisi de `window_start`'ın **eski** değerine bakıyor (MySQL atamaları soldan sağa uyguluyor, `hits` önce geliyor). Sıra ters olsaydı pencere hiç sıfırlanmazdı. Sabit pencere, sınırda 2 kat patlamaya izin veriyor (N9). Anahtar sha256 ile sabit uzunlukta; `strtolower` ve ASCII regex, büyük/küçük harf farkıyla limit atlatmayı engelliyor.
- **IP anahtarı / REMOTE_ADDR:** `X-Forwarded-For`'a güvenilmemesi sahteciliğe karşı doğru varsayılan. Ancak proxy arkasında (N3) ve IPv6'da (N2) sorunlu.
- **legacy-md5 sarmalama:** Güvenli. Önek yalnızca betik tarafından yazılabiliyor, `password_hash` çıktısı bu önekle başlayamaz. Betik idempotent: sarılmış satırlar regex'e uymuyor. Hex `strtolower` ile normalize ediliyor; PHP `md5()` de küçük harf döndürüyor.
- **session kapat/aç (login.php:67-69):** Sorun bulunmadı. CSRF kontrolü kapatmadan önce yapılıyor. Yeniden açılışta session verisi taze okunuyor. Bu arada başka sekmeden çıkış yapılırsa `use_strict_mode` eski ID'yi kabul etmiyor ve yeni boş session açılıyor. Başarıda `renewSession()` ID'yi zaten yeniliyor. Register dalında session yeniden açılmıyor (login.php:84); token o noktada zaten var olduğu için güvenlik etkisi yok, ama kırılgan bir yapı (kalite notu).
- **CSRF:** Token 32 bayt `random_bytes`, `hash_equals`, boş token reddediliyor, login CSRF'i de kapsanıyor (functions.php:75-90). Sorun yok.
- **Idle timeout:** Doğru çalışıyor. Zaman aşımında session boşaltılıyor ve ID yenileniyor. Mutlak süre yok (N8).
- **Logout:** POST + CSRF, `$_SESSION = []` ve ardından `session_regenerate_id(true)` eski session dosyasını siliyor. Sorun yok.
- **CSP ve başlıklar:** İyi. Inline script/style yok, `login.css` harici kaynak içermiyor.
- **Hata yönetimi:** N1 ve N7.
- **Migration betiği:** CLI dışından 404 dönüyor (migrate_md5_passwords.php:18-21). Web kökünde durması gerekmiyor; dışarı taşınmalı. Düz metin satırları ele alınmıyor (N4).
- **GRANT'ler:** İçerik doğru ve minimum. `login_log` üzerinde yalnızca INSERT var, denetim izi uygulama kullanıcısıyla silinemiyor. `login_throttle` üzerinde DELETE `clear()` için gerekli. Ancak hâlâ yorum satırı (Y8).

---

## 3. Yeni veya kalan açıklar

| # | Konum | Sorun | Ciddiyet |
|---|---|---|---|
| N1 | login.php:131; AuthService.php:34, 62, 142, 158 | İstisna trace'iyle parola düz metin olarak error log'a yazılıyor | **Orta** (yerelde doğrulandı) |
| N2 | AuthService.php:38-45; functions.php:19-22 | Hesap başına toplam sınır yok, IPv6'da adres başına anahtar → dağıtık brute-force | **Orta** |
| N3 | functions.php:19-22 | Reverse proxy arkasında tüm istemciler tek IP → global giriş/kayıt DoS, Y2 geri gelir | **Orta** (doğrulanmadı, dağıtıma bağlı) |
| N4 | migrate_md5_passwords.php:25 | 1. tur kodundan kalma düz metin parolalar DB'de kalıyor | **Orta** (doğrulanmadı, veriye bağlı) |
| N5 | AuthService.php:24 | `DUMMY_HASH` cost'u sabit; PHP 8.4'te timing ile kullanıcı numaralandırma | **Düşük** |
| N6 | Throttle.php:26-43 | Artır + oku atomik değil; satır arada silinirse `null[0]` uyarısı ve 0 dönüşü | **Düşük** |
| N7 | login.php:33-37, bootstrap.php | `display_errors` kapatılmıyor; uyarılar ekrana basılıyor; config hatasında başlıksız hata sayfası | **Düşük** |
| N8 | functions.php:39-64; login.php:119-123 | Mutlak oturum süresi yok, `__Host-` öneki yok, silinen kullanıcının oturumu geçerli | **Düşük** |
| N9 | Throttle.php:30-31 | Sabit pencere sınırında 2 kat deneme | **Düşük** |
| N10 | AuthService.php:43-45, 50 | Kilitli denemeler denetim loguna yazılmıyor; yanlış alana yazılan parola `login_log`'a düşebilir | **Düşük** |
| N11 | schema.sql:86-94, 78-84 | GRANT'ler ve event scheduler elle yapılacak adım; yapılmazsa uygulama geniş yetkiyle çalışır ve loglar sınırsız büyür | **Düşük** (operasyonel) |
| N12 | AuthService.php:40-45; schema.sql:82-84 | IP limiti aşıldıktan sonra da pair sayacı yazılıyor → tek IP'den `login_throttle` sınırsız şişirilebiliyor (perf3 P1) | **Orta** |

---

### N1. Parola, hata loguna düz metin olarak yazılıyor — ORTA

**Konum:** login.php:131 `error_log('login.php: ' . $e);`. Parola alan çağrılar: `AuthService::attemptLogin` (AuthService.php:34), `register` (:62), `passwordMatches` (:142), `upgradeHashIfNeeded` (:158).

`Throwable::__toString()` stack trace'i argümanlarla birlikte üretir. Bu ortamda `zend.exception_ignore_args` kapalı (XAMPP/development php.ini varsayılanı), her string argümanın ilk 15 karakteri trace'e yazılıyor. Login sırasında DB bağlantısı düşerse, kilit zaman aşımı, deadlock veya tablo eksikliği olursa `mysqli_sql_exception` fırlar ve log şöyle bir satır içerir:

```
#1 C:\...\login.php(68): App\Auth\AuthService->attemptLogin('alice', 'MySecretPass123...', '10.0.0.5')
```

Bu, yerel PHP 8.2.12 ile birebir test edilerek doğrulandı. Kullanıcı adı ve parola çifti, log dosyasına, log toplama sistemine ve yedeklere düz metin olarak gider. 15 karakter çoğu parolanın tamamı demektir. Tetiklenmesi için bir DB hatası yeterli: kesinti, bakım, bağlantı limiti dolması ya da yoğun eşzamanlı ODKU'da kilit zaman aşımı (son durum **doğrulanmadı**, bkz. N6). Log'u okuyabilen herkes (operasyon ekibi, log servisi, sızmış yedek) bu parolaları görür.

**Düzeltme (PHP 8.2+):**

```php
// src/Auth/AuthService.php
public function attemptLogin(string $username, #[\SensitiveParameter] string $password, string $ip): LoginOutcome
public function register(string $username, #[\SensitiveParameter] string $password, string $email, string $ip): RegisterResult
private function passwordMatches(?User $user, #[\SensitiveParameter] string $password): bool
private function upgradeHashIfNeeded(User $user, #[\SensitiveParameter] string $password): void
```

Trace'te artık `Object(SensitiveParameterValue)` görünür (yerelde doğrulandı). Ek olarak üretim php.ini'de `zend.exception_ignore_args = On` olmalı. Log satırı da trace yerine sınıf, mesaj, dosya ve satır içerecek şekilde daraltılabilir:

```php
} catch (Throwable $e) {
    ob_clean();
    error_log(sprintf('login.php: %s: %s at %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    require __DIR__ . '/templates/error.php';
    ob_end_flush();
}
```

---

### N2. Hesap başına üst sınır yok, IPv6 adres rotasyonu — ORTA

**Konum:** AuthService.php:38-45, functions.php:19-22

Y2'nin düzeltmesi kilidi (kullanıcı, IP) çiftine taşıdı, ama hesap başına bir üst sınır eklenmedi (2. turda önerilen "`userFails >= 20` → challenge" adımı uygulanmamış). Sonuç: her IP bir hesaba 15 dakikada 5 deneme yapabiliyor; N IP ile 5·N deneme. Bu, parola püskürtme ve botnet saldırılarında sınırsız demek.

IPv6'da durum daha da kötü: tek bir istemci genellikle bir /64 bloğuna (2^64 adres) sahip ve `REMOTE_ADDR` adres başına anahtar oluşturuyor. Tek saldırgan adres değiştirerek hem çift limitini (5) hem IP limitini (50) hem de kayıt limitini (5/saat) pratikte sınırsız aşabiliyor. Bu, Y3'teki kullanıcı adı numaralandırmasını da yeniden açıyor.

**Düzeltme:** IPv6'yı /64'e indirgeyin ve hesap başına yüksek bir global sınır ekleyin. Hesap kilidi DoS'a dönüşmesin diye bu sınır, hesabın sahibinin daha önce başarıyla girdiği IP'leri muaf tutabilir:

```php
// src/Http/functions.php
function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16) {
        // IPv6: anahtar olarak /64 önekini kullan (bir istemci genelde tüm /64'e sahiptir).
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    return $ip;
}

// src/Auth/AuthService.php
private const LOGIN_USER_WINDOW_SECONDS = 3600;
private const LOGIN_USER_LIMIT = 100;   // tüm IP'lerden toplam; meşru kullanıcı buna yaklaşmaz

public function attemptLogin(string $username, #[\SensitiveParameter] string $password, string $ip): LoginOutcome
{
    $isWellFormed = CredentialRules::isValidUsername($username)
        && strlen($password) <= CredentialRules::LOGIN_PASSWORD_MAX_BYTES;
    $userKey = 'login-user:' . strtolower($username);
    $pairKey = 'login-pair:' . strtolower($username) . '|' . $ip;

    // Sıra önemli (N12): önce IP; aşıldıysa başka anahtar yazmadan çık.
    if ($this->throttle->hit('login-ip:' . $ip, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_IP_LIMIT) {
        return LoginOutcome::failure(LoginResult::Throttled);
    }
    if ($isWellFormed
        && ($this->throttle->hit($pairKey, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_PAIR_LIMIT
            || $this->throttle->hit($userKey, self::LOGIN_USER_WINDOW_SECONDS) > self::LOGIN_USER_LIMIT)) {
        return LoginOutcome::failure(LoginResult::Throttled);
    }
    // ... mevcut akış; başarıda yalnızca clear($pairKey). $userKey bilerek temizlenmez:
    // hesap sahibinin girişi, devam eden dağıtık saldırının sayacını sıfırlamamalı.
}
```

Trade-off: 100 eşiğini aşan bir saldırı, hesap sahibini de bir saatliğine engeller. Bu yüzden eşik yüksek tutulmalı.

Hesap sınırı aşıldığında kesin engel yerine CAPTCHA veya MFA istemek daha iyidir. Bu altyapı yoksa yukarıdaki yüksek eşik, DoS ile brute-force arasında makul bir dengedir.

---

### N3. Reverse proxy arkasında global DoS — ORTA (doğrulanmadı, dağıtıma bağlı)

**Konum:** functions.php:19-22

Uygulama nginx, load balancer veya Cloudflare arkasında çalışıyorsa `REMOTE_ADDR` her istekte proxy'nin IP'sidir. Bu durumda:
- `login-ip` sayacı tüm kullanıcılar için ortaktır: 15 dakikada **51 istek** gönderen herkes, tüm sitenin girişini 429'a düşürür.
- `register-ip`: saatte **6 istek** ile tüm yeni kayıtlar durur.
- `login-pair` fiilen "kullanıcı adı başına" sayaca dönüşür: Y2 (uzaktan hesap kilitleme) geri gelir.

Kodun `X-Forwarded-For`'a körü körüne güvenmemesi doğru. Eksik olan, güvenilir proxy yapılandırması.

**Düzeltme:**

```php
// Config: TRUSTED_PROXIES="10.0.0.1,10.0.0.2" (boşsa başlık hiç okunmaz)
function clientIp(array $trustedProxies = []): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remote, $trustedProxies, true)) {
        return $remote;
    }

    // Sağdan sola yürü; güvenilir olmayan ilk adres istemcidir.
    $chain = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $candidate = $chain[$i];
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            break;
        }
        if (!in_array($candidate, $trustedProxies, true)) {
            return $candidate;
        }
    }

    return $remote;
}
```

---

### N4. 1. turdan kalma düz metin parolalar — ORTA (doğrulanmadı, veriye bağlı)

**Konum:** migrate_md5_passwords.php:25

1. tur raporuna göre (reviews/security.md, #2) eski kod parolaları md5'in yanında **düz metin** olarak da karşılaştırıyordu. Bu, DB'de düz metin saklanmış parolalar olabileceği anlamına gelir. Yeni kod bu satırları doğrulayamıyor (kullanıcı giremiyor, fail-closed). Migration da yalnızca `^[0-9a-fA-F]{32}$` desenine dokunuyor; düz metin parolalar DB'de, yedeklerde ve replikalarda süresiz kalıyor. Ayrıca 32 karakterlik hex bir düz metin parola yanlışlıkla md5 sanılıp sarılır; o kullanıcı da giremez (veri doğruluğu, güvenlik etkisi yok).

**Düzeltme:** Betiğe, bilinen hiçbir biçime uymayan satırları bulan ve sarmalayan bir adım ekleyin. Kullanıcının parolası değişmez, sadece düz metin ortadan kalkar:

```php
// migrate_md5_passwords.php — md5 döngüsünden sonra
$rows = $db->query(
    "SELECT id, password FROM users
     WHERE password NOT REGEXP '^[0-9a-fA-F]{32}$'
       AND password NOT LIKE '\$2y\$%'
       AND password NOT LIKE '\$argon2%'
       AND password NOT LIKE 'legacy-md5:%'"
);
$plain = 0;
foreach ($rows as $row) {
    $userId = (int) $row['id'];
    $newHash = password_hash((string) $row['password'], PASSWORD_DEFAULT);
    $update->bind_param('si', $newHash, $userId);   // $update döngüden önce kapatılmamalı
    $update->execute();
    $plain++;
}
$rows->free();
echo "Hashed {$plain} plaintext password(s).\n";
```

Eski md5 ve düz metin değerler binlog, yedek ve replikalarda kalmaya devam eder. Yedek saklama süresi dolunca bu kopyalar da temizlenmeli ve md5 kullanıcılarına parola değişikliği önerilmeli.

---

### N5. `DUMMY_HASH` maliyeti sabit — DÜŞÜK

**Konum:** AuthService.php:24

`DUMMY_HASH` cost 10. PHP 8.2'de `PASSWORD_DEFAULT` cost'u da 10, dolayısıyla şu an süreler eşit. **PHP 8.4'te bcrypt varsayılan cost'u 12'ye çıktı.** Yükseltmeden sonra `upgradeHashIfNeeded` kullanıcı hash'lerini girişte cost 12'ye taşır, olmayan kullanıcılar ise cost 10 ile yaklaşık 4 kat hızlı yanıt alır. Bu, Y5'in kapattığı kullanıcı adı numaralandırmasını yeniden açar. `legacy-md5:` hash'leri de betiği çalıştıran sürümün cost'unda kalır.

**Düzeltme:** Cost'u tek yerde sabitleyin ve sahte hash'i aynı ayarla çalışma anında üretin:

```php
private const HASH_OPTIONS = ['cost' => 12];

private static function dummyHash(): string
{
    static $hash = null;

    return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, self::HASH_OPTIONS);
}
// password_hash(..., PASSWORD_BCRYPT, self::HASH_OPTIONS) ve
// password_needs_rehash($h, PASSWORD_BCRYPT, self::HASH_OPTIONS) her yerde aynı sabitle çağrılsın.
```

Not: `static` önbellek yalnızca istek boyunca geçerlidir, yani her istekte bir ek `password_hash` çalışır. Bunun yerine sabit, aynı cost'la üretilmiş bir hash'i yükseltme sırasında güncellemek de yeterlidir. Önemli olan, cost değişince bu hash'in de güncellenmesidir.

---

### N6. `Throttle::hit` atomik değil — DÜŞÜK

**Konum:** Throttle.php:26-43

Artırma (ODKU) ve okuma (`SELECT`) iki ayrı autocommit sorgu. Aradaki boşlukta:
- Başka bir istek sayacı artırırsa okunan değer daha büyük olur. Bu güvenli yönde bir hata.
- `clear()` (aynı kullanıcı+IP çiftinin eşzamanlı başarılı girişi) satırı silerse `fetch_row()` `null` döner. `ev_purge_login_throttle` bu yarışa giremez: upsert satırı taze bıraktığı için purge'ün 1 günlük eşiğine takılmaz (perf3 düzeltmesi). `null[0]` uyarı üretir; `display_errors=1` ise dosya yolu ekrana basılır (N7). Sonuç `0` olur ve istek limitten geçer. Etkisi küçük, ama hata yönetimi açısından sessiz bir yol.

Ayrıca REPEATABLE READ altında, var olmayan anahtarlar için ODKU gap/next-key kilidi alır ve eşzamanlı yeni anahtar eklemeleri deadlock (1213) üretebilir (perf3 P7; InnoDB'de bilinen davranış, bu kurulumda **doğrulanmadı**). Retry olmadığı için sonuç 500 hatasıdır, N1 düzeltilmezse de log'a parola yazılır. perf3'ün önerdiği `READ COMMITTED` + 1213'te tek retry bu yolu kapatır.

**Düzeltme:** Değeri aynı sorguda `LAST_INSERT_ID(expr)` ile alın. Bu, bağlantıya özgü ve atomik:

```php
public function hit(string $key, int $windowSeconds): int
{
    $hashedKey = hash('sha256', $key);

    $stmt = $this->db->prepare(
        'INSERT INTO login_throttle (throttle_key, hits, window_start)
         VALUES (?, LAST_INSERT_ID(1), NOW())
         ON DUPLICATE KEY UPDATE
             hits = LAST_INSERT_ID(IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1)),
             window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)'
    );
    $stmt->bind_param('sii', $hashedKey, $windowSeconds, $windowSeconds);
    $stmt->execute();
    $stmt->close();

    $row = $this->db->query('SELECT LAST_INSERT_ID()')->fetch_row();

    return (int) ($row[0] ?? PHP_INT_MAX);   // okunamazsa güvenli tarafta kal: engelle
}
```

---

### N7. Hata gösterimi ve erken hatalar — DÜŞÜK

**Konum:** login.php:33-37, src/bootstrap.php

- `display_errors` kodda kapatılmıyor; yerel ini'de `1`. `catch (Throwable)` yalnızca istisnaları yakalar. Warning ve notice'ler (N6'daki `null[0]`, `session_start` uyarıları) tampona yazılır ve sayfayla birlikte, dosya yollarını içerecek şekilde kullanıcıya gider.
- `Config::fromEnv()` (login.php:36), `sendSecurityHeaders()`'tan önce çalışıyor. Env eksikse hata sayfası CSP/XFO olmadan gönderiliyor. Sayfa statik olduğu için etkisi düşük.
- `bootstrap.php` yüklenirken oluşan hatalar `ob_start` ve `try` dışında kalıyor.
- `expose_php=1`: `X-Powered-By: PHP/8.2.12` sürüm bilgisini sızdırıyor (ini ayarı).

**Düzeltme:**

```php
// src/bootstrap.php — en başta
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);   // try/catch (Throwable) yakalar
});

// login.php — başlıkları config'den bağımsız olarak önce gönder
ob_start();
sendSecurityHeaders(getenv('COOKIE_SECURE') !== '0');
try {
    $config = Config::fromEnv();
    startSession($config->secureCookies);
    // ...
```

php.ini: `expose_php = Off`, `zend.exception_ignore_args = On`.

---

### N8. Oturum yaşam döngüsü eksikleri — DÜŞÜK

**Konum:** functions.php:39-64, login.php:119-123

- **Mutlak süre yok:** her 29 dakikada bir istek atan bir oturum (ya da çalınmış bir session ID) süresiz geçerli kalır.
- **`__Host-` öneki yok:** cookie adı `PHPSESSID`. Alt alan adını kontrol eden biri `Domain=.example.com` ile cookie enjekte edebilir. `use_strict_mode` uydurma ID'leri reddeder, ama saldırganın sunucudan aldığı geçerli bir ID'yi reddetmez. CSRF yenilemesi (Y6) asıl riski kapatıyor; bu ek bir savunma katmanı.
- **Silinen kullanıcı:** `user_id` session'da kaldıkça kullanıcı "giriş yapmış" görünür. Roller boş gelir, ama hesap silinmiş veya devre dışı bırakılmış olsa da oturum düşmez.

```php
function startSession(bool $secureCookie): void
{
    ini_set('session.use_strict_mode', '1');
    session_name($secureCookie ? '__Host-sid' : 'sid');   // __Host- yalnızca Secure ile çalışır
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $secureCookie,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

const ABSOLUTE_TIMEOUT_SECONDS = 43200; // 12 saat

function enforceTimeouts(int $idleSeconds, int $absoluteSeconds): void
{
    $now = time();
    $last = $_SESSION['last_activity'] ?? null;
    $created = $_SESSION['created_at'] ?? null;
    if ((is_int($last) && $now - $last > $idleSeconds)
        || (is_int($created) && $now - $created > $absoluteSeconds)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
    $_SESSION['created_at'] ??= $now;   // renewSession() girişte bunu da sıfırlamalı
}
```

Kullanıcının hâlâ var olduğunu kontrol etmek için rolleri okuyan sorgu genişletilebilir. Kullanıcı yoksa `$_SESSION = []; renewSession();` çağrılır.

---

### N9. Sabit pencere sınırında 2 kat deneme — DÜŞÜK

**Konum:** Throttle.php:30-31

Sabit pencerede saldırgan, pencerenin son saniyesinde 5 ve yeni pencerenin ilk saniyesinde 5 deneme yaparak birkaç saniyede 10 deneme yapabilir (IP için 100). Ortalama hız yine 5/15 dk ile sınırlı; bu yüzden etkisi düşük ve kabul edilebilir bir tasarım tercihi. Daha sıkı bir sınır gerekirse iki pencereli ağırlıklı sayım (sliding window counter) kullanılabilir. N2'deki hesap başına sınır bu riski de azaltır.

---

### N10. Denetim izi boşlukları — DÜŞÜK

**Konum:** AuthService.php:43-45, 50

- `Throttled` dönen denemeler `login_log`'a yazılmıyor. Saldırı sırasında hangi hesabın hedeflendiği yalnızca `login_throttle`'daki sha256 anahtarlarında kalıyor; bunlar da raporlanabilir değil. Log şişmesini önlemek için anahtar başına pencerede bir kez loglamak yeterli: `hit()` tam limit+1 döndüğünde.
- Kullanıcılar parolayı yanlışlıkla kullanıcı adı alanına yazdığında, parola `[A-Za-z0-9_]{3,32}` desenine uyuyorsa `login_log.username`'e düz metin olarak yazılır. Bu her login log'unda olan bilinen bir risk. `login_log`'a erişimi kısıtlı tutun; 90 günlük saklama süresi (schema.sql:78-80) bunu sınırlıyor.

```php
if ($ipHits > self::LOGIN_IP_LIMIT || $pairHits > self::LOGIN_PAIR_LIMIT) {
    if ($ipHits === self::LOGIN_IP_LIMIT + 1 || $pairHits === self::LOGIN_PAIR_LIMIT + 1) {
        $this->logAttempt($isWellFormed ? $username : '', $ip, false);   // pencere başına bir kez
    }
    return LoginOutcome::failure(LoginResult::Throttled);
}
```

---

### N11. Operasyonel adımlar koda bağlı değil — DÜŞÜK

**Konum:** schema.sql:5-9, 78-94

- `app_login` kullanıcısı ve GRANT'ler yorum satırı. Uygulamanın `root` ile çalışmadığı garanti değil (Y8). GRANT içeriği doğru ve minimum; asıl sorun uygulanmamış olması.
- `ev_purge_*` event'leri `event_scheduler=ON` olmadan çalışmaz; `login_log` sınırsız büyür. Disk tükenmesi kendi başına bir DoS yolu, özellikle N2'deki IPv6 rotasyonuyla.
- `migrate_md5_passwords.php` web kökünde. CLI kontrolü (satır 18-21) doğru, ama tek seferlik bir yönetim betiği web kökünde durmamalı.
- schema.sql:7-8'deki sıra çelişkili: adım 2 betiği "uygulamayla aynı DB_* değişkenleriyle" çalıştırıyor, uygulama kullanıcısı ise adım 3'te oluşturuluyor. Bu, betiğin admin/root kimlik bilgileriyle çalıştırılmasını ve bu bilgilerin ortam değişkeninde kalmasını teşvik ediyor.

**Düzeltme:** GRANT bloğunu ayrı bir `grants.sql` dosyasına, parola yer tutucusuyla birlikte çalıştırılabilir hâlde koyun. Uygulama açılışında bir kez `SELECT CURRENT_USER()` kontrolü ile `root` bağlantısını reddedin:

```php
// src/Database.php — connect() sonunda
$current = (string) $db->query('SELECT CURRENT_USER()')->fetch_row()[0];
if (str_starts_with($current, 'root@')) {
    throw new \RuntimeException('Refusing to run as the MySQL root user');
}
```

Betiği web kökünün dışına taşıyın (ör. `bin/`). schema.sql'deki sırayı "önce kullanıcı ve GRANT, sonra betik" olacak şekilde düzeltin.

---

### N12. IP limiti aşıldıktan sonra da pair sayacı yazılıyor — ORTA

**Konum:** AuthService.php:40-45; Throttle.php:10 (yorum); schema.sql:82-84
**Kaynak:** perf3 raporu, P1. Kodda doğrulandı.

`attemptLogin` iki sayacı da karar vermeden önce artırıyor. IP limiti (50) aşılmış olsa bile her istek `login-pair:<kullanıcı>|<ip>` için bir upsert yapıyor. Saldırgan her istekte farklı, geçerli biçimli bir kullanıcı adı gönderirse **tek bir IP'den her istekte yeni bir satır** oluşur. Temizlik event'i saatte en fazla 10.000 satır siliyor, o da yalnızca 1 günden eski olanları. Tablo ve indeksler sınırsız büyür; bu disk ve buffer pool tüketmeye dayalı bir DoS yüzeyi. Throttle.php:10'daki "table stays small even under attack" varsayımı bu yüzden doğru değil. IPv6 rotasyonu (N2) IP satırları için de aynı etkiyi yaratır; /64 normalizasyonu bunu sınırlar.

**Düzeltme:** IP limitini önce kontrol edin, aşıldıysa başka anahtar yazmadan dönün (kod N2'deki örnekte). Böylece bir IP, pencere başına en fazla ~LIMIT+1 pair satırı oluşturabilir. Temizlik event'ini pencere uzunluğuna yaklaştırın:

```sql
CREATE EVENT IF NOT EXISTS ev_purge_login_throttle
    ON SCHEDULE EVERY 5 MINUTE
    DO DELETE FROM login_throttle
       WHERE window_start < NOW() - INTERVAL 1 HOUR   -- en uzun pencere: kayıt, 3600 sn
       ORDER BY window_start
       LIMIT 10000;
```

---

## 4. Öncelikli aksiyon listesi

1. Parola alan tüm metotlara `#[\SensitiveParameter]` ekleyin ve üretimde `zend.exception_ignore_args=On` yapın (N1).
2. Throttle'da IP limitini önce kontrol edin (N12). IPv6'yı /64'e indirgeyin, hesap başına yüksek bir global sınır ekleyin (N2). Proxy arkasındaysanız güvenilir proxy listesi tanımlayın (N3).
3. Migration'ı düz metin satırları kapsayacak şekilde genişletip çalıştırın; GRANT'leri gerçekten uygulayın ve event scheduler'ı açın (N4, N11, Y4, Y8).
4. `display_errors=0` ve warning → exception dönüşümü (N7); `Throttle::hit`'i `LAST_INSERT_ID` ile atomik yapın (N6).
5. Bcrypt cost'unu tek sabitte toplayın (N5); mutlak oturum süresi ve `__Host-` öneki ekleyin (N8).

---

## Takım tartışmasından notlar

**Gönderdiklerim:** perf3 ve qual3'e birer özet ve birer değerlendirme mesajı gönderdim (2 tur). Özette N1–N7'yi ve onların alanına dokunan noktaları işaret ettim:
- **perf3'e:** `Throttle::hit` her çağrıda 2 sorgu, her girişte 4 round-trip; her GET'te `last_activity` yazımı; IP sayacının başarılı girişleri de sayması; ODKU kilit/deadlock olasılığı; event scheduler kapalıysa sınırsız büyüme.
- **qual3'e:** register dalında kapalı session; `fetch_row()[0]` null kontrolü yok; schema.sql adım sırası çelişkisi; GRANT'lerin yorum satırı olması.

perf3 ve qual3'ün özet mesajları raporum yazıldıktan sonra geldi. İçerikleri, raporlarındaki (`reviews/round3/performance.md`, `quality.md`) "sec3'e" notlarıyla aynıydı. Bu notları zaten okuyup kodda doğrulamıştım. perf3 ayrıca ipuçlarımı doğruladığını bildirdi: `fetch_row` null yarışı P3'e, `last_activity` yazımı yeni bulgu P10'a, event_scheduler notu P6'ya eklendi. perf3'ün iki ek notu:
- **Purge yarışı:** `fetch_row` null yarışına purge event'inin giremeyeceğini, yalnızca `clear()`'ın tetikleyebileceğini belirtti. Doğru; N6 buna göre düzeltildi.
- **Proxy senaryosu (N3):** Tek `login-ip` satırının tüm giriş trafiği için sıcak kilit olacağını ekledi. Bu, N3'ün etkisini performans tarafında da büyütüyor.

**perf3'ten gelenler:**
- **P1 (IP limiti aşılsa da pair satırı yazılıyor):** Doğru (AuthService.php:40-45). Rapora **N12 (Orta)** olarak eklendi; N2'deki örnek kod buna göre sıralandı. perf3 bunu Yüksek değerlendiriyor. Güvenlik açısından Orta bıraktım: kimlik doğrulamayı etkilemiyor ve kaynak tüketimi saldırganın kendi istek hızıyla sınırlı. Kaynak tüketimi açısından perf3'ün değerlendirmesi geçerli.
- **P7 (ODKU deadlock → 500):** InnoDB davranışı olarak doğru, bu kurulumda doğrulanmadı. N6'ya bağlandı. N1 düzeltilmezse deadlock log'a parola yazdırır. `READ COMMITTED` + tek retry destekleniyor.
- **`DUMMY_HASH` cost'u:** Bağımsız olarak aynı sonuca vardık (N5).
- **Migration sürerken md5 kullanıcılarının girememesi (P2):** Kullanılabilirlik sorunu; güvenlik açısından fail-closed, açık değil. N4'teki düz metin adımı bcrypt sayısını artırır, P2 planlamasına dahil edilmeli.
- **Kilitli isteklerin `login_log`'a yazılmaması:** N10 ile aynı.
- **P4(b) rol önbelleği (60 sn):** Güvenlik kararı olarak bu uygulamada kabul edilebilir, iki koşulla: giriş ve çıkışta önbellek kesin temizlenmeli, admin yetkisi düşürme gibi anında etki gereken durumlar varsa (a) seçeneği (her istekte oku, ama session kilidini önce bırak) tercih edilmeli.

**qual3'ten gelenler:**
- **`DUMMY_HASH` cost'u sabit:** N5.
- **Şablonun yerel kapsamı (`$config->dbPass` dahil) görmesi (qual3 N2):** Doğru (login.php:127). Şablon bu değişkeni basmadığı için şu an istismar yolu yok. Gelecekte bir `var_dump`/debug çıktısı DB parolasını sızdırabilir; savunma derinliği açısından qual3'ün açık değişken aktarımı önerisi destekleniyor.
- **Register yolunun kapalı session ile devam etmesi (qual3 N3):** Doğru. Güvenlik etkisi yok, çünkü CSRF token o noktada session'da mevcut. qual3, PHP 8.2.12'de ikinci `session_write_close()`'un uyarısız `false` döndüğünü test etmiş.
- **`LoginOutcome::failure(LoginResult::Success)` (qual3 N1):** `$outcome->user` null olur, login.php:73'te `Error` → 500. Fail-closed; istismar edilemez. Tip güvenliği önerisi destekleniyor.
- **`COOKIE_SECURE=0`'ın HSTS'yi de kapatması:** Davranış güvenli (varsayılan açık); yalnızca isim yanıltıcı.
- **Kod geri alınmadan şema geri alınırsa tekrar eden kullanıcı adı (qual3 N17):** Güvenlik açısından önemli: `UNIQUE` index kalkar, yeni kod `1062`'ye güvenerek aynı adla ikinci hesap açtırır, `findUserByUsername` ilk satırı döndürür ve hesap karışıklığı olur. schema_rollback.sql başına "önce uygulama kodunu geri alın" uyarısı eklenmeli.

**Ortak sonuç:** Üç rapor da `login_throttle` tasarımının doğru yönde olduğu konusunda birleşiyor. Kalan işlerin çoğu aynı yerde kesişiyor: sayaç sırası ve atomiklik (N6, N12 / P1, P3, P7), cost tutarlılığı (N5) ve migration/GRANT gibi operasyonel adımlar (N4, N11 / P2, P5, Y12).
