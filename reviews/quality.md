# login.php — Kod Kalitesi İncelemesi

**İnceleyen:** quality
**Kapsam:** Okunabilirlik, PSR-1/PSR-12 uyumu, isimlendirme, sorumlulukların ayrılması, hata yönetimi, tekrar eden kod.
**Not:** Dosya değiştirilmedi; yalnızca okundu.

## Özet

| # | Satır | Konu | Önem |
|---|-------|------|------|
| 1 | 1–101 | Tek dosyada DB erişimi, iş mantığı, yönlendirme ve HTML karışık | Yüksek |
| 2 | 4–42, 69–78 | Fonksiyon isimlendirmesi tutarsız (snake_case, Pascal_Snake, camelCase) | Orta |
| 3 | 5, 19 | Değişken isimlendirmesi tutarsız (`$Conn`, `$Password`) | Düşük |
| 4 | 13–16, 20–23, 55, 69–78 | Girinti tutarsız: 2 boşluk, tab, 4 boşluk karışık; fonksiyon gövdesi girintisiz | Orta |
| 5 | 4, 12, 19, 26, 33, 69 | Fonksiyon açılış süslü parantezi aynı satırda (PSR-12 yeni satır ister) | Düşük |
| 6 | 22–23 | `else` süslü parantezsiz; gereksiz if/else ile `true/false` dönüşü | Orta |
| 7 | 69 | Parametreler ve operatörler etrafında boşluk yok | Düşük |
| 8 | 5–7, 13, 27, 34, 38, 70 | Her fonksiyon yeni DB bağlantısı açıyor; bağlantı enjekte edilmiyor | Yüksek |
| 9 | 7, 15–16, 30, 36, 76 | Hata yönetimi yok / `die()` ile ham hata; sorgu sonuçları kontrol edilmiyor | Yüksek |
| 10 | 14, 29, 36, 38, 61, 76 | SQL string birleştirme ile kuruluyor; sorgu metni satır 61'de tekrar ediliyor | Yüksek |
| 11 | 20, 44, 73 | Gevşek karşılaştırma (`==`) | Orta |
| 12 | 20, 75 | Şifre doğrulama mantığı dağınık ve tekrar ediyor (md5 iki yerde) | Yüksek |
| 13 | 44–67, 80–83 | Fonksiyon tanımları ile yan etkili kod iç içe (PSR-1 ihlali), `register_user` çağrıdan sonra tanımlı | Orta |
| 14 | 55–57, 61, 66, 88–89 | HTML `echo` ile üretiliyor; çıktı kaçışlanmıyor; `<li>` `<ul>` olmadan | Orta |
| 15 | 81–82 | `register_user` dönüş değeri yok sayılıyor; `header()` sonrası `exit` yok | Yüksek |
| 16 | 45–46, 81 | `$_POST` anahtarları varlık kontrolü olmadan okunuyor (Undefined index uyarıları) | Orta |
| 17 | 84 | Kapanış `?>` sonrası HTML var — sorun değil, ama mantık bloğu şablondan ayrılmalı | Düşük |
| 18 | 85–101 | HTML belgesi eksik: `<!DOCTYPE>`, `lang`, `<meta charset>`, `<label>` yok | Düşük |
| 19 | 5 | Sihirli değerler / sabit kodlanmış yapılandırma | Orta |
| 20 | 35 | Eski `array()` sözdizimi; tip bildirimleri ve `declare(strict_types=1)` yok | Düşük |

---

## Bulgular

### 1. Sorumlulukların ayrılmaması (Satır 1–101) — **Yüksek**
Tek dosya; veritabanı bağlantısı, veri erişimi, kimlik doğrulama, loglama, oturum yönetimi, yönlendirme ve HTML şablonunu bir arada barındırıyor. Test edilemez, yeniden kullanılamaz.

**Öneri:** En azından şu katmanlara bölün:

