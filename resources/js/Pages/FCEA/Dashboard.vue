<script setup lang="ts">
import {Head, useForm, usePage} from '@inertiajs/vue3'
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import Button from "primevue/button"
import BottomNavigation from "@/Components/BottomNavigation.vue";
import Message from "primevue/message";
import FlashMessages from "@/Components/FlashMessages.vue";
import InputText from 'primevue/inputtext'
import InputMask from 'primevue/inputmask'

defineOptions({layout: CatchEmAllLayout})

const form = useForm({catch_code: ''})

const props = defineProps<{
    myUserInfo: object,
    userRanking: object,
    flash: object,
    caughtFursuit?: object | null
}>()

const page = usePage()

const submit = () => form.post(route('fcea.dashboard.catch'))

</script>
<template>
    <Head title="Fursuits — Catch'em all!"/>

    <div class="py-16">
        <!-- Personal Ranking -->
        <div class="px-4 mb-4 max-w-sm mx-auto">
            <div class="flex justify-around items-center mb-3">
                <!-- Your Place -->
                <div class="text-center">
                    <h4 class="font-bold text-4xl"><span
                        class="font-light font-italic text-2xl">#</span>{{ myUserInfo.rank }}</h4>
                    <div class="uppercase font-mono text-base">Your Place</div>
                </div>
                <!-- Catches -->
                <div class="text-center">
                    <h4 class="font-bold text-4xl"><span
                        class="font-light font-italic text-2xl"></span>{{ myUserInfo.score }}</h4>
                    <div class="uppercase font-mono text-base">Your Catches</div>
                </div>
            </div>
            <div>
                <p class="text-center">There are {{ myUserInfo.others_behind }} behind your place.</p>
            </div>
            <p class="text-center" v-if="myUserInfo.score_till_next ==! 0"><small>{{ myUserInfo.score_till_next }} more
                to advance to the next place</small></p>
        </div>
        <div class="p-4">
            <FlashMessages :flash="flash"/>
        </div>
        <!-- Input Form -->
        <div class="px-4 py-6 bg-blue-900 text-white">
            <div class="mx-auto max-w-sm">
                <div class="text-center text-sm mb-3">
                    <h3>Enter the catch code of a fursuiter.</h3>
                </div>
                <div class="space-y-3">
                    <InputMask class="w-full text-center" :invalid="form.hasErrors" v-model="form.catch_code"
                               mask="*****" placeholder="ABCDE" fluid></InputMask>
                    <small v-if="form.hasErrors" class="text-red-300">Invalid catch code</small>
                    <Button severity="warning" class="w-full" @click="submit">Submit Catch Code</Button>
                    <p class="text-sm text-center">Your name can be displayed on the public leaderboard after you submit your first catch code.</p>
                </div>
            </div>
        </div>

        <div>
            <div class="px-4 py-4 font-bold bg-blue-200 text-xl text-center">Leaderboard</div>
            <table class="table-auto w-full striped-table" v-if="userRanking.length">
                <thead>
                <tr>
                    <th class="pl-4 text-left bg-blue-900 text-white py-2">Rank</th>
                    <th class="text-left bg-blue-900 text-white py-2">Name</th>
                    <th class="pr-4 text-right bg-blue-900 text-white py-2">Catches</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="i in userRanking">
                    <td class="pl-4 py-1">Top {{ i.rank }}</td>
                    <td>{{ i.user.name }}</td>
                    <td class="pr-4 text-right">{{ i.score }}</td>
                </tr>
                </tbody>
            </table>
            <p class="text-center py-4" v-else>Be the first person to catch a fursuiter!</p>
            <div class="px-4 py-4 font-bold bg-blue-200 text-xl text-center">How does this work?</div>
            <div class="space-y-2 px-4 py-4 text-sm max-w-sm mx-auto">
                <p>Some fursuiters at Eurofurence may have a unique 5-digit code located on the bottom right of their Fursuit Badge. These codes are your key to participating in the "Catch 'Em All" game.</p>

                <p><strong>Objective:</strong></p>
                <p>Catch as many fursuiters as possible by collecting their badge codes. Each time you "catch" a fursuiter, make sure to note their code.</p>

                <p><strong>Participation:</strong></p>
                <p>Fursuiters can choose whether or not to participate in the game. If they have a code on their badge, they're part of the fun!</p>

                <p><strong>Scoring:</strong></p>
                <p>Players with the same number of catches will share the same ranking. For example, a player with 200 catches will be ranked just above someone with 199, but if two players both have 200 catches, they will share the same rank.</p>

                <p>Good luck, and happy catching!</p>

            </div>
            <!--
        <ul class="list-group">
            <li class="list-group-item" ><p v-if="i.user_id == myUserInfo.user_id">
                [YOU]</p> Top {{ i.rank }}: {{ i.user.name }} [{{ i.score }}]
            </li>
        </ul> -->
        </div>
        <!--
        <Teleport defer to="#bottom_nav_teleport">
            <BottomNavigation/>
        </Teleport> -->
    </div>
</template>

<style scoped>
.striped-table tr:nth-child(odd) {
    background-color: #f9f9f9;
}
</style>
