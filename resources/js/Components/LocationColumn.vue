<template>
  <div class="flex items-center space-x-2">
    <!-- Location Name -->
    <span class="text-sm text-gray-900 truncate max-w-32" :title="locationName">
      {{ locationName }}
    </span>
    
    <!-- Location Type Badge -->
    <span :class="typeClasses" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
      {{ locationType }}
    </span>
    
    <!-- Status Indicator -->
    <div class="flex items-center">
      <div :class="statusDotClasses" class="w-2 h-2 rounded-full"></div>
    </div>
    
    <!-- Info Button -->
    <LocationInfo :location="location" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import LocationInfo from './LocationInfo.vue'

const props = defineProps({
  location: {
    type: Object,
    required: true
  }
})

const locationName = computed(() => props.location?.name || 'Unknown')
const locationType = computed(() => props.location?.type || 'unknown')

const typeClasses = computed(() => {
  const type = locationType.value.toLowerCase()
  switch (type) {
    case 'store':
      return 'bg-blue-100 text-blue-800'
    case 'warehouse':
      return 'bg-purple-100 text-purple-800'
    case 'supplier':
      return 'bg-orange-100 text-orange-800'
    case 'customer':
      return 'bg-green-100 text-green-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
})

const statusDotClasses = computed(() => {
  return props.location?.is_active 
    ? 'bg-green-400' 
    : 'bg-red-400'
})
</script>
