# login.php — Birleşik İnceleme Özeti

**Kaynaklar:** [security.md](security.md) (14 bulgu), [performance.md](performance.md) (10 bulgu), [quality.md](quality.md) (20 bulgu)
**Sonuç:** Dosya üretime çıkamaz. Giriş kontrolü SQL injection ile veya parola hash'inin kendisi girilerek atlatılabiliyor. Başarılı bir giriş 3+N bağlantı ve 3+N sorgu üretiyor (N = rol sayısı). Kod PSR-1/PSR-12'ye uymuyor.

Aynı sorunu birden fazla inceleyici bulduysa tek satırda birleştirildi. "Kaynak" sütunu ilgili rapordaki bulgu numarasını gösterir (S = security, P = performance, Q = quality). Düzeltme kodları ilgili raporlarda; hepsi mysqli kullanıyor.

---

## P0 — Hemen düzeltilmeli (kritik)

| # | Satır | Sorun | Düzeltme | Kaynak |
|---|-------|-------|----------|--------|
| 1 | 14, 29, 36, 38, 76 | **SQL injection**: tüm sorgular string birleştirmeyle kuruluyor; giriş atlatılabilir, veritabanı okunup değiştirilebilir | `mysqli` prepared statement + `bind_param` | S1, Q10 |
| 2 | 20, 75 | **Zayıf parola saklama**: md5 kullanılıyor, düz metin parola ve hash'in kendisi de kabul ediliyor, `==` ile "0e…" type juggling | `password_hash()` / `password_verify()`, düz metin dalını kaldır | S2, Q11, Q12 |
| 3 | 5 | **Koda gömülü `root`/`root123` DB kimlik bilgisi** | Parolayı değiştir; ortam değişkeni veya ayar dosyası kullan; sınırlı yetkili DB kullanıcısı aç | S3, Q19 |
| 4 | 54 | **Cookie'de `username:parola_hash`**: çalınırsa hesap ele geçirilir | Rastgele token (hash'lenmiş halde DB'de); mevcut cookie'leri geçersiz kıl | S4, Q12 |

## P1 — Yüksek öncelik

| # | Satır | Sorun | Düzeltme | Kaynak |
|---|-------|-------|----------|--------|
| 5 | 55, 57, 61, 66, 82, 88, 89 | **XSS**: kullanıcı adı, `$_GET['msg']`, `PHP_SELF`, rol adları kaçışsız basılıyor | Tüm çıktılarda `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` | S5, Q14 |
| 6 | 61, 7 | **Bilgi sızıntısı**: başarısız girişte SQL sorgusu ekrana basılıyor, `die()` DB hatasını gösteriyor | Genel hata mesajı göster, ayrıntıyı logla; `mysqli_report(...STRICT)` + try/catch | S6, Q9 |
| 7 | 4–10, 13, 27, 34, 38, 70 | **Her fonksiyon yeni DB bağlantısı açıyor, hiçbiri kapatılmıyor**: yük altında `max_connections` doluyor ve servis durabiliyor (DoS) | İstek başına tek, paylaşılan/enjekte edilen bağlantı | P1, Q8, S7 |
| 8 | 44–63, 29 | **Brute-force koruması yok**: `login_log` yazılıyor ama hiç okunmuyor ve sınırsız büyüyor | `login_log(username, created_at)` / `(ip, created_at)` indeksli rate-limit sorgusu; eski kayıtları temizle | S7, P9 |
| 9 | 36–40 | **N+1 sorgu** (`get_user_roles`): döngüde her rol için yeni sorgu ve yeni bağlantı | `user_roles JOIN roles` ile tek sorgu | P2, Q8 |
| 10 | 71–76 | **Kayıtta tüm `users` tablosu PHP'ye çekiliyor**, kontrol ile kayıt arasında yarış durumu var (aynı ad iki kez kaydedilebilir) | `UNIQUE(username)` indeksi + INSERT, 1062 hata kodunu yakala | P3, S10, Q7 |
| 11 | — | **Eksik indeksler** | `users.username` UNIQUE, `user_roles(user_id, role_id)`, `login_log` indeksleri | P4 |
| 12 | 81–82 | **Kayıt akışı bozuk**: dönüş değeri yok sayılıyor; `header()` sonrası `exit` yok; giriş bloğu önce çıktı bastığı için "headers already sent" hatası ile yönlendirme çalışmıyor | Sonucu kontrol et, `urlencode`, `header(); exit;`, POST dallarını ayır | Q15, S11, P10 |
| 13 | 1–101 | **Sorumluluklar ayrılmamış**: DB, iş mantığı, oturum ve HTML tek dosyada | Veri erişimi / servis / şablon katmanlarına böl | Q1, Q13 |

## P2 — Orta öncelik

