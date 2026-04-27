<script setup>
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import {
    IconBuildingStore,
    IconChartBar,
    IconCreditCard,
    IconHeartHandshake,
    IconPackage,
    IconShoppingCart,
    IconTruck,
    IconUsers,
} from "@tabler/icons-vue";
import { computed } from "vue";

const props = defineProps({
    /**
     * `light` = intended for white / gray-50 sections (tinted glass rings, colored icons).
     * `dark` = white/frosted on gradient (legacy; no current call sites after migration).
     */
    surface: {
        type: String,
        default: "light",
    },
    /**
     * `band` = decorative corner block for content sections (absolute, right-biased, scaled).
     */
    layout: {
        type: String,
        default: "band",
    },
});

const isLight = computed(() => props.surface === "light");

const nodes = [
    {
        Icon: IconBuildingStore,
        class: "left-1/2 top-0 -translate-x-1/2 -translate-y-1/2",
    },
    {
        Icon: IconShoppingCart,
        class: "right-0 top-[12%] translate-x-1/2 -translate-y-1/2",
    },
    {
        Icon: IconUsers,
        class: "right-0 top-1/2 -translate-y-1/2 translate-x-1/2",
    },
    {
        Icon: IconTruck,
        class: "right-[2%] bottom-[14%] translate-x-1/2 translate-y-1/2",
    },
    {
        Icon: IconPackage,
        class: "left-1/2 bottom-0 -translate-x-1/2 translate-y-1/2",
    },
    {
        Icon: IconChartBar,
        class: "left-[2%] bottom-[14%] -translate-x-1/2 translate-y-1/2",
    },
    {
        Icon: IconHeartHandshake,
        class: "left-0 top-1/2 -translate-y-1/2 -translate-x-1/2",
    },
    {
        Icon: IconCreditCard,
        class: "left-0 top-[12%] -translate-x-1/2 -translate-y-1/2",
    },
];

const iconSize = 20;
const iconStroke = 1.75;

const ring1 = computed(() =>
    isLight.value
        ? "border border-blue-100/90 bg-sky-50/80 backdrop-blur-sm"
        : "border border-white/10 bg-white/5 backdrop-blur-sm",
);
const ring2 = computed(() =>
    isLight.value
        ? "border border-cyan-100/80 bg-cyan-50/60 backdrop-blur-md"
        : "border border-white/20 bg-white/10 backdrop-blur-md",
);
const ring3 = computed(() =>
    isLight.value
        ? "border border-slate-200/80 bg-white/50 backdrop-blur-lg"
        : "border border-white/30 bg-white/15 backdrop-blur-lg",
);

const centerDisc = computed(() =>
    isLight.value
        ? "bg-white shadow-xl ring-8 ring-slate-200/70"
        : "bg-white shadow-2xl ring-8 ring-white/20",
);

const nodeShell = computed(() =>
    isLight.value
        ? "border border-gray-200/90 bg-white shadow-md ring-1 ring-slate-200/50"
        : "border-2 border-white/80 bg-white/20 text-white shadow-lg ring-2 ring-white/30",
);

function iconClass(i) {
    if (!isLight.value) {
        return "text-white";
    }
    return i % 2 === 0 ? "text-teal-600" : "text-blue-600";
}
</script>

<template>
    <div
        aria-hidden="true"
        :class="[
            'pointer-events-none select-none',
            layout === 'band' &&
                'absolute -right-2   top-0 z-0 h-[min(78vw,260px)] w-[min(78vw,260px)] opacity-50 sm:top-1 sm:-right-3 sm:h-[min(72vw,300px)] sm:w-[min(72vw,300px)] sm:opacity-58 md:-right-5 md:top-1 md:h-[360px] md:w-[360px] md:opacity-68 lg:-right-6 lg:top-0 lg:h-[400px] lg:w-[400px] lg:opacity-75 xl:-right-8 xl:h-[440px] xl:w-[440px] xl:opacity-80',
        ]"
    >
        <div
            class="relative h-full w-full mt-8 mr-8"
            :class="
                layout === 'band'
                    ? 'scale-[0.82] sm:scale-90 md:scale-95 lg:scale-100'
                    : ''
            "
        >
            <div class="absolute inset-0 flex items-center justify-center">
                <div
                    class="absolute left-1/2 top-1/2 h-[100%] w-[100%] -translate-x-1/2 -translate-y-1/2 rounded-full"
                    :class="ring1"
                />
                <div
                    class="absolute left-1/2 top-1/2 h-[80%] w-[80%] -translate-x-1/2 -translate-y-1/2 rounded-full"
                    :class="ring2"
                />
                <div
                    class="absolute left-1/2 top-1/2 h-[60%] w-[60%] -translate-x-1/2 -translate-y-1/2 rounded-full"
                    :class="ring3"
                />
            </div>
            <div
                class="absolute left-1/2 top-1/2 z-[5] flex h-[36%] w-[36%] max-h-[min(200px,38%)] max-w-[min(200px,38%)] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full"
                :class="centerDisc"
            />
            <div
                class="absolute left-1/2 top-1/2 z-[6] -translate-x-1/2 -translate-y-1/2"
            >
                <div
                    class="flex h-28 w-28 items-center justify-center sm:h-[7.5rem] sm:w-[7.5rem] md:h-32 md:w-32"
                >
                    <ApplicationLogo
                        class="!w-[4.5rem] !max-w-[4.5rem] sm:!w-20 sm:!max-w-20"
                    />
                </div>
            </div>
            <div
                v-for="(node, i) in nodes"
                :key="i"
                class="absolute z-[6] h-9 w-9 sm:h-10 sm:w-10 md:h-11 md:w-11"
                :class="node.class"
            >
                <div
                    class="flex h-full w-full items-center justify-center rounded-full"
                    :class="nodeShell"
                >
                    <component
                        :is="node.Icon"
                        :size="iconSize"
                        :stroke-width="iconStroke"
                        :class="iconClass(i)"
                        aria-hidden="true"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
