# login.php — Performans İncelemesi

**İnceleyen:** performance
**Dosya:** `login.php` (102 satır, sadece okundu, değiştirilmedi)

## Özet

| # | Satır | Sorun | Etki |
|---|-------|-------|------|
| 1 | 4-10, 13, 27, 34, 38, 70 | Her fonksiyon yeni DB bağlantısı açıyor, hiçbiri kapatılmıyor | **Yüksek** |
| 2 | 36-40 | `get_user_roles` içinde N+1 sorgu (döngüde ayrıca yeni bağlantı) | **Yüksek** |
| 3 | 71-74 | `register_user` tüm `users` tablosunu çekip PHP'de arıyor | **Yüksek** |
| 4 | 14, 36, 71, 29 | İndeks ihtiyaçları (`users.username`, `user_roles.user_id`, `login_log`) | **Yüksek** |
| 5 | 14, 38, 71 | `SELECT *` kullanımı | Orta |
| 6 | 52-53 | Tüm kullanıcı satırı ve rol satırları session'a yazılıyor | Orta |
| 7 | 15-16, 36-39, 71 | Sonuç setleri serbest bırakılmıyor, hata kontrolü yok | Düşük-Orta |
| 8 | 2 | `session_start()` her istekte, session kilidi gereksiz yere tutuluyor | Düşük |
| 9 | 29 | `login_log` sınırsız büyüyor, senkron INSERT | Düşük-Orta |
| 10 | 82 | `header()` sonrası `exit` yok; gereksiz işlem/çıktı | Düşük |

Tek bir başarılı login isteğinde mevcut kod **3 + N** bağlantı ve **3 + N** sorgu üretiyor (N = kullanıcının rol sayısı). Önerilen düzeltmelerle bu **1 bağlantı ve 3 sorgu** olur.

---

## 1. Her çağrıda yeni veritabanı bağlantısı, bağlantılar kapatılmıyor — Yüksek

**Satırlar:** 4-10 (`db_connect`), 13, 27, 34, 38, 70

`Get_User`, `logLoginAttempt`, `get_user_roles` ve `register_user` her biri `db_connect()` çağırıyor. Satır 38'de ise **döngünün her turunda** yeni bağlantı açılıyor. Hiçbir bağlantı `mysqli_close()` ile kapatılmıyor; script bitene kadar açık kalıyorlar.

**Etkisi:** Her bağlantı TCP/soket el sıkışması + MySQL kimlik doğrulaması demek (tipik olarak 1-5 ms, uzak sunucuda daha fazla). Yük altında `max_connections` hızla dolar ("Too many connections" hatası), MySQL tarafında thread ve bellek tüketimi artar. 5 rolü olan bir kullanıcı için tek login = 8 bağlantı.

**Düzeltme:** İstek başına tek bir bağlantı (lazy singleton) kullanın ve fonksiyonlara parametre olarak geçin. Kimlik bilgilerini koddan çıkarın.

```php
<?php
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // hatalar exception olur
        $conn = new mysqli(
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_USER'),
            getenv('DB_PASS'),
            getenv('DB_NAME')
        );
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
```

İsteğe bağlı: Yüksek trafikte kalıcı bağlantı için host başına `p:` öneki (`new mysqli('p:localhost', ...)`) kullanılabilir; ancak transaction/kilit durumunun bağlantıda kalma riskine dikkat edilmeli.

---

## 2. `get_user_roles` içinde N+1 sorgu — Yüksek

**Satırlar:** 36-40

```php
$res = mysqli_query($conn, "SELECT role_id FROM user_roles WHERE user_id = " . $userId);
while ($row = mysqli_fetch_assoc($res)) {
    $r = mysqli_query(db_connect(), "SELECT * FROM roles WHERE id = " . $row['role_id']);
```

Önce rol id'leri çekiliyor, sonra her rol için ayrı sorgu (ve ayrı bağlantı!) atılıyor. N rol = N+1 sorgu + N+1 bağlantı.

**Etkisi:** Gecikme rol sayısıyla doğrusal artar; her tur bir ağ gidiş-dönüşü. Bağlantı sızıntısıyla birleşince en pahalı nokta burası.

