<?php

namespace App\Jobs;

use Apirone\Lib\PhpQRCode\QRCode;
use App\Domain\Checkout\Models\Checkout\Checkout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class CreateReceiptFromCheckoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Checkout $checkout)
    {
    }

    public function handle(): void
    {
        $checkout = $this->checkout;
        $checkout->load('items');
        $qrcodeData = $checkout->fiskaly_data['qr_code_data'] ?? null;
        $options = [
            's' => 'qrm',
            'fc' => '#000000',
            'bc' => '#FFFFFF',
            // ...
        ];
        $base64_qr_encoded = QrCode::png($qrcodeData, $options);
        $receiptHtml = view('receipts.sale', ['checkout' => $checkout, 'qr' => $base64_qr_encoded])->render();

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new \Mpdf\Mpdf([
            'format' => [80, 500],
            'mode' => 'utf-8',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            // font
            'fontDir' => array_merge($fontDirs, [
                resource_path('assets/fonts'),
            ]),
            'fontdata' => $fontData + [ // lowercase letters only in font key
                    'fragmentmono' => [
                        'R' => 'FragmentMono-Regular.ttf',
                    ]
                ],
            'default_font' => 'fragmentmono'
        ]);
        $mpdf->WriteHTML($receiptHtml);

        \Storage::put('checkouts/' . $this->checkout->id . '.pdf', $mpdf->Output($this->checkout->id . '.pdf', \Mpdf\Output\Destination::STRING_RETURN));
    }
}
