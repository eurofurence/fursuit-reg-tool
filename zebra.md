# Zebra Badge Printer Status Flow Map

## Overview
This document maps the status flow for Zebra badge printers during a successful print job lifecycle, based on QZ-Tray events and our system status transitions.

## Print Job Lifecycle

### 1. Job Initialization
- **System Status**: `pending` → `printing`
- **Frontend Action**: Job fetched from `/pos/auth/printers/jobs` endpoint
- **QZ Action**: Job sent to printer with unique job name (e.g., `Job_3150_1755948973586`)
- **Printer Status**: Should transition to `processing`

### 2. QZ-Tray Status Events (In Order)

#### Event 1: SPOOLING
- **QZ Status**: `SPOOLING` (Code: 8)
- **Meaning**: Job is being prepared and spooled to the printer
- **System Action**: Update print job with QZ job name and status
- **Printer Status**: Should show `processing`

#### Event 2: PRINTING  
- **QZ Status**: `PRINTING` (Code: 16)
- **Meaning**: Printer is actively printing the job
- **System Action**: Confirm job is being processed
- **Printer Status**: Should show `processing`

#### Event 3: RETAINED
- **QZ Status**: `RETAINED` (Code: 8192)
- **Meaning**: Job is held/retained in printer memory (Zebra-specific)
- **System Action**: Continue monitoring
- **Printer Status**: Should show `processing`

#### Event 4: DELETING
- **QZ Status**: `DELETING` (Code: 4)
- **Meaning**: Job is being removed from printer queue
- **System Action**: Job completion detected, prepare for completion
- **Printer Status**: Should transition to `idle`

#### Event 5: COMPLETE
- **QZ Status**: `COMPLETE` (Code: 128)
- **Meaning**: Job successfully completed
- **System Action**: Mark job as printed
- **Printer Status**: Confirm `idle` state

#### Event 6: DELETED
- **QZ Status**: `DELETED` (Code: 256)
- **Meaning**: Job fully removed from system
- **System Action**: Final cleanup (no job state changes)
- **Printer Status**: Remain `idle`

### 3. System Status Transitions

```
[PENDING] → [PRINTING] → [PRINTED]
     ↓           ↓           ↓
   Fetch     Processing   Complete
   from      (SPOOLING,   (COMPLETE,
   /jobs     PRINTING,     DELETED)
             RETAINED)
```

### 4. Printer Status Transitions

```
[IDLE] → [PROCESSING] → [IDLE]
   ↓          ↓           ↓
 Ready    Actively    Ready for
 for      printing    next job
 jobs     job
```

## Current Issues Identified

1. **Missing PROCESSING Status**: Printer status doesn't show `processing` during SPOOLING/PRINTING phases
2. **Job Spooling**: System may send multiple jobs to same printer instead of one-at-a-time
3. **Status Mapping**: Need to map QZ statuses (SPOOLING, PRINTING, RETAINED) to printer `processing` state

## Required Changes

### 1. Printer Status Updates
- Map SPOOLING/PRINTING/RETAINED → `processing` 
- Map DELETING/COMPLETE/DELETED → `idle`
- Update printer state immediately when job starts

### 2. Job Queuing Logic  
- Check printer status before sending jobs
- Only send jobs to `idle` printers
- Support concurrent processing on multiple badge printers
- Ensure one job per printer at a time

### 3. Status Display
- Show "PROCESSING" status in printer management UI
- Update real-time status indicators
- Clear current_job_id when job completes

## Success Criteria

1. ✅ Printer shows `processing` status during active printing
2. ✅ Only one job per printer at a time
3. ✅ Multiple badge printers can work simultaneously  
4. ✅ Printer returns to `idle` after job completion
5. ✅ Next job only sent after printer becomes `idle`