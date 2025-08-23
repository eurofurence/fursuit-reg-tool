<script setup>
import {usePage} from "@inertiajs/vue3";
import {onMounted} from "vue";
import { useToast } from 'primevue/usetoast';
import qz from "qz-tray";

// Define emits for communicating status to parent
const emit = defineEmits({
    'qz-status-changed': (status) => {
        return status && typeof status === 'object';
    },
    'pending-jobs-updated': (count) => {
        return typeof count === 'number';
    },
    'printer-states-updated': (states) => {
        return states && typeof states === 'object';
    }
});

const page = usePage();
const toast = useToast();

// Timestamp tracking for printer states optimization
let lastPrinterStatesTimestamp = null;

// Helper function to safely emit events
function safeEmit(event, data) {
    try {
        emit(event, data);
    } catch (error) {
        console.error(`Failed to emit ${event}:`, error);
    }
}

onMounted(function() {
    // Enhanced printer callbacks with full status reporting for both printer and job events
    qz.printers.setPrinterCallbacks(async (evt) => {
        console.log('🔔 QZ Event Received:', {
            eventType: evt.eventType,
            jobName: evt.jobName,
            printerName: evt.printerName,
            status: evt.status,
            statusText: evt.statusText,
            message: evt.message,
            severity: evt.severity,
            fullEvent: evt
        });

        // Handle different event types - both printer and job events come through this callback
        if (evt.eventType === 'JOB' && evt.jobName) {
            // Handle job-specific events
            console.log(`📄 Processing JOB event for: ${evt.jobName}`);
            try {
                await reportJobStatus(evt);
                console.log(`✅ Successfully reported job status for: ${evt.jobName}`);
            } catch (error) {
                console.error(`❌ Failed to report job status for ${evt.jobName}:`, error);
            }
        } else if (evt.eventType === 'PRINTER' || !evt.eventType) {
            // Handle printer-specific events
            console.log(`🖨️  Processing PRINTER event for: ${evt.printerName}`);
            try {
                await reportPrinterStatus(evt);
                console.log(`✅ Successfully reported printer status for: ${evt.printerName}`);
            } catch (error) {
                console.error(`❌ Failed to report printer status for ${evt.printerName}:`, error);
            }
        } else {
            console.log('⚠️  Unknown event type or missing required fields:', evt);
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
        // start controlled job processing (no background polling)
        startJobProcessing();
        // start periodic health checks
        startHealthCheck();
        // start periodic printer states syncing
        startPrinterStatesSync();

        // QZ doesn't have setStatusCallbacks, we'll rely on connection promises and health checks
    }
})

// Periodic health check to ensure status accuracy
let lastKnownStatus = null;
let healthCheckCounter = 0;

function startHealthCheck() {
    setInterval(() => {
        const isActive = qz.websocket.isActive();
        const currentStatus = isActive ? 'connected' : 'disconnected';
        healthCheckCounter++;

        // Only emit and update backend if status actually changed
        if (lastKnownStatus !== currentStatus) {
            console.log(`QZ status changed from ${lastKnownStatus} to ${currentStatus}`);
            lastKnownStatus = currentStatus;

            safeEmit('qz-status-changed', {
                qz_status: currentStatus,
                is_connected: isActive,
                last_seen: new Date().toISOString()
            });

            // Only update backend when status actually changes
            updateConnectionStatus(currentStatus);
        }
        // No more periodic backend updates - only when status changes!

        // Try to reconnect if disconnected and should be connected
        if (!isActive && page.props.auth.machine.should_discover_printers) {
            console.log('QZ disconnected, attempting reconnect...');
            startQZPrint();
        }
    }, 4000); // Check every 4 seconds for more responsive updates
}

// Track active print jobs for matching with QZ events
let activePrintJobs = new Map(); // Map of printer name to {jobId, qzJobName, timestamp}
let printerPreviousStatus = new Map(); // Track previous printer status for completion detection

// Multi-printer state management - now synced with backend
let availablePrinters = new Set(); // Set of printer names
let jobProcessingInterval = null;
let printerStatesSyncInterval = null;
const machineName = page.props.auth.machine?.name || 'Unknown';

// Report job status events to backend
async function reportJobStatus(evt) {
    if (!evt.jobName) {
        console.warn('Job event missing job name:', evt);
        return;
    }

    let jobId = null;
    let qzJobName = evt.jobName;

    // Try to extract job ID from our custom job name format: Job_{id}_{timestamp}
    const jobIdMatch = evt.jobName.match(/Job_(\d+)_/);
    if (jobIdMatch) {
        jobId = parseInt(jobIdMatch[1]);
        console.log(`✅ Found job ID ${jobId} from custom job name: ${evt.jobName}`);
    } else {
        // Handle QZ-Tray default job names like "Java Printing"
        console.log(`⚠️  QZ using default job name: ${evt.jobName}, attempting to match by printer and timing`);

        // Look for active jobs on this printer (most recent within 30 seconds)
        const activeJob = activePrintJobs.get(evt.printerName);
        if (activeJob && (Date.now() - activeJob.timestamp) < 30000) {
            jobId = activeJob.jobId;
            qzJobName = activeJob.qzJobName; // Use our original job name
            console.log(`✅ Matched QZ job "${evt.jobName}" to our job ${jobId} (${qzJobName}) on printer ${evt.printerName}`);
        } else {
            console.warn(`❌ Unable to match QZ job "${evt.jobName}" to any active job on printer ${evt.printerName}`);
            return;
        }
    }

    if (!jobId) {
        console.warn('Unable to determine job ID for job:', evt.jobName);
        return;
    }
    const statusData = {
        job_id: jobId,
        qz_job_name: qzJobName, // Use our original job name, not QZ's default
        status: evt.statusText || evt.status || 'UNKNOWN',
        event_type: evt.eventType || 'JOB',
        severity: evt.severity || 'INFO',
        message: evt.message || `Job ${evt.eventType || 'STATUS'}: ${evt.statusText || evt.status}`,
        printer_name: evt.printerName || 'Unknown'
    };

    try {
        const response = await fetch(route('pos.auth.printers.jobs.qz-status', {job: jobId}), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(statusData)
        });

        if (!response.ok) {
            console.error('Failed to report job status:', response.status, response.statusText);
        } else {
            console.log('Job status reported successfully:', statusData);

            // Clean up tracking for completed/failed jobs to prevent memory leaks
            if (['COMPLETE', 'FINISHED', 'ERROR', 'FAILED', 'ABORTED'].includes(evt.statusText?.toUpperCase())) {
                // Find and remove the tracking entry for this job
                for (const [printerName, trackedJob] of activePrintJobs.entries()) {
                    if (trackedJob.jobId === jobId) {
                        activePrintJobs.delete(printerName);
                        console.log(`🧹 Cleaned up tracking for completed job ${jobId} on printer ${printerName}`);
                        break;
                    }
                }
            }
        }
    } catch (error) {
        console.error('Error reporting job status:', error);
    }
}

// Report printer status events to backend
async function reportPrinterStatus(evt) {
    if (!evt.printerName) {
        console.warn('Printer event missing printer name:', evt);
        return;
    }

    const eventData = {
        printer_name: evt.printerName,
        status: evt.statusText || evt.status || 'UNKNOWN',
        event_type: evt.eventType || 'PRINTER',
        severity: evt.severity || 'INFO',
        message: evt.message || `${evt.eventType || 'STATUS'}: ${evt.statusText || evt.status}`
    };

    try {
        // Send to the NEW printer events webhook for immediate handling
        const eventResponse = await fetch(route('pos.auth.printers.events'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(eventData)
        });

        if (!eventResponse.ok) {
            console.error('Failed to report printer event:', eventResponse.status, eventResponse.statusText);
        } else {
            const eventResult = await eventResponse.json();
            console.log('Printer event reported successfully:', eventData);
            console.log('Event handling result:', eventResult);

            // Show toast if printer was paused due to this event
            if (eventResult.handled) {
                toast.add({
                    severity: 'error',
                    summary: `Printer ${evt.printerName} Paused`,
                    detail: eventResult.action_taken,
                    life: 0 // Sticky toast
                });
            }
        }

        // Also send to legacy status endpoint for compatibility
        await fetch(route('pos.auth.printers.status'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(eventData)
        });

        // Check for printer status transitions that indicate job completion
        await checkPrinterStatusCompletion(evt);

    } catch (error) {
        console.error('Error reporting printer status:', error);
    }
}

// Update job status during processing
async function updateJobStatus(jobId, status, additionalData = {}) {
    try {
        const response = await fetch(route('pos.auth.printers.jobs.status', {job: jobId}), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                status,
                ...additionalData
            })
        });

        if (response.ok) {
            const data = await response.json();
            console.log(`Job ${jobId} status updated to: ${data.new_status}`);
        } else {
            console.error(`Failed to update job ${jobId} status:`, response.status);
        }
    } catch (error) {
        console.error(`Error updating job ${jobId} status:`, error);
    }
}

