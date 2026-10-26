# Giriş modülü — Performans İncelemesi (3. Tur)

**İnceleyen:** perf3
**Dosyalar:** `login.php`, `src/Config.php`, `src/Database.php`, `src/bootstrap.php`, `src/Auth/*.php`, `src/Http/functions.php`, `templates/login.php`, `templates/error.php`, `schema.sql`, `schema_rollback.sql`, `migrate_md5_passwords.php`. Kod dosyaları sadece okundu, hiçbiri değiştirilmedi.

## Özet

2. turun yedi bulgusunun beşi düzeltilmiş, ikisi kısmen düzeltilmiş. Rate limit artık `login_log` üzerindeki `OR` sorgusu yerine `login_throttle` tablosunda PK üzerinden nokta erişimiyle yapılıyor. Temizlik EVENT'leri ve `created_at` indeksi eklenmiş, kayıtta da sınır var. Session kilidi bcrypt süresince bırakılıyor.

Bunun bir bedeli var: istek başına sorgu sayısı arttı. Kilitli istek 1 okumadan **4 SQL'e (2'si yazma)** çıktı. Başarılı giriş akışı 1 bağlantı/4 sorgu yerine **2 bağlantı/8 sorgu** oldu, çünkü rol sorgusu artık giriş yapmış kullanıcının her GET'inde çalışıyor.

Bu turun en önemli yeni sorunları:

| # | Bulgu | Etki |
|---|---|---|
| P1 | IP limiti aşılsa da pair sayacı yazılıyor, bu yüzden `login_throttle` tek IP'den sınırsız büyüyebiliyor. Temizlik de bu hıza yetişemiyor. | **Yüksek** |
| P2 | `migrate_md5_passwords.php` tüm satırları belleğe alıyor ve tek iş parçacığında satır başına bir bcrypt yapıyor. 1 milyon kullanıcıda yaklaşık 20 saat sürer ve bu sürede md5 kullanıcıları giriş yapamaz. | **Yüksek** (büyük tabloda) |
| P3 | `Throttle::hit` her çağrıda 2 statement (upsert + SELECT) ve 2 prepare çalıştırıyor; her girişte toplam 4 statement. | Orta |
| P4 | Giriş yapmış kullanıcının her GET'i yeni bir DB bağlantısı açıp rol sorgusu çalıştırıyor, üstelik session kilidi tutulurken. | Orta/Düşük |
| P5 | `ALTER TABLE … MODIFY` koşulsuz çalışıyor; ALGORITHM/LOCK belirtilmemiş. | Orta (tek seferlik) |
| P6 | EVENT'ler: `ORDER BY` yok, throttle saklama süresi gereğinden 24 kat uzun, `login_log` birikmiş yükünün eritilmesi aylar sürebilir. | Düşük/Orta |
| P7 | IODKU, REPEATABLE READ'de gap kilidi alıyor: deadlock (1213) çıkarsa retry olmadığı için 500 dönüyor. Ayrıca hex `CHAR(64)` anahtar kullanılıyor. | Düşük |
| P8 | `set_charset` fazladan bir round-trip. | Düşük |
| P9 | 2. tur indeksleri (`idx_login_log_username_created`, `idx_login_log_ip_created`) uygulandıysa artık gereksiz, ama drop edilmiyor. | Düşük (koşullu) |
| P10 | `enforceIdleTimeout` her istekte session dosyasını yeniden yazdırıyor. | Düşük |
| — | `ob_start` | Sorun değil (aşağıda) |

---

## 1. 2. tur bulgularının durumu

| # | 2. tur bulgusu | Durum | Gerekçe (dosya:satır) |
|---|---|---|---|
| Y1 | Rate-limit sorgusunda `OR` + kapsamayan indeks | **Düzeltildi** (yerine yeni yapı geldi) | Sayım artık `login_log`'da değil. `Throttle.php:26-40` `login_throttle`'da PK (`schema.sql:70`) ile nokta erişimi yapıyor. index_merge veya tam tarama riski kalmadı. Yeni yapının kendi maliyetleri için bkz. P1, P3, P7. |
| Y2 | `login_log` temizliği çalışmıyor ve indekssiz | **Düzeltildi** | `schema.sql:62-63` `idx_login_log_created` ekliyor. `schema.sql:78-80` 5 dakikada bir `LIMIT 10000` ile siliyor. Kalan küçük konular: `ORDER BY` yok ve birikmiş yükün erimesi yavaş (bkz. P6). |
| Y3 | Kayıtta her istekte bcrypt, rate limit yok | **Düzeltildi** | `AuthService.php:65` IP sayacı bcrypt'ten önce çalışıyor (5/saat). Biçim kontrolü de (`:69-74`) bcrypt'ten önce yapılıyor: geçersiz istek bcrypt'e hiç ulaşmıyor. Alınmış kullanıcı adı hâlâ 1 bcrypt + 1 INSERT denemesine mal oluyor, ama IP başına saatte 5 ile sınırlı. |
| Y4 | Sahte `password_hash` ve md5 dalında zamanlama farkı | **Düzeltildi** | `AuthService.php:144-146` sabit `DUMMY_HASH` ile `password_verify` yapıyor. Legacy hash'ler bcrypt ile sarıldığı için (`:151-152`) her yolda tam olarak bir bcrypt çalışıyor. Not: `DUMMY_HASH` cost 10 olarak sabit yazılmış. PHP 8.4+'da `PASSWORD_DEFAULT` cost 12 olduğu için eşitlik bozulur (bkz. takım notları). |
| Y5 | `user_roles` için yinelenen indeks | **Düzeltildi** | `schema.sql:58-59`: `user_id` ile başlayan bir indeks veya PK varsa ekleme atlanıyor (`:29-30`). |
| Y6 | `ip` string; MODIFY maliyeti; UNIQUE öncesi tekrar kontrolü | **Kısmen** | Tekrar kontrolü eklendi (`schema.sql:40-46`, `:53`). `login_log.ip` artık hiçbir indekste değil; throttle anahtarı sha256 olduğu için `ip` boyutu önemini yitirdi. Ancak `MODIFY` (`schema.sql:51`) sadece yorumla uyarılıyor; koşulsuz çalışıyor ve online DDL güvencesi yok (bkz. P5). |
| Y7 | Session kilidi bcrypt süresince tutuluyor | **Düzeltildi** | `login.php:67-69` bcrypt'ten önce `session_write_close()`, sonra `session_start()` çağırıyor. Kayıtta da aynısı yapılıyor (`:84`). Kalan küçük konu: giriş yapmış GET'te rol sorgusu ve DB bağlantısı kilit altında çalışıyor (bkz. P4). |

