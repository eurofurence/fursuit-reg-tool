<script setup lang="ts">
import {Head, useForm, usePage} from '@inertiajs/vue3'
import Layout from "@/Layouts/Layout.vue";
import Button from "primevue/button"
import BottomNavigation from "@/Components/BottomNavigation.vue";
import Message from "primevue/message";
import FlashMessages from "@/Components/FlashMessages.vue";

defineOptions({ layout: Layout })

const form = useForm({ catch_code: '' })

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
    <Head title="Fursuits — Catch'em all!" />

    <FlashMessages :flash="flash" />
    <div class="card mt-5" style="width: 18rem; margin: auto;">
        <div class="card-header text-center">
            <h3>Fursuit Catch Em All</h3>
        </div>

        <div class="card-body">
            <h4 class="text-center">Place #{{ myUserInfo.rank }} with {{ myUserInfo.score }} catches</h4>
            <p class="text-center" v-if="myUserInfo.score_till_next ==! 0"><small>{{ myUserInfo.score_till_next }} more to advance to the next place</small></p>
            <p class="text-center"><small>{{ myUserInfo.others_behind }} behind you</small></p>

            <div class="text-center">
                 <input type="text" class="form-control mb-2" v-model="form.catch_code" placeholder="Enter Code" />
                <div v-if="form.hasErrors">Invalid catch code</div>
                <Button class="btn btn-primary" @click="submit">Submit ></Button>
            </div>

            <div class="mt-4">
                <h5>Top Catchers</h5>
                <ul class="list-group">
                    <li class="list-group-item" v-for="i in userRanking"><p v-if="i.user_id == myUserInfo.user_id">[YOU]</p> Top {{i.rank}}: {{i.user.name}} [{{i.score}}]</li>
                </ul>
            </div>
            <Teleport defer to="#bottom_nav_teleport"><BottomNavigation class="md:hidden" /></Teleport>
        </div>
    </div>
</template>

<style scoped>
.card {
    border: 1px solid #ccc;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

h3 {
    font-family: 'Arial', sans-serif;
    font-weight: bold;
}

.card-header {
    background-color: #f8f9fa;
}

.list-group-item {
    display: flex;
    justify-content: space-between;
    font-weight: bold;
}

.text-center {
    font-family: 'Arial', sans-serif;
    margin-top: 10px;
}
</style>
