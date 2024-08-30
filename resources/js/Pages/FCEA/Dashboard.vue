<script setup>
import {Link, Head, usePage} from '@inertiajs/vue3'
import {useForm} from 'laravel-precognition-vue-inertia'
import Button from 'primevue/button';


const form = useForm('post', route('fcea.dashboard.catch'), {
    catch_code: ""
})
const props = defineProps(
    {
        tempVar1: Number,
        tempVar2: Number,
        tempVar3: String,
        tempVar4: Object
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
            <h4 class="text-center">Var1 #{{ tempVar1 }}</h4>
            <h4 class="text-center">Var2 #{{ tempVar2 }}</h4>
            <h4 class="text-center">Var3 #{{ tempVar3 }}</h4>
            <h4 class="text-center">Fursuit #{{ tempVar4[0]['user_id'] }}</h4>
            <h4 class="text-center">Place #{{ place }}</h4>
            <p class="text-center"><small>{{ toNextPlace }} more to advance to the next place</small></p>
            <p class="text-center"><small>{{ behindYou }} behind you</small></p>

            <div class="text-center">
                 <input type="text" class="form-control mb-2" v-model="form.catch_code" placeholder="Enter Code" />
                <Button class="btn btn-primary" @click="submit()">Submit ></Button>
            </div>

            <div class="mt-4">
                <h5>Top Catchers</h5>
                <ul class="list-group">
                    <li class="list-group-item">Top 1: {{ topCatchers[0] }}</li>
                    <li class="list-group-item">Top 2...10: {{ topCatchers[1] }}</li>
                    <li class="list-group-item">...</li>
                    <li class="list-group-item">Top 704: {{ topCatchers[2] }}</li>
                </ul>
            </div>

            <div class="mt-4">
                <h5>Top Fursuiters</h5>
                <ul class="list-group">
                    <li class="list-group-item">Top 1: {{ topFursuiters[0] }}</li>
                    <li class="list-group-item">Top 2...10: {{ topFursuiters[1] }}</li>
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