```
config/database.php        -> bağlantı ayarları (env'den)
src/Database.php           -> mysqli bağlantı fabrikası (tek bağlantı)
src/UserRepository.php     -> kullanıcı sorguları
src/AuthService.php        -> login/register mantığı
public/login.php           -> controller: isteği al, servisi çağır, şablonu render et
templates/login.php        -> sadece HTML
```

```php
<?php

declare(strict_types=1);

// public/login.php (ince controller)
require __DIR__ . '/../vendor/autoload.php';

session_start();

$db = Database::connect(require __DIR__ . '/../config/database.php');
$auth = new AuthService(new UserRepository($db), new LoginLogger($db));

$viewData = ['error' => null, 'user' => null, 'roles' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $viewData = $auth->handleLogin($_POST['username'] ?? '', $_POST['password'] ?? '');
}

require __DIR__ . '/../templates/login.php';
```

### 2. Fonksiyon isimlendirmesi tutarsız (Satır 4, 12, 19, 26, 33, 69) — **Orta**
`db_connect`, `Get_User`, `check_password`, `logLoginAttempt`, `get_user_roles`, `register_user` — dört farklı stil. PSR-1 metotlar için `camelCase` ister; fonksiyonlarda da tek bir stil seçilmeli.

```php
function connectDatabase(array $config): mysqli { /* ... */ }
function findUserByUsername(mysqli $db, string $username): ?array { /* ... */ }
function verifyPassword(array $user, string $password): bool { /* ... */ }
function logLoginAttempt(mysqli $db, string $username, bool $success): void { /* ... */ }
function findUserRoles(mysqli $db, int $userId): array { /* ... */ }
function registerUser(mysqli $db, string $username, string $password, string $email): bool { /* ... */ }
```

### 3. Değişken isimlendirmesi tutarsız (Satır 5, 19) — **Düşük**
`$Conn` ve `$Password` büyük harfle başlıyor; diğer yerlerde `$conn`, `$password`. Ayrıca `$q`, `$r`, `$res`, `$u` gibi tek harfli/anlamsız isimler (29, 36, 38, 72) okunabilirliği düşürüyor.

```php
$connection = mysqli_connect(/* ... */);
function verifyPassword(array $user, string $password): bool
$roleRows = ...;   // $res yerine
$existingUser = ...; // $u yerine
```

### 4. Girinti tutarsızlığı / tab-boşluk karışımı (Satır 13–16, 20–23, 55, 70–77) — **Orta**
- 13–16: 2 boşluk girinti.
- 20–23: **tab** karakteri (PSR-12: 4 boşluk, tab yasak).
- 55: `echo` 6 boşlukla girintilenmiş, çevresi 8 boşluk.
- 70–77: `register_user` gövdesi hiç girintilenmemiş, 73 ise 2 boşluk.

**Öneri:** Tüm dosyada 4 boşluk; `php-cs-fixer` veya `phpcbf --standard=PSR12` ile otomatik düzeltme ve CI'da kontrol.

```php
function registerUser(mysqli $db, string $username, string $password, string $email): bool
{
    if (usernameExists($db, $username)) {
        return false;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $hash, $email);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}
```

### 5. Fonksiyon süslü parantez konumu (Satır 4, 12, 19, 26, 33, 69) — **Düşük**
PSR-12: fonksiyon/sınıf açılış süslü parantezi **bir sonraki satırda** olmalı; kontrol yapılarında (if/while) ise aynı satırda.

```php
function connectDatabase(array $config): mysqli
{
    // ...
}
```

### 6. `else` süslü parantezsiz ve gereksiz dallanma (Satır 20–23) — **Orta**
PSR-12 tüm kontrol yapılarında süslü parantez ister; ayrıca `} else` aynı satırda olmalı. Boolean ifadeyi `if` ile `true/false`'a çevirmek gereksiz.

```php
function verifyPassword(array $user, string $password): bool
{
    return password_verify($password, $user['password']);
}
```