// Check printer status changes for job completion detection
async function checkPrinterStatusCompletion(evt) {
    const currentStatus = (evt.statusText || evt.status || '').toUpperCase();
    const previousStatus = printerPreviousStatus.get(evt.printerName);

    // Update the previous status for next time
    printerPreviousStatus.set(evt.printerName, currentStatus);

    // Check if printer went from processing/busy state to idle/ok state
    const processingStates = ['PROCESSING', 'BUSY', 'PRINTING', 'RENDERING'];
    const idleStates = ['OK', 'IDLE', 'READY'];

    const wasProcessing = previousStatus && processingStates.includes(previousStatus);
    const nowIdle = idleStates.includes(currentStatus);

    if (wasProcessing && nowIdle) {
        console.log(`🔄 Printer ${evt.printerName} transitioned from ${previousStatus} to ${currentStatus} - checking for job completion`);

        // Look for active jobs on this printer
        const activeJob = activePrintJobs.get(evt.printerName);
        if (activeJob && (Date.now() - activeJob.timestamp) < 60000) { // Within last 60 seconds
            console.log(`🎯 Found active job ${activeJob.jobId} on printer ${evt.printerName}, marking as complete based on printer status`);

            try {
                // Mark job as completed using the manual completion endpoint
                const response = await fetch(route('pos.auth.printers.jobs.printed', {job: activeJob.jobId}), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        completion_source: 'printer_status_transition',
                        previous_printer_status: previousStatus,
                        current_printer_status: currentStatus,
                        qz_job_name: activeJob.qzJobName
                    })
                });

                if (response.ok) {
                    console.log(`✅ Successfully marked job ${activeJob.jobId} as printed based on printer status`);

                    // Show success toast for job completion
                    toast.add({
                        severity: 'success',
                        summary: 'Print Completed',
                        detail: `Job #${activeJob.jobId} printed successfully`,
                        life: 4000
                    });

                    // Clean up tracking
                    activePrintJobs.delete(evt.printerName);
                } else {
                    console.error(`❌ Failed to mark job ${activeJob.jobId} as printed:`, response.status);
                }
            } catch (error) {
                console.error(`❌ Error marking job ${activeJob.jobId} as complete:`, error);
            }
        } else if (activeJob) {
            console.log(`⚠️  Found old job ${activeJob.jobId} on printer ${evt.printerName} (age: ${Date.now() - activeJob.timestamp}ms), ignoring`);
        }
    }
}