---

## 2. Bağlantı ve sorgu sayısı (yeniden hesap)

Varsayımlar: geçerli CSRF token, güncel bcrypt hash (rehash yok), kalıcı bağlantı yok. Her prepared statement **PREPARE + EXECUTE = 2 round-trip** olarak sayıldı (`close` yanıt beklemez). Bağlantı = TCP + handshake/auth + `SET NAMES` (`Database.php:15-16`).

| İstek | 2. tur | 3. tur | Ayrıntı (3. tur) |
|---|---|---|---|
| Başarılı giriş (POST) | 1 bağlantı, 4 SQL (1 yazma) | **1 bağlantı, 7 SQL (4 yazma)**, 14 RT | ip upsert + SELECT, pair upsert + SELECT (`AuthService.php:40-41`), kullanıcı SELECT (`:47`), pair DELETE (`:56`), `login_log` INSERT (`:57`). Rol sorgusu artık POST'ta yok; yönlendirme ile gelen GET'te. |
| Başarılı giriş akışı (POST + yönlendirilen GET) | 1 bağlantı, 4 SQL | **2 bağlantı, 8 SQL** | GET: bağlantı + rol JOIN (`login.php:123`). |
| Rehash/legacy ilk giriş | +1 UPDATE | +1 UPDATE (`AuthService.php:165-170`) | +1 `password_hash`. Toplam 2 bcrypt, tek seferlik. |
| Başarısız giriş (var olan veya olmayan kullanıcı) | 1 bağlantı, 3 SQL | **1 bağlantı, 6 SQL (3 yazma)** | 4 throttle + kullanıcı SELECT + `login_log` INSERT; 1 bcrypt. |
| Başarısız giriş, hatalı biçimli kullanıcı adı | 1 bağlantı, 3 SQL | **1 bağlantı, 3 SQL** | Sadece ip hit (2) + INSERT; kullanıcı SELECT yok; 1 bcrypt (dummy). |
| Kilitli (429) | 1 bağlantı, 1 SQL, 0 yazma | **1 bağlantı, 4 SQL, 2 yazma**, bcrypt yok | IP limiti aşılmış olsa bile pair hit çalışıyor (P1). |
| Kayıt (başarılı veya alınmış ad) | 1 bağlantı, 1 SQL | **1 bağlantı, 3 SQL** | register-ip upsert + SELECT + users INSERT; 1 bcrypt. |
| Kayıt (geçersiz veya kilitli) | 1 bağlantı, 1 SQL + bcrypt | **1 bağlantı, 2 SQL**, bcrypt yok | |
| Giriş yapmış kullanıcının GET'i | **0 bağlantı** (roller session'da) | **1 bağlantı, 1 SQL** | `login.php:123`. Geçersiz CSRF'li POST'ta da çalışır. |
| Anonim GET, çıkış, geçersiz CSRF (anonim) | 0 | 0 | Tembel bağlantı (`login.php:43-50`) doğru çalışıyor. |

Ayrıca her `upsert` ve `INSERT/DELETE` autocommit ile ayrı bir commit oluyor. `innodb_flush_log_at_trx_commit=1` iken kilitli istek başına 2, başarılı giriş başına 4 redo fsync demek (group commit bunu kısmen hafifletir).

P1 + P3 + P8 düzeltmeleri uygulanırsa beklenen sayılar: kilitli istek **1 SQL**, başarısız giriş **4 SQL**, başarılı giriş **5 SQL** ve bağlantı başına bir round-trip daha az. P4'teki session önbelleği uygulanırsa giriş yapmış GET çoğu zaman **0 bağlantı** olur.

---

## 3. Yeni ve kalan sorunlar

### P1. IP limiti aşılınca da pair sayacı yazılıyor; `login_throttle` sınırsız büyüyor — Yüksek

**Satır:** `src/Auth/AuthService.php:40-45`, `src/Auth/Throttle.php:10`, `schema.sql:82-84`

```php
$ipHits = $this->throttle->hit('login-ip:' . $ip, self::LOGIN_WINDOW_SECONDS);
$pairHits = $isWellFormed ? $this->throttle->hit($pairKey, self::LOGIN_WINDOW_SECONDS) : 0;

if ($ipHits > self::LOGIN_IP_LIMIT || $pairHits > self::LOGIN_PAIR_LIMIT) {
```

