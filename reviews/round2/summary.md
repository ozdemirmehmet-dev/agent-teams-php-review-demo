# 2. Tur — Birleşik İnceleme Özeti

**İncelenen:** yeniden yazılan `login.php`, yeni `auth.php` ve `schema.sql`
**Kaynaklar:** [security.md](security.md) (12 bulgu), [performance.md](performance.md) (7 bulgu), [quality.md](quality.md) (13 bulgu)
**Sonuç:** İlk turdaki kritik ve yüksek seviyeli sorunların hepsi giderilmiş. Kritik veya yüksek seviyede güvenlik açığı kalmadı. Kalanlar çoğunlukla orta ve düşük seviyede, ağırlıklı olarak yeni eklenen giriş limiti (rate limit) ve `login_log` tarafında.

Kaynak kısaltmaları: S = security, P = performance, Q = quality (ör. S-Y1 = güvenlik raporundaki Y1).

---

## İlk tur bulgularının durumu

| Rapor | Düzeltildi | Kısmen | Düzeltilmedi |
|---|---|---|---|
| Güvenlik (14 bulgu) | 10 | 4 | 0 |
| Performans (10 bulgu + rate limit) | 7 (+ rate limit) | 3 | 0 |
| Kalite (20 bulgu) | 15 | 5 | 0 |

**Kısmen düzeltilenler:**
- Güvenlik:
  - Sınırlı yetkili DB kullanıcısı sadece yorum satırı olarak duruyor.
  - Giriş limitinde açıklar var.
  - `Secure` bayrağı proxy arkasında düşüyor.
  - Kullanıcı adları kayıt formu ve md5 zamanlama farkı üzerinden hâlâ tespit edilebiliyor.
- Performans:
  - `login_log` indeksleri eksik.
  - Log temizliği çalışmıyor.
  - Oturum kilidi bcrypt süresince tutuluyor.
- Kalite:
  - Giriş akışı hâlâ `login.php` içinde.
  - Hata yönetimi dar.
  - Şablon aynı dosyada.
  - Ortam değişkeni eksikse hata verilmiyor.
  - Enum yok.

**Sorgu sayıları (P):**

| İstek | 1. tur | 2. tur |
|---|---|---|
| Başarılı giriş | 3+N bağlantı, 3+N sorgu | 1 bağlantı, 4 sorgu |
| Başarısız giriş | 2 bağlantı, 2 sorgu | 1 bağlantı, 3 sorgu |
| Kilitli istek (429) | — | 1 bağlantı, 1 sorgu, bcrypt yok |

---

## P1 — Önce yapılacaklar (orta, birden fazla raporda)