**Düzeltme:** Tek JOIN sorgusu, prepared statement ile:

```php
function get_user_roles(mysqli $conn, int $userId): array {
    $stmt = $conn->prepare(
        'SELECT r.id, r.name
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $roles;
}
```

---

## 3. `register_user` tüm tabloyu tarıyor — Yüksek

**Satırlar:** 71-74

```php
$all = mysqli_query($conn, "SELECT * FROM users");
while($u = mysqli_fetch_assoc($all)) {
  if ($u['username'] == $username) { return false; }
}
```

Kullanıcı adının var olup olmadığını anlamak için **bütün `users` tablosu** (tüm sütunlarıyla) PHP'ye aktarılıyor.

**Etkisi:** O(n) veri transferi ve PHP belleği; `mysqli_query` varsayılan olarak tamponlu (buffered) olduğu için tüm sonuç seti PHP belleğine yüklenir → büyük tabloda `memory_limit` aşımı. 1M kullanıcıda her kayıt isteği yüzlerce MB okur. Ayrıca kontrol ile INSERT arasında yarış durumu (race condition) var; iki eşzamanlı istek aynı adı kaydedebilir.

**Düzeltme:** Kontrolü veritabanına bırakın: `UNIQUE` indeks + INSERT, çakışmada hata kodu 1062 yakalanır. Tek sorgu, yarış durumu yok.

```php
function register_user(mysqli $conn, string $username, string $password, string $email): bool {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $hash, $email);
    try {
        $stmt->execute();
        return true;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) { // Duplicate entry
            return false;
        }
        throw $e;
    } finally {
        $stmt->close();
    }
}
```

UNIQUE kısıt istenmiyorsa en azından:
```php
$stmt = $conn->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
```

---

## 4. İndeks ihtiyaçları — Yüksek

**Satırlar:** 14 (`users.username`), 36 (`user_roles.user_id`), 29 (`login_log`)

Kod şemayı göstermiyor ama sorgu desenleri şu indeksleri gerektiriyor. Bunlar yoksa her login'de `users` ve `user_roles` için tam tablo taraması yapılır.

```sql
-- Login araması ve kayıt kontrolü (aynı zamanda tekrarlı kullanıcı adını engeller)
ALTER TABLE users ADD UNIQUE INDEX ux_users_username (username);

-- Rol çözümleme: user_id ile filtre + role_id ile JOIN (kapsayan indeks)
ALTER TABLE user_roles ADD PRIMARY KEY (user_id, role_id);
-- (PK zaten başka şekilde tanımlıysa: ADD INDEX ix_user_roles_user (user_id, role_id))

-- roles.id muhtemelen zaten PRIMARY KEY; değilse:
-- ALTER TABLE roles ADD PRIMARY KEY (id);

-- Brute-force tespiti / raporlama sorguları için
ALTER TABLE login_log ADD INDEX ix_login_log_user_time (username, created_at),
                      ADD INDEX ix_login_log_ip_time (ip, created_at);
```

Not: `login_log` üzerindeki indeksler INSERT maliyetini biraz artırır; sadece bu tabloyu okuyan sorgular (rate limit vb.) varsa ekleyin. `ip` sütunu için `VARBINARY(16)` + `INET6_ATON()` kullanmak, string saklamaya göre daha küçük ve hızlı indeks sağlar.

---

## 5. `SELECT *` kullanımı — Orta

**Satırlar:** 14, 38, 71

Gerekmeyen sütunlar (ör. büyük profil alanları, `TEXT`/`BLOB`) ağ ve bellek üzerinden taşınıyor; kapsayan indeks (covering index) kullanımı engelleniyor; şema değişikliklerinde sessizce daha fazla veri çekiliyor.

**Düzeltme (satır 12-17):**

```php
function get_user(mysqli $conn, string $username): ?array {
    $stmt = $conn->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}
```

---

## 6. Session'a gereğinden fazla veri yazılıyor — Orta

**Satırlar:** 52-53

