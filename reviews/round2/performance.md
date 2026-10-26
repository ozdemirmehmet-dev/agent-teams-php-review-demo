# login.php / auth.php / schema.sql — Performans İncelemesi (2. Tur)

**İnceleyen:** performance
**Dosyalar:** `login.php` (137 satır), `auth.php` (203 satır), `schema.sql` (24 satır). Hepsi sadece okundu, hiçbiri değiştirilmedi.

## Özet

İlk turdaki ağır sorunların hepsi giderilmiş: istek başına tek bağlantı, N+1 yerine JOIN, tüm tablo taraması yerine UNIQUE + 1062, `SELECT *` yok, `exit` var. Kalan sorunlar ağırlıkla **orta/düşük** seviyede. Hepsi yeni eklenen rate-limit ve `login_log` tarafında ya da bcrypt maliyetinin nereye düştüğüyle ilgili.

| İstek | 1. tur (eski kod) | 2. tur (yeni kod) |
|---|---|---|
| Başarılı giriş | 3+N bağlantı, 3+N sorgu | **1 bağlantı, 4 sorgu** (+1 UPDATE, yalnızca hash yükseltmede) |
| Başarısız giriş | 2 bağlantı, 2 sorgu | **1 bağlantı, 3 sorgu** |
| Kilitli (429) | — (rate limit yoktu) | **1 bağlantı, 1 sorgu**, bcrypt yok |
| Geçersiz CSRF / GET | 0 | **0 bağlantı** |

---

## 1. İlk tur bulgularının durumu

| # | 1. tur bulgusu | Durum | Gerekçe |
|---|---|---|---|
| 1 | Her çağrıda yeni bağlantı, kapatılmıyor | **Düzeltildi** | `auth.php:11-27` `static $conn` ile istek başına tek bağlantı açıyor. Bağlantı yalnızca POST login/register dalında açılıyor (`login.php:30, 63`), GET ve geçersiz CSRF isteklerinde DB'ye hiç gidilmiyor. |
| 2 | `get_user_roles` içinde N+1 | **Düzeltildi** | `auth.php:153-158` tek JOIN sorgusu, prepared statement. |
| 3 | `register_user` tüm tabloyu tarıyor | **Düzeltildi** | `auth.php:187-200` doğrudan INSERT ediyor, 1062 yakalanıyor. UNIQUE indeks `schema.sql:7`'de. Yarış durumu da kalmadı. |
| 4 | İndeks ihtiyaçları | **Kısmen** | `users.username` UNIQUE, `user_roles(user_id, role_id)` ve `login_log` indeksleri eklenmiş. Ancak `login_log` indeksleri `success` sütununu içermediği için kapsayan (covering) değil; temizlik sorgusu için `created_at` indeksi yok; `ip` hâlâ string (bkz. Y1, Y2, Y6). |
| 5 | `SELECT *` | **Düzeltildi** | `auth.php:77` sadece `id, username, password` çekiyor; rol sorgusu sadece `r.name`. |
| 6 | Session'a fazla veri | **Düzeltildi** | `login.php:45-47` sadece id, kullanıcı adı ve rol adları yazılıyor; hash yok. |
| 7 | Sonuç setleri serbest bırakılmıyor, hata kontrolü yok | **Düzeltildi** | Her yerde `$stmt->close()` ve `$result->free()` (`auth.php:168`) var. `mysqli_report(... STRICT)` hataları exception'a çeviriyor, `login.php:77` yakalıyor. |
| 8 | Session kilidi uzun tutuluyor | **Büyük ölçüde düzeltildi** | `login.php:87` HTML üretilmeden önce `session_write_close()` çağırıyor. Session GET'te de açılıyor, ama CSRF token için gerekli. Kilit POST'ta bcrypt + 3-4 sorgu süresince tutuluyor; bu kabul edilebilir (bkz. Y7). |
| 9 | `login_log` sınırsız büyüme, senkron INSERT | **Kısmen** | Kilitli istekler artık log yazmıyor, bu yüzden IP başına 15 dakikada en fazla ~5 başarısız satır oluşuyor. Ama temizlik `schema.sql:17`'de **sadece yorum satırı**: hiçbir yerde çalışmıyor, LIMIT'i yok ve indeks desteği de yok (bkz. Y2). |
| 10 | `header()` sonrası `exit` yok | **Düzeltildi** | `login.php:50` ve `:71`. |
| Ek | Rate limit yok | **Düzeltildi (iyileştirme gerekli)** | `tooManyFailedAttempts()` bcrypt'ten önce çalışıyor (`login.php:36`), bu doğru sıra. Ancak sorgudaki OR ve kapsamayan indeks sorunlu (bkz. Y1). |

