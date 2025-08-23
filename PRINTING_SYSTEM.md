# Printing System Documentation

This document describes how the printing system works in the Fursuit Registration Tool, utilizing QZ.io for client-side printing via WebSocket connections.

## System Architecture

The printing system consists of several components working together:

1. **Print Job Queue** (`print_jobs` table)
2. **QZ.io Client Integration** (JavaScript/Vue.js)  
3. **POS Application** (Point of Sale interface)
4. **Polling Mechanism** (Client-side job fetching)
5. **Laravel Background Jobs** (PDF generation and job creation)

## Database Schema

### `print_jobs` Table

```sql
CREATE TABLE print_jobs (
    id BIGINT PRIMARY KEY,
    printer_id BIGINT REFERENCES printers(id) ON DELETE CASCADE,
    printable_type VARCHAR(255),    -- Polymorphic relationship (Badge, Checkout, etc.)
    printable_id BIGINT,           -- ID of the printable model
    type VARCHAR(255),             -- PrintJobTypeEnum (badge, receipt)
    status VARCHAR(255),           -- PrintJobStatusEnum (pending, printed)
    file VARCHAR(255),             -- Path to PDF file in S3 storage
    printed_at TIMESTAMP NULL,     -- When the job was marked as printed
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Job Types and Status

### Print Job Types (`PrintJobTypeEnum`)
- `badge` - Badge printing jobs (color, duplex support)
- `receipt` - Receipt printing jobs (grayscale, single-sided)

### Print Job Status (`PrintJobStatusEnum`)
- `pending` - Job created and waiting to be printed
- `printed` - Job completed and marked as printed

## Print Job Creation Flow

### 1. Badge Printing

Badge printing is initiated through the `PrintBadgeJob` Laravel job:

```php
// app/Jobs/Printing/PrintBadgeJob.php
public function handle(): void
{
    // 1. Update badge status to "Printed"
    if ($this->badge->status_fulfillment->canTransitionTo(Printed::class)) {
        $this->badge->status_fulfillment->transitionTo(Printed::class);
    }

    // 2. Generate PDF using badge renderer
    $printer = new EF28_Badge;
    $pdfContent = $printer->getPdf($this->badge);
    
    // 3. Store PDF in S3 storage
    $filePath = 'badges/'.$this->badge->id.'.pdf';
    Storage::put($filePath, $pdfContent);
    
    // 4. Find appropriate printer
    $sendTo = Printer::where('is_active', true)
        ->where('type', 'badge')
        ->where('is_double', (bool) $this->badge->dual_side_print)
        ->firstOrFail();
    
    // 5. Create print job
    $this->badge->printJobs()->create([
        'printer_id' => $sendTo->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Pending,
        'file' => $filePath,
    ]);
}
```

### 2. Receipt Printing

Receipts are created through the `ReceiptController`:

```php
// app/Http/Controllers/ReceiptController.php
$checkout->printJobs()->create([
    'printer_id' => $printer->id,
    'type' => PrintJobTypeEnum::Receipt,
    'status' => PrintJobStatusEnum::Pending,
    'file' => $filePath,
]);
```

## QZ.io Client Integration

### Component Location
- Main QZ service: `resources/js/Components/POS/QZPrintService.vue`
- Integrated in: `resources/js/Layouts/POSLayout.vue`

### Authentication & Security

QZ.io requires certificate-based authentication:

```javascript
// Certificate and signature endpoints
qz.security.setCertificatePromise(function (resolve, reject) {
    fetch(route('pos.auth.qz.cert'), {
        cache: 'no-store', 
        headers: {'Content-Type': 'text/plain'}
    })
    .then(function (data) {
        data.ok ? resolve(data.text()) : reject(data.text());
    });
});

