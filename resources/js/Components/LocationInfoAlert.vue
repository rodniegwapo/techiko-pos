<template>
  <div v-if="showAlert && currentLocation" class="mb-4">
    <a-alert
      :message="`Viewing inventory for: ${currentLocation.name}`"
      :description="currentLocation.address || currentDomain?.name || ''"
      type="info"
      show-icon
      :closable="true"
      @close="handleClose"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const isVisible = ref(true)

// Get current data
const user = computed(() => page.props.auth?.user)
const currentDomain = computed(() => page.props.currentDomain)
const currentLocation = computed(() => page.props.currentLocation)

// Check if alert should be shown
const showAlert = computed(() => {
  return user.value && isVisible.value
})

// Handle close event
const handleClose = () => {
  isVisible.value = false
}
</script>
 