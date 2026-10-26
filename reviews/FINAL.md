# Giriş Modülü — Son Karar ve Kapanış

**Durum: KAPANDI.** Üç inceleme turundaki bütün bulgular ya düzeltildi ya da aşağıda gerekçesiyle kabul edildi. Bu belgeden sonra yeni bir inceleme turu yapılmayacak.

**Neden:** Her tur bir öncekinin düzeltmelerini inceledi ve bulguların ciddiyeti her turda düştü. 1. turda kritik açıklar vardı, 3. turda kritik bulgu yoktu. Kalanlar artık "daha iyi olabilir" düzeyindeydi. Bu düzeyde inceleme her zaman yeni bir şey bulur ve döngü kendiliğinden bitmez. Bu yüzden kalan her madde için açık bir karar verildi.

---

## 1. 3. turdan sonra yapılan düzeltmeler

Hepsi gerçek bir veritabanıyla (MariaDB 10.4, PHP 8.2) uçtan uca test edildi.

| Bulgu | Ne yapıldı | Test sonucu |
|---|---|---|
| Sayaç tablosu tek IP'den sınırsız büyüyor (P-P1, S-N12) | IP sayacı önce ve tek başına kontrol ediliyor. Sınır aşıldıysa kullanıcı adı+IP sayacına satır yazılmıyor. | 70 farklı kullanıcı adı denendi: 50 deneme işlendi, 20'si 429 aldı ve tabloda 51 satır oluştu. Satır sayısı artık sınırlı. |
| Şifre hata loguna düşüyor (S-N1) | Şifre alan bütün parametrelere `#[\SensitiveParameter]` eklendi. `zend.exception_ignore_args=1` ve `display_errors=0` artık kodda ayarlanıyor. | DB hatası oluşturuldu: 500 döndü, logda şifre 0 kez geçiyor. |
| Sayaç atomik değil, iki sorgu yapıyor, kilitlenmede 500 (S-N6, P-P3, P-P7) | Artırma ve okuma tek sorguda (`LAST_INSERT_ID(expr)`). MySQL kilitlenmesinde bir kez yeniden deneniyor. | 20 paralel istekten tam 5'i şifre kontrolüne ulaştı. |
| md5 dönüştürme betiği yavaş, kesinti yaratıyor, düz metni atlıyor (P-P2, S-N4, Q-N19) | Partili çalışıyor, ilerleme yazdırıyor, kaldığı yerden devam edebiliyor (`--from-id`) ve paralel çalıştırılabiliyor (`--shard=K/M`). Koşullu UPDATE yapıyor. Düz metin şifreleri de hash'liyor. Kod, betik bitene kadar ham md5'i de kabul ediyor; böylece kimse kilitlenmiyor. | Ham md5 kullanıcı migration'dan önce girebildi. Düz metin kullanıcı migration'dan sonra girebildi. İkinci çalıştırma 0 satır değiştirdi. |
| Cevap süresi farkı (S-N5, S-Y5) | bcrypt cost'u 12'ye sabitlendi ve sahte hash aynı cost ile üretildi. Her yolda tek bir bcrypt çalışıyor. Bütün şifre formatları tek sınıfta (`PasswordHasher`). | Ortalama cevap süreleri: md5'li 0,079 sn, bcrypt 0,080 sn, olmayan kullanıcı 0,085 sn, ham md5 0,078 sn. |
| IPv6 ile sınırı atlatma (S-N2) | IPv6 adresleri /64 bloklarına göre sayılıyor. | Aynı /64'teki üç adres tek sayaç kullandı. |
| Proxy arkasında herkes tek IP görünüyor (S-N3) | `TRUSTED_PROXIES` ayarı eklendi. Yalnızca güvenilir proxy'den gelen `X-Forwarded-For` okunuyor. | Testler bu yolla farklı IP'lerden yapıldı. |
| Oturum (S-N8, P-P10, Q-N3, Q-N15) | 8 saatlik mutlak oturum süresi eklendi. HTTPS'te `__Host-` çerez öneki kullanılıyor. Silinen kullanıcının oturumu bitiyor. Oturum dosyası en fazla dakikada bir yazılıyor. Oturum kilidi tek bir yardımcı fonksiyonla bırakılıyor. | Kullanıcı silindikten sonraki sayfa açılışında oturum kapandı. |
| Migration (P-P5, P-P6, Q-N17, S-N11) | Sütun sadece gerekiyorsa genişletiliyor. Temizlik görevleri yeniden oluşturuluyor ve sıralı, küçük partilerle çalışıyor; sayaçlar 1 saat saklanıyor. Eski gereksiz indeksler siliniyor. Adım sırası düzeltildi. Geri alma betiğine "önce kodu geri alın" uyarısı eklendi. | İki kez çalıştı, geri alındı, tekrar çalıştı. |
| `LoginOutcome` geçersiz durum (Q-N1) | `failure(Success)` hata fırlatıyor, `isSuccess()` eklendi. | — |
| Şablon bütün değişkenleri görüyor (Q-N2) | `render()` yalnızca verilen değişkenleri aktarıyor. | — |
| Diğer kalite maddeleri (Q-N5–N8, N10–N13, N16, N18, Q-Y9, Q-Y11) | PSR-4 autoloader eklendi. `declare` şablonların ilk satırında. Global sabit kaldırıldı. Oturum anahtarları sabitlere taşındı. HTTP kodu ve mesaj tek `match` ifadesinde seçiliyor. `DB_PORT` doğrulanıyor. Viewport etiketi eklendi. Yanlış yorum düzeltildi. Charset bağlantı sırasında ayarlanıyor (fazladan gidiş-dönüş yok, P-P8). | `php -l` bütün dosyalarda temiz. |

