<?php

namespace App\Services\DbTriggers;

/**
 * `cms_users` jadvalidagi MySQL triggerlarining PHP versiyasi (egaz_idxdb).
 *
 * Manba: docs/idxdb_triggers.sql
 *   setIDkodUser      BEFORE INSERT
 *   incUsers          AFTER  INSERT
 *   updateKod         BEFORE UPDATE  (bazada tanasi BO'SH — no-op)
 *   updSubscrbStatus  AFTER  UPDATE
 *   decUSers          AFTER  DELETE
 *
 * ⚠ BU 5 TA TRIGGER ASOSIY egaz BAZASIDAGILAR BILAN MAZMUNAN BIR XIL
 *   (egaz/docs/egaz_triggers.sql bilan solishtirildi — farq faqat izohlarda).
 *   Ya'ni `cms_users` IKKALA bazada ham triggerli. Qaysi bazadagi qatorni
 *   yozayotgan bo'lsangiz, o'sha loyihaning bayrog'ini yoqing — ikkalasini emas.
 *   docs/db-triggers-to-service-migration.md §2
 */
class CmsUsersTriggers extends BaseTrigger
{
    const T_SET_KOD    = 'cms_users.setIDkodUser';
    const T_INC        = 'cms_users.incUsers';
    const T_UPD_KOD    = 'cms_users.updateKod';
    const T_UPD_STATUS = 'cms_users.updSubscrbStatus';
    const T_DEC        = 'cms_users.decUSers';

    /** Abonent (obunachi) roli */
    const PRIV_ABONENT = 4;

    /** "Jami" statistikasi shu soxta region ostida yuritiladi */
    const REGION_TOTAL = 15;

    /**
     * BEFORE INSERT ON cms_users — `setIDkodUser`
     *
     * kod bo'sh bo'lsa: abonent (id_cms_privileges=4) uchun 11 xonali kod va
     * <kod>@egaz.uz email generatsiya qiladi, sync_st='1' qo'yadi.
     *
     * ⚠ Bazadagi triggerdagidek: `SET NEW.kod = kodd` IF blokidan TASHQARIDA,
     *   ya'ni abonent BO'LMAGAN foydalanuvchida kod NULL ga tushadi.
     *
     * @param array|object $newRow
     * @return array
     */
    public static function setIDkodUser($newRow)
    {
        $new = self::row($newRow);

        return self::fire(self::T_SET_KOD, function () use ($new) {
            $kod = self::val($new, 'kod');
            if ($kod !== null && $kod !== '') {
                return $new; // kod allaqachon berilgan — tegmaymiz
            }

            $kodd = null;

            if (self::eq(self::val($new, 'id_cms_privileges'), self::PRIV_ABONENT)) {
                $new['sync_st'] = '1';

                $region = self::val($new, 'id_region');

                $userId = null;
                if ($region !== null) {
                    // MySQL: CONVERT(kod, UNSIGNED INTEGER); PG: qorovullangan CASE.
                    // Ifodani BaseTrigger::toUInt() dialektga qarab tanlaydi.
                    $r = self::db()->selectOne(
                        'select MAX(' . self::toUInt('kod') . ') + 1 as v '
                        . 'from cms_users where id_region = ? and id_cms_privileges = ?',
                        [$region, self::PRIV_ABONENT]
                    );
                    $userId = ($r && $r->v !== null) ? $r->v : null;
                }

                if ($userId === null) {
                    // CONCAT(LPAD(id_region,2,'0'), LPAD(1,9,'0')); region NULL bo'lsa CONCAT ham NULL
                    $kodd = ($region === null)
                        ? null
                        : self::lpad((string) $region, 2, '0') . self::lpad('1', 9, '0');
                } else {
                    $kodd = self::lpad((string) $userId, 11, '0');
                }

                $new['email'] = ($kodd === null) ? null : $kodd . '@egaz.uz';
            }

            $new['kod'] = $kodd;

            self::log(self::T_SET_KOD, 'BEFORE INSERT cms_users → kod=' . var_export($kodd, true));

            return $new;
        }, $new);
    }