// This function has been replaced by the new controlled job processing system

// Fallback mechanism for job completion when QZ-Tray doesn't report status
function startJobCompletionFallback(jobId, qzJobName, timeoutMs) {
    console.log(`⏰ Starting fallback timer for job ${jobId} (${qzJobName}) - ${timeoutMs}ms`);

    setTimeout(async () => {
        try {
            // Check if job is still in printing state
            const response = await fetch(route('pos.auth.printers.jobs.show', {job: jobId}), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok) {
                const jobData = await response.json();

                if (jobData.status === 'printing') {
                    console.log(`⚠️  Fallback triggered: Job ${jobId} still in printing state after ${timeoutMs}ms`);
                    console.log(`🔄 Attempting to mark job ${jobId} as printed via fallback`);

                    // Use the manual completion endpoint
                    const completionResponse = await fetch(route('pos.auth.printers.jobs.printed', {job: jobId}), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            fallback_reason: 'QZ-Tray completion event not received within timeout',
                            qz_job_name: qzJobName,
                            timeout_ms: timeoutMs
                        })
                    });

                    if (completionResponse.ok) {
                        console.log(`✅ Fallback successful: Job ${jobId} marked as printed`);

                        // Show success toast for fallback completion
                        toast.add({
                            severity: 'success',
                            summary: 'Print Completed',
                            detail: `Job #${jobId} printed successfully (timeout fallback)`,
                            life: 4000
                        });
                    } else {
                        console.error(`❌ Fallback failed for job ${jobId}:`, completionResponse.status);
                    }
                } else {
                    console.log(`✅ Job ${jobId} already completed (${jobData.status}) - fallback not needed`);
                }
            }
        } catch (error) {
            console.error(`❌ Error in fallback for job ${jobId}:`, error);
        }
    }, timeoutMs);
}

// Backend-synced printer state management functions
async function updatePrinterState(printerName, status, currentJob = null, error = null) {
    console.log(`📊 Updating printer ${printerName} state to '${status}' in backend`);

    try {
        const response = await fetch(route('pos.auth.printer-states.update'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                name: printerName,
                status: status,
                current_job_id: currentJob?.id || null,
                last_error_message: error?.message || null,
                machine_name: machineName
            })
        });

        if (response.ok) {
            console.log(`✅ Successfully updated printer ${printerName} state in backend`);
            // Sync printer states after update
            await syncPrinterStates();
        } else {
            console.error(`❌ Failed to update printer ${printerName} state:`, response.status);
        }
    } catch (error) {
        console.error(`❌ Error updating printer ${printerName} state:`, error);
    }
}

