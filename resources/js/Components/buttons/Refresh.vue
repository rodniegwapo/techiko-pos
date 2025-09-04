<script setup>
import { onMounted, ref } from 'vue'
import { PrinterOutlined, RedoOutlined } from '@ant-design/icons-vue'
defineProps({
    loading: {
        type: Boolean,
        defaultl: false
    }
})
</script>

<template>
    <a-button>
        <template #icon>
            <RedoOutlined
                :spin="loading"
                style="transform: rotate(255deg)"
                class="text-lg text-gray-400 leading-normal w-8"
            />
        </template>
    </a-button>
</template>
