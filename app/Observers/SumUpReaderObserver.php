<?php

namespace App\Observers;

use App\Models\SumUpReader;

class SumUpReaderObserver
{
    public function created(SumUpReader $sumUpReader): void
    {
        $response = \Http::sumup()->post("/v0.1/merchants/".config('services.sumup.merchant_code')."/readers", [
            'name' => $sumUpReader->name,
            'pairing_code' => (string) $sumUpReader->paring_code,
        ])->throw();
        $data = $response->json();
        $sumUpReader->remote_id = $data['id'];
        $sumUpReader->save();
    }

    public function updated(SumUpReader $sumUpReader): void
    {
        \Http::sumup()->patch("/v0.1/merchants/".config('services.sumup.merchant_code')."/readers/".$sumUpReader->remote_id, [
            'name' => $sumUpReader->name,
        ])->throw();
    }

    public function deleted(SumUpReader $sumUpReader): void
    {
        \Http::sumup()->delete("/v0.1/merchants/".config('services.sumup.merchant_code')."/readers/".$sumUpReader->remote_id)->throw();
    }
}
