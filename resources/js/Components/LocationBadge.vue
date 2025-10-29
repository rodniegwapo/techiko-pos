<script setup>
import { ref, computed } from 'vue'
import { IconMapPin, IconChevronDown } from '@tabler/icons-vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { notification } from 'ant-design-vue'

const page = usePage()
const visible = ref(false)

// Get current location from page props
const currentLocation = computed(() => page.props.currentLocation)

// Get locations from page props
const locations = computed(() => {
  const locationsData = page.props.locations || []
  return Array.isArray(locationsData) ? locationsData : Object.values(locationsData)
})

// Get location icon/color based on type
const getLocationIcon = (type) => {
  const icons = {
    store: 'bg-green-500',
    warehouse: 'bg-blue-500',
    supplier: 'bg-orange-500',
    customer: 'bg-purple-500',
    default: 'bg-gray-500'
  }
  return icons[type?.toLowerCase()] || icons.default
}

// Switch location using set-default API
const switchLocation = async (location) => {
  try {
    // Call the set-default API
    await axios.post(`/inventory/locations/${location.id}/set-default`)
    
    // Close popover
    visible.value = false
    
    // Full page reload with new location in URL
    const url = new URL(window.location)
    url.searchParams.set('location_id', location.id)
    window.location.href = url.toString()
    
  } catch (error) {
    console.error('Failed to set default location:', error)
    
    // Show error notification
    notification.error({
      message: 'Location Update Failed',
      description: 'Failed to update default location. Please try again.',
      duration: 5
    })
  }
}
</script>

<template>
  <div class="fixed top-4 right-4 z-50">
    <a-popover
      v-model:open="visible"
      placement="bottomRight"
      trigger="click"
      :overlay-style="{ width: '280px' }"
    >
      <template #content>
        <div class="w-full">
          <div class="font-semibold mb-3 text-gray-800 flex items-center">
            <IconMapPin class="w-4 h-4 mr-2 text-green-500" />
            Switch Location
          </div>
          <div class="space-y-1 max-h-60 overflow-y-auto">
            <div
              v-for="location in locations"
              :key="location.id"
              @click="switchLocation(location)"
              class="p-3 hover:bg-gray-50 cursor-pointer rounded-lg flex items-center justify-between transition-colors"
              :class="{ 
                'bg-green-50 border border-green-300 shadow-sm': location.id === currentLocation?.id,
                'border border-transparent hover:bg-gray-50': location.id !== currentLocation?.id
              }"
            >
              <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2 mb-1">
                  <div :class="getLocationIcon(location.type)" class="w-2 h-2 rounded-full flex-shrink-0"></div>
                  <div class="font-medium text-gray-900 truncate">{{ location.name }}</div>
                </div>
                <div class="text-xs text-gray-500 truncate">{{ location.address || 'No address' }}</div>
              </div>
              <div class="flex items-center space-x-2 ml-2">
                <span class="text-xs text-gray-400 capitalize">{{ location.type }}</span>
                <div v-if="location.id === currentLocation?.id" class="w-2 h-2 bg-green-500 rounded-full shadow-sm"></div>
              </div>
            </div>
          </div>
        </div>
      </template>
      
      <a-button 
        type="primary" 
        size="large"
        class="flex items-center space-x-2 bg-green-500 hover:bg-green-600 border-green-500 rounded-xl px-6 py-3 shadow-lg font-medium"
      >
        <IconMapPin class="w-5 h-5" />
        <span class="font-medium">{{ currentLocation?.name || 'Select Location' }}</span>
        <IconChevronDown class="w-4 h-4" />
      </a-button>
    </a-popover>
  </div>
</template>