# 3. Tur — Birleşik İnceleme Özeti

**İncelenen:** `login.php`, `src/`, `templates/`, `login.css`, `schema.sql`, `schema_rollback.sql`, `migrate_md5_passwords.php`
**Kaynaklar:** [security.md](security.md) (sec3, 12 bulgu), [performance.md](performance.md) (perf3, 10 bulgu), [quality.md](quality.md) (qual3, 19 bulgu)
**Sonuç:** Kritik bulgu yok. Yüksek önemde iki performans bulgusu var: biri tek satırlık bir düzeltme, diğeri büyük bir tabloda çalıştırılan tek seferlik migration ile ilgili. Kalanlar orta ve düşük seviyede. Bunların bir kısmı sunucu kurulumuna (proxy, `php.ini`) veya veriye bağlı ve inceleyiciler bunları "doğrulanmadı" diye işaretledi.

Kaynak kısaltmaları: S = sec3, P = perf3, Q = qual3 (ör. S-N1 = güvenlik raporundaki N1).

---

## 2. tur bulgularının durumu

| Rapor | Düzeltildi | Kısmen | Düzeltilmedi |
|---|---|---|---|
| Güvenlik (Y1–Y12) | 8 | 4 (Y3, Y7, Y8, Y11) | 0 |
| Performans (Y1–Y7) | 5 | 2 (Y2, Y6) | 0 |
| Kalite (Y1–Y13) | 9 | 4 (Y5, Y9, Y11, Y12) | 0 |

**Kısmen düzeltilenler:**
- **Güvenlik:**
  - Kayıtta "alınmış" mesajı duruyor ama sınırlı.
  - `display_errors` kodda kapatılmıyor.
  - GRANT'ler hâlâ yorum satırı.
  - Mutlak oturum süresi yok.
- **Performans:**
  - Temizlik görevlerinde küçük eksikler var.
  - `ALTER ... MODIFY` koşulsuz çalışıyor.
- **Kalite:**
  - Süper global'lere hâlâ doğrudan erişiliyor.
  - Action ve session anahtarları dağınık string'ler.
  - Viewport meta etiketi yok.
  - `schema.sql`'de tablo tanımları eksik.

**Görüş farkı:** sec3 ve qual3 bazı maddeleri farklı değerlendirdi. Örneğin hata yönetimini qual3 "düzeldi", sec3 "kısmen" saydı. Sebep bakış açısı: kalite talebi karşılanmış, ama güvenlik açısından küçük noktalar kalmış. İkisi de raporlarında bu farkı belirtti.

**Sorgu sayıları (P):** Deneme sayacı her istekte iki yazma ve iki okuma yaptığı için sayılar arttı.

| İstek | 2. tur | 3. tur | P1 + P3 düzeltilince |
|---|---|---|---|
| Kilitli istek | 1 SQL, 0 yazma | 4 SQL, 2 yazma | 1 SQL |
| Başarısız giriş | 3 SQL | 6 SQL | 4 SQL |
| Başarılı giriş (POST) | 4 SQL | 7 SQL | 5 SQL |
| Giriş yapmış kullanıcının GET'i | 0 bağlantı | 1 bağlantı, 1 SQL | aynı (bkz. 9. madde) |

---

## P1 — Önce yapılacaklar

