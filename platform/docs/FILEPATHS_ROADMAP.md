# File Paths / Module Re-org Roadmap (Operational)

Bu doküman, mevcut `platform/` altındaki dağınık dosya yapısını **kırmadan** (geri uyumlu) modül yapısına taşıma haritasıdır.

## Hedef yapı

- `platform/modules/<module>/...` altında gerçek iş mantığı
- Eski dosya yolları (örn. `ajax-ticket-*.php`) **stub/wrapper** olarak kalır ve yeni modüle proxy eder.
- Böylece:
  - 404/route kırmadan refactor yapılır
  - yeni geliştirme tek yerde toplanır

## Adım sırası

### 1) Tickets (başladı)
- Canonical API: `platform/modules/tickets/ticket_api.php`
- Legacy entry: `platform/api/ticket.php` (loader)

**Sonraki paketlerde (ticket):**
- `platform/ajax-ticket-*.php` dosyaları wrapper'a çevrilecek
- Tüm ticket aksiyonları tek yerden yönetilecek: `action=close|reply|upload|counters|list|workflow_update|cardinfo_save|mutabakat_save`

### 2) Mutabakat
- Canonical: `platform/modules/mutabakat/...`
- Legacy: `platform/mutabakat/*.php` stub

### 3) Dashboard
- Canonical: `platform/modules/dashboard/...`

## Eski → Yeni yol eşleştirmesi (tickets)

| Legacy | Canonical |
|---|---|
| `platform/api/ticket.php` | `platform/modules/tickets/ticket_api.php` |
| `platform/ajax-ticket-close.php` | `...ticket_api.php?action=close` |
| `platform/ajax-ticket-reply.php` | `...ticket_api.php?action=reply` |
| `platform/ajax-ticket-upload.php` | `...ticket_api.php?action=upload` |
| `platform/ajax-ticket-counters.php` | `...ticket_api.php?action=counters` |
| `platform/ajax-tickets-list.php` | `...ticket_api.php?action=list` |
| `platform/ajax-ticket-workflow-update.php` | `...ticket_api.php?action=workflow_update` |
| `platform/ajax-ticket-cardinfo-save.php` | `...ticket_api.php?action=cardinfo_save` |
| `platform/ajax-ticket-mutabakat-save.php` | `...ticket_api.php?action=mutabakat_save` |

> Not: Bu doküman "yapılacaklar" listesidir. Bu pakette sadece API'nin modül yoluna taşınması yapıldı.

## Tickets modülü (başlandı)

**Yeni kanonik giriş noktaları**
- `platform/modules/tickets/ticket_api.php` (kodsuz yükleyici: `platform/api/ticket.php` buraya yönlenir)

**Eski -> Yeni eşleştirme (hedef)
- `platform/ajax-ticket-close.php` -> `platform/api/ticket.php?action=close`
- `platform/ajax-ticket-reply.php` -> `platform/api/ticket.php?action=reply`
- `platform/ajax-ticket-upload.php` -> `platform/api/ticket.php?action=upload`
- `platform/ajax-ticket-counters.php` -> `platform/api/ticket.php?action=counters`
- `platform/ajax-tickets-list.php` -> `platform/api/ticket.php?action=list`
- `platform/ajax-ticket-workflow-update.php` -> `platform/api/ticket.php?action=workflow_update`
- `platform/ajax-ticket-cardinfo-save.php` -> `platform/api/ticket.php?action=cardinfo_save`
- `platform/ajax-ticket-mutabakat-save.php` -> `platform/api/ticket.php?action=mutabakat_save`

> Bu sürümde **kanonik API** modül altına alındı. Diğer action’ların tek tek API’ye taşınması bir sonraki adım.

## Mutabakat modülü (sırada)

Hedef: `platform/modules/mutabakat/` altına taşımak. Önce sadece yönlendirme/stub ile başlanacak:
- `platform/mutabakat/havuz.php` -> `platform/modules/mutabakat/havuz.php`
- `platform/mutabakat/atama.php` -> `platform/modules/mutabakat/atama.php`
- ...

## Uygulama kuralı

- Her adımda en fazla **3 dosya** gerçek mantık taşınır.
- Eski yollar her zaman çalışır (stub).
- Toplu test en sonda.
