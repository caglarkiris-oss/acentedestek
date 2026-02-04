# Mutabakat V2 - PRD (Product Requirements Document)

## Proje Özeti
PHP + MySQL tabanlı, multi-tenant sigorta mutabakat sistemi. Sigorta acenteleri arasında poliçe mutabakatı (eşleştirme) ve atama işlemlerini yönetir.

---

## Teknik Mimari

### Tech Stack
- **Backend**: PHP 8.2 (Procedural)
- **Database**: MySQL/MariaDB
- **Frontend**: Vanilla HTML/CSS/JS (inline)

### Dosya Yapısı
```
/platform/
├── config.php           # DB ve uygulama ayarları
├── db.php               # mysqli connection
├── helpers.php          # CSV, date, money helpers
├── auth.php             # Session ve rol kontrolü
├── login.php            # Test login sayfası
├── layout/
│   ├── header.php       # Header ve CSS
│   └── footer.php       # Footer ve JS
├── mutabakat/
│   ├── havuz.php        # Ana havuz ekranı (sekmeler)
│   ├── atama.php        # Atama ekranı
│   ├── index.php        # Redirect
│   └── ajax-edit-cell.php
├── ajax-ticket-mutabakat-save.php
├── ajax-ticket-close.php
├── ajax-mutabakat-export.php
├── sql/
│   └── 001_mutabakat_v2.sql
└── uploads/mutabakat/
```

---

## Veritabanı Şeması

### Ana Tablolar
1. **mutabakat_v2_periods** - Dönem yönetimi (yıl/ay)
2. **mutabakat_v2_rows** - TEK satır tablosu (ANA_CSV, TALI_CSV, TICKET)
3. **mutabakat_v2_import_batches** - CSV import batch'leri
4. **mutabakat_v2_import_errors** - Import hataları
5. **mutabakat_v2_matches** - Eşleştirme sonuçları
6. **mutabakat_v2_assignments** - Atama başlıkları
7. **mutabakat_v2_assignment_rows** - Atama detayları
8. **mutabakat_v2_disputes** - İtiraz kayıtları

### Kilit Alanlar
- **source_type**: 'ANA_CSV' | 'TALI_CSV' | 'TICKET'
- **row_status**: 'HAVUZ' | 'ESLESEN' | 'ESLESMEYEN' | 'ITIRAZ'
- **txn_type**: 'SATIS' | 'IPTAL' | 'ZEYIL'

---

## Roller ve Yetkiler

| Rol | Açıklama | Yetkiler |
|-----|----------|----------|
| MAIN (ACENTE_YETKILISI) | Ana acente | Tüm satırları görür, Ana CSV yükler, eşleştirme yapar, atama yapar |
| TALI (TALI_ACENTE_YETKILISI) | Tali acente | Sadece kendi satırlarını görür, workmode=csv ise Tali CSV yükler |

### Multi-tenant Filtre
- **MAIN**: `period_id + ana_acente_id`
- **TALI**: `period_id + ana_acente_id + tali_acente_id`

---

## Temel Fonksiyonlar

### 1. CSV Import
- BOM/encoding otomatik tespit
- Header normalize (Türkçe karakterler, boşluklar)
- Validation + error logging
- Batch tracking

### 2. Eşleştirme (run_match)
- Ana CSV vs Havuz (TALI_CSV + TICKET)
- policy_no üzerinden eşleştirme
- Eşleşen → row_status='ESLESEN', match kaydı oluştur
- Eşleşmeyen → row_status='ESLESMEYEN'

### 3. Atama
- ESLESEN satırlar tali acentelere atanır
- Özet hesaplama (SATIS/IPTAL/ZEYIL)
- summary_hakedis = toplam araci_kom_payi

### 4. Ticket Entegrasyonu
- Ticket kapanınca → TICKET satırı oluşur
- Period otomatik bulunur/oluşturulur
- workflow_status'a göre txn_type kontrolü

---

## Tamamlanan Özellikler (v1.0)

- [x] Login ve session yönetimi
- [x] Dönem (period) otomatik oluşturma
- [x] Ana CSV yükleme
- [x] Tali CSV yükleme (workmode=csv)
- [x] Eşleştirme (run_match)
- [x] Eşleşmeyen satır düzenleme
- [x] Atama işlemi
- [x] Ticket mutabakat kaydı
- [x] Export (CSV)

## Tamamlanan Özellikler (v1.1 - Onay Mekanizması)

- [x] Atama durumları: BEKLEMEDE → ONAYLANDI / REDDEDILDI / ITIRAZDA
- [x] Tali acente onay/red/itiraz işlemleri
- [x] Red sebebi zorunluluğu
- [x] İtiraz notu ve takibi
- [x] Atama detay modal (satır listesi)
- [x] Ana acente için durum takibi

---

## Backlog / P1 Features

- [ ] İtiraz (dispute) workflow
- [ ] Ödeme modülü entegrasyonu
- [ ] PDF rapor çıktısı
- [ ] Dashboard özet istatistikler
- [ ] Email bildirimleri

---

## Kurulum

```bash
# 1. MySQL veritabanı oluştur
mysql -e "CREATE DATABASE IF NOT EXISTS mutabakat_db;"

# 2. Tabloları yükle
mysql mutabakat_db < /platform/sql/001_mutabakat_v2.sql

# 3. Config ayarla
# /platform/config.php içinde DB bilgilerini düzenle

# 4. PHP sunucusu başlat
php -S 0.0.0.0:8082 -t /platform
```

---

## Son Güncelleme
**Tarih**: 2026-02-03
**Versiyon**: 1.0.0