**Sorgu sayıları (son hali):**

| İstek | Sorgu sayısı |
|---|---|
| Kilitli istek | 1 (IP kilidi) veya 2 (kullanıcı adı+IP kilidi) |
| Başarısız giriş | 4 |
| Başarılı giriş | 5 |
| Giriş yapmış kullanıcının sayfa açışı | 1 |

---

## 2. Bilerek kabul edilenler (düzeltilmeyecek)

| Bulgu | Karar ve gerekçe |
|---|---|
| Hesap başına toplam deneme sınırı yok (S-N2'nin bir kısmı) | **Kabul.** Hesap başına sert bir kilit, 2. turda kapatılan "herkes başkasının hesabını kilitleyebilir" sorununu geri getirir. Çok IP'den gelen saldırıya karşı doğru çözüm CAPTCHA'dır; bu bir ürün kararıdır. IP ve /64 sınırları ilk savunmayı sağlıyor. |
| Sabit pencere sınırında 2 kat deneme (S-N9) | **Kabul.** Bu durumda bile en fazla 15 dakikada 10 deneme yapılabiliyor. Kayan pencere daha fazla sorgu ve karmaşıklık getirir. |
| Kilitli denemeler `login_log`'a yazılmıyor (S-N10) | **Kabul.** Yazılırsa 1. maddedeki tablo şişmesi geri gelir. Deneme sayısı `login_throttle` sayacında duruyor. |
| Kayıtta "kullanıcı adı alınmış" mesajı (S-Y3) | **Kabul.** Kullanıcıya gereken bir bilgi. Kayıt IP başına saatte 5 denemeyle sınırlı. |
| Giriş yapmış kullanıcının her sayfa açışında rol sorgusu (P-P4) | **Kabul.** Birincil anahtar üzerinden tek bir sorgu. Karşılığında rol kaldırma ve kullanıcı silme anında etkili oluyor. |
| Sayaç anahtarı hex `CHAR(64)` (P-P7) | **Kabul.** Kazanç ihmal edilebilir. |
| `login_log.ip` metin olarak saklanıyor (P-Y6) | **Kabul.** Büyük tabloda tip değiştirmek ağır bir migration demek, kazancı düşük. |
| Birim testi için arayüz ve repository yok (Q-N9, Q-Y5) | **Kabul.** Tek bir gerçek uygulaması olan sınıflara arayüz eklemek soyutlama maliyeti getirir. Doğrulama gerçek veritabanıyla uçtan uca testlerle yapılıyor. |
| Tembel servis için closure (Q-N4) | **Kabul.** 8 satırlık, okunabilir bir kod. |
| PHPStan yapılandırması (Q-N14) | **Ertelendi.** Projede Composer yok. Composer eklendiğinde PHPStan da eklenmeli. |
| Action adları string (Q-Y9'un kalanı) | **Kabul.** Üç değer var, ikisi şablonda biri controller'da. Oturum anahtarları sabitlere taşındı. |
| Var olan tabloların tanımları `schema.sql`'de yok (Q-Y12) | **Kabul.** Bu tablolar migration'ın konusu değil. Beklenen tablolar dosyanın başında listeli. |
| Büyük `users` tablosunda sütun genişletme yazmaları bloklar (P-P5) | **Kabul, belgelendi.** InnoDB bu değişikliği tablo kopyalamadan yapamaz. Büyük tabloda pt-online-schema-change veya gh-ost kullanılmalı; bu not `schema.sql`'de yazılı. |

---

## 3. Canlıya almadan önce yapılması gerekenler (kodla çözülemez)

1. `schema.sql` dosyasını mysql istemcisiyle çalıştır.
2. Sınırlı yetkili uygulama kullanıcısını gerçek bir şifreyle oluştur (`schema.sql`'in sonunda). `root123` şifresini değiştir.
3. Ortam değişkenlerini tanımla: `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, gerekirse `DB_PORT`. Proxy arkasındaysan `TRUSTED_PROXIES`. HTTPS zorunlu: `HTTPS_ONLY=0` sadece yerel geliştirme içindir.
4. MySQL'de `event_scheduler=ON` (hem `SET GLOBAL` ile hem `my.cnf`'te).
5. Kodu yayına al. Sunucuda **PHP 8.2 veya üstü** olmalı (`#[\SensitiveParameter]` için).
6. `php migrate_md5_passwords.php` çalıştır. Site açıkken çalışabilir; büyük tabloda `--shard` ile paralel çalıştır.
7. Migration bittiğinde `PasswordHasher` içindeki ham md5 dalı (`isRawMd5` ile başlayan blok) silinebilir. Bu tek ve isteğe bağlı bir temizlik.

---

## 4. Bundan sonrası için kural

- Bu modül için yeni bir inceleme turu, ancak **kod yeni bir özellik için değiştiğinde** ve **sadece değişen kısım için** yapılır.
- Bir incelemede sadece **Kritik** ve **Yüksek** bulgular yayını durdurur. Orta ve Düşük bulgular bir sonraki planlı işe eklenir veya bu belgedeki gibi gerekçeyle kabul edilir.
- Kabul edilen bir madde, koşulları değişmedikçe tekrar açılmaz. Örneğin CAPTCHA'ya karar verilirse "hesap başına sınır" maddesi yeniden değerlendirilir.
