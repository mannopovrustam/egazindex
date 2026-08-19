<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClickHouseServiceProvider;
use App\Providers\DualWriteServiceProvider;

/*
| egaz-indexator dagi config/app.php -> 'providers' ro'yxatining ekvivalenti.
|
| U yerdagi Auth/Event/Route ServiceProvider lar L11+ da keraksiz (ularning
| vazifasi bootstrap/app.php ga o'tgan), BroadcastServiceProvider esa asl
| loyihada IZOHGA OLINGAN edi. Amalda qoladigan yagona qo'shimcha provayder —
| ClickHouse klienti.
|
| DualWriteServiceProvider — bu loyihaning O'ZIGA XOS qo'shimchasi (asl
| loyihada yo'q): MySQL ga yozilgan har bir qatorni PostgreSQL nusxasiga ham
| yozadi. config/dual_write.php, docs/dual-write.md
*/

return [
    ClickHouseServiceProvider::class,
    DualWriteServiceProvider::class,
    AppServiceProvider::class,
];
