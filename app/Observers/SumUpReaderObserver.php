<?php

namespace App\Observers;

use App\Models\SumUpReader;

class SumUpReaderObserver
{
    public function created(SumUpReader $sumUpReader): void
    {
        try {
            $response = \Http::sumup()->post('/v0.1/merchants/'.config('services.sumup.merchant_code').'/readers', [
                'name' => $sumUpReader->name,
                'pairing_code' => (string) $sumUpReader->paring_code,
            ])->throw();

            $data = $response->json();
            if (isset($data['id'])) {
                $sumUpReader->remote_id = $data['id'];
                $sumUpReader->saveQuietly();
            }
        } catch (\Exception $e) {
            // Log the error but don't prevent the reader from being created locally
            \Log::error('Failed to create SumUp reader remotely', [
                'reader_id' => $sumUpReader->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function updated(SumUpReader $sumUpReader): void
    {
        // Only update if we have a remote_id and only the name field is dirty
        if ($sumUpReader->remote_id && $sumUpReader->isDirty('name') && ! $sumUpReader->isDirty('remote_id')) {
            try {
                \Http::sumup()->patch('/v0.1/merchants/'.config('services.sumup.merchant_code').'/readers/'.$sumUpReader->remote_id, [
                    'name' => $sumUpReader->name,
                ])->throw();
            } catch (\Exception $e) {
                \Log::error('Failed to update SumUp reader remotely', [
                    'reader_id' => $sumUpReader->id,
                    'remote_id' => $sumUpReader->remote_id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    public function deleted(SumUpReader $sumUpReader): void
    {
        if ($sumUpReader->remote_id) {
            try {
                \Http::sumup()->delete('/v0.1/merchants/'.config('services.sumup.merchant_code').'/readers/'.$sumUpReader->remote_id)->throw();
            } catch (\Exception $e) {
                \Log::error('Failed to delete SumUp reader remotely', [
                    'reader_id' => $sumUpReader->id,
                    'remote_id' => $sumUpReader->remote_id,
                    'error' => $e->getMessage(),
                ]);
                // Don't throw here - we still want to delete locally even if remote deletion fails
            }
        }
    }
}