`$_SESSION['user'] = $user;` tüm kullanıcı satırını (`SELECT *` sonucu, şifre hash'i dahil) session'a koyuyor; roller de tam satır olarak saklanıyor. Session her istekte serileştirilip okunup yazıldığı için büyük session verisi tüm sayfaların maliyetini artırır (dosya I/O, Redis/Memcached trafiği). Güvenlik açısından da hash'in session'da durması istenmez.

**Düzeltme:**

```php
session_regenerate_id(true);
$_SESSION['user_id']  = (int)$user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['roles']    = array_column(get_user_roles($conn, (int)$user['id']), 'name', 'id');
```

---

## 7. Sonuç setleri serbest bırakılmıyor, hata kontrolü yok — Düşük-Orta

**Satırlar:** 15-16, 36-39, 71

`mysqli_free_result()` hiç çağrılmıyor; özellikle satır 71'deki tam tablo sonucu ve satır 38'deki döngü içi sonuçlar script sonuna kadar bellekte kalıyor. Ayrıca `mysqli_query` `false` dönerse `mysqli_fetch_assoc(false)` PHP 8'de `TypeError` fırlatır.

**Düzeltme:** Prepared statement + `$stmt->close()` (yukarıdaki örneklerde var) ve `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` ile hataları exception'a çevirin. Klasik sorgu kullanılacaksa:

```php
$res = $conn->query($sql);
$row = $res->fetch_assoc();
$res->free();
```

---

## 8. `session_start()` her istekte ve kilit tutuluyor — Düşük

**Satır:** 2

Varsayılan dosya tabanlı session handler, session dosyasını script bitene kadar kilitler. Sayfa sadece GET ile form gösterirken de session açılıyor; aynı kullanıcıdan gelen paralel istekler sıraya girer.

**Düzeltme:** Session'ı yalnızca gerektiğinde açın ve yazma bitince kapatın:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    // ... login işlemi, $_SESSION yazımı ...
    session_write_close(); // kilidi erken bırak
}
```

---

## 9. `login_log` sınırsız büyüme ve senkron yazım — Düşük-Orta

**Satır:** 29

Her deneme (başarısız dahil) senkron INSERT yapıyor ve hiç temizlenmiyor. Brute-force saldırısında tablo hızla büyür, indeksler şişer, yazma gecikmesi login yanıt süresine eklenir.

**Düzeltme:** Prepared statement, aynı bağlantıyı kullanma ve periyodik temizlik (veya tarih bazlı partition):

```php
function log_login_attempt(mysqli $conn, string $username, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ok = (int)$success;
    $stmt = $conn->prepare(
        'INSERT INTO login_log (username, ip, success, created_at) VALUES (?, INET6_ATON(?), ?, NOW())'
    );
    $stmt->bind_param('ssi', $username, $ip, $ok);
    $stmt->execute();
    $stmt->close();
}
```

```sql
-- Cron / MySQL EVENT ile eski kayıtları temizleme
DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY LIMIT 10000;
```

---

**Ek (security ile tartışmadan):** `login_log` yazılıyor ama hiç okunmuyor; rate limiting yok. Brute-force hem güvenlik hem yük sorunu (her deneme bir SELECT + bcrypt doğrulama + INSERT). Yukarıdaki `(username, created_at)` ve `(ip, created_at)` indeksleri sayesinde aşağıdaki sorgu ucuz bir indeks aralık taraması olur; pahalı `password_verify` çağrısından **önce** çalıştırılmalı:

```php
function too_many_attempts(mysqli $conn, string $username, string $ip): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM login_log
          WHERE success = 0
            AND created_at > NOW() - INTERVAL 15 MINUTE
            AND (username = ? OR ip = INET6_ATON(?))'
    );
    $stmt->bind_param('ss', $username, $ip);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return $cnt >= 10;
}
```

(`OR` iki farklı indeksle MySQL'de index_merge kullanır; daha öngörülebilir plan için iki ayrı `COUNT(*)` sorgusu da tercih edilebilir.)

---

## 10. `header()` sonrası `exit` yok — Düşük

**Satır:** 82

Yönlendirme başlığı gönderildikten sonra script çalışmaya devam ediyor ve tüm HTML formunu boşuna üretiyor (ayrıca aynı istekte `username` POST'u varsa login mantığı da çalışmış olabilir). Gereksiz CPU/çıktı; ayrıca güvenlik ve doğruluk sorunu.

```php
if (isset($_POST['register'])) {
    register_user(db(), $_POST['new_username'], $_POST['new_password'], $_POST['email']);
    header('Location: login.php?msg=' . urlencode('Kayıt tamamlandı'));
    exit;
}
```

---

## Önerilen bütünleşik login akışı

```php
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    session_start();
    $conn = db();                                   // istek başına tek bağlantı
    $user = get_user($conn, $_POST['username']);    // 1 sorgu, indeksli, gerekli sütunlar

    $ok = $user && password_verify($_POST['password'], $user['password']);
    log_login_attempt($conn, $_POST['username'], $ok); // 1 sorgu

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['roles']    = array_column(get_user_roles($conn, (int)$user['id']), 'name', 'id'); // 1 JOIN sorgusu
    }
    session_write_close();
}
```

Sonuç: 3+N bağlantı / 3+N sorgu → **1 bağlantı / 3 sorgu**, tam tablo taraması yok.

---

## Takım tartışmasından notlar

**Gönderdiklerim:**
- **security'ye:** Bağlantı sızıntısı, N+1, tam tablo taraması ve SELECT * bulgularımın özeti; ayrıca güvenlik alanına giren noktalar: `register_user` kontrolündeki TOCTOU yarış durumu (UNIQUE kısıt yok), session'da (s.52) ve cookie'de (s.54) parola hash'i, s.82'de `header()` sonrası `exit` eksikliği, rate limiting yokluğu ve `login_log`'un sınırsız büyümesi (DoS yüzeyi), s.7'de `mysqli_connect_error()` ile bilgi sızıntısı, md5'in hızlı hash olması.
- **quality'ye:** Aynı performans özeti; ayrıca kalite alanına girenler: tek paylaşılan bağlantı/DI tasarımı, isimlendirme tutarsızlığı (`Get_User`, `logLoginAttempt`, `$Conn`/`$conn`, `$Password`), `mysqli_query` dönüş değerinin kontrol edilmemesi, `register_user`'ın dönüş değerinin yok sayılması (s.81), karışık girinti, hardcoded kimlik bilgileri (s.5).

**Aldıklarım ve değerlendirmem:**
- **security:** Kritik SQL injection (14, 29, 36, 38, 76), md5/düz metin parola karşılaştırması (20), cookie'de hash (54) ve reflected XSS (55, 57, 61, 66, 82, 88-89) bildirdi. Performans tarafında `register_user` tam taraması + yarış durumu, N+1 içindeki bağlantı açılımının **bağlantı tükenmesi DoS vektörü** olması ve session'a tüm satırın yazılması konusunda bulgularımı doğruladı. Haklı yeni nokta: `login_log` yazılıyor ama hiç okunmuyor, rate limiting yok. Bunu Bulgu 9'a indeks destekli bir rate-limit sorgusu olarak ekledim; indeks önerim (Bulgu 4) bu sorguyu ucuz kılıyor.
- **quality:** Bağlantı yönetimi (13, 27, 34, 38, 70), N+1 (36-40), tam tablo taraması (71-74) ve SELECT * bulgularını bağımsız olarak doğruladı; bunları kalite raporunda sorumluluk ayrımı (tek paylaşılan bağlantı / dependency injection) açısından ele alıyor. Raporuma eklenecek yeni bir performans bulgusu çıkmadı.

**Ortak sonuç:** Üç inceleyici de bağlantı yönetimi, N+1 ve `register_user` tam taramasını işaret etti. Prepared statement + tek bağlantı + JOIN + UNIQUE indeks önerileri hem güvenlik (SQLi, yarış durumu), hem kalite, hem performans sorunlarını aynı anda çözüyor; düzeltmeler bu yüzden birlikte uygulanmalı.