| # | Satır | Sorun | Düzeltme | Kaynak |
|---|-------|-------|----------|--------|
| 14 | 52–53 | Session fixation (`session_regenerate_id` yok); parola hash'i dahil tüm kullanıcı satırı session'a yazılıyor | `session_regenerate_id(true)`; session'a yalnızca `id` ve `username` | S8, P6 |
| 15 | 88, 93 | CSRF token yok (login ve kayıt formları) | Session tabanlı token + `hash_equals` | S9 |
| 16 | 2, 54 | Cookie/session bayrakları (`HttpOnly`, `Secure`, `SameSite`) yok | `session_set_cookie_params` / `setcookie` options dizisi | S12 |
| 17 | 14, 38, 71 | `SELECT *` kullanımı: gereksiz veri taşınıyor ve kod şemaya örtük olarak bağlanıyor | Gerekli kolonları açıkça seç | P5, Q10 |
| 18 | 7, 15–16, 30, 36–39, 76 | Sorgu sonuçları kontrol edilmiyor, sonuçlar bırakılmıyor | Exception modu, `free()` / `close()` | P7, Q9 |
| 19 | 45–46, 81 | `$_POST` alanları varlık ve geçerlilik kontrolü olmadan okunuyor | `filter_input` / `??` + uzunluk ve biçim doğrulaması | Q16, S10 |
| 20 | 4–42, 69–78, 5, 19 | PSR-1/PSR-12 isimlendirme ihlali: `Get_User`, `logLoginAttempt`, `db_connect` karışık; `$Conn`, `$Password` | Fonksiyonlarda camelCase, değişkenlerde tutarlı stil | Q2, Q3 |
| 21 | 13–16, 20–23, 55, 69–78 | Girinti karışık (2 boşluk / tab / 4 boşluk / girintisiz) | PSR-12: 4 boşluk; `php-cs-fixer` / `phpcbf` | Q4 |
| 22 | 20–23 | `else` süslü parantezsiz, gereksiz `if/else` ile true/false dönüşü | `return password_verify(...)` | Q6 |
| 23 | 20, 44, 73 | Gevşek karşılaştırma (`==`) | `===` | Q11 |

## P3 — Düşük öncelik

| # | Satır | Sorun | Kaynak |
|---|-------|-------|--------|
| 24 | 90, 96 | Parola alanları `type="text"` | S13, Q18 |
| 25 | 60–61 | Hata mesajı ve yanıt süresiyle kullanıcı adı tespiti (user enumeration) | S14 |
| 26 | 2 | `session_start()` her istekte çalışıyor, session kilidi script sonuna kadar tutuluyor | P8 |
| 27 | 4, 12, 19, 26, 33, 69 | Fonksiyon süslü parantezi aynı satırda (PSR-12 yeni satır ister); operatörlerin etrafında boşluk yok | Q5, Q7 |
| 28 | 85–101 | HTML eksik: DOCTYPE, `lang`, `meta charset`, `<label>`; `<li>` `<ul>` olmadan | Q18, Q14 |
| 29 | 1, 35 | `declare(strict_types=1)` ve tip bildirimleri yok; eski `array()` sözdizimi | Q20 |
| 30 | 84 | Mantık bloğu ile şablon ayrı değil | Q17 |

---

## Ortak düzeltmeler: birini uygulayınca birden fazla sorun çözülüyor

1. **Tek paylaşılan mysqli bağlantısı + prepared statements**: SQL injection (#1), bağlantı tükenmesi (#7), hata yönetimi (#6, #18) ve SQL tekrarı birlikte çözülür.
2. **`password_hash` / `password_verify` + token tabanlı "beni hatırla"**: #2, #4 ve dağınık parola mantığı (Q12) birlikte çözülür.
3. **`UNIQUE(username)` + `JOIN` ile rol sorgusu + indeksler**: #9, #10, #11 ve yarış durumu birlikte çözülür.
4. **Session'a yalnızca `id` ve `username` yazmak + `session_regenerate_id`**: #14 ve session boyutu sorunu (P6) birlikte çözülür.
5. **Katmanlara bölme + `htmlspecialchars` kullanan şablon**: #5, #13 ve PSR-1 yan etki ihlali birlikte çözülür.

Performans raporuna göre bu düzeltmelerle başarılı bir giriş **3+N bağlantı / 3+N sorgu** yerine **1 bağlantı / 3 sorgu** yapar ve kayıttaki tam tablo taraması kalkar.

## Takım tartışmasının özeti

- Üç inceleyici bağlantı yönetimi, N+1 sorgu ve kayıttaki tam tablo taramasını birbirinden bağımsız olarak buldu.
- **security → performance:** `login_log` yazılıyor ama hiç okunmuyor, yani rate limiting yok. Performance raporuna indeksli rate-limit sorgusu olarak eklendi (P9).
- **performance → security:** Kapatılmayan bağlantılar DoS vektörü oluşturuyor ve `login_log` sınırsız büyüyor. Security #7'ye eklendi.
- **security → quality:** Kayıt formu gönderildiğinde önce giriş bloğu çıktı basıyor ve `header()` "headers already sent" ile başarısız oluyor. Quality #15'e eklendi.
- **performance → quality:** Bağlantılar kapatılmıyor, sonuçlar bırakılmıyor. Quality #8'e eklendi.
- **quality → security:** CRLF/open redirect notu tartışıldı. PHP satır sonu içeren header'ları reddettiği ve hedef sabit olduğu için ayrı bir açık sayılmadı; asıl risk URL'deki kullanıcı adından kaynaklanan XSS.
- **Lead notu:** quality'nin düzeltme örnekleri ilk başta PDO kullanıyordu. Diğer raporlar ve login.php ile tutarlı olması için mysqli'ye çevrildi.