async function syncPrinterStates() {
    try {
        const headers = {
            'Accept': 'application/json'
        };

        // Add If-Modified-Since header if we have a timestamp
        if (lastPrinterStatesTimestamp) {
            headers['If-Modified-Since'] = lastPrinterStatesTimestamp;
        }

        const response = await fetch(route('pos.auth.printer-states.api'), { headers });

        // 304 Not Modified - nothing changed
        if (response.status === 304) {
            console.log('📡 Printer states unchanged (304), skipping update');
            return {}; // Return empty to avoid further processing
        }

        if (response.ok) {
            const data = await response.json();
            const statesArray = data.states || data; // Handle both new and old formats
            const newTimestamp = response.headers.get('Last-Modified') || data.last_updated;

            lastPrinterStatesTimestamp = newTimestamp;
            console.log(`📡 Synced printer states from backend (timestamp: ${newTimestamp}):`, statesArray);

            // Convert array to keyed object for easier lookup in job processing
            const statesObject = {};
            statesArray.forEach(printer => {
                statesObject[printer.name] = printer;
            });

            safeEmit('printer-states-updated', statesObject);
            return statesObject;
        } else {
            console.error('Failed to sync printer states:', response.status);
            return {};
        }
    } catch (error) {
        console.error('Error syncing printer states:', error);
        return {};
    }
}

// Initialize printer when discovered
async function initializePrinter(printerName) {
    availablePrinters.add(printerName);
    console.log(`🖨️  Initializing printer: ${printerName}`);

    // Initialize as idle in backend if not exists
    await updatePrinterState(printerName, 'idle');
}

// Start periodic syncing of printer states
function startPrinterStatesSync() {
    // Only sync once on startup - header updates on page navigation
    syncPrinterStates();
}

// Enhanced error handling functions
function isErrorRecoverable(error) {
    const recoverableMessages = [
        'PAPER_OUT', 'PAPEROUT', 'OUT_OF_PAPER',
        'OFFLINE', 'PRINTER_OFFLINE', 'DEVICE_OFFLINE',
        'USER_INTERVENTION', 'INTERVENTION_REQUIRED',
        'DOOR_OPEN', 'COVER_OPEN',
        'MEDIA_JAM', 'PAPER_JAM', 'JAM',
        'LOW_TONER', 'LOW_INK', 'TONER_LOW',
        'BUSY', 'PRINTER_BUSY',
        'WARMING_UP', 'INITIALIZING'
    ];

    const errorMessage = (error.message || error.toString()).toUpperCase();
    return recoverableMessages.some(msg => errorMessage.includes(msg));
}

function getRetryReason(error) {
    const errorMessage = (error.message || error.toString()).toUpperCase();

    if (errorMessage.includes('PAPER')) return 'Paper issue detected';
    if (errorMessage.includes('OFFLINE')) return 'Printer offline';
    if (errorMessage.includes('INTERVENTION')) return 'User intervention required';
    if (errorMessage.includes('JAM')) return 'Paper jam detected';
    if (errorMessage.includes('TONER') || errorMessage.includes('INK')) return 'Low consumables';
    if (errorMessage.includes('BUSY')) return 'Printer busy';
    if (errorMessage.includes('WARMING')) return 'Printer warming up';

    return 'Recoverable printer error';
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
            startPrinterMonitoring();
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
        startPrinterMonitoring();
    }
}

// Start monitoring printer and job events
function startPrinterMonitoring() {
    qz.printers.startListening().then(() => {
        console.log("✅ Started listening for printer/job events");
        // Request immediate status for all printers
        return qz.printers.getStatus();
    }).then(() => {
        console.log("✅ Requested current status for all printers");
    }).catch((error) => {
        console.error("❌ Failed to start printer monitoring:", error);
    });
}

// Multi-printer job processing - check for jobs for all printers frequently
function startJobProcessing() {
    // Start continuous job checking every 4 seconds
    jobProcessingInterval = setInterval(() => {
        checkForJobsForAllPrinters();
    }, 4000);

    // Initial job check
    checkForJobsForAllPrinters();
}

