<script setup>
import {usePage} from "@inertiajs/vue3";
import {onMounted} from "vue";
import qz from "qz-tray";
import { http } from "formjs-vue2"

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
        fetch(route('pos.qz.cert'), {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
            .then(function (data) {
                data.ok ? resolve(data.text()) : reject(data.text());
            });
    });
    qz.security.setSignatureAlgorithm("SHA512"); // Since 2.1
    qz.security.setSignaturePromise(function (toSign) {
        return function (resolve, reject) {
            fetch("/pos/qz/sign?request=" + toSign, {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
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
        http.get(route('pos.printers.jobs'),{},{
            onSuccess(printJobs) {
                printJobs.data.forEach((job) => {
                    console.log("job", job);
                    http.post(route('pos.printers.jobs.printed', {job: job.id}), {}, {
                        onSuccess() {
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
                        }
                    });
                });
            }
        })
    },5000)
}

function findPrinters() {
    qz.printers.details().then((printers) => {
        http.post(route('pos.printers.store'), {printers: printers});
    }).catch((err) => {
        console.error(err);
    });
}
</script>

<template>

</template>

<style scoped>

</style>
