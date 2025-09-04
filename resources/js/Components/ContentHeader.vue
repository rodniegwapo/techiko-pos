<script setup>
import dayjs from 'dayjs'

defineProps({
    title: String,
    isDashboard: {
        type: Boolean,
        default: false
    }
})

</script>

<template>
    <div>
        <div class="w-full flex justify-between items-end">
            <p class="text-xl font-semibold mb-0" v-if="isDashboard">
                {{ title }}
            </p>
            <p class="text-xl font-semibold mb-0" v-else>
                {{ title }}
            </p>
        </div>

        <div
            class="flex lg:w-1/2 md:w-1/2 sm:w-full lg:space-x-6 md:space-x-6 sm:space-x-0 items-center"
        >
            <p v-if="isDashboard" class="sm:hidden lg:block md:block">
                Here’s your dashboard today
            </p>
            <p class="text-xs sm:hidden lg:block md:block">
              {{ dayjs().format('dddd, MMMM D, YYYY HH:mm:ss') }}
            </p>
        </div>

        <div>
            <div class="text-[8pt] sm:block md:hidden lg:hidden">
                <span v-if="isDashboard">Here’s your dashboard today</span>
             {{ dayjs().format('dddd, MMMM D, YYYY HH:mm:ss') }}
            </div>
        </div>
    </div>
</template>
