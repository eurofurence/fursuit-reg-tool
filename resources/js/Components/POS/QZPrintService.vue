<script setup>
import {usePage} from "@inertiajs/vue3";
import {onMounted} from "vue";
import qz from "qz-tray";

// Define emits for communicating status to parent
const emit = defineEmits({
    'qz-status-changed': (status) => {
        return status && typeof status === 'object';
    },
    'pending-jobs-updated': (count) => {
        return typeof count === 'number';
    }
});

const page = usePage();

// Helper function to safely emit events
function safeEmit(event, data) {
    try {
        emit(event, data);
    } catch (error) {
        console.error(`Failed to emit ${event}:`, error);
    }
}

onMounted(function() {
    qz.printers.setPrinterCallbacks((evt) => {
        if (evt.eventType === 'PRINTER') {
            if (evt.printerName === labelPrinter.value) {
                labelPrinterStatus.value = evt.statusText;
            }
            if (evt.printerName === documentPrinter.value) {
                documentPrinterStatus.value = evt.statusText;
            }
        }
    });
    qz.security.setCertificatePromise(function (resolve, reject) {
        fetch(route('pos.auth.qz.cert'), {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
            .then(function (data) {
                data.ok ? resolve(data.text()) : reject(data.text());
            });
    });
    qz.security.setSignatureAlgorithm("SHA512"); // Since 2.1
    qz.security.setSignaturePromise(function (toSign) {
        return function (resolve, reject) {
            fetch("/pos/auth/qz/sign?request=" + toSign, {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
                .then(function (data) {
                    data.ok ? resolve(data.text()) : reject(data.text());
                });
        };
    });

    if(page.props.auth.machine.should_discover_printers) {
        startQZPrint();
        // start polling for print jobs
        pollPrintJobs();
        // start periodic health checks
        startHealthCheck();
        
        // QZ doesn't have setStatusCallbacks, we'll rely on connection promises and health checks
    }
})

// Periodic health check to ensure status accuracy
let lastKnownStatus = null;

function startHealthCheck() {
    setInterval(() => {
        const isActive = qz.websocket.isActive();
        const currentStatus = isActive ? 'connected' : 'disconnected';
        
        // Only emit if status actually changed
        if (lastKnownStatus !== currentStatus) {
            console.log(`QZ status changed from ${lastKnownStatus} to ${currentStatus}`);
            lastKnownStatus = currentStatus;
            
            safeEmit('qz-status-changed', {
                qz_status: currentStatus,
                is_connected: isActive,
                last_seen: new Date().toISOString()
            });
        }
        
        // Update backend status if it doesn't match current state
        updateConnectionStatus(currentStatus);
        
        // Try to reconnect if disconnected and should be connected
        if (!isActive && page.props.auth.machine.should_discover_printers) {
            console.log('QZ disconnected, attempting reconnect...');
            startQZPrint();
        }
    }, 5000); // Check every 5 seconds for more responsive updates
}

// Sequential job processing to ensure proper order and error handling
async function processNextJob(jobs, index) {
    if (index >= jobs.length) {
        console.log('All print jobs processed');
        return;
    }

    const job = jobs[index];
    console.log(`Processing job ${index + 1}/${jobs.length}:`, job);

    try {
        // Set up printer configuration
        const printerOptions = (job.type === 'badge') ? {
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

        console.log('Printer options:', printerOptions);
        
        const config = qz.configs.create(job.printer, printerOptions);
        const data = [{
            type: 'pixel',
            format: 'pdf',
            flavor: 'file',
            data: job.file
        }];

        // Send to printer and wait for completion
        await qz.print(config, data);
        console.log(`Job ${job.id} printed successfully`);

        // Mark job as printed on success
        await fetch(route('pos.auth.printers.jobs.printed', {job: job.id}), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        // Process next job after successful completion
        setTimeout(() => processNextJob(jobs, index + 1), 1000); // Small delay between jobs
        
    } catch (error) {
        console.error(`Failed to print job ${job.id}:`, error);
        
        // Report job failure to backend for retry logic
        try {
            await fetch(route('pos.auth.printers.jobs.failed', {job: job.id}), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': page.props.csrf_token || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({
                    error_message: error.message || 'Print job failed'
                })
            });
            console.log(`Job ${job.id} marked as failed`);
        } catch (reportError) {
            console.error(`Failed to report job failure for job ${job.id}:`, reportError);
        }
        
        // Continue with next job after longer delay
        setTimeout(() => processNextJob(jobs, index + 1), 2000); // Longer delay on error
    }
}



// Function to update connection status on the server
async function updateConnectionStatus(status) {
    try {
        const response = await fetch(route('pos.auth.machine.status.update'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ status })
        });
        
        if (response.ok) {
            console.log(`QZ status updated to: ${status}`);
            // Emit status change to parent component
            safeEmit('qz-status-changed', {
                qz_status: status,
                is_connected: status === 'connected',
                last_seen: new Date().toISOString()
            });
        } else {
            console.error('Failed to update QZ connection status:', response.status, response.statusText);
        }
    } catch (error) {
        console.error('Failed to update QZ connection status:', error);
    }
}

function startQZPrint() {
    if(!qz.websocket.isActive()) {
        qz.websocket.connect().then(() => {
            console.log("Connected to QZ");
            updateConnectionStatus('connected');
            findPrinters();
        }).catch((err) => {
            console.error("QZ connection failed:", err);
            updateConnectionStatus('error');
            // Emit error status to parent
            safeEmit('qz-status-changed', {
                qz_status: 'error',
                is_connected: false,
                last_seen: new Date().toISOString()
            });
        });
    } else {
        updateConnectionStatus('connected');
        findPrinters();
    }
}

function pollPrintJobs() {
    setInterval(() => {
        fetch(route('pos.auth.printers.jobs'), {
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then((data) => data.json())
            .then((printJobs) => {
                // Handle both direct array and paginated response structures
                const jobs = Array.isArray(printJobs) ? printJobs : (printJobs.data || []);
                
                // Process jobs sequentially, not all at once
                if (jobs.length > 0) {
                    processNextJob(jobs, 0);
                }
                
                // Emit pending jobs count update
                safeEmit('pending-jobs-updated', jobs.length);
            })
            .catch((error) => {
                console.error('Failed to fetch print jobs:', error);
            });
    }, 5000);
}

function findPrinters() {
    qz.printers.details().then((printers) => {
        fetch(route('pos.auth.printers.store'), {
            method: "POST",
            body: JSON.stringify({printers: printers}),
            headers: {
                'Content-Type': 'application/json'
            }
        });
    }).catch((err) => {
        console.error(err);
    });
}
</script>

<template>

</template>

<style scoped>

</style>
