<script setup lang="ts">
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import Card from "primevue/card";
import {
    User,
    Link as LinkIcon,
    Info,
    Plus,
    Trash2,
    Pencil,
    X,
    Check,
    Clock,
    XCircle,
    PawPrint,
    Trophy,
    Target,
    Crown,
    Gem,
    Sparkles,
    Star,
    BookOpen,
} from "lucide-vue-next";

const props = defineProps<{
    profile: {
        uuid: string;
        name: string;
        avatar: string | null;
        description: string | null;
        links: string[];
        status: "pending" | "approved" | "rejected" | null;
        rejection_reason: string | null;
    };
    fursuits: Array<{
        id: number;
        name: string;
        species: string | null;
        image: string | null;
        caught: number;
        rank: number | null;
        rarity: {
            level: string;
            label: string;
            color: string;
            icon: string;
        };
    }>;
    stats: {
        caught: number;
        rank: number | null;
    } | null;
    canEdit: boolean;
    flash?: any;
}>();

const editing = ref(false);

const form = useForm({
    description: props.profile.description ?? "",
    links: [...props.profile.links],
});

const startEditing = () => {
    form.description = props.profile.description ?? "";
    form.links = [...props.profile.links];
    form.clearErrors();
    editing.value = true;
};

const cancelEditing = () => {
    editing.value = false;
    form.reset();
    form.clearErrors();
};

const addLink = () => {
    if (form.links.length < 10) {
        form.links.push("");
    }
};

const removeLink = (index: number) => {
    form.links.splice(index, 1);
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        links: data.links.map((url) => url.trim()).filter((url) => url !== ""),
    })).put(route("catch-em-all.profiles.update", props.profile.uuid), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
};

const linkError = (index: number) =>
    (form.errors as Record<string, string>)[`links.${index}`];

const statusBadge = computed(() => {
    switch (props.profile.status) {
        case "approved":
            return null;
        case "rejected":
            return {
                label: "Rejected",
                icon: XCircle,
                classes: "bg-red-900/50 text-red-300 border-red-700",
            };
        case "pending":
            return {
                label: "Pending review",
                icon: Clock,
                classes: "bg-yellow-900/50 text-yellow-300 border-yellow-700",
            };
        default:
            return null;
    }
});

const getRarityIcon = (rarity: string) => {
    switch (rarity) {
        case "legendary":
            return Crown;
        case "epic":
            return Gem;
        case "rare":
            return Sparkles;
        case "uncommon":
            return Star;
        case "common":
            return BookOpen;
        default:
            return Star;
    }
};

const getRarityIconColor = (rarity: string) => {
    switch (rarity) {
        case "legendary":
            return "text-orange-400";
        case "epic":
            return "text-purple-400";
        case "rare":
            return "text-blue-400";
        case "uncommon":
            return "text-green-400";
        default:
            return "text-gray-400";
    }
};

const displayHost = (url: string) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};
</script>

