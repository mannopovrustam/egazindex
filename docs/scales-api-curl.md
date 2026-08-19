# Scales moduli — curl so'rovlari

Endpointlar ([routes/web.php:60-61](../routes/web.php#L60-L61)) →
[app/Services/Scales.php](../app/Services/Scales.php)

| Metod | URL | Vazifa |
|---|---|---|
| POST | `/scales/info`  | Tarozi o'lchovini qabul qilib `tb_scales_logs` ga yozadi |
| POST | `/scales/photo` | `id` bo'yicha saqlangan rasmni base64 ko'rinishida qaytaradi |

Autentifikatsiya: **HTTP Basic**. Login/parol `.env` dagi `SCALES_LOGIN` /
`SCALES_PSW` dan olinadi; hozir `.env` da ular yo'q, shuning uchun
`Scales::authify()` dagi default qiymatlar ishlaydi.

CSRF: `scales/*` [bootstrap/app.php:52-57](../bootstrap/app.php#L52-L57) da
istisnoga kiritilgan, ya'ni token kerak emas.

---

## 1. POST /scales/info

```bash
curl -X POST "http://egaz.local/scales/info" \
  -u "scales-weg:rgw-345-f142-5se3" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "org_id": 13,
    "car_number": "01A123BC",
    "weight": 12345.67,
    "event_date": "2026-08-19 10:30:00",
    "image": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD..."
  }'
```

### Parametrlar

| Nomi | Majburiy | Izoh |
|---|---|---|
| `org_id`     | **Ha** — yo'q bo'lsa 401 | Tashkilot ID |
| `car_number` | Ha (amalda) | Mashina raqami |
| `weight`     | Ha (amalda) | `*1.00` bilan songa o'giriladi |
| `event_date` | Ha (amalda) | `strtotime()` tushunadigan har qanday format |
| `image`      | Ha (amalda) | `data:image/jpeg;base64,...` yoki toza base64 |

> Faqat `org_id` tekshiriladi. Qolgan 4 tasi `isset()` siz o'qiladi
> ([Scales.php:40-45](../app/Services/Scales.php#L40-L45)), shuning uchun
> ularsiz "Undefined array key" chiqadi — hammasini yuborgan ma'qul.

### Javoblar

```jsonc
1                                                                  // muvaffaqiyat (raqam, JSON emas)
{"api_status":0,"api_message":"Authentication failed!","api_http":401}
{"api_status":0,"api_message":"[org_id] parameter missed!","api_http":401}
{"api_status":0,"api_message":"Database insert error: ...","api_http":500}
```

> HTTP kodi doim **200** — haqiqiy status javob ichidagi `api_http` da.

### form-urlencoded varianti (rasmsiz)

```bash
curl -X POST "http://egaz.local/scales/info" \
  -u "scales-weg:rgw-345-f142-5se3" \
  -d "org_id=13" -d "car_number=01A123BC" \
  -d "weight=12345.67" -d "event_date=2026-08-19 10:30:00" -d "image="
```

### Haqiqiy rasm faylini yuborish

```bash
IMG=$(base64 -w0 photo.jpg)
curl -X POST "http://egaz.local/scales/info" \
  -u "scales-weg:rgw-345-f142-5se3" \
  -H "Content-Type: application/json" \
  -d "{\"org_id\":13,\"car_number\":\"01A123BC\",\"weight\":12345.67,\"event_date\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"image\":\"data:image/jpeg;base64,$IMG\"}"
```

---

## 2. POST /scales/photo

```bash
curl -X POST "http://egaz.local/scales/photo" \
  -u "scales-weg:rgw-345-f142-5se3" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"id": 1}'
```

```jsonc
{"api_status":1,"api_message":"Photo retrieved successfully!","api_http":200,
 "photo":"data:image/jpeg;base64,..."}
{"api_status":0,"api_message":"Photo not found!","api_http":404}
```

---

## Bazaviy URL

`egaz.local` vhost'i **boshqa** papkaga (`egaz-main/egazl13/public`) qaraydi.
Shu loyiha uchun variantlar:

```bash
# 1) localhost orqali (root .htaccess public/ ga yo'naltiradi)
http://localhost/egaz-main/egaz-index13/scales/info

# 2) o'z vhost'ini qo'shib (tavsiya etiladi)
http://index13.local/scales/info

# 3) Apache'siz, tez test uchun
php artisan serve --port=8013
http://127.0.0.1:8013/scales/info
```
