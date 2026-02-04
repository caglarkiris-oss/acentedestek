# Filepaths Standardization (Modules + Canonical Entry Points)

Bu paket, mevcut sistemi bozmadan **kanonik dosya yollarını** oluşturur.

## Amaç
- Var olan `/platform/*.php` ve `/platform/mutabakat/*.php` dosyalarını kırmadan,
  yeni bir modül yapısı altında **tek bir kanonik yol** sunmak.
- Eski yollar çalışmaya devam eder (geri uyumluluk).

## Yeni Kanonik Yollar
- Tickets: `/platform/api/ticket.php` (içerik: `/platform/modules/tickets/*`)
- Mutabakat: `/platform/modules/mutabakat/<sayfa>.php`  (wrapper → `/platform/mutabakat/<sayfa>.php`)
- Dashboard: `/platform/modules/dashboard/dashboard.php`  (wrapper → `/platform/dashboard.php`)
- Users: `/platform/modules/users/*.php`  (wrapper → `/platform/*.php`)

## Sonraki Adım (Strangler)
1. Mutabakat sayfalarını tek tek modül içine **taşıyıp** iç require yollarını `__DIR__` / platform_root bazlı hale getirmek
2. Header/menu linklerini modül/route standardına sabitlemek
3. Legacy dosyaları (eski yollar) tamamen wrapper'a indirgemek

Tarih: 2026-02-04


## Güncelleme (2026-02-04)
- Mutabakat sayfaları artık **gerçekten** `modules/mutabakat/` içine taşındı.
- Eski `platform/mutabakat/*.php` dosyaları wrapper olarak kaldı.


## Güncelleme (2026-02-04)
- Root sayfalar modüllere taşındı ve root dosyalar wrapper yapıldı:
  - dashboard.php → modules/dashboard/dashboard.php
  - tickets.php → modules/tickets/tickets.php
  - ticket.php → modules/tickets/ticket.php
  - users.php → modules/users/users.php


## Güncelleme (2026-02-04)
- Root altındaki kalan PHP sayfaları modül klasörlerine taşındı (wrapper bırakıldı).
- Taşınan dosya sayısı: 38


## Güncelleme (2026-02-04)
- Modül içi yeniden organizasyon: agencies, billing, mutabakat ajax.
- Taşınan/yeniden yönlendirilen öğe sayısı: 8
  - ajax-mutabakat-import.php: mutabakat_root → mutabakat
  - ajax-mutabakat-export.php: mutabakat_root → mutabakat
  - agencies.php: misc → agencies
  - agencies-create.php: misc → agencies
  - agencies-profile.php: users → agencies
  - agency-profile.php: users → agencies
  - agency-directory.php: misc → agencies
  - billing.php: misc → billing
