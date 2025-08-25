<style>
    body {
        font-family: Arial, sans-serif;
        font-size: 6px;
        line-height: 1.0;
        margin: 0;
        padding: 0;
        color: #000;
    }
    
    .page-header {
        text-align: center;
        margin-bottom: 5px;
        border-bottom: 1px solid #000;
        padding-bottom: 3px;
    }
    
    .page-header h1 {
        font-size: 10px;
        margin: 0 0 1px 0;
        font-weight: bold;
        color: #000;
    }
    
    .page-header h2 {
        font-size: 8px;
        margin: 0;
        color: #000;
    }
    
    .range-section {
        margin-bottom: 8px;
    }
    
    .range-header {
        border: 1px solid #000;
        padding: 2px;
        font-weight: bold;
        font-size: 8px;
        margin-bottom: 3px;
        color: #000;
    }
    
    .attendee-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-family: monospace;
        /* font-size is now configurable via inline style */
    }
    
    .attendee-table tr:nth-child(even) {
        background-color: #f8f8f8;
    }
    
    .attendee-cell {
        padding: 0.5px 2px;
        border-right: 1px solid #ddd;
        border-bottom: 1px solid #ddd;
        text-align: left;
        vertical-align: top;
        color: #000;
        font-family: 'Courier New', Courier, monospace;
        white-space: pre;
    }
    
    .attendee-cell:last-child {
        border-right: none;
    }
    
    .no-data {
        text-align: center;
        color: #000;
        padding: 5px;
        font-size: 6px;
    }
    
    .summary {
        margin-top: 10px;
        text-align: center;
        color: #000;
        font-size: 6px;
        border-top: 1px solid #000;
        padding-top: 3px;
    }
</style>