- İki sayaç da kontrol yapılmadan **önce** artırılıyor. IP limiti (50) aşıldıktan sonra da her istek `login-pair:<kullanıcı>|<ip>` anahtarı için upsert yapıyor. Saldırgan her istekte farklı ve geçerli biçimli bir kullanıcı adı gönderirse, **tek bir IP'den her istekte yeni bir satır** oluşur.
- Temizlik (`schema.sql:82-84`) saatte bir, en fazla 10.000 satır siliyor ve sadece `window_start` 1 günden eski satırları alıyor. Saatte 10.000 satır = günde 240.000 satır. Saniyede 200 istek atan tek bir istemci günde ~17 milyon satır üretir. Tablo ve PK + `idx_login_throttle_window` indeksleri sürekli büyür, buffer pool'dan sıcak sayfaları iter, rastgele sha256 anahtarlar da sayfa bölünmeleri yaratır.
- `Throttle.php:10`'daki "the table stays small even under attack" yorumu bu yüzden doğru değil.
- Kilitli yol ayrıca 2. tura göre pahalılaştı: 1 okuma yerine 2 upsert + 2 SELECT.

**Etki:** Yüksek. Tek istemciyle disk ve bellek tüketme yüzeyi oluşuyor ve kilitli yol hiç ucuz değil.

**Düzeltme:** IP limitini önce değerlendirin ve aşıldıysa pair sayacına hiç dokunmayın. Temizliği de pencere uzunluğuna göre ayarlayın (bkz. P6).

```php
public function attemptLogin(string $username, string $password, string $ip): LoginOutcome
{
    $isWellFormed = CredentialRules::isValidUsername($username)
        && strlen($password) <= CredentialRules::LOGIN_PASSWORD_MAX_BYTES;

    // IP limiti aşıldıysa başka satır yazmadan çık: IP başına en fazla LIMIT+1 pair satırı oluşur.
    if ($this->throttle->hit('login-ip:' . $ip, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_IP_LIMIT) {
        return LoginOutcome::failure(LoginResult::Throttled);
    }

    $pairKey = 'login-pair:' . strtolower($username) . '|' . $ip;
    if ($isWellFormed && $this->throttle->hit($pairKey, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_PAIR_LIMIT) {
        return LoginOutcome::failure(LoginResult::Throttled);
    }

    // ... geri kalanı aynı
}
```

Bununla bir IP, 15 dakikada en fazla ~51 pair satırı oluşturabilir. Kilitli istek de P3 ile birlikte **1 statement**'a iner.

---

### P2. `migrate_md5_passwords.php` büyük tabloda: tüm satırlar bellekte, tek iş parçacığı, satır başına bcrypt — Yüksek (büyük tabloda)

**Satır:** `migrate_md5_passwords.php:25-35`, ilişkili: `src/Auth/AuthService.php:150-155`

1. **Bellek:** `$db->query(...)` varsayılan olarak `MYSQLI_STORE_RESULT` kullanıyor: eşleşen **tüm** satırlar PHP sürecine kopyalanıyor. mysqlnd tamponu `memory_limit`'e sayılır. Birkaç milyon satırda CLI'nın 128 MB'lık varsayılan limiti aşılabilir ve iş ortada ölür.
2. **Süre:** Her satır için `password_hash` (cost 10, ~50-100 ms) çalışıyor, tek iş parçacığında. 1 milyon md5 kullanıcısı yaklaşık **14-28 saat**, 10 milyon ise günler demek. İlerleme çıktısı da yok.
3. **Erişilebilirlik bağı:** Yeni `passwordMatches()` (`AuthService.php:150-155`) düz md5'i artık tanımıyor. Yeni kod migrasyon bitmeden yayına alınırsa henüz sarılmamış kullanıcılar bu süre boyunca **giriş yapamaz**. `schema.sql:5-8`'deki sıra bunu ima ediyor ama süre yazılmamış.
4. **Transaction yok:** Her UPDATE ayrı bir autocommit. Burada bu aslında **doğru tercih**: tüm işi tek transaction'a almak, bcrypt süresi boyunca binlerce satır kilidini saatlerce tutar. fsync maliyeti bcrypt'in yanında önemsiz. İyileştirme olarak hash'leri transaction **dışında** hesaplayıp sadece UPDATE'leri küçük partiler hâlinde commit etmek yeterli.
5. **Tarama:** `REGEXP` indeks kullanamaz ve tam tarama yapar. Tek seferlik iş için kabul edilebilir. Yeniden çalıştırılabilir olması iyi: sarılmış satırlar REGEXP'e uymuyor.
6. UPDATE eski değeri kontrol etmiyor (`WHERE id = ?`). İş saatlerce sürerken satır başka bir yoldan değişirse üzerine yazılır. `AND password = ?` koşulu ucuz bir güvence.

**Düzeltme:** Keyset ile partiler hâlinde okuyun, hash'leri transaction dışında hesaplayın ve işi `id % N` ile paralel süreçlere bölün:

