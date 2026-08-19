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