qz.security.setSignaturePromise(function (toSign) {
    return function (resolve, reject) {
        fetch("/pos/auth/qz/sign?request=" + toSign, {
            cache: 'no-store', 
            headers: {'Content-Type': 'text/plain'}
        })
        .then(function (data) {
            data.ok ? resolve(data.text()) : reject(data.text());
        });
    };
});
```

### WebSocket Connection

```javascript
function startQZPrint() {
    if(!qz.websocket.isActive()) {
        qz.websocket.connect().then(() => {
            console.log("Connected to QZ");
            findPrinters();
        }).catch((err) => {
            console.error(err);
        });
    } else {
        findPrinters();
    }
}
```

### Printer Discovery

The system automatically discovers available printers:

```javascript
function findPrinters() {
    qz.printers.details().then((printers) => {
        fetch(route('pos.auth.printers.store'), {
            method: "POST",
            body: JSON.stringify({printers: printers}),
            headers: {'Content-Type': 'application/json'}
        });
    }).catch((err) => {
        console.error(err);
    });
}
```

## Polling Mechanism

### Job Polling Loop

The client polls for new print jobs every 5 seconds:

```javascript
function pollPrintJobs() {
    setInterval(() => {
        fetch(route('pos.auth.printers.jobs'), {
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        })
        .then((data) => data.json())
        .then((printJobs) => {
            printJobs.data.forEach((job) => {
                processPrintJob(job);
            });
        })
    }, 5000) // Poll every 5 seconds
}
```

### Job Processing

Each print job is processed with appropriate printer settings:

```javascript
printJobs.data.forEach((job) => {
    // 1. Mark job as printed first
    fetch(route('pos.auth.printers.jobs.printed', {job: job.id}), {
        method: 'POST'
    }).then(() => {
        // 2. Configure printer options based on job type
        var printerOptions = (job.type === 'badge') ? {
            colorType: 'color',
            size: job.paper.mm,
            units: 'mm',
            duplex: job.duplex,
        } : {
            colorType: 'grayscale',
            size: [80],
            rasterize: true,
            units: 'mm',
            scaleContent: false,
        };
        
        // 3. Create QZ print configuration
        var config = qz.configs.create(job.printer, printerOptions);
        var data = [{
            type: 'pixel',
            format: 'pdf',
            flavor: 'file',
            data: job.file // S3 temporary URL
        }];
        
        // 4. Send to printer
        qz.print(config, data).catch((err) => {
            console.error(err);
        });
    });
});
```

## API Endpoints

### POS Authentication Routes

```php
// routes/pos-auth.php
Route::middleware('auth:machine')->group(function () {
    // QZ Tray authentication
    Route::get('/qz/sign', [QzCertController::class, 'sign'])->name('qz.sign');
    Route::get('/qz/cert', [QzCertController::class, 'cert'])->name('qz.cert');
    
    // Printer management
    Route::post('/printers/store', [PrinterController::class, 'store'])->name('printers.store');
    Route::get('/printers/jobs', [PrinterController::class, 'jobIndex'])->name('printers.jobs');
    Route::post('/printers/jobs/{job}/printed', [PrinterController::class, 'jobPrinted'])->name('printers.jobs.printed');
});
```

### Job Retrieval

The `jobIndex` method returns pending print jobs for the authenticated machine:

```php
public function jobIndex()
{
    $machine = auth('machine')->user();
    
    return PrintJob::whereHas('printer', fn ($query) => $query->where('machine_id', $machine->id))
        ->where('status', '=', PrintJobStatusEnum::Pending)
        ->limit(5)
        ->orderBy('created_at')
        ->get()
        ->map(fn (PrintJob $printJob) => [
            'id' => $printJob->id,
            'printer' => $printJob->printer->name,
            'type' => $printJob->type,
            'file' => Storage::drive('s3')->temporaryUrl($printJob->file, now()->addDay()),
            'paper' => collect($printJob->printer->paper_sizes)->where('name', $printJob->printer->default_paper_size)->first(),
            'duplex' => ($printJob->type === PrintJobTypeEnum::Receipt) ? false : $printJob->printable->dual_side_print,
        ])->values()->toArray();
}
```

## Print Job Lifecycle

1. **Creation**: Laravel job creates PDF and print job record
2. **Storage**: PDF is stored in S3 with temporary URL access
3. **Polling**: POS client polls for pending jobs every 5 seconds  
4. **Authentication**: QZ.io authenticates using certificates
5. **Processing**: Client marks job as printed, then sends to printer
6. **Completion**: Job status updated to 'printed' with timestamp

## Configuration Requirements

### Machine Setup

- POS machines must have `should_discover_printers` flag enabled
- QZ Tray software installed and running
- WebSocket connection capability
- Access to printer hardware

### Printer Requirements

- Printers must be registered and active in the system
- Badge printers: Support color printing and duplex
- Receipt printers: Support grayscale printing (80mm width)

### Security

- Certificate-based authentication for QZ.io
- Machine authentication for API access  
- S3 temporary URLs for secure file access
- HTTPS required for WebSocket connections

## Troubleshooting

### Common Issues

1. **QZ Connection Failed**: Check QZ Tray is running and accessible
2. **No Printers Found**: Verify printer discovery and registration
3. **Jobs Not Polling**: Check machine `should_discover_printers` setting
4. **Authentication Errors**: Verify QZ certificate and signature endpoints
5. **File Access Denied**: Check S3 temporary URL generation and permissions

### Debugging

- Browser console logs show QZ connection status and job processing
- Server logs track print job creation and API calls
- Database `print_jobs` table shows job status and timing
- QZ Tray logs provide detailed printer communication info

## File Storage

Print job PDFs are stored in S3 under the `badges/` directory with the pattern:
- Badge PDFs: `badges/{badge_id}.pdf`
- Receipt PDFs: `receipts/{checkout_id}.pdf`

Temporary URLs are generated with 24-hour expiration for security.