### 7. Boşluk kullanımı (Satır 69–76) — **Düşük**
`register_user($username,$password,$email){`, `$conn=db_connect();`, `while($u = ...)`, `mysqli_query($conn,"INSERT...")` — virgül sonrası, `=` çevresi ve anahtar kelime sonrası (`while (`) boşluk PSR-12 zorunluluğu.

```php
function usernameExists(mysqli $db, string $username): bool
{
    $stmt = $db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}
```

### 8. Her fonksiyon yeni bağlantı açıyor (Satır 13, 27, 34, 38, 70) — **Yüksek**
`db_connect()` her çağrıda yeni bağlantı oluşturuyor; satır 38'de döngü içinde bile. Açılan bağlantılar hiç kapatılmıyor (`mysqli_close`) ve sonuç setleri serbest bırakılmıyor (`mysqli_free_result`) — performance'ın işaretiyle eklendi; kaynak yaşam döngüsü kodda hiç yönetilmiyor. Bu hem gizli bağımlılık (test edilemez) hem de kaynak israfı. Bağlantı bir kez oluşturulup parametre/constructor ile verilmeli.

```php
final class UserRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, password FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $result->free();
        $stmt->close();

        return $user ?: null;
    }
}
```

### 9. Hata yönetimi eksik (Satır 7, 15–16, 30, 36–39, 76) — **Yüksek**
- 7: `die()` ile ham DB hatası kullanıcıya basılıyor; uygulama akışı kontrolsüz sonlanıyor.
- 15–16, 36–39: `mysqli_query` `false` dönerse `mysqli_fetch_assoc(false)` hata verir; kontrol yok.
- 30, 76: INSERT sonucu kontrol edilmiyor; kayıt başarısız olsa da `true` dönüyor (77).

**Öneri:** mysqli'yi exception moduyla (`mysqli_report`) kullanın, tek bir yerde yakalayıp loglayın, kullanıcıya genel mesaj gösterin.

```php
final class Database
{
    private static ?mysqli $connection = null;

    public static function connect(array $config): mysqli
    {
        if (self::$connection === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$connection = new mysqli(
                $config['host'],
                $config['user'],
                $config['password'],
                $config['database']
            );
            self::$connection->set_charset('utf8mb4');
        }

        return self::$connection;
    }
}

try {
    $db = Database::connect($config);
} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Servis geçici olarak kullanılamıyor.');
}
```

### 10. SQL string birleştirme ve tekrar (Satır 14, 29, 36, 38, 61, 76) — **Yüksek**
Tüm sorgular string birleştirme/interpolasyon ile kuruluyor (güvenlik detayı için security raporuna bakınız). Kalite açısından: üç farklı stil karışık (`.` birleştirme, `"...'$var'..."` interpolasyon, inline argüman) ve satır 61'de sorgu metni **kopyalanarak** ekrana basılıyor (DRY ihlali + bilgi sızıntısı). Tek tip prepared statement kullanılmalı; sorgu kullanıcıya asla gösterilmemeli. Ayrıca `SELECT *` (14, 38, 71) kodu tablo şemasına örtük olarak bağlıyor ve hangi kolonların kullanıldığını gizliyor (performance da bu noktayı işaret etti); gereken kolonlar açıkça yazılmalı.

```php
function logLoginAttempt(mysqli $db, string $username, bool $success): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $successFlag = (int) $success;

    $stmt = $db->prepare(
        'INSERT INTO login_log (username, ip, success, created_at) VALUES (?, ?, ?, NOW())'
    );
    $stmt->bind_param('ssi', $username, $ip, $successFlag);
    $stmt->execute();
    $stmt->close();
}
```