| # | Konum | Sorun | Düzeltme | Kaynak |
|---|---|---|---|---|
| 1 | AuthService.php:40-45 | **IP sınırı aşıldıktan sonra da kullanıcı adı+IP sayacı yazılıyor.** Tek bir IP, her istekte farklı kullanıcı adı göndererek `login_throttle` tablosunu sınırsız büyütebilir. Saatlik temizlik (10.000 satır) yetişemez. `Throttle.php:10`'daki "tablo küçük kalır" yorumu bu yüzden yanlış. | IP sayacını önce kontrol et; sınır aşıldıysa kullanıcı adı+IP sayacına dokunmadan çık. Tek satırlık değişiklik. | P-P1 (Yüksek), S-N12, Q-N18 |
| 2 | login.php:131; AuthService.php:34, 62, 142, 158 | **Şifre hata loguna yazılabiliyor.** Giriş sırasında bir DB hatası olursa loga yazılan hata kaydı (stack trace) fonksiyon parametrelerini, dolayısıyla şifreyi de içeriyor. sec3 yerelde test etti: şifrenin ilk 15 karakteri loga düşüyor. `zend.exception_ignore_args` kapalıysa geçerli; üretim `php.ini`'sinde bu ayar varsayılan olarak açık. | Şifre alan tüm parametrelere `#[\SensitiveParameter]` ekle. Üretimde `zend.exception_ignore_args=On`. | S-N1 (Orta), P-P7 |
| 3 | migrate_md5_passwords.php | **md5 dönüştürme betiği büyük tabloda saatler sürer.** Tüm satırları belleğe alıyor ve tek süreçte satır başına bir bcrypt yapıyor; 1 milyon kullanıcı ~20 saat. Yeni kod bu sırada yayındaysa henüz dönüştürülmemiş kullanıcılar giriş yapamaz. Ayrıca ilk turdan kalma düz metin şifreleri hiç dönüştürmüyor. | Partili (id aralığıyla) ve paralel çalıştır, ilerlemeyi yazdır, `WHERE password = <eski değer>` ile koşullu güncelle. Düz metin şifreler için ayrı bir adım ekle. Yeni kodu migration bitmeden yayına alma. | P-P2 (Yüksek), S-N4, Q-N19 |
| 4 | AuthService.php:38-45; functions.php:19-22 | **Dağıtık saldırı ve IP adresi sorunları.** (a) Hesap başına toplam bir sınır yok. IPv6'da her adres ayrı sayılıyor, bu yüzden saldırgan adres değiştirerek sınırı pratikte aşabiliyor. (b) Uygulama bir reverse proxy arkasındaysa herkes aynı IP'den geliyor görünür: 51 istek tüm girişleri, 6 istek tüm kayıtları durdurur. Bu (b) maddesi sunucu kurulumuna bağlı, doğrulanmadı. | IPv6 adreslerini /64 bloklarına göre say. Hesap başına yüksek bir genel sınır koy. Kilitlemek yerine gecikme veya CAPTCHA uygula. Proxy arkasındaysan güvenilir proxy listesiyle `X-Forwarded-For` oku. | S-N2, S-N3, P-P1 |
| 5 | Throttle.php:26-43 | **Sayaç işlemi atomik değil ve iki sorgu yapıyor.** Önce yazıp sonra okuyor. Arada başarılı bir giriş satırı silerse okuma boş döner, istek 0 sayılıp sınırı atlar ve `null[0]` uyarısı çıkar. Her çağrı 2 statement çalıştırıyor. Kilitlenme (1213) olursa yeniden deneme yok: kullanıcı 500 görür ve 2. maddeye göre şifre loga düşer. | `hits = LAST_INSERT_ID(IF(...))` ile tek sorguda artır ve oku. Kilitlenmede bir kez yeniden dene. | S-N6, P-P3, P-P7, Q-N18 |
| 6 | schema.sql | **Migration ayrıntıları:** (a) `ALTER TABLE ... MODIFY` her çalıştırmada koşulsuz çalışıyor ve büyük tabloda yazmaları bloklayabilir. (b) Kurulum sırası çelişiyor: md5 betiği 2. adımda DB ayarlarıyla çalışıyor ama uygulama kullanıcısı 3. adımda oluşturuluyor. (c) Temizlik `DELETE`'lerinde `ORDER BY` yok. Sayaç kayıtları 1 gün tutuluyor, 1 saat yeterli. `CREATE EVENT IF NOT EXISTS` mevcut görevi güncellemiyor. (d) `event_scheduler` kapalıysa temizlik hiç çalışmıyor ve bunu bildiren bir şey yok. (e) Geri alma betiğinde "önce kodu geri alın" uyarısı yok. Sadece şema geri alınırsa aynı kullanıcı adı iki kez kaydedilebilir ve hesaplar karışabilir. | `MODIFY`'ı uzunluk kontrolüyle koşullu yap ve `ALGORITHM=INPLACE, LOCK=NONE` ekle (büyük tabloda pt-osc/gh-ost). Adım sırasını düzelt. `DROP EVENT IF EXISTS` + `CREATE EVENT` ve `ORDER BY` kullan. Sayaç saklama süresini 1 saate indir. Geri alma dosyasına uyarıyı ekle. | P-P5, P-P6, Q-N17, S-N11 |

## P2 — Orta öncelik

