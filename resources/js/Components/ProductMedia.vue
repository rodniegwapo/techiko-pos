<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
    representationType: {
        type: String,
        default: null,
    },
    representation: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: String,
        default: "",
    },
});

const imageFailed = ref(false);

watch(
    () => [props.representationType, props.representation],
    () => {
        imageFailed.value = false;
    },
);

const isImage = computed(
    () =>
        props.representationType === "image" &&
        !!props.representation &&
        !imageFailed.value,
);

const isColor = computed(
    () => props.representationType === "color" && !!props.representation,
);

const letter = computed(() => {
    const n = (props.name || "").trim();
    return n ? n.charAt(0).toUpperCase() : "P";
});

const colorStyle = computed(() => {
    if (!isColor.value) return null;
    const hex = String(props.representation).replace(/^#/, "");
    return { backgroundColor: `#${hex}` };
});

function onImageError() {
    imageFailed.value = true;
}
</script>

<template>
    <div
        class="flex items-center justify-center overflow-hidden bg-stone-200 text-stone-500"
    >
        <img
            v-if="isImage"
            :src="String(representation)"
            :alt="name || 'Product'"
            class="h-full w-full object-cover"
            @error="onImageError"
        />
        <div v-else-if="isColor" class="h-full w-full" :style="colorStyle" />
        <span
            v-else
            class="select-none text-2xl font-semibold tracking-wide"
        >
            {{ letter }}
        </span>
    </div>
</template>
