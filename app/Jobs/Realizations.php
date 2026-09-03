<?php

namespace App\Jobs;

use App\Actions\IReal;
use Illuminate\Bus\Queueable;
use Exception;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class Realizations implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($payload) {
        $this->data = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle() {
        if (env('JOBS_DISABLE', false)) return;
        if (!isset($this->data['hash']) || !isset($this->data['method']) || !isset($this->data['action']) || !isset($this->data['rb'])) {
            // Antispoof KADRI (frame) haqidagi yozuv — realizatsiya EMAS.
            // egaz tomonidagi `failed_jobs_manual` qayta yuborish mexanizmi
            // (PaymentData::retryFailedManual) xato manzil tanlab, bunday
            // yozuvni shu yerga jo'natgan edi. Xato manba tuzatilgan, lekin
            // jadvalda yotgan eski qatorlar baribir kelib qolishi mumkin:
            // exception otib navbatni band qilmaymiz va failed_jobs ni
            // shishirmaymiz — rasmni inobatga olmasdan o'tkazib yuboramiz,
            // keyingi realizatsiyalar indekslanishda davom etadi.
            if (isset($this->data['id_request_ballon_id']) || isset($this->data['file'])) {
//                \Log::warning('Queue realizations: frame (rasm) payload — indekslanmaydi, o`tkazib yuborildi; rb='
//                    . (isset($this->data['id_request_ballon_id']) ? $this->data['id_request_ballon_id'] : '?'));
                return;
            }
            //\Log::debug($this->data['rb']);
            throw new Exception('Wrong input parameters!', 103);
        }
        $rb = json_decode($this->data['rb'], true);
        $result = "FAILED";
        switch ($this->data['method']) {
            case 'realizations':
                if ($this->data['action'] == 'add') {
                    (new IReal())->handle([$rb]);
                    $result = 'OK';
                }
                break;
            default:
                $result = 'Unknowen!';
                break;
        }
        \Log::info('Queue ' . $this->data['method'] . '_' . $this->data['action'] . ': ' . $rb['id'] . ' -> ' . $result);
    }
}