---

## 2. Yeni ve kalan sorunlar

### Y1. Rate-limit sorgusu: `OR` + kapsamayan indeks — Orta

**Satır:** `auth.php:123-127`, `schema.sql:12-14`

```sql
WHERE success = 0
  AND (username = ? OR ip = ?)
  AND created_at > NOW() - INTERVAL ? MINUTE
```

- `username = ? OR ip = ?` iki farklı indekse yayılıyor. MySQL bunu ancak **index_merge (sort_union)** ile iki aralık taramasına çevirebiliyor. Bu da maliyet tahminine bağlı: tablo büyüdükçe ya da istatistikler bozuldukça optimizer tam tablo taramasına (`type: ALL`) düşebilir. Sorgu **her login denemesinde** çalıştığı için plan kararsızlığı doğrudan login gecikmesine yansır.
- `success` sütunu indekste yok. Bu yüzden eşleşen her satır için clustered index'e gidilip `success` okunuyor (`Using where`, kapsayan değil). Paylaşılan NAT IP'si veya popüler kullanıcı adı gibi başarılı girişlerin çok olduğu anahtarlarda, 15 dakikalık penceredeki **başarılı** satırlar da okunup atılıyor.
- `COUNT(*)` gereksiz yere tüm eşleşmeleri sayıyor; eşik sadece 5.

**Etki:** Orta. Küçük tabloda fark edilmez; milyonlarca satırlık `login_log`'da ve saldırı altında her istek için tam tarama riski taşır.

**Düzeltme:** İndeksleri `success`'i de içerecek şekilde değiştirin, sorguyu `UNION ALL` ile iki saf aralık taramasına bölün ve `LIMIT` ile erken durdurun:

```sql
ALTER TABLE login_log
    DROP INDEX idx_login_log_username_created,
    DROP INDEX idx_login_log_ip_created,
    ADD INDEX idx_login_log_user_succ_created (username, success, created_at),
    ADD INDEX idx_login_log_ip_succ_created   (ip, success, created_at);
```

```php
function tooManyFailedAttempts(mysqli $db, string $username, string $ip): bool
{
    $minutes = LOCKOUT_MINUTES;
    $limit = MAX_FAILED_ATTEMPTS;
    // Her dal kendi indeksinde salt-indeks aralık taraması yapar; LIMIT erken durdurur.
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM (
             (SELECT 1 FROM login_log
               WHERE username = ? AND success = 0
                 AND created_at > NOW() - INTERVAL ? MINUTE
               LIMIT ?)
             UNION ALL
             (SELECT 1 FROM login_log
               WHERE ip = ? AND success = 0
                 AND created_at > NOW() - INTERVAL ? MINUTE
               LIMIT ?)
         ) t'
    );
    $stmt->bind_param('siisii', $username, $minutes, $limit, $ip, $minutes, $limit);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $count >= MAX_FAILED_ATTEMPTS;
}
```

Not: `UNION ALL`, hem kullanıcı adı hem IP eşleşen satırı iki kez sayar; bu yüzden kilit, mevcut OR sorgusuna göre biraz daha erken devreye girebilir. Birebir aynı sayım isteniyorsa iki dalı ayrı ayrı değerlendirmek daha doğru olur: `userFails >= 5 || ipFails >= 5`. Bu ayrıca güvenlik açısından da daha anlamlı bir kuraldır (bkz. takım notları).

---

### Y2. `login_log` temizliği çalışmıyor ve indekssiz — Orta

**Satır:** `schema.sql:16-17`

```sql
-- DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY;
```

1. Satır yorum olarak bırakılmış; ne bir cron ne de bir `EVENT` var. Başarılı girişler ve dağıtık saldırı denemeleri yüzünden tablo hâlâ sınırsız büyüyor.
2. `created_at` hiçbir indeksin **ilk** sütunu değil. Mevcut indeksler `(username, created_at)` ve `(ip, created_at)`, bu yüzden bu DELETE **tam tablo taraması** yapar.
3. `LIMIT` olmadığı için ilk çalıştırmada milyonlarca satır tek transaction'da silinir. Bu uzun süreli satır kilitleri, büyük undo/binlog ve replikasyon gecikmesi demek. Bu sırada login'lerdeki INSERT'ler bekleyebilir.