| # | Konum | Sorun | Düzeltme | Kaynak |
|---|---|---|---|---|
| 1 | auth.php:120-135, login.php:36-58 | **Giriş limiti tasarımı.** (a) Kontrol, log yazılmadan önce yapıldığı için paralel isteklerle limit aşılabiliyor. (b) `username OR ip` sayımı yüzünden herkes başka bir hesabı 5 denemeyle süresiz kilitleyebiliyor; NAT arkasındaki kullanıcılar birbirini kilitliyor. (c) Başarılı giriş sayacı sıfırlamıyor. (d) `OR` + `success` içermeyen indeks sorgunun verimini düşürüyor. | Önce kaydet, sonra say. Kilidi (kullanıcı adı, IP) çiftine bağla, IP için ayrı ve yüksek bir eşik koy, hesaba kilit yerine gecikme veya CAPTCHA uygula. Başarıda sayacı sıfırla. `UNION ALL` / iki `COUNT` ile `(username, success, created_at)` ve `(ip, success, created_at)` indeksleri. | S-Y1, S-Y2, S-Y12, P-Y1 |
| 2 | schema.sql:16-17 | **`login_log` temizliği hiç çalışmıyor**, `created_at` indeksi yok. 1. maddedeki "önce kaydet" değişikliği uygulanınca tablo saldırı altında çok hızlı büyür. | `created_at` indeksi + `LIMIT`'li, parça parça silen bir MySQL EVENT. Kilitli istekleri IP başına dakikada en fazla 1 satırla logla. | P-Y2, Q-Y12, S-Y1 |
| 3 | login.php:61-75, auth.php:177-203 | **Kayıtta limit yok.** Toplu sahte hesap açılabiliyor, "alınmış" mesajıyla kullanıcı adları sınırsız tespit edilebiliyor, her istek bcrypt çalıştırdığı için CPU yorulabiliyor. | Hash'ten önce IP bazlı limit (ör. saatte 5 kayıt). Mümkünse "alınmış" bilgisini genel bir mesajla gizle. | S-Y3, P-Y3 |
| 4 | auth.php:110-117, login.php:53-56 | **md5 geçiş yolu.** Hiç giriş yapmayan kullanıcıların md5 hash'leri süresiz kalıyor. md5 hesaplar daha hızlı cevap verdiği için tespit edilebiliyor. | Mevcut md5'leri tek seferde `password_hash(md5)` ile sarmala, düz md5 dalını kaldır. Zamanlama eşitlemesi için `password_hash` yerine sabit bir dummy hash ile `password_verify` kullan. | S-Y4, S-Y5, P-Y4 |
| 5 | login.php:31, auth.php:137-146, 179-181 | **Uzunluk sınırları yok.** Girişte uzun kullanıcı adı `login_log` INSERT'inde 1406 hatası ve 500'e yol açabiliyor (strict mod ve dar `VARCHAR` varsayımıyla). Şifrede üst sınır yok (bcrypt 72 bayttan sonrasını yok sayar). `strlen` bayt saydığı için 4 Türkçe karakter "8 karakter" kuralını geçiyor. | Kuralları tek yerde topla (ör. `validateUsername`/`validatePassword`), girişte de uygula. `mb_strlen` kullan, şifre için 72 bayt üst sınır koy. | Q-Y13, Q-Y6, S-Y10 |
| 6 | login.php:7, 77-85; auth.php:18-21 | **Hata yönetimi ve yapılandırma.** Sadece `mysqli_sql_exception` yakalanıyor, `startSecureSession()` ve `csrfToken()` try dışında. Ortam değişkeni eksikse uygulama boş kullanıcıyla bağlanmayı deniyor. | `Throwable` yakala veya global bir handler kur. Eksik ortam değişkeninde açık bir hatayla dur (fail-fast). | S-Y7, S-Y8, Q-Y8 |
| 7 | schema.sql | **Migration güvenli değil.** İki kez çalıştırılamıyor. Tekrarlanan kullanıcı adı varsa `UNIQUE` eklenemiyor, öncesinde kontrol yok. Geri alma adımı yok. `user_roles` indeksi birincil anahtarın tekrarı olabilir. Sınırlı yetkili kullanıcı sadece yorumda. | Tekrarları bulan bir kontrol sorgusu, `IF NOT EXISTS` / `information_schema` kontrolleri, geri alma betiği. GRANT'leri gerçekten uygula. | Q-Y12, P-Y5, S-Y8 |
| 8 | login.php:29-60 | **Giriş akışı controller'da** (kalite: Yüksek). Rate limit, doğrulama, loglama ve zamanlama eşitlemesi `login.php`'de, kayıt ise `auth.php`'de; giriş akışı test edilemiyor. 1. maddedeki değişiklik de bu yüzden dağınık yapılmak zorunda. | Tüm akışı `attemptLogin(): LoginResult` fonksiyonunda topla. 1. madde bunun üzerine kurulursa tek yerde değişir. | Q-Y1 |

## P2 — Sonra yapılacaklar (düşük, orta kalite bulguları)