| # | Konum | Sorun | Kaynak |
|---|---|---|---|
| 7 | LoginOutcome.php:9-23; login.php:71-82 | `LoginOutcome::failure(LoginResult::Success)` ile kullanıcısı olmayan bir "başarılı" sonuç üretilebiliyor. `login.php`'deki `match` ifadesinde Success dalı yok; kod yalnızca önündeki `redirect()` geri dönmediği için doğru çalışıyor. Öneri: `failure()` Success'i reddetsin ve `isSuccess()` eklensin. | Q-N1 |
| 8 | login.php:127, 133; templates/login.php | Şablon `require` ile yüklendiği için controller'ın bütün değişkenlerini görüyor, DB şifresini tutan `$config` dahil. İstismar yolu yok, savunma derinliği konusu. Öneri: değişkenleri açık bir listeyle veren yalıtılmış `render()`. | Q-N2, S |
| 9 | login.php:66-69, 84, 119-125; functions.php:55-73 | **Oturum yönetimi:** Oturum kilidi elle ve tutarsız açılıp kapatılıyor; giriş yolu yeniden açıyor, kayıt yolu açmıyor. Zaman aşımı kontrolü her istekte oturum dosyasını yeniden yazıyor. Giriş yapmış kullanıcının her sayfa açışında DB bağlantısı açılıp rol sorgusu çalışıyor. Mutlak oturum süresi yok, `__Host-` öneki yok. Silinen kullanıcının oturumu geçerli kalıyor. | Q-N3, Q-N15, P-P4, P-P10, S-N8 |
| 10 | src/Auth/*.php; functions.php | Birim testi hâlâ gerçek MySQL gerektiriyor. `Throttle` için arayüz yok, kullanıcı sorguları için repository yok, saat enjekte edilemiyor, süper global'ler doğrudan okunuyor. | Q-N9, Q-Y5 |

## P3 — Düşük öncelik

| # | Sorun | Kaynak |
|---|---|---|
| 11 | `DUMMY_HASH` cost'u sabit (10). PHP 8.4'te varsayılan 12 olduğu için olmayan kullanıcılar daha hızlı cevap alır ve kullanıcı adı zamanlamayla tahmin edilebilir. Cost tek bir sabitte toplanmalı ve dummy hash çalışma anında üretilmeli. | S-N5, P, Q |
| 12 | `display_errors` kodda kapatılmıyor; uyarılar ekrana basılabiliyor. Config hatasında güvenlik başlıkları olmadan hata sayfası gidiyor. | S-N7 |
| 13 | Sabit pencere sınırında birkaç saniyede iki kat deneme yapılabiliyor. | S-N9 |
| 14 | Kilitli denemeler denetim loguna yazılmıyor. | S-N10 |
| 15 | `set_charset` fazladan bir gidiş-dönüş ekliyor. 2. tur indeksleri uygulandıysa artık gereksiz ama silinmiyor. | P-P8, P-P9 |
| 16 | `bootstrap.php`'de PSR-4 autoloader yerine elle `require_once` listesi var. Şablonlarda `declare` ilk satırda değil (PSR-12). `login.php` yan etkili bir giriş dosyası olduğu hâlde sabit tanımlıyor (PSR-1). | Q-N6, Q-N7, Q-N8 |
| 17 | Küçük kalite noktaları: tembel servis için referansla yakalanan `$service`; HTTP kodu ve mesajın iki ayrı dallanmada seçilmesi; md5 formatı bilgisinin iki dosyaya dağılması; şifre `maxlength`'inin bayt sabitini kullanması; `$userId`'nin daraltılmadan kullanılması; PHPStan yapılandırması olmaması; geçersiz `DB_PORT`'un sessizce 0 olması; isimlendirme; viewport meta etiketi; action ve session anahtarlarının sabit olmaması. | Q-N4, N5, N10–N14, N16, Q-Y9, Q-Y11 |

---

## Takım tartışmasının özeti

- **perf3 → sec3, qual3:** IP sınırı aşılınca da sayaç satırı yazılması (1. madde). İki inceleyici de kodda doğruladı. sec3 bunu güvenlik bulgusu (N12) olarak aldı, qual3 yanlış yorumu (N18) kaydetti.
- **sec3 → perf3:** Sayacın yaz-sonra-oku yapısındaki yarış durumu ve IPv6'nın /64'e indirilmesi. perf3 ikisini de ekledi ve kendi önerdiği tek sorguluk çözümün yarışı da kapattığını gösterdi.
- **sec3 + perf3:** Bir kilitlenme 500'e dönüşünce şifre loga düşüyor. Böylece performans bulgusu P7 ile güvenlik bulgusu N1 birleşti (2. ve 5. maddeler).
- **perf3 → qual3:** "İkinci `session_start` çerezi tekrar gönderiyor" iddiası yanlış çıktı; qual3 düzeltti.
- **qual3 → sec3:** Altı notun altısı doğrulandı. En önemlisi, geri alma betiğinde "önce kodu geri alın" uyarısının eksik olması; sec3 bunun güvenlik açısından da şart olduğunu gösterdi.
- **qual3 + perf3 → sec3:** Sabit `DUMMY_HASH` maliyeti; sec3 düşük önemde bulgu (N5) olarak ekledi.
- **Süreç:** Bu turda ajanlara baştan benzersiz adlar (sec3, perf3, qual3) verildi. Mesajlar doğru kişilere gitti ve eski ajanlara hiç mesaj düşmedi. Kod dosyaları değişmedi.
