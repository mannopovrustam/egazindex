# Egaz Indexator - Yordamchi tizim (Integration & Analytics)

## Loyiha haqida (About)
**Egaz-Indexator** yordamchi tizim bo'lib, to'lov tizimlari integratsiyalari, API so'rovlarni qabul qilish, loglarni saqlash, analitika va tezkor hisobotlarni qayta ishlashga (indekslashga) va prognozlarni tuzishga mo'ljallangan loyihadir. Asosiy tizimga yuklamani kamaytirish uchun, ko'p resurs talab qiladigan jarayonlar ushbu proyektga ajratilgan (Mikroservis yondashuvi arxitekturasi elementlari mavjud).

## Texnologik stek
- **Backend:** PHP 7.0+, Laravel 5.5
- **Ma'lumotlar bazasi:** MySQL (`egaz_idxdb.sql`) shuningdek katta hajmdagi analitika uchun **ClickHouse** ma'lumotlar ombori.
- **Asosiy Paketlar:** 
  - `smi2/phpclickhouse` - ClickHouse ma'lumotlar bazasi bilan tezkor ishlash, loglarni yozish va chuqur analitik so'rovlar uchun Driver.

## Ma'lumotlar bazasi strukturasi
Indexator bazasida barcha amaliyotlarning agregatsiya qilingan holati, tashqi to'lov tizimlar integratsiyasi, abonentning biometrik maxfiyligini ta'minlovchi yuz tasdiqlash loglari kabi jadval elementlar mavjud. 

Asosiy e'tibor ma'lumotlar agregatsiyasi hamda optimizatsiyasiga qaratilgan va jadvallar asosan `i_` prefiksi orqali ifodalanadi:
- `i_abonent_details`, `i_abonent_orgs` - Abonentlarning umumlashtirilgan (indeks qilingan) va tayyor ko'rinishdagi bazasi (tezkor qidirish uchun).
- `i_balance`, `i_deposit_details` - Maxsus tashkilotlarning va hududlarning balans hamda depozit statistikasi (soyalashilgan holat).
- To'lov Integratsiyalari (`i_money_details`, `i_money_orgs`, `i_money_cancelled`, `i_money_failed`) - To'lov tizimlari (PAYNET, PAYME, CLICK, MUNIS, APELSIN, HGT) orqali amalga oshirilgan barcha turdagi tushumlar, tranzaksiyalar, qaytarilgan yoki xato o'tgan to'lovlar hisobi va auditi.
- Monitoring va Analitika (`i_real_details`, `i_hour_realize`) - Xodimlarning hamda inspektorlarning kunlik va ehtiyojga qarab hatto **soatbay realizatsiya (gaz sotish)** tahlili va ko'rsatkichlari.
- Biometrika - Face ID (`i_face_id_detail`, `i_face_id_payload`, `i_face_id_relations`, `i_face_id_recipients`) - MyID yoki shunga o'xshash biometrik identifikatsiya (yuzni tasdiqlash) tizimidan kelib tushadigan API so'rovlar qabuli va mijoz shaxsini yuz orqali verifikatsiya qilish mexanizmlari.

## Arxitektura va Vazifasi
Bu tizim **Asosiy dastur (egaz)** dagi yuklamani (Load) sezilarli ravishda kamaytirish uchun chiqarilgan maxsus hisobot-analitik moduldur. Barcha "og'ir" hisoblashlar, to'lov darvozalarini (Payment Gateways) nazorat qilish, soatbay hisobotlarni shkallantirish hamda tashqi tizimlar (shuningdek Face ID biometrik arxitekturasi) bilan xavfsiz ma'lumot almashish va sinxronizatsiya loglari shu loyihada olib boriladi.
