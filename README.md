# agent-teams-php-review-demo

Bu depo bir **[Claude Code](https://claude.com/claude-code) agent teams denemesidir.** Üç inceleyici ajan (security, performance, quality) bir PHP giriş (login) kodunu paralel olarak inceledi, bulgularını birbirleriyle tartıştı ve düzeltmeleri yönlendirdi. Süreç üç tur sürdü ve [`reviews/FINAL.md`](reviews/FINAL.md) ile kapatıldı.

> ⚠️ **İlk `login.php` sürümü bilerek güvensiz yazıldı.** Ajanların bulacağı sorunlar olsun diye içine SQL injection, kaçışsız çıktıyla XSS, md5 ve düz metin şifreler, koda gömülü veritabanı şifresi, döngü içinde sorgu ve PSR-12 ihlalleri gibi hatalar kasıtlı olarak kondu. O sürüm bu depoda yok; nasıl göründüğü ve neden güvensiz olduğu 1. tur raporlarında ([`reviews/security.md`](reviews/security.md) vb.) satır satır anlatılıyor. Raporlarda geçen `root` / `root123` gibi değerler o kasıtlı kodun **uydurma** bilgileridir, gerçek bir sisteme ait değildir.

Depodaki güncel kod üç turdan sonra ulaşılan son hâldir. Yine de bir **demo** olarak hazırlandı; gerçek bir sistemde kullanmadan önce kendi incelemenizi yapın.

## Ne yapıldı?

Ekip lideri (ana Claude Code oturumu) kodu yazdı, inceleyici ajanları başlattı, aralarındaki mesajları koordine etti ve raporları birleştirdi. İnceleyiciler kodu sadece okudu, değiştirmedi.

| İnceleyici | Ne inceledi |
|---|---|
| **security** | Güvenlik açıkları. Her bulguya ciddiyet derecesi verdi: kritik, yüksek, orta, düşük. |
| **performance** | Veritabanı bağlantıları, N+1 sorgular, indeksler, kilitlenme ve tablo büyümesi. Her turda istek başına sorgu sayısını yeniden hesapladı. |
| **quality** | Okunabilirlik, isimlendirme, sorumlulukların ayrılması, test edilebilirlik ve PSR-1/PSR-4/PSR-12 uyumu. |

Her bulgu için satır numarası, açıklama ve düzeltilmiş kod örneği verildi. İnceleyiciler birbirlerine mesaj atıp birbirlerinin gözden kaçırdığı noktaları işaret etti. Bunun sonuçları her raporun **"Takım tartışmasından notlar"** bölümünde yer alıyor.

### Turlar

| Tur | İncelenen | Sonuç | Raporlar |
|---|---|---|---|
| 1 | Bilerek güvensiz yazılmış `login.php` | Kritik açıklar (SQLi, md5, gömülü şifre…) | [`reviews/`](reviews/) (`security.md`, `performance.md`, `quality.md`, `summary.md`) |
| 2 | İlk yeniden yazım (`login.php` + `auth.php`) | Kritik açık kalmadı; giriş sınırı tasarımında orta seviyede sorunlar | [`reviews/round2/`](reviews/round2/) |
| 3 | Katmanlara ayrılmış kod (`src/`, `templates/`) | Kritik bulgu yok; iki yüksek önemli performans bulgusu | [`reviews/round3/`](reviews/round3/) |
| Kapanış | 3. tur bulgularının düzeltilmesi | Her bulgu düzeltildi ya da gerekçesiyle kabul edildi | [`reviews/FINAL.md`](reviews/FINAL.md) |

### Agent teams hakkında notlar

- **Ad çakışması:** 2. turda yeni ajanlar eski adlarla başlatılmak istendi. Sistem bu yüzden onları `-2` ekiyle adlandırdı, ama talimatlarında hâlâ eski adlar yazıyordu. Bu yüzden ilk mesajlar 1. turun ajanlarına gitti. 3. turda ajanlara baştan benzersiz adlar (`sec3`, `perf3`, `qual3`) verildi ve sorun yaşanmadı.
- **İnceleme döngüsü:** İnceleme → düzeltme → inceleme döngüsü kendiliğinden bitmiyor, çünkü her tur bir şey bulur. Bu yüzden 3. turdan sonra kalan her madde için açık bir karar verildi ve bir kapanış kuralı yazıldı ([`reviews/FINAL.md`](reviews/FINAL.md), bölüm 4).
- `.claude/settings.json` agent teams özelliğini açan ayarı içerir (`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`).

## Proje yapısı

```
login.php                  Giriş noktası: istekleri yönetir, şablonu çizer
src/
  bootstrap.php            PHP ayarları ve PSR-4 autoloader
  Config.php               Ortam değişkenlerinden ayarlar (eksikse hemen hata verir)
  Database.php             mysqli bağlantısı
  Auth/AuthService.php     Giriş, kayıt, roller
  Auth/PasswordHasher.php  Şifre formatları (bcrypt, eski md5) tek yerde
  Auth/Throttle.php        Deneme sınırı sayaçları
  Auth/...                 CredentialRules, User, sonuç enum'ları
  Http/functions.php       Oturum, CSRF, güvenlik başlıkları, render
templates/                 HTML şablonları
schema.sql                 Veritabanı migration'ı (tekrar çalıştırılabilir)
schema_rollback.sql        Migration'ı geri alır
migrate_md5_passwords.php  Eski md5 / düz metin şifreleri bcrypt'e taşır
reviews/                   Bütün inceleme raporları
```

## Kurulum

**Gereksinimler:** PHP 8.2 veya üstü (`mysqli` ve `mbstring` eklentileriyle), MySQL 8 ya da MariaDB 10.4+.

Bu bir demo olduğu için var olan tabloları (`users`, `roles`, `user_roles`, `login_log`) oluşturmuyor; `schema.sql` bunların zaten var olduğunu varsayar. Sıfırdan denemek için önce bu tabloları oluşturun:

```sql
CREATE DATABASE app_db CHARACTER SET utf8mb4;
USE app_db;
CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) NOT NULL,
                    password VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL);
CREATE TABLE roles (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL);
CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, PRIMARY KEY (user_id, role_id));
CREATE TABLE login_log (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) NOT NULL,
                        ip VARCHAR(45) NOT NULL, success TINYINT NOT NULL, created_at DATETIME NOT NULL);
```

Ardından:

1. **Migration:** `mysql -u <admin> app_db < schema.sql`
2. **Uygulama kullanıcısı:** `schema.sql`'in sonundaki `CREATE USER` / `GRANT` satırlarını kendi güçlü şifrenizle çalıştırın.
3. **Ayarlar:** `cp .env.example .env` ve değerleri doldurun. `.env` asla commit edilmez.
4. **Event scheduler:** Eski logların temizliği için `SET GLOBAL event_scheduler = ON;` (kalıcı olması için `my.cnf`'e de ekleyin).
5. **Çalıştırma:** Uygulama ayarları `getenv()` ile okur. Yerelde denemek için:

   ```bash
   set -a; . ./.env; set +a
   HTTPS_ONLY=0 php -S 127.0.0.1:8000
   # Tarayıcıda: http://127.0.0.1:8000/login.php
   ```

   Apache'de `SetEnv`, nginx + PHP-FPM'de `fastcgi_param` ya da `env[...]` ile verin.
6. **Eski şifreler:** Veritabanında md5 veya düz metin şifre varsa `php migrate_md5_passwords.php` çalıştırın. Site açıkken çalışabilir; büyük tablolarda `--shard=K/M` ile paralel çalıştırılabilir.

Ayrıntılı canlıya alma listesi: [`reviews/FINAL.md`](reviews/FINAL.md), bölüm 3.

## Lisans

Lisans belirtilmemiştir. Kod eğitim ve deneme amaçlıdır.