```php
// Kullanım: php migrate_md5_passwords.php [işçi_sayısı] [işçi_no]
// Örnek (8 çekirdek): for i in 0..7: php migrate_md5_passwords.php 8 $i &
$workers = max(1, (int) ($argv[1] ?? 1));
$part    = (int) ($argv[2] ?? 0);
$batch   = 500;

$db = Database::connect(Config::fromEnv());
$select = $db->prepare(
    "SELECT id, password FROM users
      WHERE id > ? AND id % ? = ? AND password REGEXP '^[0-9a-fA-F]{32}$'
      ORDER BY id
      LIMIT ?"
);
$update = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');

$lastId = 0;
$wrapped = 0;
do {
    $select->bind_param('iiii', $lastId, $workers, $part, $batch);
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC); // en fazla $batch satır bellekte

    // bcrypt transaction dışında: satır kilitleri sadece UPDATE'ler süresince tutulur.
    $changes = [];
    foreach ($rows as $row) {
        $old = (string) $row['password'];
        $changes[] = [(int) $row['id'], $old,
            AuthService::LEGACY_PREFIX . password_hash(strtolower($old), PASSWORD_DEFAULT)];
    }

    $db->begin_transaction();
    foreach ($changes as [$id, $old, $new]) {
        $update->bind_param('sis', $new, $id, $old);
        $update->execute();
        $wrapped += $update->affected_rows;
    }
    $db->commit();

    if ($rows !== []) {
        $lastId = (int) end($rows)['id'];
        fwrite(STDERR, "worker {$part}: id <= {$lastId}, wrapped {$wrapped}\n");
    }
} while (count($rows) === $batch);

$select->close();
$update->close();
echo "Wrapped {$wrapped} md5 hash(es).\n";
```

8 süreçle 1 milyon satır ~2-3 saate iner. Bu, CPU sayısına göre doğrusal ölçeklenir; çalıştırma sırasında web sunucusunda değil ayrı bir makinede çalıştırılması önerilir. Migrasyon bitene kadar yeni kodu yayına almayın.

---

### P3. `Throttle::hit` her çağrıda 2 statement ve 2 prepare — Orta

**Satır:** `src/Auth/Throttle.php:22-44`

- Upsert'ten sonra değeri okumak için ayrı bir `SELECT` çalışıyor. Girişte iki hit olduğu için her denemede **4 statement / 8 round-trip** ediyor.
- `prepare()` her çağrıda yeniden yapılıyor; aynı istekte aynı SQL iki kez parse ediliyor.
- Upsert ile SELECT arasında başka bir istek sayacı artırabilir. Sonuç güvenli yönde hatalı olur (fazla sayar), ama okumanın ayrı yapılmasına gerek yok.
- İki statement atomik değil (sec3'ün notu, doğrulandı). Aynı kullanıcı adı+IP için eşzamanlı başarılı bir giriş, arada `clear()` (`AuthService.php:56`) ile satırı silerse `fetch_row()` `null` döner. `Throttle.php:40`'taki `null[0]` bir uyarı üretir ve `(int) null = 0` olur; o istek limitten muaf geçer. Purge event'i bu yarışa giremez: upsert `window_start`'ı en fazla 1 saat geriye bırakıyor, purge ise 1 günden eskiyi siliyor. Tek statement'lı çözüm bu yarışı da ortadan kaldırır.

**Düzeltme:** `LAST_INSERT_ID(expr)` ile yeni değeri aynı statement'ta döndürün ve statement'ı nesnede tutun:

```php
final class Throttle
{
    private ?\mysqli_stmt $hitStmt = null;

    public function __construct(private readonly mysqli $db) {}

    public function hit(string $key, int $windowSeconds): int
    {
        $hashedKey = hash('sha256', $key, true); // 32 bayt ham; sütun BINARY(32) (bkz. P7)

        $this->hitStmt ??= $this->db->prepare(
            'INSERT INTO login_throttle (throttle_key, hits, window_start)
             VALUES (?, LAST_INSERT_ID(1), NOW())
             ON DUPLICATE KEY UPDATE
                 hits = LAST_INSERT_ID(IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1)),
                 window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)'
        );
        $this->hitStmt->bind_param('sii', $hashedKey, $windowSeconds, $windowSeconds);
        $this->hitStmt->execute();

        // LAST_INSERT_ID(expr) ile ayarlanan değer mysqli insert_id ile döner; ek SELECT gerekmez.
        return (int) $this->db->insert_id;
    }
}
```

(MySQL'de `UPDATE` atamaları soldan sağa değerlendirilir; `hits` eski `window_start` ile hesaplanır, mevcut kodla aynı davranış. Sürücü `insert_id`'yi dönmezse yedek olarak `SELECT LAST_INSERT_ID()` kullanılabilir. Bu tabloya dokunmaz.)

Kazanç: giriş başına 2 statement ve 4 round-trip daha az; P1 ile birlikte kilitli istek tek statement olur.

---

### P4. Giriş yapmış kullanıcının her GET'i DB bağlantısı açıyor, session kilidi altında — Orta/Düşük

**Satır:** `login.php:119-125`, `src/Auth/AuthService.php:97-119`

```php
// Roles are read on every request so a revoked role takes effect immediately.
$roles = $loggedInUser !== null ? $authService()->getUserRoles($userId) : [];
$csrfToken = csrfToken();
session_write_close();
```

- 2. turda roller session'daydı ve GET 0 bağlantıydı. Şimdi her GET: TCP bağlantısı + auth + `SET NAMES` + PREPARE + EXECUTE. Sorgunun kendisi ucuz (`user_roles`'ta `user_id` ile başlayan indeks + `roles` PK + küçük filesort). Maliyetin büyük kısmı bağlantı kurulumu (yerel ağda ~1-3 ms, TLS ile daha fazla) ve MySQL `max_connections` tüketimi.
- Bu iş, `session_write_close()` (`:125`) **öncesinde** yapılıyor. Yani aynı tarayıcının paralel istekleri DB bağlantısı + sorgu süresince bekliyor (Y7'nin GET'teki küçük kalıntısı).
- Geçersiz CSRF'li POST'larda ve başarısız kayıt sonrasında da (giriş yapmışsa) çalışıyor.