### 11. Gevşek karşılaştırma `==` (Satır 20, 44, 73) — **Orta**
Tip dönüşümü sürprizlerine açık (özellikle satır 20'de hash karşılaştırması: `"0e123..." == "0e456..."` → `true`). Her yerde `===` kullanılmalı; şifre için `password_verify`/`hash_equals`.

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
```

### 12. Şifre mantığı dağınık ve tekrarlı (Satır 20, 54, 75) — **Yüksek**
`md5` hem 20'de hem 75'te ayrı ayrı çağrılıyor; 20 ayrıca düz metin şifreyi de kabul ediyor (iki farklı "format" desteği belgelenmemiş). Hash'leme tek bir yerde, `password_hash`/`password_verify` ile yapılmalı; eski md5 kayıtları için bilinçli bir migrasyon yolu yazılmalı. Satır 54'te hash'in çereze yazılması da aynı mantığın sızması.

```php
final class PasswordHasher
{
    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
```

### 13. Bildirim ve yan etki karışımı (Satır 44–67, 69–78, 80–83) — **Orta**
PSR-1: "Bir dosya ya sembol (fonksiyon/sınıf) bildirmeli ya da yan etki üretmeli, ikisini birden değil." Burada fonksiyonlar, çalıştırılan kod ve HTML iç içe. `register_user` (69) login işleme bloğundan sonra, kendi çağrısından hemen önce tanımlanmış — okuyucu akışı takip edemiyor. Ayrıca kayıt formu gönderildiğinde de `REQUEST_METHOD == 'POST'` bloğu (44) çalışıyor; iki form tek bir `if` ile ayrıştırılmıyor → kayıt sırasında boş kullanıcı adıyla login denemesi loglanıyor.

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    match ($action) {
        'login' => $controller->login($_POST),
        'register' => $controller->register($_POST),
        default => http_response_code(400),
    };
}
```

### 14. HTML'in `echo` ile üretilmesi ve kaçışsız çıktı (Satır 55–57, 61, 66, 88–89) — **Orta**
- HTML parçaları PHP string'leri içinde, inline `style` ile (61).
- 57: `<li>` öğeleri `<ul>` olmadan ve `<html>` etiketinden **önce** basılıyor → geçersiz HTML.
- Hiçbir çıktı `htmlspecialchars` ile kaçışlanmıyor (XSS — security alanı).

**Öneri:** Şablon dosyası + kaçış yardımcı fonksiyonu.

```php
<?php function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); } ?>

<?php if ($user !== null): ?>
    <h2>Tekrar hoş geldin, <?= e($user['username']) ?>!</h2>
    <ul>
        <?php foreach ($roles as $role): ?>
            <li><?= e($role['name']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php elseif ($error !== null): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>
```

### 15. Dönüş değeri yok sayılıyor; `header()` sonrası `exit` yok (Satır 81–82) — **Yüksek**
`register_user` `false` dönse bile (kullanıcı adı alınmış) "Registered" mesajıyla yönlendiriliyor. `header('Location: ...')` sonrası `exit` olmadığından sayfa render edilmeye devam ediyor. Ayrıca URL query'si `urlencode` edilmemiş.

**Ek (security'nin işaretiyle):** Kayıt formu da POST olduğundan satır 44'teki login bloğu önce çalışıyor ve satır 61'de `echo` ile çıktı üretiyor; ardından satır 82'deki `header()` **"headers already sent"** uyarısı verip yönlendirme hiç gerçekleşmiyor. Yani mevcut haliyle kayıt sonrası yönlendirme fiilen bozuk. Çözüm: tüm işlem/yönlendirme mantığını herhangi bir çıktıdan önce çalıştırın, çıktıyı yalnızca şablonda üretin (Bulgu 1, 13, 14).

```php
if ($action === 'register') {
    $ok = registerUser($db, $newUsername, $newPassword, $email);
    $_SESSION['flash'] = $ok ? 'Kayıt başarılı.' : 'Bu kullanıcı adı zaten alınmış.';
    header('Location: login.php', true, 303);
    exit;
}
```

### 16. Girdi doğrulaması / varlık kontrolü yok (Satır 45–46, 81) — **Orta**
`$_POST['username']` vb. doğrudan okunuyor; alan yoksa `Undefined array key` uyarısı. Boş değer, uzunluk, e-posta formatı kontrol edilmiyor.

```php
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $error = 'Kullanıcı adı ve şifre zorunludur.';
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
if ($email === false) {
    $errors[] = 'Geçerli bir e-posta girin.';
}
```

### 17. `?>` ve şablon ayrımı (Satır 84) — **Düşük**
Sadece PHP içeren dosyalarda kapanış `?>` atlanmalı (PSR-12). Bu dosyada HTML takip ettiği için teknik olarak gerekli; ancak asıl sorun mantık ile şablonun aynı dosyada olması (bkz. Bulgu 1). Ayrıştırıldığında mantık dosyası `?>` içermemeli.

### 18. Eksik/geçersiz HTML yapısı ve erişilebilirlik (Satır 85–101) — **Düşük**
`<!DOCTYPE html>`, `lang`, `<meta charset="utf-8">` yok; form alanları `<label>` olmadan, `<br>` ile düzenleniyor; şifre alanları `type="text"` (90, 96); login formunda submit butonuna `name` yok, bu yüzden iki form ayırt edilemiyor.

```html
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Giriş</title>
</head>
<body>
<form method="post" action="login.php">
    <input type="hidden" name="action" value="login">
    <label for="username">Kullanıcı adı</label>
    <input type="text" id="username" name="username" autocomplete="username"
           value="<?= e($oldUsername) ?>" required>

    <label for="password">Şifre</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit">Giriş yap</button>
</form>
</body>
</html>
```

### 19. Sabit kodlanmış yapılandırma (Satır 5, 54) — **Orta**
Host, kullanıcı (`root`), şifre ve veritabanı adı kod içinde; `86400 * 30` sihirli sayı. Ortama göre değiştirilemez, sürüm kontrolüne sır sızar.

```php
// config/database.php
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'user' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'database' => getenv('DB_NAME') ?: 'app_db',
];

const REMEMBER_ME_TTL_SECONDS = 60 * 60 * 24 * 30;
```

### 20. Modern PHP özelliklerinin kullanılmaması (Satır 1, 35, tüm fonksiyonlar) — **Düşük**
`declare(strict_types=1)` yok, parametre/dönüş tipleri yok, `array()` yerine `[]` kullanılmalı, `mysqli` prosedürel API (`mysqli_query($conn, ...)`) yerine nesne yönelimli mysqli API'si (`$db->prepare(...)`) tercih edilmeli. Dosya başında PHPDoc/açıklama yok.

```php
<?php

declare(strict_types=1);

/**
 * @return list<array{id:int,name:string}>
 */
function findUserRoles(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT r.id, r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roles = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $stmt->close();

    return $roles;
}
```

---

## Önerilen araçlar
- `squizlabs/php_codesniffer` → `phpcs --standard=PSR12 login.php`
- `friendsofphp/php-cs-fixer` → otomatik biçimlendirme
- `phpstan/phpstan` (seviye 6+) → tip ve null hataları (ör. `mysqli_fetch_assoc(false)`)
- `.editorconfig` → `indent_style = space`, `indent_size = 4`

---

## Takım tartışmasından notlar

- **security'ye gönderilenler:** Satır 54 çerezde `username:hash` (secure/httponly/samesite yok), satır 61'de SQL sorgusunun ekrana basılması, satır 20'de düz metin şifre kabulü + md5 + `==` (magic hash), satır 82 `header()` sonrası `exit` eksikliği ve açık yönlendirme/CRLF, satır 88–89 XSS (`PHP_SELF`, `value`), şifre alanlarının `type="text"` olması, CSRF token ve `session_regenerate_id` eksikliği, satır 52'de şifre hash'i dahil tüm kullanıcı satırının session'a konması, satır 5 hardcoded `root` kimlik bilgisi ve satır 7 `die()` ile hata sızıntısı. Bu maddeleri raporumda kalite boyutuyla (hata yönetimi, sorumluluk ayrımı, DRY) ele aldım; güvenlik derinliği security raporuna bırakıldı.
- **performance'a gönderilenler:** Her fonksiyonda yeni DB bağlantısı (13, 27, 34, 38, 70), satır 36–40 N+1 sorgu (döngü içinde bir de yeni bağlantı), satır 71–74 tüm `users` tablosunun çekilip PHP'de aranması, `SELECT *` kullanımı. Kalite tarafında bağımlılık enjeksiyonu (Bulgu 8) ve JOIN'li sorgu örneği (Bulgu 20) ile karşılandı.
- **security'den gelen geri bildirim:** SQLi (14, 29, 36, 38, 76), XSS (55, 57, 61, 66, 82, 88–89), md5/düz metin parola, çerez, CSRF ve `session_regenerate_id` bulgularını teyit etti. Kalite alanına işaret ettiği noktaların çoğu (exit eksikliği, `die()`, `==`, dönüş değeri, `type="text"`, isset eksikliği, isimlendirme) raporda zaten vardı. **Yeni ve haklı nokta:** satır 61'deki `echo` sonrası satır 82'deki `header()` "headers already sent" hatası verir → Bulgu 15'e eklendi.
- **performance'tan gelen geri bildirim:** Bağlantı tekrarı, N+1, tam tablo taraması ve `SELECT *` bulgularımızla örtüştü. **Yeni ve haklı nokta:** bağlantılar kapatılmıyor, `mysqli_free_result` çağrılmıyor → Bulgu 8'e eklendi. İşaret ettiği DI/paylaşılan bağlantı, isimlendirme, hata kontrolü, fonksiyon sırası, exit eksikliği ve hardcoded kimlik bilgisi zaten Bulgu 2, 3, 8, 9, 13, 15, 19'da vardı.
- **performance'ın kalite alanına işaret ettiği maddelerin eşleşmesi:** isimlendirme tutarsızlığı (`Get_User`, `logLoginAttempt`, `get_user_roles`, `$Conn`/`$conn`, `$Password`) → Bulgu 2–3; tek paylaşılan bağlantı/DI → Bulgu 1, 8, 9 (`Database::connect` tek bağlantı döndürür); `mysqli_query` false dönünce `mysqli_fetch_assoc(false)` → Bulgu 9; `register_user` kullanıldıktan sonra tanımlanmış ve dönüş değeri yok sayılıyor (81) → Bulgu 13, 15; `header()` sonrası `exit` yok (82) → Bulgu 15; tab/boşluk karışımı → Bulgu 4; hardcoded kimlik bilgisi (5) → config/env'e taşıma, Bulgu 19. Ayrıca `SELECT *` kullanımının okunabilirlik etkisi Bulgu 10'a eklendi.
- **Not:** "performance" ilk mesaj gönderildiğinde aktif ajan listesinde görünmüyordu, ama mesaj iletildi ve yanıt geldi. Tartışma tek turda kapatıldı.
- **Tutarlılık düzeltmesi (team-lead talebi):** `login.php` mysqli kullandığından, düzeltme örneklerindeki PDO kodu nesne yönelimli **mysqli prepared statement** (`prepare` + `bind_param` + `execute` + `get_result`) ile değiştirildi; hatalar `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` ile exception'a çevriliyor. Böylece security ve performance raporlarındaki örneklerle aynı API kullanılıyor.
- **Örtüşme kararı:** SQL enjeksiyonu, XSS ve şifre saklama konuları üç raporda da geçebilir; kalite raporunda bunlar yalnızca "tutarsız stil / DRY / hata yönetimi" perspektifinden işaretlendi, çözüm kodlarında güvenli varsayılanlar (mysqli prepared statement, `password_hash`, `htmlspecialchars`) kullanıldı ki raporlar birbiriyle çelişmesin.