**Etki:** Orta. Zamanla tablo, indeks boyutu ve buffer pool baskısı büyür; temizlik ilk kez çalıştırıldığında da kesinti riski doğar.

**Düzeltme:**

```sql
ALTER TABLE login_log ADD INDEX idx_login_log_created (created_at);

-- Küçük partiler halinde temizlik (MySQL EVENT)
CREATE EVENT IF NOT EXISTS ev_purge_login_log
ON SCHEDULE EVERY 1 HOUR
DO
  DELETE FROM login_log
   WHERE created_at < NOW() - INTERVAL 90 DAY
   ORDER BY created_at
   LIMIT 10000;
```

PHP tarafında cron ile yapılacaksa:

```php
$db = db();
do {
    $db->query('DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 90 DAY ORDER BY created_at LIMIT 10000');
    $deleted = $db->affected_rows;
} while ($deleted === 10000);
```

Çok yüksek hacimde `created_at` üzerinden `RANGE` partition kurup eski partition'ı `DROP PARTITION` ile silmek en ucuz yöntemdir.

---

### Y3. Kayıt endpoint'i: her istekte bcrypt, rate-limit yok — Orta

**Satır:** `auth.php:187`, `login.php:61-75`

`registerUser()` kullanıcı adının alınmış olup olmadığını bilmeden önce `password_hash()` çalıştırıyor. Bu, varsayılan cost 10 ile istek başına yaklaşık 50-100 ms CPU demek. Kayıt dalında `tooManyFailedAttempts` benzeri bir sınır olmadığı için, geçerli formatta ama alınmış bir kullanıcı adıyla gönderilen her istek tam bir bcrypt + bir INSERT denemesi maliyeti çıkarıyor. Login tarafı 5 denemeden sonra ucuzlaşırken, kayıt tarafı **sınırsız CPU tüketme yüzeyi** olarak kalıyor. Her başarılı istek ayrıca `users` tablosuna bir satır ekliyor.

**Etki:** Orta. Az sayıda istemciyle PHP-FPM worker'ları bcrypt ile meşgul edilebilir.

**Düzeltme:** Kayıtta da IP bazlı ucuz bir sınır uygulayın; bcrypt'i bu kontrolden sonra çalıştırın. `login_log`'a ayrı bir tür eklemek yerine basit bir sayaç tablosu yeterli:

```php
function tooManyRegistrations(mysqli $db, string $ip): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM register_log
          WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $count >= 10;
}

// login.php, register dalında, registerUser() çağrısından önce:
if (tooManyRegistrations($db, $ip)) {
    http_response_code(429);
    $error = 'Çok fazla kayıt denemesi.';
} else {
    $result = registerUser($db, ...);
    // register_log'a (ip, created_at) INSERT
}
```

```sql
CREATE TABLE register_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_register_log_ip_created (ip, created_at)
);
```

---

### Y4. Bilinmeyen kullanıcıda `password_hash()` ile zamanlama eşitleme — Düşük

**Satır:** `login.php:53-56`

Bilinmeyen kullanıcı için `password_hash()` çağırmak bir bcrypt maliyeti harcıyor. Bu tasarım gereği ve doğru bir fikir. Ancak:

- `password_hash()`, `password_verify()`'dan farklı olarak her seferinde `random_bytes` ile salt üretip yeni bir hash string'i oluşturuyor. Maliyet neredeyse aynı ama gereksiz iş yapıyor. Sonuç da boşa atılıyor.
- Asıl önemli nokta: **legacy md5 hash'li kullanıcıda** `password_verify()` (`auth.php:102`) geçersiz formatı görünce bcrypt çalıştırmadan anında `false` dönüyor. Yanlış şifre girilen md5 kullanıcısı yaklaşık 0 ms'de, bilinmeyen kullanıcı ise yaklaşık 70 ms'de yanıt alıyor. Eşitleme bu durumda tutmuyor. Bu bir güvenlik sorunu; security'ye iletildi.

**Etki:** Düşük (performans). Önerilen düzeltme maliyeti artırmıyor, sabitliyor.