| # | Konum | Sorun | Kaynak |
|---|---|---|---|
| 9 | auth.php:43-52 | `Secure` bayrağı proxy arkasında düşüyor; HSTS, CSP ve iframe koruması yok. **Site HTTP'den de açılıyorsa Yüksek.** | S-Y9, Q-Y8 |
| 10 | login.php:44, auth.php:56-63 | Girişte CSRF token yenilenmiyor | S-Y6 |
| 11 | login.php:47, 83-84 | Roller session'da önbellekte tutuluyor, oturum zaman aşımı ve çıkış yok | S-Y11 |
| 12 | auth.php:174-203, login.php:69-74 | `'ok'/'invalid'/'taken'` string dönüş kodları; beklenmeyen değerde `$registerErrors[$result]` tanımsız anahtar uyarısı verir | Q-Y2 |
| 13 | auth.php:95-118 | `verifyPassword` adı salt okuma gibi ama DB'ye yazıyor | Q-Y3 |
| 14 | auth.php:11-70 | Statik bağlantı ve süper global'lere doğrudan erişim test edilebilirliği düşürüyor | Q-Y5 |
| 15 | — | `login_log.ip` metin olarak saklanıyor; `password` kolonunu genişletmek büyük tabloda kopya gerektirir | P-Y6 |
| 16 | login.php:7 → 87 | Giriş POST'unda session kilidi bcrypt süresince tutuluyor | P-Y7 |
| 17 | çeşitli | Tipsiz `array $user`, şablonda tipsiz session verisi, bilinmeyen `action` sessizce yok sayılıyor, namespace yok, inline stil ve `<br>` | Q-Y4, Q-Y7, Q-Y9, Q-Y10, Q-Y11 |

---

## Takım tartışmasının özeti

- **Ortak bulgu:** Giriş limitinin tasarımı (1. madde) üç raporda da çıktı. security-2 yarış durumu ve hesap kilitlemeyi, performance-2 indeks ve `OR` sorununu, quality-2 de akış controller'da olduğu için düzeltmenin dağınık yapılmak zorunda kalacağını gösterdi.
- **security-2 → performance-2:** "Önce kaydet, sonra say" önerisi. performance-2 maliyetini hesapladı (başarılı giriş 4 → 5 sorgu, kilitli istek artık yazıyor) ve bu yüzden log temizliğini önceliklerin 1. sırasına aldı.
- **performance-2 → quality-2:** Migration'ın iki kez çalıştırılamaması, girişte uzunluk sınırı olmaması (1406 → 500) ve `login.php:74`'teki tanımsız anahtar. quality-2 bunları doğruladı, uzunluk sınırını yeni bulgu (Y13) olarak ekledi.
- **quality-2 → security-2:** Hesap kilitleme, şifre üst sınırı, "alınmış" mesajıyla kullanıcı adı tespiti. security-2 altısını da doğruladı.
- **Görüş ayrılığı:** Kayıttaki "alınmış" mesajını taslak kabul edilebilir saymıştı. security-2 ise kayıtta limit yokken bunu Orta seviyede tutuyor (3. madde). Bu özet security-2'nin görüşünü esas alıyor.

### Süreç notu (lead)

Yeni ajanlar `security-2`, `performance-2` ve `quality-2` adlarıyla başladı ama talimatlarında eski adlar yazıyordu. İlk mesajları bu yüzden ilk turun ajanlarına gitti. Lead notları doğru ajanlara iletti ve eski ajanları durdurdu. Bu sırada:
- İlk turun security ajanı `reviews/security.md` dosyasına bir 2. tur bölümü ekledi; lead bu bölümü çıkarıp ilk tur raporunu eski haline getirdi.
- Aynı ajan `round2/security.md` dosyasına bir taslak yazdı. security-2 taslaktaki 10 maddeyi kodda doğrulayıp kendi raporuyla birleştirdi.

Son raporlar sadece 2. tur ajanlarının çıktısıdır. İncelenen kod dosyaları (`login.php`, `auth.php`, `schema.sql`) değişmedi.