**Etki:** Bu sayfa sadece login/hoş geldin sayfası olduğu için trafiği düşükse etki düşük. Uygulamanın ana açılış sayfası olarak kullanılıyorsa orta.

**Düzeltme (iki seçenek):**

a) Rol iptalinin hemen işlemesi şartsa (güvenlik kararı) sorguyu koruyun, ama kilidi önce bırakın:

```php
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$loggedInUser = is_int($userId) && is_string($username) ? $username : null;
$csrfToken = csrfToken();
session_write_close();                       // kilit DB işinden önce bırakılır

$roles = $loggedInUser !== null ? $authService()->getUserRoles($userId) : [];
```

b) Birkaç saniyelik gecikme kabul edilebiliyorsa kısa ömürlü bir session önbelleği kullanın. Önbellek isabetinde GET 0 bağlantıya iner:

```php
const ROLE_CACHE_SECONDS = 60;

$roles = [];
if ($loggedInUser !== null) {
    $cache = $_SESSION['role_cache'] ?? null;
    if (is_array($cache) && is_int($cache['at'] ?? null) && $cache['at'] > time() - ROLE_CACHE_SECONDS) {
        $roles = $cache['roles'];
    } else {
        $roles = $authService()->getUserRoles($userId);
        $_SESSION['role_cache'] = ['roles' => $roles, 'at' => time()];
    }
}
$csrfToken = csrfToken();
session_write_close();
```

(b) seçeneğinde rol iptali en fazla 60 saniye gecikir; bu karar güvenlik tarafına aittir (bkz. takım notları). Çıkış ve giriş anında `role_cache` zaten `renewSession`/`$_SESSION = []` ile temizlenmiyorsa açıkça `unset` edilmeli.

---

### P5. `ALTER TABLE users MODIFY` koşulsuz ve kilit güvencesi yok — Orta (tek seferlik)

**Satır:** `schema.sql:50-51`

```sql
ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;
```

- utf8mb4'te `VARCHAR(32)` (≤128 bayt, 1 baytlık uzunluk öneki) → `VARCHAR(255)` (≤1020 bayt, 2 baytlık önek) değişimi INPLACE yapılamaz. MySQL sessizce `ALGORITHM=COPY`'ye geçer, tabloyu kopyalar ve bu sırada **yazmaları bloklar**. Sütun daha önce NULL kabul ediyorsa `NOT NULL` de ayrıca bir yeniden yapılandırma gerektirir. Yorum (`:50`) "off-peak" diyor ama koddaki hiçbir şey bunu zorlamıyor.
- Dosya "Safe to run more than once" diyor (`:1`), ama `MODIFY` her çalıştırmada mevcut tanıma bakmadan gönderiliyor. Tanım zaten aynıysa MySQL 8 bunu genelde metadata seviyesinde geçer, ancak sürüme ve MariaDB'ye göre davranış değişebiliyor. Diğer DDL'lerin (`migrate_add_index`) koşullu olmasıyla da tutarsız.
- `migrate_assert_unique_usernames()` (`:42`) indekssiz `GROUP BY username` ile tüm tabloyu tarayıp geçici tablo/sıralama kullanıyor. Tek seferlik iş için kabul edilebilir; büyük tabloda bunu da bakım penceresine planlayın.

**Düzeltme:** Sütunu kontrol edip sadece gerekirse değiştirin ve kopyalama gerekiyorsa sessizce kilitlemek yerine hata verin:

```sql
DELIMITER //
CREATE PROCEDURE migrate_widen_password()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password'
          AND (character_maximum_length < 255 OR is_nullable = 'YES')
    ) THEN
        -- Kopyalama gerekiyorsa burada hata alınır: o zaman pt-online-schema-change / gh-ost kullanın.
        ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL, ALGORITHM = INPLACE, LOCK = NONE;
    END IF;
END//
DELIMITER ;

CALL migrate_widen_password();
DROP PROCEDURE migrate_widen_password;
```

Büyük tabloda önerilen yol:

```
pt-online-schema-change --alter "MODIFY password VARCHAR(255) NOT NULL" D=app_db,t=users --execute
```

---

### P6. EVENT'ler: `ORDER BY` yok, throttle saklama süresi uzun, birikmiş yük yavaş eriyor — Düşük/Orta

**Satır:** `schema.sql:78-84`

```sql
DO DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY LIMIT 10000;
...
DO DELETE FROM login_throttle WHERE window_start < NOW() - INTERVAL 1 DAY LIMIT 10000;
```

1. **İndeks kullanımı:** İki DELETE için de ilgili indeks var (`idx_login_log_created`, `idx_login_throttle_window`); normal durumda aralık taraması yapılır. `ORDER BY` olmadığı için hangi 10.000 satırın silineceği belirsiz. `binlog_format=STATEMENT` iken her çalıştırmada "unsafe statement" uyarısı loglanır. `ORDER BY <sütun>` hem deterministik yapar hem de optimizer'ı indekse yönlendirir.
2. **`login_throttle` saklama süresi:** En uzun pencere 3600 s (`AuthService.php:20`). Penceresi bitmiş satır zaten `IF(...)` ile sıfırlanıyor, yani 1 saatten eski satır hiçbir işe yaramıyor. 1 gün saklamak tabloyu gereğinden ~24 kat büyük tutuyor. Saatte 10.000 satır da P1 senaryosunda çok düşük.
3. **`login_log` birikmiş yükü:** Önceki turlarda temizlik hiç çalışmadığı için tabloda 90 günden eski çok sayıda satır olabilir. 5 dakikada 10.000 satır = günde 2,88 milyon. 100 milyonluk bir birikim yaklaşık 35 günde erir. Bu süre boyunca event sürekli tam kapasitede çalışır. İlk kurulumda birikmişi bir kerelik bir döngüyle eritmek daha iyi. Ayrıca `idx_login_log_created`'in büyük tabloda oluşturulması (`:62-63`) online olsa da uzun sürer; bunu da planlayın.