<template>
    <CatchEmAllLayout
        :title="profile.name"
        subtitle="User profile"
        :flash="flash"
        icon="profile"
    >
        <!-- Profile Header -->
        <Card class="bg-white shadow-sm border border-gray-700">
            <template #content>
                <div class="flex items-center gap-4">
                    <img
                        v-if="profile.avatar"
                        :src="profile.avatar"
                        :alt="`Avatar of ${profile.name}`"
                        class="w-14 h-14 rounded-full object-cover flex-shrink-0"
                    />
                    <div
                        v-else
                        class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center flex-shrink-0"
                    >
                        <User class="w-7 h-7 text-white" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-xl font-bold text-gray-200 truncate">
                            {{ profile.name }}
                        </h2>
                        <span
                            v-if="statusBadge !== null"
                            class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 text-xs font-semibold rounded-full border"
                            :class="statusBadge.classes"
                        >
                            <component :is="statusBadge.icon" class="w-3 h-3" />
                            {{ statusBadge.label }}
                        </span>
                    </div>
                    <button
                        v-if="canEdit && !editing"
                        @click="startEditing"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-500 hover:bg-blue-600 text-white text-sm font-medium transition-colors"
                    >
                        <Pencil class="w-4 h-4" />
                        Edit
                    </button>
                </div>
                <!-- Catcher Stats -->
                <div v-if="stats" class="grid grid-cols-2 gap-2 mt-4">
                    <div
                        class="text-center py-3 bg-blue-900/30 rounded-lg border border-blue-800"
                    >
                        <Target class="w-5 h-5 mx-auto mb-1 text-blue-400" />
                        <div class="text-lg font-bold text-blue-300">
                            {{ stats.caught }}
                        </div>
                        <div class="text-xs text-blue-400">
                            Fursuits caught
                        </div>
                    </div>
                    <div
                        class="text-center py-3 bg-yellow-900/30 rounded-lg border border-yellow-800"
                    >
                        <Trophy class="w-5 h-5 mx-auto mb-1 text-yellow-400" />
                        <div class="text-lg font-bold text-yellow-300">
                            {{ stats.rank !== null ? `#${stats.rank}` : "-" }}
                        </div>
                        <div class="text-xs text-yellow-400">
                            Leaderboard rank
                        </div>
                    </div>
                </div>
                <p
                    v-if="canEdit && profile.status === 'pending'"
                    class="mt-3 text-xs text-gray-400"
                >
                    Your latest changes are awaiting review and are not yet
                    visible to other players.
                </p>
                <p
                    v-else-if="canEdit && profile.status === 'rejected'"
                    class="mt-3 text-xs text-gray-400"
                >
                    Your profile was rejected and is hidden from other players.
                    Edit it to submit it for review again.
                </p>
            </template>
        </Card>

        <!-- Rejection Reason (owner only) -->
        <Card
            v-if="canEdit && profile.status === 'rejected' && profile.rejection_reason"
            class="bg-white shadow-sm border border-red-700"
        >
            <template #content>
                <h3 class="flex gap-2 text-sm font-medium text-red-300 mb-2">
                    <XCircle class="w-4 h-4" />
                    Reason for rejection
                </h3>
                <p class="text-gray-200 whitespace-pre-line break-words">
                    {{ profile.rejection_reason }}
                </p>
            </template>
        </Card>

        <!-- View Mode -->
        <template v-if="!editing">
            <Card class="bg-white shadow-sm border border-gray-700">
                <template #content>
                    <h3 class="flex gap-2 text-sm font-medium text-gray-300 mb-2">
                        <Info class="w-4 h-4" />
                        About
                    </h3>
                    <p
                        v-if="profile.description"
                        class="text-gray-200 whitespace-pre-line break-words"
                    >
                        {{ profile.description }}
                    </p>
                    <p v-else class="text-gray-400 italic">
                        {{
                            canEdit
                                ? "You haven't written anything about yourself yet."
                                : "This user hasn't written anything about themselves yet."
                        }}
                    </p>
                </template>
            </Card>

            <Card
                v-if="profile.links.length > 0"
                class="bg-white shadow-sm border border-gray-700"
            >
                <template #content>
                    <h3 class="flex gap-2 text-sm font-medium text-gray-300 mb-3">
                        <LinkIcon class="w-4 h-4" />
                        Links
                    </h3>
                    <p class="text-gray-400 text-sm font-small mb-2">
                        <strong>Note: </strong> links may lead to external websites, which are not affiliated with Eurofurence
                    </p>
                    <div class="space-y-2">
                        <a
                            v-for="url in profile.links"
                            :key="url"
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="flex items-center gap-3 p-3 bg-gray-800 rounded-lg border border-gray-700 hover:border-blue-500 transition-colors"
                        >
                            <LinkIcon
                                class="w-4 h-4 text-blue-400 flex-shrink-0"
                            />
                            <span class="flex-1 min-w-0">
                                <span
                                    class="block text-sm font-medium text-gray-200 truncate"
                                >
                                    {{ displayHost(url) }}
                                </span>
                                <span
                                    class="block text-xs text-gray-400 truncate"
                                >
                                    {{ url }}
                                </span>
                            </span>
                        </a>
                    </div>
                </template>
            </Card>

            <!-- Catch-Em-All Fursuits -->
            <Card
                v-if="fursuits.length > 0"
                class="bg-white shadow-sm border border-gray-700"
            >
                <template #content>
                    <h3
                        class="flex items-center gap-2 text-sm font-medium text-gray-300 mb-3"
                    >
                        <PawPrint class="w-4 h-4" />
                        Fursuits in Catch-Em-All
                    </h3>
                    <div class="space-y-2">
                        <div
                            v-for="fursuit in fursuits"
                            :key="fursuit.id"
                            class="flex items-center p-3 bg-gray-800 rounded-lg border border-gray-700"
                        >
                            <img
                                v-if="fursuit.image"
                                :src="fursuit.image"
                                :alt="fursuit.name"
                                class="w-12 h-12 rounded-md object-cover mr-4"
                            />
                            <div
                                v-else
                                class="w-12 h-12 rounded-md bg-gray-700 flex items-center justify-center mr-4"
                            >
                                <PawPrint class="w-6 h-6 text-gray-500" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4
                                    class="font-bold text-base text-gray-200 truncate"
                                >
                                    {{ fursuit.name }}
                                </h4>
                                <p
                                    v-if="fursuit.species"
                                    class="text-sm text-gray-400 truncate"
                                >
                                    {{ fursuit.species }}
                                </p>
                            </div>
                            <div
                                class="flex flex-col items-end gap-1 ml-3 flex-shrink-0"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold text-blue-300 bg-blue-900/50 border border-blue-700 rounded-full whitespace-nowrap"
                                    >
                                        <Target class="w-3 h-3" />
                                        {{ fursuit.caught }}
                                        {{ fursuit.caught === 1 ? "catch" : "catches" }}
                                    </span>
                                    <component
                                        :is="getRarityIcon(fursuit.rarity.level)"
                                        class="w-4 h-4 flex-shrink-0"
                                        :class="getRarityIconColor(fursuit.rarity.level)"
                                        :title="fursuit.rarity.label"
                                        :aria-label="`Rarity: ${fursuit.rarity.label}`"
                                    />
                                </span>
                                <span
                                    v-if="fursuit.rank !== null"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold text-yellow-300 bg-yellow-900/50 border border-yellow-700 rounded-full whitespace-nowrap"
                                >
                                    <Trophy class="w-3 h-3" />
                                    Rank #{{ fursuit.rank }}
                                </span>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </template>

        <!-- Edit Mode -->
        <form v-else @submit.prevent="submit">
            <div class="space-y-4">
                <Card class="bg-white shadow-sm border border-gray-700">
                    <template #content>
                        <p class="block text-sm text-gray-400">
                            <strong>Note:</strong> your profile picture is synchronized with your identity provider account.
                            To change it, please update your avatar at:
                            <br />
                            <a
                                href="https://identity.eurofurence.org"
                                target="_blank"
                                class="text-blue-400 hover:underline">identity.eurofurence.org
                            </a>
                        </p>
                    </template>
                </Card>
                <Card class="bg-white shadow-sm border border-gray-700">
                    <template #content>
                        <label
                            for="description"
                            class="block text-sm font-medium text-gray-300 mb-2"
                        >
                            About
                        </label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="4"
                            maxlength="255"
                            placeholder="Tell other players about yourself…"
                            class="w-full rounded-lg bg-gray-800 border border-gray-600 text-gray-200 placeholder-gray-500 p-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                        />
                        <div class="flex justify-between mt-1">
                            <p
                                v-if="form.errors.description"
                                class="text-xs text-red-400"
                            >
                                {{ form.errors.description }}
                            </p>
                            <p class="text-xs text-gray-500 ml-auto">
                                {{ form.description.length }}/255
                            </p>
                        </div>
                    </template>
                </Card>

                <Card class="bg-white shadow-sm border border-gray-700">
                    <template #content>
                        <div class="flex items-center justify-between mb-3">
                            <label
                                class="text-sm font-medium text-gray-300"
                            >
                                Links
                            </label>
                            <button
                                type="button"
                                @click="addLink"
                                :disabled="form.links.length >= 10"
                                class="flex items-center gap-1 px-2 py-1 rounded-lg text-sm text-blue-400 hover:bg-gray-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <Plus class="w-4 h-4" />
                                Add link
                            </button>
                        </div>
                        <div v-if="form.links.length > 0" class="space-y-3">
                            <div v-for="(_, index) in form.links" :key="index">
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="form.links[index]"
                                        type="text"
                                        inputmode="url"
                                        placeholder="example.com/you"
                                        class="flex-1 min-w-0 rounded-lg bg-gray-800 border border-gray-600 text-gray-200 placeholder-gray-500 p-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    />
                                    <button
                                        type="button"
                                        @click="removeLink(index)"
                                        class="p-3 rounded-lg text-red-400 hover:bg-gray-800 transition-colors"
                                        aria-label="Remove link"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                                <p
                                    v-if="linkError(index)"
                                    class="mt-1 text-xs text-red-400"
                                >
                                    {{ linkError(index) }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-400 italic">
                            No links yet - add your socials, gallery, or
                            website.
                        </p>
                        <p v-if="form.errors.links" class="mt-2 text-xs text-red-400">
                            {{ form.errors.links }}
                        </p>
                    </template>
                </Card>

                <p class="text-xs text-gray-400 px-1">
                    Changes to your profile are reviewed before they become
                    visible to other players.
                </p>

                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex-1 flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-gradient-to-r from-blue-500 to-purple-600 text-white font-medium transition-opacity disabled:opacity-50"
                    >
                        <Check class="w-4 h-4" />
                        Save profile
                    </button>
                    <button
                        type="button"
                        @click="cancelEditing"
                        :disabled="form.processing"
                        class="flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 font-medium transition-colors disabled:opacity-50"
                    >
                        <X class="w-4 h-4" />
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </CatchEmAllLayout>
</template>

<style scoped>
:deep(.p-card) {
    border-radius: 12px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
}
</style>
