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
            <div class="bg-primary-500 pl-4 drop-shadow">
                <div class="text-white max-w-screen-lg mx-auto flex gap-3">
                    <Link as="div" :href="route('welcome')" class="cursor-pointer relative h-16 flex gap-3">
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
                                    Fursuit Catch Em All
                                </div>
                            </div>
                        </div>
                    </Link>
                    <div class="flex items-center justify-end ml-auto pr-2">
                        <Link :href="route('auth.logout')" method="POST" class="text-white"
                              v-if="usePage().props.auth.user">
                            <Button title="Logout" class="text-white" text size="large" icon="pi pi-sign-out"
                                    aria-label="Submit"/>
                        </Link>
                    </div>
                </div>
            </div>
            <!-- End of Logo -->
            <div class="flex-1">
                <slot></slot>
            </div>
            <div id="bottom_nav_teleport"></div>
        </div>
    </div>
</template>