**Düzeltme:** Sabit bir sahte hash'e karşı `password_verify` kullanın; md5 dalında da aynı maliyeti harcayın:

```php
// auth.php
const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'; // password_hash('x') çıktısı

function burnHashTime(string $password): void
{
    password_verify($password, DUMMY_HASH);
}

// login.php:53-56
if ($user === null) {
    burnHashTime($password);
}

// auth.php verifyPassword(), md5 dalında eşleşmezse:
if ($isLegacyMd5) {
    if (hash_equals(strtolower($stored), md5($password))) {
        updatePasswordHash($db, (int) $user['id'], $password);
        return true;
    }
    burnHashTime($password);
}
```

`DUMMY_HASH`, sunucuda bir kez `password_hash()` ile üretilmeli ve `PASSWORD_DEFAULT` cost'u ile aynı olmalı.

---

### Y5. `user_roles` indeksi muhtemelen yinelenen indeks — Düşük

**Satır:** `schema.sql:9`

`ALTER TABLE user_roles ADD INDEX idx_user_roles_user_role (user_id, role_id);`

Tipik bir ara tabloda `PRIMARY KEY (user_id, role_id)` zaten bulunur. Öyleyse bu indeks birebir yinelenen bir indekstir: her INSERT/DELETE'te iki kez yazılır ve fazladan disk/bellek tüketir. Tabloda PK yoksa da UNIQUE olmaması, aynı rolün iki kez atanmasına izin veriyor. Bu durumda JOIN aynı rol adını iki kez döndürür.

**Etki:** Düşük. Şema elimizde olmadığı için kesin değil; uygulamadan önce `SHOW INDEX FROM user_roles` ile kontrol edilmeli.

**Düzeltme:**

```sql
-- PK yoksa, düz indeks yerine:
ALTER TABLE user_roles ADD PRIMARY KEY (user_id, role_id);
-- PK zaten (user_id, role_id) ise schema.sql:9 satırını kaldırın.
```

---

### Y6. `login_log.ip` hâlâ string, migration maliyeti — Düşük

**Satır:** `auth.php:141`, `schema.sql:4, 14`

- 1. turda önerilen `VARBINARY(16)` + `INET6_ATON()` uygulanmamış. IPv6 string'leri 39 bayta kadar çıkabiliyor (utf8mb4'te indeks anahtarı daha da büyük); bu da `(ip, …)` indeksini gereğinden büyük yapıyor. Tek başına küçük bir kazanç olduğu için düşük öncelikli.
- `schema.sql:4` `MODIFY password VARCHAR(255)`: Mevcut kolon `VARCHAR(32)` ise utf8mb4'te uzunluk ön eki 1 bayttan 2 bayta çıkıyor. Bu InnoDB'de yerinde (INPLACE) yapılamaz ve **tablo kopyalama** gerektirir. Büyük `users` tablosunda bu tek seferlik işlem sırasında yazmalar bloklanır. Bakım penceresinde ya da `pt-online-schema-change` / `gh-ost` ile çalıştırılmalı.

