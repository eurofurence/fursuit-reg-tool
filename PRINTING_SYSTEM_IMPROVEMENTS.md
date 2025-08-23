# QZ.io Print System Integration Improvements Plan

## Current Implementation Analysis

### Methods Currently Used

**WebSocket Connection:**
- `qz.websocket.connect()` - Establishes connection to QZ Tray
- `qz.websocket.isActive()` - Checks if connection is active

**Security/Authentication:**
- `qz.security.setCertificatePromise()` - Sets up certificate authentication
- `qz.security.setSignaturePromise()` - Sets up signature authentication  
- `qz.security.setSignatureAlgorithm("SHA512")` - Sets signing algorithm

**Printer Management:**
- `qz.printers.details()` - Discovers available printers
- `qz.printers.setPrinterCallbacks()` - Sets up basic printer callbacks (limited use)

**Print Operations:**
- `qz.configs.create()` - Creates printer configuration
- `qz.print()` - Sends print jobs (fire-and-forget approach)

### Current Limitations

1. **No Print Job Tracking**: Current system marks jobs as "printed" before actually printing
2. **No Status Feedback**: No real-time printer status monitoring
3. **No Error Recovery**: Failed jobs are lost, no retry mechanism
4. **Bulk Processing**: Processes all jobs at once without sequencing
5. **No Connection Status Display**: No visual feedback on QZ connection status
6. **Limited Printer Status**: Basic printer callbacks, no comprehensive status monitoring

## Proposed Improvements

### 1. Print Server Machine Designation

#### Database Changes Required:
```sql
-- Add print server designation to machines table
ALTER TABLE machines ADD COLUMN is_print_server BOOLEAN DEFAULT false;

-- Add QZ connection tracking
ALTER TABLE machines ADD COLUMN qz_connection_status ENUM('connected', 'disconnected', 'error') DEFAULT 'disconnected';
ALTER TABLE machines ADD COLUMN qz_last_seen_at TIMESTAMP NULL;
```

#### Machine Model Updates:
- Add `is_print_server` boolean field
- Add `qz_connection_status` enum field  
- Add `qz_last_seen_at` timestamp field
- Add method to get pending print job count for this machine

### 2. Enhanced Print Job Status Tracking

#### New QZ.io Methods to Implement:

**Printer Status Monitoring:**
```javascript
// Set up comprehensive printer status callbacks
qz.printers.setPrinterCallbacks((evt) => {
    // Handle events: "JOB" and "PRINTER" types
    // Process severity levels: FATAL, ERROR, WARN, INFO
    // Track specific printer status changes
});

// Start listening to specific printers
qz.printers.startListening("PrinterName");

// Get current printer status
qz.printers.getStatus("PrinterName");

// Stop listening when no longer needed
qz.printers.stopListening("PrinterName");
```

**Enhanced Print Job Management:**
```javascript
// Sequential print job processing with proper tracking
function processNextPrintJob() {
    if (currentPrintJob) return; // One job at a time
    
    const job = getNextPendingJob();
    if (!job) return;
    
    currentPrintJob = job;
    updateJobStatus(job.id, 'printing');
    
    qz.print(config, data)
        .then(() => {
            updateJobStatus(job.id, 'printed');
            currentPrintJob = null;
            processNextPrintJob(); // Process next job
        })
        .catch((error) => {
            handlePrintError(job, error);
            currentPrintJob = null;
        });
}
```

### 3. Real-Time Status Display

#### UI Components to Add:

**Print Server Status Component:**
```vue
<template>
    <div class="print-server-status">
        <!-- QZ Connection Status -->
        <div class="connection-status">
            <span class="status-dot" :class="qzStatus"></span>
            QZ Print: {{ qzStatusText }}
        </div>
        
        <!-- Pending Jobs Counter -->
        <div class="pending-jobs" v-if="pendingJobCount > 0">
            <Icon name="printer" />
            {{ pendingJobCount }} pending jobs
        </div>
        
        <!-- Current Print Job -->
        <div class="current-job" v-if="currentJob">
            Printing: {{ currentJob.name }}
        </div>
    </div>
</template>
```

### 4. Print Queue Management System

#### New Print Job Statuses:
```php
enum PrintJobStatusEnum: string
{
    case Pending = 'pending';
    case Queued = 'queued';           // NEW: In client queue
    case Printing = 'printing';       // NEW: Currently printing
    case Printed = 'printed';
    case Failed = 'failed';           // NEW: Print failed
    case Cancelled = 'cancelled';     // NEW: Job cancelled
}
```

#### Enhanced Print Job Model:
```php
class PrintJob extends Model
{
    // Add new fields
    protected $fillable = [
        'printer_id',
        'printable_type',
        'printable_id', 
        'type',
        'status',
        'file',
        'printed_at',
        'queued_at',        // NEW: When job entered client queue
        'started_at',       // NEW: When printing started
        'failed_at',        // NEW: When job failed
        'error_message',    // NEW: Error details
        'retry_count',      // NEW: Number of retry attempts
        'machine_id',       // NEW: Which machine is processing
    ];
    
    // Add status transition methods
    public function markAsQueued(): void;
    public function markAsPrinting(): void;
    public function markAsPrinted(): void;
    public function markAsFailed(string $error): void;
}
```

### 5. Robust Error Handling & Retry Logic