    /**
     * AFTER INSERT ON cms_users — `incUsers`
     *
     * Abonent bo'lsa: cms_sync_users ga 'C' yozuvi + a_users ga nusxa.
     * Har qanday holatda: tb_statistics hisoblagichlari +1.
     *
     * @param array|object $newRow
     * @return void
     */
    public static function incUsers($newRow)
    {
        $new = self::row($newRow);

        self::fire(self::T_INC, function () use ($new) {
            $priv   = self::val($new, 'id_cms_privileges');
            $region = self::val($new, 'id_region');
            $org    = self::val($new, 'id_org');
            $status = self::val($new, 'status');
            $isAbon = self::eq($priv, self::PRIV_ABONENT);

            if ($isAbon) {
                self::ins(self::T_INC, 'cms_sync_users', [
                    'kod'  => self::val($new, 'kod'),
                    'flag' => 'C',
                ]);

                self::insIgnore(self::T_INC, 'a_users', [
                    'id'          => self::val($new, 'id'),
                    'name'        => self::val($new, 'name'),
                    'status'      => $status,
                    'id_org'      => $org,
                    'address'     => self::val($new, 'address'),
                    'kod'         => self::val($new, 'kod'),
                    'psp'         => self::val($new, 'psp'),
                    'deposit'     => self::val($new, 'deposit'),
                    'id_mahalla'  => self::val($new, 'id_mahalla'),
                    'dt_creation' => self::today(),
                ]);
            }

            // --- "Jami" (region 15) ---
            self::statBump(self::T_INC, 'users', ['id_region' => self::REGION_TOTAL], +1);

            if ($isAbon) {
                self::statBump(self::T_INC, 'subscribers', ['id_region' => self::REGION_TOTAL], +1);
                self::statBump(
                    self::T_INC,
                    self::eq($status, 'Active') ? 'subscribers_active' : 'subscribers_non_active',
                    ['id_region' => self::REGION_TOTAL],
                    +1
                );
            }

            // --- O'z viloyati ---
            if (self::neq($region, self::REGION_TOTAL)) {
                self::statBump(self::T_INC, 'users', ['id_region' => $region], +1);

                if ($isAbon) {
                    self::statBump(self::T_INC, 'subscribers', ['id_region' => $region, 'id_org' => $org], +1);
                    self::statBump(
                        self::T_INC,
                        self::eq($status, 'Active') ? 'subscribers_active' : 'subscribers_non_active',
                        ['id_region' => $region, 'id_org' => $org],
                        +1
                    );
                }
            }
        });
    }

    /**
     * BEFORE UPDATE ON cms_users — `updateKod`
     *
     * Bazada tanasi BO'SH (no-op). Asosiy egaz bazasidagi nusxada izohga
     * olingan mantiq bor edi (`sync_st='1'`) — shu yerda ham xuddi shunday
     * yozilgan, lekin bayroq bilan qorovullangan.
     *
     * @param array|object $newRow
     * @return array
     */
    public static function updateKod($newRow)
    {
        $new = self::row($newRow);

        return self::fire(self::T_UPD_KOD, function () use ($new) {
            if (self::eq(self::val($new, 'id_cms_privileges'), self::PRIV_ABONENT)) {
                $new['sync_st'] = '1';
            }
            return $new;
        }, $new);
    }