**Düzeltme:**

```sql
DROP EVENT IF EXISTS ev_purge_login_log;
CREATE EVENT ev_purge_login_log
    ON SCHEDULE EVERY 5 MINUTE
    DO DELETE FROM login_log
        WHERE created_at < NOW() - INTERVAL 90 DAY
        ORDER BY created_at
        LIMIT 10000;

DROP EVENT IF EXISTS ev_purge_login_throttle;
CREATE EVENT ev_purge_login_throttle
    ON SCHEDULE EVERY 1 MINUTE
    DO DELETE FROM login_throttle
        WHERE window_start < NOW() - INTERVAL 1 HOUR   -- en uzun pencere (REGISTER_WINDOW_SECONDS)
        ORDER BY window_start
        LIMIT 10000;
```

İlk kurulumda birikmişi eritmek için (CLI, mysqli):

```php
$db = Database::connect(Config::fromEnv());
do {
    $db->query(
        'DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY ORDER BY created_at LIMIT 10000'
    );
    $deleted = $db->affected_rows;
    usleep(100_000); // replikasyon ve diğer yazmalara nefes payı
} while ($deleted === 10000);
```

Not (sec3'ün notu): Temizlik tamamen `event_scheduler`'a bağlı. `schema.sql:9` bunu sadece yorumla istiyor. Scheduler kapalıysa (birçok kurulumda varsayılan böyle, replikada da genelde kapalı) iki tablo da sessizce sınırsız büyür. Kurulumda `SELECT @@event_scheduler;` ile doğrulayın veya aynı DELETE'leri cron ile çalıştırın.

Not: `CREATE EVENT IF NOT EXISTS` mevcut event'i güncellemez. Tanımı değiştirmek için `DROP EVENT IF EXISTS` + `CREATE` kullanılmalı; aksi hâlde düzeltilmiş tanım yeniden çalıştırmada uygulanmaz.

---

### P7. `login_throttle`: IODKU deadlock riski ve hex anahtar — Düşük

**Satır:** `src/Auth/Throttle.php:24-34`, `schema.sql:66-72`

- **Deadlock:** REPEATABLE READ'de, var olmayan bir anahtar için `INSERT … ON DUPLICATE KEY UPDATE` gap/next-key kilitleri alır. Aynı boşluğa eşzamanlı yeni anahtar ekleyen istekler birbirini kilitleyebilir (InnoDB'de bilinen bir davranış). Bu durumda 1213 hatası alınır. Kodda retry olmadığı için hata `login.php:129`'a düşer ve kullanıcı **500** görür. Rastgele sha256 anahtarlar olasılığı düşürür ama yoğun saldırıda sıfırlamaz.
- **Sıcak satır:** Paylaşılan NAT veya saldırgan IP'sinin `login-ip:` satırı her istekte güncelleniyor. Autocommit sayesinde kilit sadece statement süresince (milisaniyenin altında) tutuluyor, yani çekişme o IP'nin istekleriyle sınırlı ve küçük. Ayrı bir düzeltme gerektirmiyor; P1 ile o IP'nin istek başına yazması 2'den 1'e iner.
- **Anahtar boyutu:** `CHAR(64)` hex, utf8mb4 tabloda değişken genişlikli ve collation'lı karşılaştırılıyor. `BINARY(32)` + `hash('sha256', $key, true)` anahtarı yarıya indirir. `idx_login_throttle_window` her girişe PK'yi taşıdığı için o indeks de küçülür. Karşılaştırma da ikili olur.

**Düzeltme:**

```php
// Database::connect içinde: bu kısa işlemler için gap kilidini kaldırır.
$db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
// (P8'deki MYSQLI_INIT_COMMAND ile birleştirilirse ek round-trip olmaz.)

// Throttle::hit içinde deadlock'ta bir kez yeniden dene:
try {
    $this->hitStmt->execute();
} catch (\mysqli_sql_exception $e) {
    if ($e->getCode() !== 1213) {
        throw $e;
    }
    $this->hitStmt->execute();
}
```

```sql
-- Tablo yeni olduğu için doğrudan:
CREATE TABLE IF NOT EXISTS login_throttle (
    throttle_key BINARY(32) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    window_start DATETIME NOT NULL,
    PRIMARY KEY (throttle_key),
    KEY idx_login_throttle_window (window_start)
) ENGINE = InnoDB;
```

---

### P8. `set_charset` fazladan bir round-trip — Düşük

**Satır:** `src/Database.php:15-16`

`set_charset()` bağlantıdan sonra ayrı bir `SET NAMES` gönderiyor. Her DB'li istekte bir round-trip daha demek. Karakter seti handshake'te de verilebilir:

```php
public static function connect(Config $config): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $db = mysqli_init();
    $db->options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4');            // handshake'te gönderilir
    // P7'yi uygulamak isterseniz: $db->options(MYSQLI_INIT_COMMAND,
    //     'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->real_connect($config->dbHost, $config->dbUser, $config->dbPass, $config->dbName, $config->dbPort);

    return $db;
}
```