#### Printer Error Detection:
```javascript
function handlePrinterStatus(evt) {
    if (evt.eventType === 'PRINTER') {
        switch(evt.statusCode) {
            case 'media-empty':
            case 'media-jam':
            case 'offline':
                pausePrintQueue();
                showPrinterError(evt.printerName, evt.message);
                break;
            case 'online':
                resumePrintQueue();
                clearPrinterError(evt.printerName);
                break;
        }
    }
}

function handleJobStatus(evt) {
    if (evt.eventType === 'JOB') {
        const job = findJobByName(evt.jobName);
        if (job) {
            if (evt.severity === 'ERROR' || evt.severity === 'FATAL') {
                markJobAsFailed(job.id, evt.message);
            }
        }
    }
}
```

#### Retry Logic:
```javascript
async function retryFailedJob(job, maxRetries = 3) {
    if (job.retryCount >= maxRetries) {
        markJobAsPermanentlyFailed(job.id);
        return;
    }
    
    // Wait before retry (exponential backoff)
    const delay = Math.pow(2, job.retryCount) * 1000;
    await new Promise(resolve => setTimeout(resolve, delay));
    
    incrementRetryCount(job.id);
    attemptPrintJob(job);
}
```

### 6. Enhanced API Endpoints

#### New/Modified Routes:
```php
// Machine status updates
Route::post('/machines/{machine}/qz-status', [MachineController::class, 'updateQzStatus']);
Route::get('/machines/{machine}/print-stats', [MachineController::class, 'getPrintStats']);

// Enhanced print job management  
Route::post('/printers/jobs/{job}/queued', [PrinterController::class, 'markJobAsQueued']);
Route::post('/printers/jobs/{job}/printing', [PrinterController::class, 'markJobAsPrinting']);
Route::post('/printers/jobs/{job}/failed', [PrinterController::class, 'markJobAsFailed']);
Route::post('/printers/jobs/{job}/retry', [PrinterController::class, 'retryJob']);

// Printer status updates
Route::post('/printers/{printer}/status', [PrinterController::class, 'updatePrinterStatus']);
```

#### Enhanced Job API Response:
```json
{
    "data": [
        {
            "id": 123,
            "printer": "Badge_Printer_01",
            "type": "badge", 
            "file": "https://s3-url...",
            "status": "pending",
            "created_at": "2024-01-01T12:00:00Z",
            "queued_at": null,
            "started_at": null,
            "retry_count": 0,
            "paper": {...},
            "duplex": true,
            "priority": 1
        }
    ],
    "meta": {
        "total_pending": 5,
        "total_printing": 1,
        "printer_status": {
            "Badge_Printer_01": "online",
            "Receipt_Printer_01": "media-empty"
        }
    }
}
```

### 7. Print Queue Processing Logic

#### Sequential Job Processing:
```javascript
class PrintQueueManager {
    constructor() {
        this.currentJobs = new Map(); // printer -> current job
        this.printerStatus = new Map(); // printer -> status
        this.queuePaused = false;
    }
    
    async processQueue() {
        if (this.queuePaused) return;
        
        const availablePrinters = this.getAvailablePrinters();
        
        for (const printer of availablePrinters) {
            if (!this.currentJobs.has(printer)) {
                const job = await this.getNextJobForPrinter(printer);
                if (job) {
                    this.startPrintJob(printer, job);
                }
            }
        }
    }
    
    async startPrintJob(printer, job) {
        this.currentJobs.set(printer, job);
        
        try {
            await this.markJobAsQueued(job.id);
            await this.markJobAsPrinting(job.id);
            
            const config = qz.configs.create(printer);
            const data = [{ type: 'pixel', format: 'pdf', flavor: 'file', data: job.file }];
            
            await qz.print(config, data);
            await this.markJobAsPrinted(job.id);
            
        } catch (error) {
            await this.markJobAsFailed(job.id, error.message);
            this.scheduleRetry(job);
        } finally {
            this.currentJobs.delete(printer);
        }
    }
}
```

### 8. Real-Time Updates via WebSocket/Server-Sent Events

#### Client-Side Status Broadcasting:
```javascript
function broadcastMachineStatus() {
    const status = {
        machine_id: MACHINE_ID,
        qz_connected: qz.websocket.isActive(),
        pending_jobs: Object.keys(pendingJobs).length,
        current_jobs: Array.from(currentJobs.values()),
        printer_status: Object.fromEntries(printerStatus),
        last_updated: new Date().toISOString()
    };
    
    fetch('/pos/auth/machines/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(status)
    });
}

// Broadcast status every 30 seconds
setInterval(broadcastMachineStatus, 30000);
```

## Implementation Priority

### Phase 1: Core Infrastructure
1. Database schema updates (print job statuses, machine fields)
2. Enhanced PrintJob model with new statuses
3. Basic UI status components

### Phase 2: Print Job Tracking  
1. Sequential print job processing
2. Enhanced QZ.io printer status monitoring
3. Real-time status updates to server

### Phase 3: Error Handling & Recovery
1. Retry logic implementation
2. Printer error detection and handling
3. Print queue pause/resume functionality

### Phase 4: Advanced Features
1. Print job prioritization
2. Advanced printer diagnostics
3. Historical print job analytics
4. Print server load balancing

## Expected Benefits

1. **Reliability**: No more lost print jobs, proper error handling
2. **Visibility**: Real-time status of print operations
3. **Troubleshooting**: Clear error messages and retry capabilities  
4. **Performance**: Sequential processing prevents printer overload
5. **Scalability**: Print server designation allows distributed printing
6. **User Experience**: Visual feedback on print system status

## Testing Strategy

1. **Unit Tests**: Print job state transitions and retry logic
2. **Integration Tests**: QZ.io API interactions and error scenarios
3. **Load Testing**: Multiple simultaneous print jobs
4. **Failure Testing**: Printer offline, out of ink, paper jam scenarios
5. **Recovery Testing**: Print server restart, network interruption scenarios