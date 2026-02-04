# Acentedestek Platform PRD

## Proje Özeti
PHP tabanlı sigorta/bankacılık SaaS platformu için enterprise seviye UI refactor projesi.

## Orijinal Problem Statement
Çalışan PHP tabanlı sigorta/bankacılık SaaS platformunun SADECE ARAYÜZ (UI) katmanını sıfırdan, profesyonel, kurumsal, premium bir tasarım dili ile komple yeniden inşa etmek. Backend, DB, iş kuralları, mutabakat algoritmalarına dokunulmayacak.

## Teknik Stack
- **Backend**: PHP (MySQL)
- **Frontend**: HTML, CSS, JavaScript
- **UI Framework**: Custom Design System
- **Font**: Inter (Google Fonts)
- **Icons**: Lucide Icons
- **Theme**: Lacivert/Koyu Mavi (#0f172a - #1e3a8a)

## Kullanıcı Tercihleri
- ✅ Renk Paleti: Lacivert/Koyu Mavi (Primary), Success: #16a34a, Danger: #dc2626, Warning: #f59e0b
- ✅ Sidebar: Collapsible (daraltılabilir)
- ✅ Tipografi: Inter font
- ✅ İkon Seti: Lucide Icons
- ✅ Dashboard: Gerçek backend verileriyle KPI kartları

## Tamamlanan İşler (Ocak 2026)

### Design System Oluşturuldu
- `/platform/layout/tokens.css` - CSS değişkenleri, renk paleti, tipografi
- `/platform/layout/components.css` - Butonlar, kartlar, tablolar, formlar, badgeler
- `/platform/layout/layout.css` - App shell, collapsible sidebar, topbar, grid sistem
- `/platform/layout/pages.css` - Sayfa-bazlı stiller (login, dashboard, tickets, mutabakat)
- `/platform/layout/combined.css` - Performans için birleştirilmiş CSS
- `/platform/layout/app.js` - Sidebar toggle, dropdown, toast, tooltips

### Güncellenen Sayfalar
1. **Login** - Premium kart tasarımı, gradient arka plan, kurumsal görünüm
2. **Dashboard** - KPI kartları (açık ticket, beklemede, eşleşmeyen, prim, acente, kullanıcı)
3. **Header/Footer** - Collapsible sidebar, Lucide ikonları, modern topbar
4. **Tickets** - İkonlu menü, premium tablo, arama/filtre sidebar
5. **Agencies** - Avatar, badge, modern tablo düzeni
6. **Users** - Arama, filtreleme, avatar'lı liste
7. **Mutabakat/Havuz** - İkonlu başlıklar, upload alanları, tab sistemi
8. **Billing** - KPI kartları, premium tablo
9. **Reports** - Yakında geliyor placeholder

## Kalan İşler / Backlog

### P0 (Kritik)
- [ ] Ticket create/edit sayfaları UI güncellemesi
- [ ] Agency create/edit sayfaları
- [ ] User create/edit sayfaları

### P1 (Önemli)
- [ ] Logs sayfası tasarımı
- [ ] Agency profile sayfası
- [ ] Agency directory sayfası
- [ ] CSV detay sayfaları

### P2 (Nice-to-have)
- [ ] Dark mode desteği
- [ ] Mobile responsive iyileştirmeler
- [ ] Micro-animasyonlar
- [ ] Toast bildirimleri iyileştirmesi

## Test Sonuçları
- Frontend: %85 başarı oranı
- Lucide ikonları düzgün yükleniyor
- Sidebar collapse çalışıyor
- KPI kartları backend'den veri çekiyor
- Inter font tüm sayfalarda aktif

## Notlar
- Backend koduna hiç dokunulmadı
- Tüm PHP iş mantığı korundu
- Mevcut route yapısı değişmedi
- CSS class uyumluluğu için legacy class'lar korundu
