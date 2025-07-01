<script setup>
import {usePage} from "@inertiajs/vue3";
import {onMounted} from "vue";
import qz from "qz-tray";

const page = usePage();

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
        // start pollng for print jobs
        pollPrintJobs();
    }
})



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
                printJobs.data.forEach((job) => {
                    console.log("job", job);
                    fetch(route('pos.auth.printers.jobs.printed', {job: job.id}), {
                        method: 'POST'
                    }).then(() => {
                            var printerOptions = (job.type === 'badge') ? {
                                colorType: 'color',
                                size: job.paper.mm,
                                units: 'mm',
                                duplex: job.duplex,
                            } : {
                                colorType: 'grayscale',
                                size: [
                                    80,
                                ],
                                rasterize: true,
                                units: 'mm',
                                scaleContent: false,
                            };
                            console.log(printerOptions);
                            var config = qz.configs.create(job.printer, printerOptions);
                            var data = [{
                                type: 'pixel',
                                format: 'pdf',
                                flavor: 'file',
                                data: job.file
                            }];
                            qz.print(config, data).catch((err) => {
                                console.error(err);
                            });
                        });
                });
            })
    },5000)
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
