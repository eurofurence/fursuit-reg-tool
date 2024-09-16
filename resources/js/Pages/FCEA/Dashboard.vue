<script setup>
import {Link, Head, usePage} from '@inertiajs/vue3'
import {useForm} from 'laravel-precognition-vue-inertia'
import Button from 'primevue/button';
import DashboardButton from "@/Components/POS/DashboardButton.vue";


const form = useForm('post', route('fcea.dashboard.catch'), {
    catch_code: ""
})
const props = defineProps(
    {
        myUserInfo: Object,
        userRanking: Object,
        myFursuitInfos: Object,
        fursuitRanking: Object,
        myFursuitInfoCatchedTotal: Number,
    }
)

function submit() {
    form.submit();
}
</script>
<template>
    <Head>
        <title>POS - Dashboard</title>
    </Head>
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
                <Button class="btn btn-primary" @click="submit()">Submit ></Button>
            </div>

            <div class="mt-4">
                <h5>Top Catchers</h5>
                <ul class="list-group">
                    <li class="list-group-item" v-for="i in userRanking"><p v-if="i.user_id == myUserInfo.user_id">[YOU]</p> Top {{i.id}} - {{i.rank}}: {{i.user.name}} [{{i.score}}]</li>
                </ul>
            </div>

            <h4 class="text-center">Place #{{ myFursuitInfos[0].rank }} with {{ myFursuitInfos[0].score }} times being catched</h4>
            <p class="text-center" v-if="myFursuitInfos[0].score_till_next ==! 0"><small>{{ myFursuitInfos[0].score_till_next }} more to advance to the next place</small></p>
            <p class="text-center"><small>{{ myFursuitInfos[0].others_behind }} behind you</small></p>
            <p class="text-center"><small>You were been {{ myFursuitInfoCatchedTotal }} catched between all fursuits</small></p>

            <div class="mt-4">
                <h5>Top Fursuiters</h5>
                <ul class="list-group">
                    <li class="list-group-item" v-for="i in fursuitRanking">Top {{i.id}} - {{i.rank}}: {{i.fursuit.name??'error'}} [{{i.score}}]</li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            place: 705,
            toNextPlace: 4,
            behindYou: 232,
            code: '',
            topCatchers: [999, 421, 4, 8],
            topFursuiters: [999, 421, 1],
        };
    }
};
</script>

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