- `schema.sql:7` UNIQUE indeksi eklemeden önce tekrarlanan kullanıcı adları kontrol edilmiyor. Tabloda tekrar varsa ALTER, uzun bir indeks oluşturma işleminin sonunda 1062 hatasıyla geri alınır; büyük tabloda bu işlem boşa gider. Önce kontrol edin (quality-2'nin notu):

```sql
SELECT username, COUNT(*) FROM users GROUP BY username HAVING COUNT(*) > 1;
```

```php
// İsteğe bağlı: ip'yi ikili sakla
$stmt = $db->prepare(
    'INSERT INTO login_log (username, ip, success, created_at) VALUES (?, INET6_ATON(?), ?, NOW())'
);
```

---

### Y7. Session kilidi bcrypt süresince tutuluyor — Düşük

**Satır:** `login.php:7` → `:87`

`session_start()` en başta çağrılıyor, `session_write_close()` ise POST işlemi bittikten sonra. Login POST'unda kilit bcrypt (~70 ms) + 3-4 sorgu boyunca tutuluyor. Aynı tarayıcıdan gelen paralel istekler (ör. aynı session ile çift tıklama veya AJAX) bu süre boyunca sıraya giriyor. Login sayfası için kabul edilebilir; düzeltme sadece çok yoğun trafikte değer:

```php
session_start();
$csrfOk = isValidCsrfToken(postString('csrf_token'));
session_write_close();          // bcrypt ve DB işi kilitsiz yapılır

// ... tooManyFailedAttempts, findUserByUsername, verifyPassword ...

if ($ok) {
    session_start();            // sadece yazmak için yeniden aç
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    // ...
    session_write_close();
}
```

(Kodu karmaşıklaştırdığı için şu an önerilmez. Bilgi amaçlı yazıldı.)

---

## 3. Bağlantı ve sorgu sayısı (yeniden hesap)

Varsayımlar: kullanıcının hash'i güncel bcrypt (rehash yok), geçerli CSRF token, kilit yok. Her prepared statement için PREPARE + EXECUTE round-trip'i (CLOSE yanıt beklemez) sayılmıştır.

### Başarılı giriş

| # | Kaynak | Sorgu |
|---|---|---|
| — | `auth.php:17-23` | 1 bağlantı + `SET NAMES utf8mb4` (`set_charset`) |
| 1 | `auth.php:123` | Rate-limit `SELECT COUNT(*)` |
| 2 | `auth.php:77` | `SELECT id, username, password FROM users` |
| 3 | `auth.php:140` | `INSERT INTO login_log` |
| 4 | `auth.php:153` | Rol JOIN `SELECT` |

**Toplam: 1 bağlantı, 4 SQL sorgusu** (≈8 DB round-trip + 1 charset). md5 → bcrypt yükseltmesi veya `password_needs_rehash` olursa **+1 UPDATE (5 sorgu)** ve fazladan bir `password_hash`. Bu da sadece bir kez olur. CPU: 1× bcrypt doğrulama.

1. turda öngörülen "1 bağlantı / 3 sorgu" hedefine göre +1 sorgu var. Bu rate-limit sorgusu ve gerekli.

### Başarısız giriş (var olan kullanıcı, yanlış şifre)

Rate-limit SELECT + kullanıcı SELECT + `INSERT login_log` → **1 bağlantı, 3 sorgu**, 1× bcrypt.

### Başarısız giriş (olmayan kullanıcı)

Aynısı: **1 bağlantı, 3 sorgu** + 1× `password_hash` (zamanlama eşitleme).

### Diğer yollar

- Kilitli (429): **1 bağlantı, 1 sorgu**, bcrypt yok, INSERT yok. Saldırı altında ucuz yol.
- Geçersiz CSRF veya GET: **0 bağlantı, 0 sorgu.**
- Kayıt: **1 bağlantı, 1 INSERT**, 1× bcrypt (alınmış kullanıcı adında da). Bkz. Y3.

---

## 4. Öncelik sırası

1. **Y1:** Rate-limit sorgusunu UNION ALL / iki sorgu + `success` içeren indekslerle değiştirin (orta).
2. **Y2:** `created_at` indeksi + gerçekten çalışan, partili temizlik EVENT'i (orta). security-2'nin "önce INSERT" TOCTOU düzeltmesi uygulanırsa önceliği **1. sıraya** çıkar (bkz. takım notları).
3. **Y3:** Kayıt endpoint'ine IP bazlı sınır (orta).
4. Y4-Y7: düşük öncelik; Y4'teki md5 zamanlama farkı güvenlik açısından ayrıca önemli.

---

## Takım tartışmasından notlar

**Gönderdiklerim:** İlk mesajlar yanlışlıkla ilk turun "security" ve "quality" ajanlarına gitti. team-lead'in düzeltmesinden sonra aynı içerik **security-2** ve **quality-2**'ye yeniden gönderildi.
- **security-2'ye:** Kalan performans bulgularının özeti (Y1 OR/index_merge, Y2 indekssiz temizlik, Y3 kayıtta bcrypt + rate-limit yok). Güvenlik alanına girenler: (a) legacy md5 kullanıcılarında `password_verify` anında `false` döndüğü için `login.php:55`'teki zamanlama eşitlemesinin bozulması; (b) `username OR ip` kuralıyla saldırganın herhangi bir hesabı 5 denemeyle 15 dakika kilitleyebilmesi ve NAT arkasındaki kullanıcıların birbirini kilitlemesi; (c) kayıt endpoint'inde rate-limit ve log olmaması (toplu hesap açma); (d) kilitliyken başarısız denemelerin loglanmaması (denetim izi kaybı).
- **quality-2'ye:** Aynı özet. Kalite alanına girenler: (a) `schema.sql` idempotent değil, mevcut tekrarlı kullanıcı adları varsa UNIQUE eklenemez ve ön temizlik adımı yok; (b) `user_roles` için muhtemel yinelenen indeks; (c) login'de kullanıcı adı uzunluğu sınırlanmıyor, kolon sınırını aşan değer strict modda 1406 → 500 üretebilir; (d) `login.php:74`'te beklenmeyen dönüş değerinde tanımsız anahtar riski; retention mantığının sadece SQL yorumunda kalması.

**Aldıklarım ve değerlendirmem:**
- **quality-2** dört gözlem gönderdi; hepsini kodda doğruladım:
  1. Rate-limit sorgusundaki OR → index_merge; iki COUNT veya UNION ALL, `success` içeren indeksler. **Haklı**, raporda zaten Y1.
  2. Temizlik için `created_at` indeksi ve LIMIT'li parti silme. **Haklı**, raporda zaten Y2.
  3. `user_roles` PK'si (user_id, role_id) ise `schema.sql:9` gereksiz. **Haklı**, raporda zaten Y5.
  4. `schema.sql:7` UNIQUE eklenmeden önce tekrarlanan kullanıcı adları kontrol edilmiyor. **Haklı, yeni**; Y6'ya migration notu olarak eklendi.
- **Eski "security" ajanı:** İlk turun "security" ajanı yanlış adresli ilk mesajıma yanıt verdi ve 4 güvenlik notunu da doğruladı: md5 zamanlama farkı **düşük**, hedefli hesap kilitleme **orta**, kayıtta rate-limit olmaması **orta**, kilitliyken loglanmama **düşük**. Hesap kilidi için `(username, ip)` çifti, IP'ye ayrı ve daha yüksek bir eşik, sert kilit yerine gecikme/CAPTCHA önerdi. Kapsayan indeks ve parti silme önerilerinin yük altındaki DoS'u azalttığını da belirtti. Bu öneri Y1'deki "iki dalı ayrı değerlendirme" notuyla uyumlu: ayrı sorgular, ayrı eşikleri de ucuza mümkün kılıyor. Bu yanıt eski ajandan geldiği için yalnızca bilgi olarak kaydedildi.
- **security-2** sonradan yanıt verdi. Dört notu doğrulayıp kendi raporuna ekledi ve indeks/parti silme önerilerini destekledi. Perf açısından önemli bir ek notu var: TOCTOU'yu (eşzamanlı isteklerin limiti aşması) önlemek için "önce `INSERT success=0`, sonra COUNT, başarılı olursa `insert_id` ile PK üzerinden `UPDATE success=1`" yapısını öneriyor. Değerlendirmem:
  - **Sorgu sayısı:** Başarılı giriş INSERT + COUNT + kullanıcı SELECT + UPDATE + rol SELECT = **5 sorgu** (bugün 4). Başarısız giriş **3 sorgu** (değişmiyor). Kilitli istek **2 sorgu, 1 yazma** (bugün 1 okuma, 0 yazma). PK üzerinden yapılan UPDATE ucuz; ek maliyet küçük.
  - **`login_log` büyümesi:** Bu en önemli etki. Bugün kilitli istekler yazmadığı için bir IP 15 dakikada en fazla ~5 başarısız satır üretebiliyor. Yeni yapıda kilitli istekler de satır ekliyor; tek bir IP saniyede yüzlerce satır yazabilir. Bu durumda **Y2 (çalışan, `created_at` indeksli, parti parti temizlik) ortadan önceliğe yükseliyor**. Y1'deki `LIMIT`'li sayım da zorunlu hale geliyor: limitsiz `COUNT(*)` saldırı altında kendi yazdığı satırları saymak zorunda kalır.
  - **Tavsiye:** security-2'nin dediği gibi login'de kullanıcı adı ≤ 32 karakterle sınırlanmalı (`registerUser`'daki regex ile tutarlı). Bu hem indeks girişlerini küçültür hem de 1406 → 500 hatasını önler. Kilitli istekler için satır yazmak yerine, IP başına dakikada bir satırla sınırlı bir "blocked" kaydı da düşünülebilir.
- **Ortak sonuç:** Üç alan da kalan ana sorunlar olarak `login_log` tarafını (rate-limit sorgusu, retention) ve kayıt endpoint'inde sınır olmamasını işaret ediyor.