    /**
     * AFTER UPDATE ON cms_users — `updSubscrbStatus`
     *
     * @param array|object $newRow  UPDATE payload'i (yoki to'liq yangi qator)
     * @param array|object $oldRow  UPDATE dan OLDINGI qator (majburiy)
     * @return void
     */
    public static function updSubscrbStatus($newRow, $oldRow)
    {
        $old = self::row($oldRow);
        $new = self::effective($newRow, $old);

        self::fire(self::T_UPD_STATUS, function () use ($new, $old) {
            $org = self::val($new, 'id_org');
            if ($org === null) $org = self::val($old, 'id_org');

            $newStatus = self::val($new, 'status');
            $oldStatus = self::val($old, 'status');

            if (self::neq($newStatus, $oldStatus)) {
                $becameActive = self::eq($newStatus, 'Active') && self::neq($oldStatus, 'Active');

                $activeDelta    = $becameActive ? +1 : -1;
                $nonActiveDelta = $becameActive ? -1 : +1;

                self::statBump(self::T_UPD_STATUS, 'subscribers_active',     ['id_org' => $org], $activeDelta);
                self::statBump(self::T_UPD_STATUS, 'subscribers_non_active', ['id_org' => $org], $nonActiveDelta);
                self::statBump(self::T_UPD_STATUS, 'subscribers_active',     ['id_region' => self::REGION_TOTAL], $activeDelta);
                self::statBump(self::T_UPD_STATUS, 'subscribers_non_active', ['id_region' => self::REGION_TOTAL], $nonActiveDelta);
            }

            $priv = self::ifnull(self::val($new, 'id_cms_privileges'), self::val($old, 'id_cms_privileges'));

            if (self::eq($priv, self::PRIV_ABONENT)) {
                $id = self::ifnull(self::val($new, 'id'), self::val($old, 'id'));

                self::upd(self::T_UPD_STATUS, 'a_users', ['id' => $id], [
                    'name'       => self::ifnull(self::val($new, 'name'),       self::val($old, 'name')),
                    'status'     => self::ifnull(self::val($new, 'status'),     self::val($old, 'status')),
                    'id_org'     => self::ifnull(self::val($new, 'id_org'),     self::val($old, 'id_org')),
                    'address'    => self::ifnull(self::val($new, 'address'),    self::val($old, 'address')),
                    'psp'        => self::ifnull(self::val($new, 'psp'),        self::val($old, 'psp')),
                    'deposit'    => self::ifnull(self::val($new, 'deposit'),    self::val($old, 'deposit')),
                    'id_mahalla' => self::ifnull(self::val($new, 'id_mahalla'), self::val($old, 'id_mahalla')),
                ]);
            }
        });
    }

    /**
     * AFTER DELETE ON cms_users — `decUSers`
     *
     * @param array|object $oldRow
     * @return void
     */
    public static function decUSers($oldRow)
    {
        $old = self::row($oldRow);

        self::fire(self::T_DEC, function () use ($old) {
            $priv   = self::val($old, 'id_cms_privileges');
            $region = self::val($old, 'id_region');
            $org    = self::val($old, 'id_org');
            $status = self::val($old, 'status');
            $isAbon = self::eq($priv, self::PRIV_ABONENT);

            self::statBump(self::T_DEC, 'users', ['id_region' => self::REGION_TOTAL], -1);

            if ($isAbon) {
                self::statBump(self::T_DEC, 'subscribers', ['id_region' => self::REGION_TOTAL], -1);
                self::statBump(
                    self::T_DEC,
                    self::eq($status, 'Active') ? 'subscribers_active' : 'subscribers_non_active',
                    ['id_region' => self::REGION_TOTAL],
                    -1
                );
            }

            if (self::neq($region, self::REGION_TOTAL)) {
                self::statBump(self::T_DEC, 'users', ['id_region' => $region], -1);

                if ($isAbon) {
                    self::statBump(self::T_DEC, 'subscribers', ['id_region' => $region, 'id_org' => $org], -1);
                    self::statBump(
                        self::T_DEC,
                        self::eq($status, 'Active') ? 'subscribers_active' : 'subscribers_non_active',
                        ['id_region' => $region, 'id_org' => $org],
                        -1
                    );
                }
            }
        });
    }
}