(`MYSQLI_INIT_COMMAND` de bir round-trip'tir, ama `SET NAMES` ile birlikte değil onun yerine sayılırsa toplam aynı kalır.)

---

### P9. 2. tur `login_log` indeksleri drop edilmiyor — Düşük (koşullu)

**Satır:** `schema.sql:61-63`, `schema_rollback.sql`

2. tur şeması uygulanmışsa tabloda `idx_login_log_username_created` ve `idx_login_log_ip_created` duruyor. `login_log` artık sadece denetim logu (`schema.sql:61`), bu indeksleri okuyan bir sorgu yok. Yine de her `INSERT` (her giriş denemesi) iki fazladan B-tree girişi yazıyor ve event'in her DELETE'i de onları güncelliyor. 2. tur şeması hiç uygulanmadıysa bu bulgu geçersiz. Önce `SHOW INDEX FROM login_log` ile kontrol edin.

```sql
-- schema.sql'e, rollback'teki migrate_drop_index benzeri bir yardımcıyla:
CALL migrate_drop_index('login_log', 'idx_login_log_username_created');
CALL migrate_drop_index('login_log', 'idx_login_log_ip_created');
```

---

### P10. Her istekte session dosyası yazılıyor — Düşük

**Satır:** `src/Http/functions.php:55-64`, `login.php:39`

`enforceIdleTimeout()` her istekte `$_SESSION['last_activity'] = time()` atıyor. Değer her saniye değiştiği için `session.lazy_write` (varsayılan açık) yazmayı atlayamıyor. Anonim GET'ler dahil her istekte session dosyası yeniden yazılıyor ve kilit yazma bitene kadar tutuluyor. Disk I/O küçük, ama paylaşımlı veya ağ üzerindeki session depolarında (NFS, Redis) fark eder.

**Düzeltme:** Zaman damgasını sadece belirli aralıklarla yenileyin. Boşta kalma süresinin hassasiyeti 60 saniye kadar azalır, bu da 1800 saniyelik zaman aşımı için önemsiz:

```php
function enforceIdleTimeout(int $idleSeconds): void
{
    $now = time();
    $lastActivity = $_SESSION['last_activity'] ?? null;
    if (is_int($lastActivity) && $now - $lastActivity > $idleSeconds) {
        $_SESSION = [];
        session_regenerate_id(true);
        $lastActivity = null;
    }

    // Değer değişmezse lazy_write dosyayı yeniden yazmaz.
    if (!is_int($lastActivity) || $now - $lastActivity >= 60) {
        $_SESSION['last_activity'] = $now;
    }
}
```

---

### Sorun olmayan / kabul edilebilir noktalar

- **`ob_start()` (`login.php:33`):** Sayfa birkaç KB, tamponlama belleği ihmal edilebilir. Erken flush'a ihtiyaç yok. `ob_clean` ile hata sayfasına temiz geçiş sağlıyor. Performans sorunu yok.
- **`session_write_close()` + `session_start()` (`login.php:67-69`):** İkinci `session_start` session dosyasını yeniden açıp okuyor (mikrosaniyeler). Cookie zaten istekte olduğu için yeniden `Set-Cookie` gönderilmiyor. bcrypt (~50-100 ms) süresince kilidin bırakılması bu küçük maliyetten çok daha değerli. Tek yan etki: `session.gc_probability` > 0 ise GC olasılığı giriş isteğinde iki kez denenir. Bu ihmal edilebilir.
- **`sha256` hesaplaması:** Mikrosaniye seviyesinde; maliyet sorunu yok. Anahtarın boyutu için bkz. P7.
- **Rol sorgusu planı:** `user_roles` için `user_id` ile başlayan indeks garantileniyor (`schema.sql:58-59`), `roles` PK ile birleşiyor. Sorgu ucuz; maliyet bağlantıda (P4).
- **Legacy kullanıcının ilk girişi:** 2 bcrypt (doğrulama + yeni hash) + 1 UPDATE. Tek seferlik.

---

## 4. Öncelik sırası

1. **P1:** IP limiti aşılınca pair sayacına dokunmadan çık. Tek satırlık değişiklik, en büyük risk bu.
2. **P2:** Migrasyonu partili ve paralel hâle getir. Yeni kodu migrasyon bitmeden yayına alma.
3. **P6 + P3:** Throttle temizliğini 1 saate ve dakikalık sıklığa çek, `DROP/CREATE EVENT` kullan. `hit()`'i tek statement yap.
4. **P5:** `MODIFY`'ı koşullu ve `LOCK=NONE` ile yap, büyük tabloda pt-osc/gh-ost kullan.
5. **P4, P7, P8, P9:** Düşük öncelik.

---

## Takım tartışmasından notlar

**Gönderdiklerim:**
- **sec3'e:** (1) P1: IP limiti aşılsa da pair satırı yazılıyor, tek IP'den `login_throttle` şişirilebiliyor (DoS). (2) `DUMMY_HASH` cost 10 sabit; PHP 8.4+'da `PASSWORD_DEFAULT` cost 12 olduğu için bilinmeyen kullanıcı yolu daha hızlı, bu da zamanlamadan kullanıcı adı tespitine yol açar. (3) IODKU deadlock → 500. (4) Migrasyon sürerken düz md5 kullanıcılarının giriş yapamaması. (5) Kilitli isteklerin `login_log`'a yazılmaması (denetim izi). Ayrıca P4(b)'deki rol önbelleğinin iptal gecikmesi yaratacağını belirttim.
- **qual3'e:** (1) Migrasyonda buffered sorgu, parti ve ilerleme olmaması, UPDATE'te eski değer kontrolünün olmaması. (2) `MODIFY`'ın koşulsuz olması ve "safe to run more than once" iddiasıyla çelişmesi. (3) 2. tur `login_log` indekslerinin drop edilmemesi. (4) EVENT'lerde `ORDER BY` olmaması, throttle saklama süresi ve `Throttle.php:10`'daki yanlış yorum. (5) `hit()`'te çift statement ve tekrar prepare. (6) Rol sorgusunun session kilidi altında çalışması ve `set_charset` round-trip'i.

**Aldıklarım ve değerlendirmem:**
- **qual3** altı gözlem gönderdi; hepsini kodda kontrol ettim:
  1. `hit()`'in iki round-trip'i ve `LAST_INSERT_ID` çözümü. **Haklı**, raporda zaten P3. Başarılı giriş sayısı (7 SQL) benim hesabımla aynı.
  2. `session_write_close` + `session_start` ile session dosyasının iki kez okunup yazılması. **Kısmen haklı**: ikinci okuma mikrosaniyeler sürüyor. `Set-Cookie`'nin tekrar gönderilmesi ise doğru değil: cookie istekte geldiği için PHP yeni bir cookie göndermiyor. Yeni cookie sadece `renewSession()`'daki `session_regenerate_id` ile gidiyor, bu da gerekli. Raporda "sorun olmayan noktalar" altında.
  3. Giriş yapmış her GET'te bağlantı ve rol sorgusu. **Haklı**, P4.
  4. Migrasyon betiği. **Haklı**, P2 (idempotent olduğu notu raporda da var).
  5. `MODIFY`'ın tabloyu yeniden kurması **haklı** (P5). İndeks adının hem parametrede hem DDL string'inde tekrar etmesi bir kalite riski; performansa etkisi yok, qual3'ün raporuna bırakıldı.
  6. `bootstrap.php` tüm sınıfları yüklüyor. **Haklı ama önemsiz**: opcache ile maliyet ihmal edilebilir, ayrı bulgu açılmadı.
- **sec3** güvenlik bulgularını ve dört performans ipucu gönderdi:
  - `hit()`'in atomik olmaması ve `clear()` ile yarışta `fetch_row()`'un `null` dönmesi. **Doğrulandı**, P3'e eklendi. Purge event'inin bu yarışa giremeyeceğini ise ben ekledim (1 gün vs. en fazla 1 saatlik pencere).
  - `enforceIdleTimeout` her istekte session'a yazıyor. **Doğrulandı**, yeni bulgu **P10** olarak eklendi.
  - IP sayacı başarılı girişleri de sayıyor (`AuthService.php:40`, sonucu bilinmeden artırılıyor). **Doğrulandı.** NAT veya kurumsal proxy arkasında 15 dakikada 50 giriş, meşru trafik için kapasite tavanı oluyor. Eşik seçimi güvenlik kararı, sec3'ün raporunda.
  - `event_scheduler` kapalıysa tablolar sınırsız büyür. **Doğrulandı**, P6'ya not olarak eklendi.
  - IODKU deadlock riski: benim P7 ile aynı.
  - `DUMMY_HASH` cost 10 (sec3 bulgu 4): benim notumla aynı, sec3 kendi raporunda düşük olarak işledi.
  - Proxy arkasında `REMOTE_ADDR`'ın tek IP olması (sec3 bulgu 3): bu durumda P1'deki limit herkes için ortak olur ve tüm girişler 51. istekte 429 alır. Performans açısından `login-ip:` satırı tek bir sıcak satıra dönüşür ve tüm giriş trafiği bu satırın kilidinde sıraya girer. Proxy yapılandırmasına bağlı olduğu için koddan doğrulanamadı.
- **sec3'ün ikinci mesajı (raporumu değerlendirdi):**
  - P1'i kendi raporuna N12 (Orta) olarak aldı ve sıralama düzeltmesini onayladı. IPv6'da her adres ayrı anahtar olduğu için `login-ip:` satırlarında da aynı şişmenin olduğunu ve /64 normalizasyonu gerektiğini ekledi (N2). Performans açısından **katılıyorum**: /64 normalizasyonu, tek istemcinin oluşturabileceği IP satırı sayısını da sınırlar.
  - P7: 1213 → 500 yolunda, istisna stack trace'i parolayı log'a yazdığı için (N1) deadlock aynı zamanda bir sızıntı yolu. READ COMMITTED + retry önerisini destekledi.
  - P4(b): 60 saniyelik rol iptal gecikmesi bu uygulama için kabul edilebilir. Ancak önbellek giriş ve çıkışta kesin temizlenmeli. Admin rolünün düşürülmesi anında etki gerektiriyorsa (a) seçeneği kullanılmalı. Rapordaki P4 notu bununla uyumlu.
  - P2: Migrasyon sırasında md5 kullanıcılarının giriş yapamaması güvenlik açığı değil, kullanılabilirlik sorunu (fail-closed). Katılıyorum. sec3, düz metin parola satırları için de bir migrasyon adımı öneriyor (N4). Bu uygulanırsa P2'deki bcrypt sayısı ve süre o satırlar kadar artar; aynı partili/paralel yapı kullanılmalı.
- **Ortak alanlar (karşı tarafların değerlendirmesi beklenen):** `DUMMY_HASH` cost uyumu ve rol önbelleği gecikmesi güvenlik kararıdır (sec3). Migrasyon betiğinin yapısı ve `schema.sql`'in idempotent olmaması kalite alanına da girer (qual3). Bu raporda yalnızca performans boyutları ele alındı.
