<script setup>
import {Link, usePage} from '@inertiajs/vue3';
import dayjs from "dayjs";
import Button from "primevue/button";
import {formatEuroFromCents} from "../helpers.js";
const page = usePage().props;
</script>

<template>
    <div class="sm:bg-gray-100">
        <div class="min-h-screen w-full mx-auto bg-white flex flex-col">
            <!--- Logo -->
            <Link as="div" :href="route('welcome')" class="bg-primary-500 pl-4 drop-shadow">
                <div class="flex text-white gap-3 h-16 max-w-screen-lg mx-auto relative">
                    <img src="../../assets/ef.svg"
                         class="hidden xs:block rounded-full bg-primary-500 h-24 px-2 pb-2 pt-2 -mt-2 absolute z-100"
                         alt="Eurofurence Logo">
                    <div class="w-24 hidden xs:block"></div>
                    <div class="flex items-center">
                        <div>
                            <div class="font-logo tracking-wider font-semibold text-2xl">
                                EUROFURENCE
                            </div>
                            <div class="font-semibold text-sm sm:text-base -mt-1">
                                Fursuit Badge Registration
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end ml-auto pr-2">
                        <Link :href="route('auth.logout')" method="POST" class="text-white" v-if="usePage().props.auth.user">
                            <Button title="Logout" class="text-white" text size="large" icon="pi pi-sign-out"
                                    aria-label="Submit"/>
                        </Link>
                    </div>
                </div>
            </Link>
            <!-- End of Logo -->
            <div class="pb-4 max-w-screen-lg w-full mx-auto flex-1">
                <slot></slot>
            </div>
            <!-- Footer -->
            <div class="text-primary-950 text-center py-2 pt-8">
                <div class="text-sm">
                    <p>© {{ dayjs().format('YYYY') }} Eurofurence e.V. All rights reserved.</p>
                    <div>
                        <a href="https://help.eurofurence.org/legal/imprint" target="_blank"
                           class="underline">Imprint</a>
                        <span class="mx-2">|</span>
                        <a href="https://help.eurofurence.org/legal/privacy" target="_blank"
                           class="underline">Privacy Policy</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
