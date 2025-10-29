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
                'bg-green-50 border border-green-300 shadow-sm': location.id === selectedLocationId,
                'border border-transparent hover:bg-gray-50': location.id !== selectedLocationId
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
                <div v-if="location.id === selectedLocationId" class="w-2 h-2 bg-green-500 rounded-full shadow-sm"></div>
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

<script setup>
import { ref } from 'vue'
import { IconMapPin, IconChevronDown } from '@tabler/icons-vue'
import { useGlobalLocation } from '@/Composables/useGlobalLocation'

const visible = ref(false)

const {
  selectedLocationId,
  locations,
  currentLocation,
  switchToLocation,
  getLocationIcon
} = useGlobalLocation()

// Debug logging (remove in production)
// console.log('LocationBadge - selectedLocationId:', selectedLocationId.value)
// console.log('LocationBadge - locations:', locations.value)
// console.log('LocationBadge - currentLocation:', currentLocation.value)

const switchLocation = (location) => {
  switchToLocation(location)
  visible.value = false
}
</script>