async function checkForJobsForAllPrinters() {
    try {
        console.log('🔍 Checking for jobs for all printers...');

        // Fetch all pending jobs
        const response = await fetch(route('pos.auth.printers.jobs'), {
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            console.error('Failed to fetch print jobs:', response.status);
            return;
        }

        const printJobs = await response.json();
        const jobs = Array.isArray(printJobs) ? printJobs : (printJobs.data || []);

        // Emit pending jobs count update
        safeEmit('pending-jobs-updated', jobs.length);

        // Process jobs for each available printer
        for (const job of jobs) {
            const printerName = job.printer;

            // Only process if printer exists and is available locally
            if (availablePrinters.has(printerName)) {

                console.log(`📋 Found job ${job.id} for idle printer ${printerName}`);

                // Claim and process this job for this printer
                processPrinterJob(printerName, job);

                // Only process one job per printer per cycle
                break;
            }
        }

    } catch (error) {
        console.error('❌ Error checking for jobs:', error);
    }
}

// Process a job for a specific printer
async function processPrinterJob(printerName, job) {
    console.log(`🔄 Processing job ${job.id} for printer ${printerName}`);

    // Update printer state to working
    updatePrinterState(printerName, 'working', job);

    try {
        // Claim the job
        await claimJob(job.id);

        // Process the job
        await processSingleJob(job);

        console.log(`✅ Completed job ${job.id} for printer ${printerName}`);

        // Mark printer as idle again
        updatePrinterState(printerName, 'idle', null);

    } catch (error) {
        console.error(`❌ Job ${job.id} failed on printer ${printerName}:`, error);

        // Pause the printer and store the error
        updatePrinterState(printerName, 'paused', job, error);

        // Show toast notification for printer pause
        toast.add({
            severity: 'error',
            summary: `Printer ${printerName} Paused`,
            detail: `Job #${job.id} failed. Check printer status and use Skip/Retry to continue.`,
            life: 0 // Sticky toast
        });
    }
}

async function claimJob(jobId) {
    console.log(`🔒 Claiming job ${jobId}...`);

    try {
        const response = await fetch(route('pos.auth.printers.jobs.status', {job: jobId}), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                status: 'queued' // Mark as queued/claimed by this machine
            })
        });

        if (response.ok) {
            console.log(`✅ Successfully claimed job ${jobId}`);
        } else {
            console.error(`❌ Failed to claim job ${jobId}:`, response.status);
            throw new Error(`Failed to claim job ${jobId}`);
        }
    } catch (error) {
        console.error(`❌ Error claiming job ${jobId}:`, error);
        throw error;
    }
}

async function processSingleJob(job) {
    console.log(`🔄 Processing job ${job.id}:`, job);

    try {
        // Update job status to printing (already claimed as queued)
        await updateJobStatus(job.id, 'printing', {
            printer_name: job.printer
        });
        console.log(`📊 Updated job ${job.id} status to 'printing'`);

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

        // Generate unique job name for QZ-Tray tracking
        const qzJobName = `Job_${job.id}_${Date.now()}`;
        console.log(`🏷️  Generated job name: ${qzJobName} for job ${job.id}`);

        // Add job name to print data for QZ-Tray tracking
        data[0].jobName = qzJobName;
        console.log(`📄 Added job name to print data:`, data[0]);

        // Track this job for matching with QZ events
        activePrintJobs.set(job.printer, {
            jobId: job.id,
            qzJobName: qzJobName,
            timestamp: Date.now()
        });
        console.log(`📝 Tracking job ${job.id} on printer: ${job.printer}`);

        // Send to printer - QZ will handle completion via callbacks
        console.log(`🖨️  Sending job ${job.id} (${qzJobName}) to printer: ${job.printer}`);
        await qz.print(config, data);
        console.log(`✅ Job ${job.id} successfully sent to printer with name: ${qzJobName}`);

        // Start a fallback timer to check job status if QZ doesn't report completion
        // This helps handle cases where QZ-Tray doesn't generate completion events
        startJobCompletionFallback(job.id, qzJobName, 10000); // 10 second timeout

    } catch (error) {
        console.error(`Failed to print job ${job.id}:`, error);

        // Re-throw error to be handled by processPrinterJob (which will pause the printer)
        throw error;
    }
}

function findPrinters() {
    qz.printers.details().then((printers) => {
        console.log(`🔍 Found ${printers.length} printers:`, printers.map(p => p.name));

        // Initialize all discovered printers
        printers.forEach(printer => {
            initializePrinter(printer.name);
        });

        // Send printer details to backend
        fetch(route('pos.auth.printers.store'), {
            method: "POST",
            body: JSON.stringify({printers: printers}),
            headers: {
                'Content-Type': 'application/json'
            }
        });
    }).catch((err) => {
        console.error('Error finding printers:', err);
    });
}
</script>

<template>

</template>

<style scoped>

</style>
